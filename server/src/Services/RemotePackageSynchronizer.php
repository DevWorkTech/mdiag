<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Services;

use DevWorkTech\MDiag\Models\DiagnosticBrand;
use DevWorkTech\MDiag\Models\DiagnosticPackageVersion;
use DevWorkTech\MDiag\Services\Sync\ProviderSyncClientFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use RuntimeException;

final class RemotePackageSynchronizer
{
    public function __construct(
        private readonly Config $config,
        private readonly Filesystem $files,
        private readonly ProviderSyncClientFactory $clients,
    ) {
    }

    /**
     * Совместимый программный интерфейс для неинтерактивного запуска: сначала
     * читает официальный каталог, затем сохраняет найденные пакеты.
     *
     * @param list<string> $modules
     * @param list<string> $versions
     * @param callable(string): void $report
     * @return array{available: int, downloaded: int, skipped: int}
     */
    public function sync(
        string $provider,
        array $modules,
        array $versions,
        bool $latest,
        bool $dryRun,
        bool $force,
        callable $report,
    ): array {
        $packages = $this->discover(
            $provider,
            $modules,
            $versions,
            $latest,
            $report,
        );

        return $this->syncPackages(
            $provider,
            $packages,
            $dryRun,
            $force,
            $report,
        );
    }

    /**
     * Только получает официальный список. Ничего не пишет на диск и позволяет
     * консольной команде показать пользователю варианты до скачивания.
     *
     * @param list<string> $modules
     * @param list<string> $versions
     * @param callable(string): void $report
     * @return list<array<string, mixed>>
     */
    public function discover(
        string $provider,
        array $modules,
        array $versions,
        bool $latest,
        callable $report,
    ): array {
        return $this->clients->for($provider)->discover(
            $modules,
            $versions,
            $latest,
            $report,
        );
    }

    /**
     * Добавляет к официальным данным состояние локального хранилища.
     *
     * @param list<array<string, mixed>> $packages
     * @return list<array<string, mixed>>
     */
    public function describe(string $provider, array $packages): array
    {
        foreach ($packages as $index => $package) {
            $kind = (string) ($package['kind'] ?? 'diagnostic');
            $code = (string) $package['code'];
            $remote = (string) $package['version'];
            $brand = DiagnosticBrand::query()
                ->where('provider', $provider)
                ->where('kind', $kind)
                ->where('code', $code)
                ->first();
            $local = null;
            $exact = null;
            if ($brand !== null) {
                $local = $brand->versions()->where('source_serial', (string) ($package['serial_no'] ?? ''))
                    ->where('active', true)
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->first();
                $exact = $brand->versions()->where('source_serial', (string) ($package['serial_no'] ?? ''))
                    ->where('version', $remote)
                    ->first();
            }

            if ($exact !== null
                && $this->files->isFile($this->absolutePath($exact->relative_path))) {
                $status = 'уже загружено';
            } elseif ($exact !== null) {
                $status = 'файл отсутствует';
            } elseif ($local === null) {
                $status = 'новый пакет';
            } elseif (strnatcasecmp($remote, (string) $local->version) > 0) {
                $status = 'доступно обновление';
            } else {
                $status = 'архивная версия';
            }

            $packages[$index]['local_version'] = $local?->version;
            $packages[$index]['local_status'] = $status;
        }

        return $packages;
    }

    /**
     * Сохраняет уже выбранный пользователем набор и проверяет каждый файл.
     *
     * @param list<array<string, mixed>> $packages
     * @param callable(string): void $report
     * @return array{available: int, downloaded: int, skipped: int}
     */
    public function syncPackages(
        string $provider,
        array $packages,
        bool $dryRun,
        bool $force,
        callable $report,
    ): array {
        $client = $this->clients->for($provider);
        $stats = [
            'available' => count($packages),
            'downloaded' => 0,
            'skipped' => 0,
        ];

        foreach ($packages as $package) {
            $code = $this->segment((string) $package['code']);
            $version = $this->segment((string) $package['version']);
            $kind = $this->segment((string) ($package['kind'] ?? 'diagnostic'));
            $report("{$provider}/{$kind}/{$code} {$version}");

            if ($dryRun) {
                $stats['skipped']++;
                continue;
            }

            $brand = DiagnosticBrand::query()->updateOrCreate(
                [
                    'provider' => $provider,
                    'kind' => $kind,
                    'code' => $code,
                ],
                [
                    'name' => (string) ($package['name'] ?: $code),
                    'enabled' => true,
                ],
            );

            $existing = DiagnosticPackageVersion::query()
                ->where('brand_id', $brand->id)
                ->where('version', (string) $package['version'])
                ->where('source_serial', (string) ($package['serial_no'] ?? ''))
                ->first();

            if (!$force && $existing !== null
                && $this->files->isFile($this->absolutePath($existing->relative_path))) {
                $report('  уже сохранён');
                $stats['skipped']++;
                continue;
            }

            $filename = $this->filename($package);
            $relative = implode(DIRECTORY_SEPARATOR, [
                $provider,
                $kind,
                $this->segment((string) ($package['serial_no'] ?? 'shared') ?: 'shared'),
                $code,
                $version,
                $filename,
            ]);
            $destination = $this->absolutePath($relative);
            $this->files->ensureDirectoryExists(dirname($destination), 0750, true);
            $temporary = $destination . '.part-' . bin2hex(random_bytes(6));

            try {
                $client->download($package, $temporary);
                if ($this->files->exists($destination)) {
                    $this->files->delete($destination);
                }
                if (!$this->files->move($temporary, $destination)) {
                    throw new RuntimeException('Не удалось переместить скачанный пакет.');
                }
            } finally {
                if ($this->files->exists($temporary)) {
                    $this->files->delete($temporary);
                }
            }

            DiagnosticPackageVersion::query()->updateOrCreate(
                [
                    'brand_id' => $brand->id,
                    'version' => (string) $package['version'],
                    'source_serial' => (string) ($package['serial_no'] ?? ''),
                ],
                [
                    'relative_path' => $relative,
                    'size_bytes' => (int) filesize($destination),
                    'md5' => hash_file('md5', $destination),
                    'sha256' => hash_file('sha256', $destination),
                    'source_version_detail_id' => $package['version_detail_id'] ?: null,
                    'source_soft_id' => $package['soft_id'] ?: null,
                    'source_url' => $package['download_url'] ?: null,
                    'release_notes' => $package['notes'] ?: null,
                    'published_at' => $this->publishedAt($package['published_at'] ?? null),
                    'active' => true,
                ],
            );

            $stats['downloaded']++;
            $report('  скачан и проверен');
        }

        return $stats;
    }

    /** Выбирает безопасное имя файла из DTO или URL. */
    private function filename(array $package): string
    {
        $name = trim((string) ($package['filename'] ?? ''));
        if ($name === '') {
            $name = basename((string) parse_url(
                (string) ($package['download_url'] ?? ''),
                PHP_URL_PATH
            ));
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: '';

        return $name === '' ? 'package.bin' : $name;
    }

    private function segment(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($value)) ?: '';
        if ($value === '' || $value === '.' || $value === '..') {
            throw new RuntimeException('Официальный пакет содержит небезопасный идентификатор.');
        }

        return $value;
    }

    /** Нормализует дату публикации; при неизвестном формате берёт текущее время. */
    private function publishedAt(mixed $value): mixed
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return now();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return now();
        }
    }

    /** Превращает относительный путь БД в абсолютный путь private storage. */
    private function absolutePath(string $relative): string
    {
        return rtrim(
            (string) $this->config->get('mdiag-dwt.package_root'),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . ltrim($relative, DIRECTORY_SEPARATOR);
    }
}
