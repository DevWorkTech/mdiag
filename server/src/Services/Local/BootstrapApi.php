<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;
use DevWorkTech\MDiag\Services\ProfileRegistry;
use Symfony\Component\HttpFoundation\Response;

/** Локальная таблица адресов; не зависит от основного APP_URL Laravel. */
final class BootstrapApi
{
    /** Все bootstrap-адреса формируются от настроенного домена, без внешних origin. */
    public function configuration(string $provider, ProfileRegistry $profiles): Response
    {
        if ($provider !== 'xdiag') { throw new AccessDenied('unsupported'); }
        $paths = json_decode(file_get_contents(__DIR__ . '/../../../resources/xdiag-routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $urls = [];
        foreach ($paths as $key => $path) {
            $urls[] = ['key' => $key, 'value' => $profiles->localBase($provider) . '/' . ltrim($path, '/')];
        }
        return response()->json(['code' => 0, 'msg' => 'success', 'data' => ['urls' => $urls, 'version' => '22', 'area' => '1']]);
    }

}
