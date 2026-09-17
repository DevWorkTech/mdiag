<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;
use DevWorkTech\MDiag\Models\{DiagnosticPackageVersion, LocalUser, ScannerAccessRule};
use Symfony\Component\HttpFoundation\Response;

/** Фильтрует каталог и файлы одними правилами; не обращается к внешним серверам. */
final class LocalCatalog
{
    public const DOWNLOAD_PATHS = ['mobile/softCenter/downloadDiagSoftWs.action',
        'mobile/softCenter/downloadEncryptDiagSoft.action', 'mobile/softCenter/diagpointdown.php'];
    public function scanner(LocalUser $user, string $serial): ScannerAccessRule
    {
        $scanner = ScannerAccessRule::where('provider', $user->provider)
            ->where('user_id', $user->id)->where('serial', strtoupper($serial))->first();
        if (!$scanner) { throw new AccessDenied('scanner_denied'); }
        if (!$scanner->downloads_allowed) { throw new AccessDenied('downloads_denied', $scanner->denied_message); }
        if ($scanner->expires_at && $scanner->expires_at->lessThanOrEqualTo(now())) { throw new AccessDenied('subscription_expired', $scanner->denied_message); }
        if (!$user->downloads_allowed) { throw new AccessDenied('downloads_denied', $user->denied_message); }
        return $scanner;
    }

    /** null = весь каталог, [] = ни одного; ограничения пользователя и SN пересекаются. */
    public function allowed(LocalUser $user, ScannerAccessRule $scanner, string $code): bool
    {
        foreach ([$user->allowed_modules, $scanner->allowed_modules] as $list) {
            if ($list !== null && !in_array(strtoupper($code), array_map('strtoupper', $list), true)) { return false; }
        }
        return true;
    }

    public function products(LocalUser $user): array
    {
        return ScannerAccessRule::where('provider', $user->provider)->where('user_id', $user->id)->get()
            ->map(fn ($s) => [
                'serialNo' => $s->serial, 'productType' => 'xdiasft3S',
                'productName' => 'MDiag DWT', 'cc' => (string) $user->id,
                'expireDate' => ($s->expires_at ?? $user->expires_at)?->toDateTimeString() ?? '',
            ])->all();
    }

    public function versions(LocalUser $user, ScannerAccessRule $scanner, string $kind, bool $latest, array $params, string $token): array
    {
        $query = DiagnosticPackageVersion::with('brand')->where('active', true)
            ->where(fn ($q) => $q->where('source_serial', '')->orWhere('source_serial', $scanner->serial))
            ->whereHas('brand', fn ($q) => $q->where('provider', $user->provider)->where('kind', $kind)->where('enabled', true));
        $soft = $params['softId'] ?? null;
        if (is_scalar($soft) && (string) $soft !== '') { $query->where('source_soft_id', (string) $soft); }
        $module = $params['softPackageId'] ?? null;
        if (is_scalar($module) && (string) $module !== '') { $query->whereHas('brand', fn ($q) => $q->where('code', strtoupper((string) $module))); }
        $records = $query->get()->filter(fn ($v) => $this->allowed($user, $scanner, $v->brand->code) && $this->file($v) !== null)
            ->sort(fn ($a, $b) => version_compare(ltrim($b->version, 'Vv'), ltrim($a->version, 'Vv')));
        if ($latest) { $records = $records->unique('brand_id'); }
        return $records->map(fn ($v) => $this->dto($v, $scanner->serial, $token))->values()->all();
    }

    /** Все URL создаются заново из локального домена, source_url клиенту не выдаётся. */
    private function dto(DiagnosticPackageVersion $v, string $serial, string $token): array
    {
        $url = rtrim((string) config('mdiag-dwt.base_url'), '/') . '/' . $v->brand->provider
            . '/mobile/softCenter/downloadDiagSoftWs.action?' . http_build_query([
                'versionDetailId' => $v->id, 'serialNo' => $serial, 'token' => $token,
            ], '', '&', PHP_QUERY_RFC3986);
        return [
            'softId' => $v->source_soft_id ?: (string) $v->brand_id,
            'softPackageId' => $v->brand->code, 'softPackageID' => $v->brand->code,
            'softName' => $v->brand->name, 'softShowName' => $v->brand->name,
            'versionNo' => $v->version, 'softVersion' => $v->version, 'maxVersionNo' => $v->version,
            'versionDetailId' => (string) $v->id, 'maxVersionDetailId' => (string) $v->id,
            'pubVersionDetailId' => (string) $v->id,
            'fileSize' => $v->size_bytes, 'md5' => $v->md5, 'sha256' => $v->sha256,
            'downloadUrl' => $url, 'url' => $url, 'cdnAllURL' => $url,
            'fileName' => basename($v->relative_path), 'serialNo' => $serial,
        ];
    }

    public function download(LocalUser $user, ScannerAccessRule $scanner, string $id): Response
    {
        $v = DiagnosticPackageVersion::with('brand')->whereKey($id)->where('active', true)
            ->whereHas('brand', fn ($q) => $q->where('provider', $user->provider)->where('enabled', true))->first();
        if (!$v || ($v->source_serial !== '' && $v->source_serial !== $scanner->serial)) { throw new AccessDenied('not_found'); }
        if (!$this->allowed($user, $scanner, $v->brand->code)) { throw new AccessDenied('module_denied', $user->module_denied_message); }
        $path = $this->file($v);
        if ($path === null) { throw new AccessDenied('not_found'); }
        $response = new PhpDownloadResponse($path, 200, [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'X-MDiag-SHA256' => $v->sha256, 'X-Content-Type-Options' => 'nosniff',
        ], false, 'attachment');
        return $response;
    }

    /** Проверяем realpath, чтобы даже ошибочный путь в БД не открыл чужой файл. */
    private function file(DiagnosticPackageVersion $version): ?string
    {
        $root = realpath((string) config('mdiag-dwt.package_root'));
        if ($root === false) { return null; }
        $path = realpath($root . DIRECTORY_SEPARATOR . $version->relative_path);
        return $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
    }
}
