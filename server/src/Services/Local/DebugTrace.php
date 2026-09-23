<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;

/** Локальный JSONL-журнал. Не использует глобальные handlers/Sentry и не пишет тела запросов. */
final class DebugTrace
{
    public static function write(string $event, array $context = []): bool
    {
        if (!config('app.debug', false)) { return true; }
        $dir = storage_path('logs');
        if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
        $file = $dir . '/mdiag-debug-' . gmdate('Y-m-d') . '.log';
        $requestId=app()->bound('request') ? request()->attributes->get('mdiag_request_id') : null;
        $line = json_encode(['time'=>gmdate('c'),'event'=>$event]+$context+['id'=>$requestId], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        return @file_put_contents($file, $line . PHP_EOL, FILE_APPEND|LOCK_EX) !== false;
    }

    /** URL без query/fragment/userinfo; резервный login содержит секреты даже в path. */
    public static function url(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p)) { return '[invalid-url]'; }
        $path = (string) ($p['path'] ?? '/');
        if (str_contains($path, '/login.php/')) { $path = strstr($path, '/login.php/', true) . '/login.php/[redacted]'; }
        return ($p['scheme'] ?? '') . '://' . ($p['host'] ?? '')
            . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
    }
}
