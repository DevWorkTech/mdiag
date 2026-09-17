<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Services\Sync;

/**
 * Единый контракт адаптера внешнего диагностического приложения.
 *
 * Каждый новый APK получает отдельную реализацию: так протоколы XPRO/DiagZone
 * не смешиваются с уже проверенным протоколом X-DIAG.
 */
interface ProviderSyncClient
{
    public function provider(): string;

    /**
     * @param list<string> $modules
     * @param list<string> $versions
     * @param callable(string): void $report
     * @return list<array<string, mixed>>
     */
    public function discover(
        array $modules,
        array $versions,
        bool $latest,
        callable $report,
    ): array;

    /** Скачивает один DTO-пакет во временный файл для дальнейшей проверки. */
    public function download(array $package, string $destination): void;
}
