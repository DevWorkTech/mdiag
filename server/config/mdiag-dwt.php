<?php

declare(strict_types=1);

/*
 * MDiag DWT: настройки независимого локального репозитория.
 * Входящий HTTP API никогда не обращается к поставщику. Интернет использует
 * только консольный клиент синхронизации с учётной записью владельца сервера.
 */
// Независим от APP_URL основного Laravel. Здесь только DNS-имя без схемы/пути.
// Этим же значением ограничены routes и построение всех локальных URL.
$domain = 'diag.devwork.ru';
// http допустим для отдельной LAN-сборки без TLS; схема должна совпадать с APK/Nginx.
$scheme = 'https';
$profile = static fn (string $name, bool $enabled = false): array => [
    'enabled' => $enabled,
    'prefix' => $name,
    'sync' => ['enabled' => false],
];
$xdiag = $profile('xdiag', true);
$xdiag['sync'] = [
    'enabled' => (string) env('MDIAG_XDIAG_LOGIN', '') !== ''
        && (string) env('MDIAG_XDIAG_PASSWORD', '') !== '',
    'driver' => 'xdiag-7.00.014',
    'username' => (string) env('MDIAG_XDIAG_LOGIN', ''),
    'password' => (string) env('MDIAG_XDIAG_PASSWORD', ''),
    // Необязательные проверенные оператором SOAP URL: при заполнении заменяют автоматический список.
    // Сохраняйте путь, порт и query (?wsdl), полученные из конфигурации оригинального APK.
    'soap_endpoints' => ['product' => [], 'diagnostic' => [], 'public' => []],
    // Резервный протокол APK требует SN. Без него резерв не отправляется.
    'fallback_login' => ['enabled' => true, 'serial_no' => '', 'timezone' => null],
];
return [
    'http' => [
        'enabled' => true, 'domain' => $domain,
        'middleware' => ['api'], 'route_name_prefix' => 'mdiag-dwt.',
    ],
    'local_domain' => $domain,
    'base_url' => $scheme . '://' . $domain,
    // Пакеты приватные: никогда не размещайте этот каталог под nginx alias.
    'package_root' => storage_path('app/private/mdiag/packages'),
    'snapshot_root' => storage_path('app/private/mdiag/snapshots'),
    'connect_timeout' => 15,
    'request_timeout' => 120,
    'verify_tls' => false,
    'auth' => [
        // На время диагностики входа: отдельный локальный файл logs/mdiag-auth.log.
        // Сохраняет только время и причину, не использует глобальные log handlers.
        'diagnostic_log' => false,
        'session_hours' => 12,
        'max_sessions' => 5,
        'login_attempts' => 10,
        'login_decay_seconds' => 60,
        // Регистрация локальная; новые пользователи заблокированы до разрешения оператора.
        'registration_enabled' => false,
    ],
    // Необязательные sources/paths для публичных web-снимков. Подробнее src/Modules/Web.
    'web_content' => ['modules' => []],
    'profiles' => [
        'xdiag' => $xdiag,
        'xpro7' => $profile('xpro7'), 'xpro5' => $profile('xpro5'),
        'diagzone' => $profile('diagzone'), 'prodiag' => $profile('prodiag'),
    ],
];

