<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;

use Illuminate\Http\Request;

/**
 * Штатные подписи X-DIAG 7.00.014, проверенные по BaseManager и DownloadManager.
 * Алгоритмы оставлены совместимыми с APK; секретом служит только локальный токен.
 * MD5 здесь — часть существующего протокола, а не способ хранения пароля.
 */
final class XDiagSignature
{
    public function identity(Request $request, array $parsed): array
    {
        if ($parsed['method'] !== null) {
            return [(string) ($parsed['auth']['cc'] ?? ''), (string) ($parsed['auth']['sign'] ?? '')];
        }
        // DownloadManager отправляет cc/sign именно HTTP-заголовками.
        return [(string) $request->header('cc', $request->input('user_id', $request->input('ccs', ''))),
            (string) $request->header('sign', $request->input('sign', ''))];
    }

    public function verify(Request $request, array $parsed, string $token, string $sign): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/iD', $sign)) { return false; }
        if ($parsed['method'] !== null) {
            // ksoap2 подписывает значения в порядке параметров в SOAP Body.
            $source = implode('', array_values($parsed['params']));
        } else {
            $params = $parsed['params'];
            unset($params['sign'], $params['token']);
            $params = array_filter($params, static fn ($v) => $v !== null && (string) $v !== '');
            ksort($params, SORT_STRING);
            if ($request->headers->has('cc') && $request->headers->has('sign')) {
                // RequestParams.c(): конкатенация отсортированных значений без разделителей.
                $source = implode('', array_values($params));
            } elseif ($request->input('action') !== null) {
                // RequestParams.b(): исходные, НЕ URL-encoded key=value через &.
                $parts = [];
                foreach ($params as $key => $value) { $parts[] = $key . '=' . $value; }
                $source = implode('&', $parts);
            } else {
                // Нет догадок об альтернативных download-подписях: неизвестный формат закрыт.
                return false;
            }
        }
        return hash_equals(md5($source . $token), strtolower($sign));
    }
}
