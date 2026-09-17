<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Подтверждённые коды входа сохранены из APK. 900xxx — собственные коды MDiag,
 * а не предположения о закрытом сервере. Оператор меняет тексты/коды в БД.
 * APK может показывать встроенный текст для известных кодов: произвольный msg
 * доступен в протоколе, но его отображение зависит от конкретного экрана APK.
 */
final class ErrorCatalog
{
    public const DEFAULTS = [
        'invalid_credentials' => [100001, 'Неверный логин или пароль.'],
        'invalid_request' => [1023, 'Некорректные или неполные параметры запроса.'],
        'session_invalid' => [900001, 'Войдите в локальную учётную запись заново.'],
        'user_disabled' => [900002, 'Доступ к локальному серверу заблокирован.'],
        'subscription_expired' => [900003, 'Срок локальной подписки истёк.'],
        'module_denied' => [900004, 'Этот модуль не разрешён для вашей учётной записи.'],
        'scanner_denied' => [900005, 'Сканер не разрешён для этой учётной записи.'],
        'downloads_denied' => [900006, 'Скачивание отключено.'],
        'not_found' => [900007, 'Файл отсутствует в локальном хранилище.'],
        'unsupported' => [900008, 'Эта функция пока не поддерживается локальным сервером.'],
        'registration_disabled' => [900009, 'Регистрация доступна только администратору.'],
        'login_taken' => [900010, 'Локальное имя пользователя уже занято.'],
        'rate_limited' => [900011, 'Слишком много попыток. Повторите позже.'],
        'internal_error' => [900012, 'Ошибка локального сервера. Обратитесь к администратору.'],
    ];

    public function respond(string $provider, array $parsed, AccessDenied $error, Protocol $protocol): Response
    {
        [$code, $message] = self::DEFAULTS[$error->reason] ?? self::DEFAULTS['internal_error'];
        $custom = DB::table('mdiag_error_messages')->where('provider', $provider)->where('key', $error->reason)->first();
        $message = $error->userMessage ?: ($custom?->message ?: $message);
        $status = (int) ($custom?->http_status ?? 200);
        if ($status < 200 || $status > 599 || ($status >= 300 && $status < 400)) { $status = 200; }
        return $protocol->reply($parsed, [
            'code' => (int) ($custom?->code ?? $code), 'msg' => $message,
            'message' => $message, 'data' => null,
        ], $status)->header('X-MDiag-Error', $error->reason);
    }
}
