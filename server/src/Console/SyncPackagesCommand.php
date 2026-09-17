<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Console;

use DevWorkTech\MDiag\Services\RemotePackageSynchronizer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Интерактивная синхронизация официальных пакетов.
 *
 * Без параметров команда показывает последние версии и предлагает отметить
 * нужные позиции. Для cron/скриптов остаются --all, --module и --package-version.
 */
final class SyncPackagesCommand extends Command
{
    protected $signature = 'mdiag:sync
        {provider? : Профиль приложения; по умолчанию все включённые}
        {--all : Скачать все найденные модули и версии без вопроса}
        {--module=* : Код модуля; параметр можно повторять}
        {--package-version=* : Номер версии; параметр можно повторять}
        {--latest : Запрашивать только последние версии}
        {--list : Только показать официальный список}
        {--updates : Показывать только новые, потерянные или обновляемые пакеты}
        {--dry-run : Не скачивать файлы}
        {--force : Повторно скачать уже сохранённую версию}';

    protected $description =
        'Показать и синхронизировать доступные аккаунту пакеты MDiag';

    public function handle(RemotePackageSynchronizer $synchronizer): int
    {
        $modules = $this->normalizedModules();
        $versions = $this->normalizedVersions();
        if ($this->option('latest') && $versions !== []) {
            $this->components->error('--latest нельзя использовать вместе с --package-version.');

            return self::INVALID;
        }

        $provider = $this->argument('provider');
        $providers = is_string($provider) && $provider !== ''
            ? [strtolower($provider)]
            : $this->enabledSyncProfiles();
        if ($providers === []) {
            $this->components->error(
                'Нет профилей синхронизации. Заполните login/password в .env.',
            );

            return self::FAILURE;
        }

        $failed = false;
        foreach ($providers as $current) {
            try {
                $this->processProvider(
                    $current,
                    $modules,
                    $versions,
                    $synchronizer,
                );
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->error(
                    "{$current}: {$exception->getMessage()}",
                );
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Получает официальный каталог, показывает сравнение и запускает скачивание.
     *
     * @param list<string> $modules
     * @param list<string> $versions
     */
    private function processProvider(
        string $provider,
        array $modules,
        array $versions,
        RemotePackageSynchronizer $synchronizer,
    ): void {
        $manual = !$this->option('all') && $modules === [];
        $latest = (bool) $this->option('latest') || ($manual && $versions === []);

        $this->newLine();
        $this->components->info("Официальный каталог {$provider}");
        $packages = $synchronizer->discover(
            $provider,
            $modules,
            $versions,
            $latest,
            fn (string $message) => $this->line($message),
        );
        $packages = $synchronizer->describe($provider, $packages);

        if ($this->option('updates')) {
            $packages = array_values(array_filter(
                $packages,
                static fn (array $package): bool => in_array(
                    (string) ($package['local_status'] ?? ''),
                    ['новый пакет', 'файл отсутствует', 'доступно обновление'],
                    true,
                ),
            ));
        }
        if ($packages === []) {
            $this->components->info('Подходящих обновлений нет.');
            return;
        }

        $this->showPackages($packages);
        if ($this->option('list')) {
            $this->components->info('Список показан, файлы не скачивались.');
            return;
        }

        if ($manual) {
            if (!$this->input->isInteractive()) {
                $this->components->warn(
                    'Терминал неинтерактивный: используйте --all или --module.',
                );
                return;
            }
            $packages = $this->selectPackages($packages);
            if ($packages === []) {
                $this->components->warn('Ничего не выбрано.');
                return;
            }
        }

        $stats = $synchronizer->syncPackages(
            $provider,
            $packages,
            (bool) $this->option('dry-run'),
            (bool) $this->option('force'),
            fn (string $message) => $this->line($message),
        );
        $this->components->info(sprintf(
            '%s: выбрано=%d, скачано=%d, пропущено=%d',
            $provider,
            $stats['available'],
            $stats['downloaded'],
            $stats['skipped'],
        ));
    }

    /** @param list<array<string, mixed>> $packages */
    private function showPackages(array $packages): void
    {
        $rows = [];
        foreach ($packages as $index => $package) {
            $rows[] = [
                $index + 1,
                ($package['kind'] ?? 'diagnostic') === 'public'
                    ? 'программа'
                    : 'марка',
                $package['code'],
                $package['name'],
                $package['version'],
                $package['local_version'] ?? '—',
                $package['local_status'] ?? '—',
                $this->humanBytes((int) ($package['file_size'] ?? 0)),
                $package['serial_no'] ?? '—',
            ];
        }
        $this->table(
            ['№', 'Тип', 'Код', 'Название', 'Официальная', 'Локальная', 'Состояние', 'Размер', 'SN'],
            $rows,
        );
    }

    /**
     * Laravel choice поддерживает ввод «0,2,5» и возвращает несколько строк.
     *
     * @param list<array<string, mixed>> $packages
     * @return list<array<string, mixed>>
     */
    private function selectPackages(array $packages): array
    {
        $choices = [];
        $indexes = [];
        foreach ($packages as $index => $package) {
            $label = sprintf(
                '%s/%s %s — %s',
                $package['kind'] ?? 'diagnostic',
                $package['code'],
                $package['version'],
                $package['local_status'] ?? '',
            );
            $choices[] = $label;
            $indexes[$label] = $index;
        }
        $selected = $this->choice(
            'Что скачать? Можно выбрать несколько номеров через запятую',
            $choices,
            null,
            null,
            true,
        );
        $selected = is_array($selected) ? $selected : [$selected];

        return array_values(array_map(
            static fn (string $label): array => $packages[$indexes[$label]],
            array_values(array_filter(
                $selected,
                static fn (mixed $label): bool => is_string($label)
                    && isset($indexes[$label]),
            )),
        ));
    }

    /** @return list<string> */
    private function normalizedModules(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            (array) $this->option('module'),
        ))));
    }

    /** @return list<string> */
    private function normalizedVersions(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) $this->option('package-version'),
        ))));
    }

    /** @return list<string> */
    private function enabledSyncProfiles(): array
    {
        $profiles = (array) config('mdiag-dwt.profiles', []);

        return array_values(array_map(
            'strval',
            array_keys(array_filter(
                $profiles,
                static fn (mixed $profile): bool => is_array($profile)
                    && ($profile['enabled'] ?? false)
                    && data_get($profile, 'sync.enabled', false),
            )),
        ));
    }

    /** Человекочитаемый размер для таблицы выбора. */
    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }
        $units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return number_format($bytes / (1024 ** $power), 2, '.', ' ')
            . ' ' . $units[$power];
    }
}
