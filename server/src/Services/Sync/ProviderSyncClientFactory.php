<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Services\Sync;

use RuntimeException;

/** Выбирает проверенный клиент по профилю приложения. */
final class ProviderSyncClientFactory
{
    public function __construct(
        private readonly XDiagOfficialClient $xdiag,
    ) {
    }

    public function for(string $provider): ProviderSyncClient
    {
        return match (strtolower($provider)) {
            'xdiag' => $this->xdiag,
            default => throw new RuntimeException(
                "No verified synchronization adapter exists for {$provider}. "
                . 'Supply that application APK before enabling its sync profile.'
            ),
        };
    }
}
