<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Реестр приложений: проверяет профиль и строит внешний/локальный base URL.
 */
final class ProfileRegistry
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string, mixed> Включённый профиль с настроенным upstream. */
    public function get(string $provider): array
    {
        $profile = $this->config->get("mdiag-dwt.profiles.{$provider}");

        if (!is_array($profile) || !($profile['enabled'] ?? false)) {
            throw new NotFoundHttpException('Unknown or disabled diagnostic application.');
        }

        return $profile;
    }

    /** @return array{0: string, 1: array<string, mixed>} Профиль по URL-префиксу. */
    public function byPrefix(string $prefix): array
    {
        $profiles = $this->config->get('mdiag-dwt.profiles', []);

        if (!is_array($profiles)) {
            throw new NotFoundHttpException('Diagnostic profiles are not configured.');
        }

        foreach ($profiles as $provider => $profile) {
            if (!is_array($profile) || (string) ($profile['prefix'] ?? '') !== $prefix) {
                continue;
            }

            return [(string) $provider, $this->get((string) $provider)];
        }

        throw new NotFoundHttpException('Unknown diagnostic application prefix.');
    }

    public function localBase(string $provider): string
    {
        $profile = $this->get($provider);
        $base = rtrim((string) $this->config->get('mdiag-dwt.base_url'), '/');
        $prefix = trim((string) ($profile['prefix'] ?? $provider), '/');

        return $base . '/' . rawurlencode($prefix);
    }
}
