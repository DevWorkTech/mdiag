<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Console;

use DevWorkTech\MDiag\Models\DiagnosticBrand;
use DevWorkTech\MDiag\Models\DiagnosticPackageVersion;
use DevWorkTech\MDiag\Services\ProfileRegistry;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Ручное добавление законно полученного пакета без обращения к официальному
 * серверу. Используется для восстановления резервной копии.
 */
final class AddPackageCommand extends Command
{
    protected $signature = 'mdiag:package:add
        {provider : Diagnostic application profile}
        {brand : Module code, for example BENZ}
        {version : Package version}
        {file : Absolute path to a legally obtained package}
        {--kind=diagnostic : diagnostic or public}
        {--name= : Human-readable module name}
        {--notes= : Примечание}
        {--serial= : SN для персонального пакета; пусто только для общего файла}';

    protected $description = 'Import an authorized package into local storage';

    public function handle(
        ProfileRegistry $profiles,
        Filesystem $files,
    ): int {
        $provider = strtolower((string) $this->argument('provider'));
        $profiles->get($provider);
        $kind = strtolower(trim((string) $this->option('kind')));
        if (!in_array($kind, ['diagnostic', 'public'], true)) {
            throw new RuntimeException('--kind must be diagnostic or public.');
        }

        $brandCode = strtoupper((string) $this->argument('brand'));
        $version = (string) $this->argument('version');
        $source = (string) $this->argument('file');
        $serial = strtoupper((string) ($this->option('serial') ?? ''));
        foreach ([$brandCode, $version, $serial ?: 'shared'] as $segment) {
            if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}$/D', $segment) || str_contains($segment, '..')) {
                throw new RuntimeException('Недопустимый код, версия или SN.');
            }
        }

        if (!$files->isFile($source) || !is_readable($source)) {
            throw new RuntimeException("Package is not readable: {$source}");
        }

        $brand = DiagnosticBrand::query()->firstOrCreate(
            ['provider' => $provider, 'kind' => $kind, 'code' => $brandCode],
            ['name' => (string) ($this->option('name') ?: $brandCode), 'enabled' => true],
        );

        $relative = implode(DIRECTORY_SEPARATOR, [
            $provider, $kind, $serial ?: 'shared', $brandCode, $version, basename($source),
        ]);
        $destination = rtrim(
            (string) config('mdiag-dwt.package_root'),
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR . $relative;
        $files->ensureDirectoryExists(dirname($destination), 0750, true);

        if (!$files->copy($source, $destination)) {
            throw new RuntimeException('Unable to copy the package into local storage.');
        }

        $record = DiagnosticPackageVersion::query()->updateOrCreate(
            ['brand_id' => $brand->id, 'version' => $version, 'source_serial' => $serial],
            [
                'relative_path' => $relative,
                'size_bytes' => filesize($destination),
                'md5' => hash_file('md5', $destination),
                'sha256' => hash_file('sha256', $destination),
                'release_notes' => $this->option('notes'),
                'published_at' => now(),
                'active' => true,
            ],
        );

        $this->components->info(
            "Imported {$provider}/{$kind}/{$brandCode} {$version}; id={$record->id}"
        );

        return self::SUCCESS;
    }
}
