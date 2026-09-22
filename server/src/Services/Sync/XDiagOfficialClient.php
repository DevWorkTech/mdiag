<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag\Services\Sync;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SoapClient;
use SoapHeader;
use SoapParam;
use SoapVar;
use Throwable;

/**
 * Клиент официальных сервисов X-DIAG: исходная ветка 7.00.004, вход/SOAP сверены с 7.00.014.
 *
 * Повторяет протокол Android-приложения: form-login, поля Build.*, cookie,
 * SOAP-заголовок authenticate, получение привязанных сканеров, каталог версий
 * и параметры скачивания. Права всегда определяет официальный сервер.
 */
final class XDiagOfficialClient implements ProviderSyncClient
{
    /** Основной production-сервер из assets/configurl.json. */
    public const PRIMARY_ORIGIN = 'https://services.x-diag.info';

    /** Резервный порт из download/public веток APK. */
    public const PORT_8000_ORIGIN = 'https://services.x-diag.info:8000';

    /** Отдельный сервер динамической конфигурации из APK. */
    public const CONFIG_ORIGIN = 'https://config.x-diag.info';

    /**
     * Тестовый login endpoint из APK. Автоматически не используется, чтобы
     * production-пароль случайно не ушёл в тестовый контур.
     */
    public const DEVELOPMENT_LOGIN_ORIGIN = 'https://services.x-diag.info:8008/dev';

    /** Старый IP, который приложение использует только для скачивания. */
    public const LEGACY_DOWNLOAD_ORIGIN = 'http://79.174.70.103';

    /** Тот же download-IP на резервном порту из ADAS-ветки APK. */
    public const LEGACY_DOWNLOAD_PORT_8000_ORIGIN = 'http://79.174.70.103:8000';

    /** Namespace SOAP authenticate, дословно взятый из BaseManager APK. */
    private const SOAP_AUTH_NAMESPACE = 'https://79.174.70.97';

    private const APP_ID = '21035';
    private const PROTOCOL_VERSION = '5.3.0';
    // Обычный вход LoginFuction из APK передаёт type=0.
    private const LOGIN_TYPE = '0';
    private const PRODUCT_TYPE = 'xdiasft3S';
    private const LANGUAGE_ID = '1002';
    private const ANDROID_USER_AGENT = 'okhttp/3.12.1';

    private const FALLBACK_LOGIN_PATH = '/diagdevice/app/user/login.php';

    private const LOGIN_PATH = '/?action=passport_service.login';
    private const PRODUCT_WSDL_PATH = '/services/productService.php?wsdl';
    private const DIAGNOSTIC_WSDL_PATH = '/services/xdigPadDiagSoftService.php?wsdl';
    private const PUBLIC_WSDL_PATH = '/services/xdigPadPublicSoftService.php?wsdl';
    private const DIAGNOSTIC_DOWNLOAD_PATH = '/mobile/softCenter/downloadEncryptDiagSoft.action';
    private const DIAGNOSTIC_DOWNLOAD_WS_PATH = '/mobile/softCenter/downloadDiagSoftWs.action';
    private const PUBLIC_DOWNLOAD_PATH = '/mobile/softCenter/diagpointdown.php';
    private const ADAS_DOWNLOAD_PATH = '/mobile/softCenter/adaspointdown.php';
    private const OPEN_DIAG_DOWNLOAD_PATH = '/opendiag/downloadDiagSoftForDiag.php';

    public const METHOD_REGISTERED_PRODUCTS = 'getRegisteredProductsForPad';
    public const METHOD_LATEST_DIAGNOSTIC = 'queryLatestDiagSofts';
    public const METHOD_HISTORY_DIAGNOSTIC = 'queryHistoryDiagSofts';
    public const METHOD_LATEST_PUBLIC = 'queryLatestPublicSofts';

    /** Актуальные SOAP URL из config_service.urls; только для консольной синхронизации. */
    private array $serviceUrls = [];
    private CookieJar $cookies;
    private ?string $token = null;
    private ?string $userId = null;
    private ?string $accountType = null;

    /** @var array<string, mixed> Полный login JSON нужен для поиска SN. */
    private array $loginResponse = [];

    /** @var array<string, SoapClient> SOAP-клиенты повторно используются по endpoint без загрузки WSDL. */
    private array $soapClients = [];

    public function __construct(
        private readonly Config $config,
    ) {
        $this->cookies = new CookieJar();
    }

    /** @return list<string> Серверы для прозрачного gateway приложения. */
    public static function gatewayUpstreams(): array
    {
        return [self::PRIMARY_ORIGIN, self::PORT_8000_ORIGIN];
    }

    /** @return list<string> Все origin, которые Laravel заменяет в ответах. */
    public static function gatewayRewriteOrigins(): array
    {
        return [
            self::PRIMARY_ORIGIN,
            self::PORT_8000_ORIGIN,
            self::CONFIG_ORIGIN,
            self::LEGACY_DOWNLOAD_ORIGIN,
            self::LEGACY_DOWNLOAD_PORT_8000_ORIGIN,
        ];
    }

    /**
     * Выбирает правильный официальный origin по сохранённому штатному пути.
     * Это исключает случайную отправку getConfig.php или старого download API
     * на основной сервер, где тот же путь может означать другую функцию.
     *
     * @return list<array{pattern: string, upstreams: list<string>}>
     */
    public static function gatewayUpstreamRoutes(): array
    {
        return [
            [
                'pattern' => '~^/getConfig\\.php$~i',
                'upstreams' => [self::CONFIG_ORIGIN],
            ],
            [
                'pattern' => '~^/(?:diag/dlDiagSoftPack\\.php|mobile/softCenter/(?:adaspointdown|diagpointdown)\\.php)$~i',
                'upstreams' => [
                    self::LEGACY_DOWNLOAD_ORIGIN,
                    self::LEGACY_DOWNLOAD_PORT_8000_ORIGIN,
                    self::PRIMARY_ORIGIN,
                    self::PORT_8000_ORIGIN,
                ],
            ],
        ];
    }

    /** @return list<string> Штатные маршруты выдачи пакетов в этой версии. */
    public static function downloadPathPatterns(): array
    {
        return [
            '~^/mobile/softCenter/(?:downloadEncryptDiagSoft\\.action|downloadDiagSoftWs\\.action|diagpointdown\\.php|adaspointdown\\.php)$~i',
            '~^/diag/(?:dlDiagSoftPack\\.php|downloadDiagSoftWsFor(?:OldDiagLicence|NewLicence)\\.action)$~i',
            '~^/opendiag/downloadDiagSoftForDiag\\.php$~i',
        ];
    }

    public function provider(): string
    {
        return 'xdiag';
    }

    /**
     * Авторизуется, получает все привязанные SN и собирает только разрешённые
     * официальным аккаунтом модули.
     *
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
    ): array {
        $this->boot();
        $this->authenticate($report);
        $report('  Авторизация на официальном сервере: OK');
        $this->refreshServiceUrls($report);
        $serials = $this->registeredSerials();
        $report('  Привязанные сканеры: ' . implode(', ', $serials));
        $packages = [];

        foreach ($serials as $serial) {
            $report("  {$serial}: получаю диагностические модули");
            $diagnostic = $this->extractPackages(
                $this->soapCall('diagnostic', self::METHOD_LATEST_DIAGNOSTIC, [
                    'serialNo' => $serial,
                    'lanId' => self::LANGUAGE_ID,
                    'defaultLanId' => self::LANGUAGE_ID,
                    'cc' => $this->requiredSessionValue($this->userId, 'user_id'),
                ]),
                'diagnostic',
                $serial,
            );
            $diagnostic = $this->filterModules($diagnostic, $modules);
            $packages = array_merge($packages, $diagnostic);

            // В интерактивном режиме показываем последние версии быстро.
            // Без --latest команда дополнительно запрашивает историю каждой марки.
            if (!$latest) {
                foreach ($diagnostic as $item) {
                    $softId = trim((string) ($item['soft_id'] ?? ''));
                    if ($softId === '') {
                        $report("  {$item['code']}: нет softId, история пропущена");
                        continue;
                    }
                    $report("  {$item['code']}: получаю историю версий");
                    $history = $this->soapCall(
                        'diagnostic',
                        self::METHOD_HISTORY_DIAGNOSTIC,
                        [
                            'serialNo' => $serial,
                            'softId' => $softId,
                            'lanId' => self::LANGUAGE_ID,
                            'defaultLanId' => self::LANGUAGE_ID,
                        ],
                    );
                    $packages = array_merge(
                        $packages,
                        $this->extractPackages($history, 'diagnostic', $serial),
                    );
                }
            }

            $report("  {$serial}: получаю программы и общие компоненты");
            $public = $this->soapCall('public', self::METHOD_LATEST_PUBLIC, [
                'serialNo' => $serial,
                'lanId' => self::LANGUAGE_ID,
                'defaultLanId' => self::LANGUAGE_ID,
            ]);
            $packages = array_merge(
                $packages,
                $this->filterModules(
                    $this->extractPackages($public, 'public', $serial),
                    $modules,
                ),
            );
        }

        $packages = $this->uniquePackages($packages);
        $packages = array_values(array_filter(
            $packages,
            static fn (array $item): bool => $versions === []
                || in_array((string) $item['version'], $versions, true),
        ));
        if ($latest) {
            $packages = $this->latestPackages($packages);
        }
        if ($packages === []) {
            throw new RuntimeException(
                'Официальный X-DIAG не вернул пакетов по заданному фильтру.',
            );
        }

        return $packages;
    }

    /** Скачивает пакет с теми же GET-параметрами и резервами, что APK. */
    public function download(array $package, string $destination): void
    {
        $serial = trim((string) ($package['serial_no'] ?? ''));
        if ($serial === '') {
            throw new RuntimeException('У пакета отсутствует serialNo.');
        }
        $params = [
            'serialNo' => $serial,
            'versionDetailId' => (string) ($package['version_detail_id'] ?? ''),
            'isEncrypted' => '1',
            'isIncr' => ($package['is_increment'] ?? false) ? '1' : '0',
        ];
        if ($this->accountType !== null && $this->accountType !== '') {
            $params['accountType'] = $this->accountType;
        }

        $last = null;
        foreach ($this->downloadCandidates($package) as $url) {
            try {
                if (is_file($destination)) {
                    unlink($destination);
                }
                $query = $this->looksLikeDownloadEndpoint($url) ? $params : [];
                $this->downloadWithRedirects($url, $query, $destination);
                $this->assertDownloadedFile($destination, $package);
                return;
            } catch (Throwable $exception) {
                $last = $exception;
            }
        }

        throw new RuntimeException(
            'Пакет не скачался ни с одного официального адреса: '
            . ($last?->getMessage() ?? 'нет доступных адресов'),
            previous: $last,
        );
    }

    /** Проверяет PHP SOAP и два обязательных секрета из .env. */
    private function boot(): void
    {
        if (!app()->runningInConsole()) {
            throw new RuntimeException('Синхронизация разрешена только через консоль.');
        }
        if (!extension_loaded('soap')) {
            throw new RuntimeException('Для синхронизации требуется PHP SOAP.');
        }
        $this->requiredCredential('username');
        $this->requiredCredential('password');
    }

    /** Отправляет form-login с набором Android Build.*, используемым APK. */
    private function authenticate(callable $report): void
    {
        try {
            $this->authenticatePassport();
            return;
        } catch (Throwable $primaryError) {
            $settings = (array) $this->config->get('mdiag-dwt.profiles.xdiag.sync.fallback_login', []);
            if (!(bool) ($settings['enabled'] ?? true)) {
                throw $primaryError;
            }
            $serial = trim((string) ($settings['serial_no'] ?? ''));
            if ($serial === '') {
                throw new RuntimeException(
                    $primaryError->getMessage()
                    . ' Резервный вход пропущен: укажите sync.fallback_login.serial_no в config/mdiag-dwt.php.',
                    previous: $primaryError,
                );
            }
            $report('  Основной вход не выполнен; пробую резервный вход для указанного сканера.');
            try {
                $this->authenticateFallback($serial, (string) ($settings['timezone'] ?? date_default_timezone_get()));
            } catch (Throwable $fallbackError) {
                throw new RuntimeException(
                    $primaryError->getMessage() . ' Резервный вход: ' . $fallbackError->getMessage(),
                    previous: $fallbackError,
                );
            }
        }
    }

    /** Обычный вход по логину и паролю, как LoginActivity APK. */
    private function authenticatePassport(): void
    {
        $username = $this->requiredCredential('username');
        $payload = [
            'app_id' => self::APP_ID,
            'ver' => self::PROTOCOL_VERSION,
            'login_key' => $username,
            'password' => $this->requiredCredential('password'),
            'time' => (string) floor(microtime(true) * 1000),
            'type' => self::LOGIN_TYPE,
            'device_token' => '',
            'DeviceId' => substr(hash('sha256', strtolower($username)), 0, 16),
            'manuf' => 'Google',
            'model' => 'Pixel 5',
            'board' => 'redfin',
            'device' => 'redfin',
            'product' => 'redfin',
            'sdk' => '33',
        ];
        $response = $this->pending()->asForm()->post(
            self::PRIMARY_ORIGIN . self::LOGIN_PATH,
            $payload,
        );
        if (!$response->successful()) {
            throw new RuntimeException("Ошибка официальной авторизации HTTP {$response->status()}.");
        }
        $json = $response->json();
        if (!is_array($json)) {
            throw new RuntimeException('Официальный login вернул не JSON.');
        }

        $message = $this->firstScalar($json, [
            'message', 'msg', 'error.message', 'error.msg', 'error', 'data.message', 'data.msg',
        ]);
        $this->token = $this->firstScalar($json, [
            'data.token', 'data.user.token', 'token',
        ]);
        $this->userId = $this->firstScalar($json, [
            'data.user.user_id', 'data.user.userId', 'data.user.id',
            'data.user_id', 'data.cc', 'cc',
        ]);
        $this->accountType = $this->firstScalar($json, [
            'data.user.type', 'data.accountType', 'accountType',
        ]);
        $this->loginResponse = $json;

        if ($this->token === null || $this->userId === null) {
            // Отсутствие полей не доказывает неверный пароль: возможен иной формат ответа.
            // Выводим только числовой код, наличие полей и очищенное сообщение.
            $code = $this->firstScalar($json, ['code', 'error.code', 'data.code']);
            $code = $code !== null && preg_match('/^-?[0-9]{1,10}$/D', $code) === 1
                ? $code
                : 'не указан';
            // Расшифровка соответствует обработчику LoginFuction и строкам исходного APK.
            $fallbackMessage = match ($code) {
                '30001', '100001', '100011' => 'Неверный пароль',
                '100002' => 'Имя пользователя не существует',
                '40000' => 'Не может войти в домен',
                '40001' => 'Версия входа в систему указана неверно',
                '1023' => 'В запросе отсутствуют необходимые параметры',
                default => null,
            };
            $safeMessage = $this->redactLoginMessage($message ?? $fallbackMessage, $json, $payload);
            throw new RuntimeException(sprintf(
                'Не удалось получить login-сессию: HTTP %d; code=%s; token=%s; user_id=%s.%s',
                $response->status(),
                $code,
                $this->token === null ? 'отсутствует' : 'получен',
                $this->userId === null ? 'отсутствует' : 'получен',
                $safeMessage === '' ? '' : " Сообщение: {$safeMessage}",
            ));
        }
    }

    /**
     * Альтернативный вход APK для конкретного сканера.
     * Использует deviceUser.cc/token для SOAP; loginUser.token относится к другому сервису.
     */
    private function authenticateFallback(string $serial, string $timezone): void
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m-d H:i:s');
        $payload = [
            'username' => $this->requiredCredential('username'),
            'password' => md5(md5($this->requiredCredential('password')) . $date),
            'dateTime' => $date,
            'serialNo' => $serial,
        ];
        // BaseManager.g добавляет значения в путь по алфавитному порядку ключей.
        $pathValues = $payload;
        ksort($pathValues, SORT_STRING);
        $url = self::PRIMARY_ORIGIN . self::FALLBACK_LOGIN_PATH
            . '/' . implode('/', array_map('rawurlencode', array_values($pathValues)));
        try {
            $response = $this->pending()->asForm()->post($url, $payload);
        } catch (Throwable) {
            // URL содержит производное пароля: не включаем URL или исходное исключение в сообщение.
            throw new RuntimeException('Ошибка соединения с альтернативным login endpoint.');
        }
        if (!$response->successful()) {
            throw new RuntimeException("HTTP {$response->status()} от альтернативного login endpoint.");
        }
        $json = $response->json();
        if (!is_array($json)) {
            throw new RuntimeException('Альтернативный login вернул не JSON.');
        }
        $code = $this->firstScalar($json, ['code']);
        $token = $this->firstScalar($json, ['data.deviceUser.token']);
        $userId = $this->firstScalar($json, ['data.deviceUser.cc']);
        if (!in_array($code, ['0', '1'], true) || $token === null || $userId === null) {
            $safeCode = $code !== null && preg_match('/^-?[0-9]{1,10}$/D', $code) === 1 ? $code : 'не указан';
            throw new RuntimeException("Нет подтверждённой deviceUser-сессии; code={$safeCode}.");
        }
        $this->token = $token;
        $this->userId = $userId;
        $this->accountType = null;
        $this->loginResponse = $json;
        // Сессия SOAP должна соответствовать новому входу.
        $this->soapClients = [];
    }

    /**
     * Не выводит тело login-ответа, пароль, идентификатор устройства или токены.
     * Удаляет также секреты из вложенных полей, если сервер повторил их в сообщении.
     */
    private function redactLoginMessage(?string $message, array $json, array $payload): string
    {
        if ($message === null) {
            return '';
        }
        $secrets = [
            (string) $payload['login_key'],
            (string) $payload['password'],
            (string) $payload['DeviceId'],
        ];
        array_walk_recursive($json, static function (mixed $value, mixed $key) use (&$secrets): void {
            if (is_scalar($value) && preg_match(
                '/token|password|secret|cookie|authorization|user.?id|serial|email|phone/i',
                (string) $key,
            ) === 1) {
                $secrets[] = (string) $value;
            }
        });
        $secrets = array_values(array_filter($secrets, static fn (string $value): bool => $value !== ''));
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        $message = str_replace($secrets, '[скрыто]', $message);
        // Убираем управляющие символы терминала и ограничиваем длину текста.
        $message = preg_replace('/[\\x00-\\x1F\\x7F-\\x9F]/u', ' ', $message) ?? '';
        return mb_substr($message, 0, 400);
    }

    /**
     * Получает SN штатным getRegisteredProductsForPad. Если login JSON уже
     * содержит SN, объединяет оба источника без дублей.
     *
     * @return list<string>
     */
    private function registeredSerials(): array
    {
        $serials = $this->extractNamedScalars(
            $this->loginResponse,
            ['serialNo', 'serial_no', 'serial_number'],
        );
        $products = $this->soapCall('product', self::METHOD_REGISTERED_PRODUCTS, [
            'productType' => self::PRODUCT_TYPE,
            'requestType' => 1,
        ]);
        $serials = array_merge(
            $serials,
            $this->extractNamedScalars(
                $this->normalize($products),
                ['serialNo', 'serial_no', 'serial_number'],
            ),
        );
        $serials = array_values(array_unique(array_filter(array_map(
            static fn (string $serial): string => strtoupper(trim($serial)),
            $serials,
        ), static fn (string $serial): bool => preg_match(
            '/^[A-Z0-9._:-]{4,128}$/',
            $serial,
        ) === 1)));

        if ($serials === []) {
            throw new RuntimeException(
                'Официальный аккаунт не вернул зарегистрированных сканеров.',
            );
        }

        return $serials;
    }

    /**
     * SOAP 1.1 с authenticate. Подпись повторяет BaseManager:
     * MD5(склеенные значения параметров + token).
     *
     * @param array<string, scalar|null> $params
     */
    private function soapCall(string $service, string $method, array $params): mixed
    {
        $last = null;
        $errors = [];
        foreach ($this->soapEndpoints($service) as $endpoint) {
            try {
                $client = $this->soapClient($endpoint);
                $signSource = '';
                $arguments = [];
                foreach ($params as $name => $value) {
                    $signSource .= (string) $value;
                    $arguments[] = new SoapParam($value, (string) $name);
                }
                $client->__setSoapHeaders([new SoapHeader(
                    self::SOAP_AUTH_NAMESPACE,
                    'authenticate',
                    [
                        'cc' => new SoapVar($this->requiredSessionValue($this->userId, 'user_id'), XSD_STRING, null, null, 'cc', self::SOAP_AUTH_NAMESPACE),
                        'sign' => new SoapVar(md5($signSource . $this->requiredSessionValue($this->token, 'token')), XSD_STRING, null, null, 'sign', self::SOAP_AUTH_NAMESPACE),
                    ],
                    false,
                )]);
                $result = $client->__soapCall($method, $arguments, ['soapaction' => '']);
                $decoded = $this->normalize($result);
                if (is_array($decoded)) {
                    $code = $this->firstScalar($decoded, ['code', 'return.code']);
                    if ($code !== null && preg_match('/^-?[0-9]{1,10}$/D', $code) === 1 && !in_array($code, ['0', '1'], true)) {
                        throw new RuntimeException("Официальный SOAP вернул code={$code}.");
                    }
                }
                return $result;
            } catch (Throwable $exception) {
                $last = $exception;
                $errors[] = $endpoint . ": " . $exception->getMessage();
            }
        }

        throw new RuntimeException(
            "SOAP-метод {$method} не выполнился: "
            . implode(' | ', $errors)
            . ' Проверьте sync.soap_endpoints в config/mdiag-dwt.php; вход повторять не требуется.',
            previous: $last,
        );
    }

    /**
     * APK получает адреса по ключам конфигурации, а не выбирает их по имени SOAP-метода.
     * Пароль и token сюда не передаются. Ошибка bootstrap не отменяет уже успешный вход.
     */
    private function refreshServiceUrls(callable $report): void
    {
        $this->serviceUrls = [];
        try {
            $response = $this->pending()->get(self::PRIMARY_ORIGIN . '/', [
                'action' => 'config_service.urls', 'app_id' => self::APP_ID, 'ver' => self::PROTOCOL_VERSION,
            ]);
            $rows = $response->json('data.urls');
            if (!$response->successful() || !is_array($rows)) {
                throw new RuntimeException('HTTP ' . $response->status() . '; нет data.urls');
            }
            $this->parseServiceUrls($rows, $report);
            $report('  Конфигурация сервисов: получено SOAP-адресов ' . count($this->serviceUrls));
            if (!isset($this->serviceUrls['product'])) {
                $report('  В конфигурации не выбран сервис product: смотрите строки config ниже/выше; адрес списка сканеров пока не подтверждён.');
            }
        } catch (Throwable $exception) {
            // Не печатаем тело ответа, cookies и URL с секретами.
            $report('  Конфигурация сервисов недоступна; использую адреса APK/настройки.');
        }
    }

    /**
     * Принимает список key/value и словарь key => URL.
     * Выводит только известный ключ, origin/path и имена query-параметров.
     * Значения query (в том числе token/sign), userinfo и fragment не выводятся.
     */
    private function parseServiceUrls(array $rows, callable $report): void
    {
        $keys = [
            'product' => ['productservice.*', 'getRegisteredProductsForPad'],
            'diagnostic' => ['xdigpaddiagsoftservice.*', 'queryLatestDiagSofts'],
            'public' => ['xdigpadpublicsoftservice.*', 'queryLatestPublicSofts'],
        ];
        $seen = 0;
        $observedKeys = [];
        foreach ($rows as $index => $row) {
            $key = is_array($row) ? ($row['key'] ?? null) : (is_string($index) ? $index : null);
            $url = is_array($row) ? ($row['value'] ?? null) : $row;
            if (is_string($key) && preg_match('/^[a-zA-Z0-9_.\*-]{1,100}$/D', $key)) {
                $observedKeys[] = $key;
            }
            if (!is_string($key) || !is_string($url)) { continue; }
            foreach ($keys as $service => $aliases) {
                if (!in_array($key, $aliases, true)) { continue; }
                $seen++;
                $p = parse_url(trim($url));
                $safe = '[некорректный URL]';
                if (is_array($p) && isset($p['host'])) {
                    $safe = ($p['scheme'] ?? '') . '://' . $p['host']
                        . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '/');
                    if (isset($p['query'])) {
                        parse_str($p['query'], $query);
                        $safe .= '?' . implode('&', array_map(
                            static fn ($k) => $k === 'wsdl' ? 'wsdl' : rawurlencode((string) $k) . '=[скрыто]',
                            array_keys($query),
                        ));
                    }
                }
                // Управляющие символы исключены из строк терминала.
                $safe = preg_replace('/[\\x00-\\x1f\\x7f]/', '', $safe);
                if (!$this->trustedSoapUrl(trim($url))) {
                    $report('  config ' . $key . ': ' . $safe . ' — отклонён: схема/хост вне разрешённого списка.');
                    continue;
                }
                // Wildcard-ключ APK приоритетнее алиаса конкретного метода.
                if (!isset($this->serviceUrls[$service]) || $key === $aliases[0]) {
                    $this->serviceUrls[$service] = trim($url);
                }
                $report('  config ' . $key . ': ' . $safe . ' — принят.');
            }
        }
        if ($seen === 0) {
            $report('  config: ни одного ожидаемого ключа в data.urls; элементов: ' . count($rows)
                . '; формат: ' . (array_is_list($rows) ? 'список' : 'словарь') . '.');
            $report('  config: имена ключей (без значений): ' . implode(', ', array_slice(array_unique($observedKeys), 0, 40)));
        }
    }

    /** Не разрешаем конфигурационному ответу отправить cc/sign на посторонний хост. */
    private function trustedSoapUrl(string $url): bool
    {
        $p = parse_url($url);
        if ($p === false || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) { return false; }
        $host = strtolower((string) ($p['host'] ?? ''));
        return in_array($host, ['services.x-diag.info', 'config.x-diag.info'], true);
    }

    /** @return list<string> */
    private function soapEndpoints(string $service): array
    {
        // Явно заданные оператором адреса имеют приоритет над bootstrap и встроенными.
        // Это доверенная локальная настройка, а не параметры запроса планшета.
        $configured = (array) $this->config->get('mdiag-dwt.profiles.xdiag.sync.soap_endpoints.' . $service, []);
        if ($configured !== []) {
            foreach ($configured as $url) {
                $p = is_string($url) ? parse_url($url) : false;
                if ($p === false || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])
                    || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
                    throw new RuntimeException('Некорректный HTTPS адрес sync.soap_endpoints.' . $service);
                }
            }
            return array_values(array_unique($configured));
        }
        $path = match ($service) {
            'product' => self::PRODUCT_WSDL_PATH,
            'public' => self::PUBLIC_WSDL_PATH,
            default => self::DIAGNOSTIC_WSDL_PATH,
        };
        $urls = [];
        if (isset($this->serviceUrls[$service])) {
            $urls[] = $this->serviceUrls[$service];
        }
        // SoapClient(null) не читает WSDL даже если в location остался ?wsdl.
        // Сначала точный URL из APK/bootstrap, затем вариант без query.
        $urls[] = self::PRIMARY_ORIGIN . $path;
        $urls[] = self::PRIMARY_ORIGIN . explode('?', $path, 2)[0];
        $urls[] = self::PORT_8000_ORIGIN . $path;
        $urls[] = self::PORT_8000_ORIGIN . explode('?', $path, 2)[0];
        return array_values(array_unique($urls));
    }

    /** Создаёт SOAP-клиент с cookie login-сессии и Android User-Agent. */
    private function soapClient(string $endpoint): SoapClient
    {
        if (isset($this->soapClients[$endpoint])) {
            return $this->soapClients[$endpoint];
        }
        // APK не загружает WSDL: SOAP 1.1 отправляется прямо на endpoint без ?wsdl.
        $verifyTls = (bool) $this->config->get('mdiag-dwt.verify_tls', false);
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
                'allow_self_signed' => !$verifyTls,
            ],
            'http' => [
                'timeout' => (int) $this->config->get('mdiag-dwt.request_timeout', 900),
                'user_agent' => self::ANDROID_USER_AGENT,
            ],
        ]);
        $client = new SoapClient(null, [
            'location' => $endpoint,
            'uri' => self::SOAP_AUTH_NAMESPACE,
            'style' => SOAP_RPC,
            'use' => SOAP_ENCODED,
            'connection_timeout' => (int) $this->config->get(
                'mdiag-dwt.connect_timeout',
                15,
            ),
            'exceptions' => true,
            'keep_alive' => true,
            'soap_version' => SOAP_1_1,
            'stream_context' => $context,
            'trace' => true,
        ]);
        foreach ($this->cookies->toArray() as $cookie) {
            if (isset($cookie['Name'], $cookie['Value'])) {
                $client->__setCookie((string) $cookie['Name'], (string) $cookie['Value']);
            }
        }

        return $this->soapClients[$endpoint] = $client;
    }

    /** @return list<array<string, mixed>> */
    private function extractPackages(mixed $payload, string $kind, string $serial): array
    {
        $normalized = $this->normalize($payload);
        $packages = [];
        $visit = function (mixed $node) use (&$visit, &$packages, $kind, $serial): void {
            if (!is_array($node)) {
                return;
            }
            if (!array_is_list($node)) {
                $record = $this->packageRecord($node, $kind, $serial);
                if ($record !== null) {
                    $packages[] = $record;
                }
            }
            foreach ($node as $child) {
                $visit($child);
            }
        };
        $visit($normalized);

        return $packages;
    }

    /** @param array<string, mixed> $node @return array<string, mixed>|null */
    private function packageRecord(array $node, string $kind, string $serial): ?array
    {
        $code = $this->value($node, [
            'softPackageID', 'softPackageId', 'softPackageid', 'softpackageId',
        ]);
        $version = $this->value($node, [
            'versionNo', 'softVersion', 'maxVersionNo', 'vNum',
        ]);
        $detailId = $this->value($node, [
            'versionDetailId', 'pubVersionDetailId', 'maxVersionDetailId',
            'versionDetialId',
        ]);
        $url = $this->value($node, [
            'url', 'downloadUrl', 'downloadURL', 'incrementPath', 'cdnAllURL',
        ]);
        if ($code === null || $version === null
            || ($url === null && $detailId === null)) {
            return null;
        }

        $fileName = $this->value($node, ['fileName', 'incrementName']);
        if ($url !== null && $fileName !== null && str_ends_with($url, '/')) {
            $url .= ltrim($fileName, '/');
        }

        return [
            'kind' => $kind,
            'code' => strtoupper(trim($code)),
            'version' => trim($version),
            'name' => trim((string) ($this->value(
                $node,
                ['softName', 'softShowName', 'softDesc'],
            ) ?? strtoupper($code))),
            'download_url' => $url,
            'version_detail_id' => $detailId,
            'soft_id' => $this->value($node, ['softId', 'diagSoftId']),
            'serial_no' => $serial,
            'filename' => $fileName,
            'file_size' => (int) ($this->value(
                $node,
                ['fileSize', 'increSoftFileSize'],
            ) ?? 0),
            'md5' => $this->value($node, ['md5', 'md5Sign']),
            'sha256' => $this->value($node, ['sha256']),
            'notes' => $this->value(
                $node,
                ['softExplain', 'remark', 'mRemarks', 'softDesc', 'outline'],
            ),
            'published_at' => $this->value($node, [
                'softUpdateTime', 'versionCreateDate', 'updateDate',
                'serverCurrentTime',
            ]),
            'is_increment' => isset($node['incrementPath'])
                || isset($node['increVerisonDetailId']),
        ];
    }

    /** Приводит SOAP-объекты и вложенные JSON/XML строки к массивам PHP. */
    private function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            return $this->normalize(get_object_vars($value));
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }
        $trimmed = trim($value);
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $json = json_decode($trimmed, true);
            if (is_array($json)) {
                return $this->normalize($json);
            }
        }
        if ($trimmed[0] === '<' && function_exists('simplexml_load_string')) {
            $xml = @simplexml_load_string($trimmed);
            if ($xml !== false) {
                return $this->normalize(json_decode(
                    (string) json_encode($xml),
                    true,
                ));
            }
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $packages @param list<string> $modules */
    private function filterModules(array $packages, array $modules): array
    {
        if ($modules === []) {
            return $packages;
        }

        return array_values(array_filter(
            $packages,
            static fn (array $item): bool => in_array(
                strtoupper((string) $item['code']),
                $modules,
                true,
            ) || in_array(strtoupper((string) $item['name']), $modules, true),
        ));
    }

    /** Удаляет дубли, возвращённые для нескольких сканеров. */
    private function uniquePackages(array $packages): array
    {
        $unique = [];
        foreach ($packages as $package) {
            $key = implode('|', [
                (string) $package['kind'],
                (string) ($package['serial_no'] ?? ''),
                strtoupper((string) $package['code']),
                (string) $package['version'],
            ]);
            $unique[$key] = array_replace($unique[$key] ?? [], $package);
        }

        return array_values($unique);
    }

    /** Оставляет максимальную версию каждого модуля и типа. */
    private function latestPackages(array $packages): array
    {
        $latest = [];
        foreach ($packages as $package) {
            $key = (string) ($package['serial_no'] ?? '') . '|' . (string) $package['kind'] . '|'
                . strtoupper((string) $package['code']);
            if (!isset($latest[$key]) || strnatcasecmp(
                (string) $package['version'],
                (string) $latest[$key]['version'],
            ) > 0) {
                $latest[$key] = $package;
            }
        }

        return array_values($latest);
    }

    /** @param array<string, mixed> $node @param list<string> $names */
    private function value(array $node, array $names): ?string
    {
        foreach ($names as $name) {
            foreach ($node as $key => $candidate) {
                if (strcasecmp((string) $key, $name) !== 0
                    || (!is_scalar($candidate) && $candidate !== null)) {
                    continue;
                }
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** Рекурсивно находит значения указанных полей в JSON/SOAP. */
    private function extractNamedScalars(mixed $node, array $names): array
    {
        if (!is_array($node)) {
            return [];
        }
        $names = array_map('strtolower', $names);
        $values = [];
        foreach ($node as $key => $value) {
            if (is_scalar($value)
                && in_array(strtolower((string) $key), $names, true)) {
                $values[] = (string) $value;
            }
            if (is_array($value)) {
                $values = array_merge(
                    $values,
                    $this->extractNamedScalars($value, $names),
                );
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $json @param list<string> $paths */
    private function firstScalar(array $json, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = data_get($json, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /** HTTP-клиент с cookie jar и заголовками OkHttp этой версии APK. */
    private function pending(): PendingRequest
    {
        return Http::connectTimeout((int) $this->config->get(
            'mdiag-dwt.connect_timeout',
            15,
        ))
            ->timeout((int) $this->config->get('mdiag-dwt.request_timeout', 900))
            ->withOptions([
                'cookies' => $this->cookies,
                // Одинаковая настройка для авторизации и скачивания пакетов.
                'verify' => (bool) $this->config->get('mdiag-dwt.verify_tls', false),
                'allow_redirects' => false,
            ])
            ->withHeaders([
                'User-Agent' => self::ANDROID_USER_AGENT,
                'Accept' => '*/*',
                'Connection' => 'Keep-Alive',
            ]);
    }

    /** @return list<string> Основной URL и резервные port/IP адреса из APK. */
    private function downloadCandidates(array $package): array
    {
        $kind = (string) ($package['kind'] ?? 'diagnostic');
        $advertised = trim((string) ($package['download_url'] ?? ''));
        if ($advertised !== '' && !preg_match('~^https?://~i', $advertised)) {
            $advertised = self::PRIMARY_ORIGIN . '/' . ltrim($advertised, '/');
        }
        $fallbacks = $kind === 'public'
            ? [
                self::PRIMARY_ORIGIN . self::PUBLIC_DOWNLOAD_PATH,
                self::PORT_8000_ORIGIN . self::PUBLIC_DOWNLOAD_PATH,
                self::LEGACY_DOWNLOAD_ORIGIN . self::PUBLIC_DOWNLOAD_PATH,
                self::LEGACY_DOWNLOAD_PORT_8000_ORIGIN . self::PUBLIC_DOWNLOAD_PATH,
            ]
            : [
                self::PRIMARY_ORIGIN . self::DIAGNOSTIC_DOWNLOAD_PATH,
                self::PRIMARY_ORIGIN . self::DIAGNOSTIC_DOWNLOAD_WS_PATH,
                self::PORT_8000_ORIGIN . self::OPEN_DIAG_DOWNLOAD_PATH,
                self::LEGACY_DOWNLOAD_ORIGIN . self::PUBLIC_DOWNLOAD_PATH,
                self::LEGACY_DOWNLOAD_PORT_8000_ORIGIN . self::ADAS_DOWNLOAD_PATH,
            ];

        return array_values(array_unique(array_filter([$advertised, ...$fallbacks])));
    }

    /** @param array<string, string> $params */
    private function downloadWithRedirects(
        string $url,
        array $params,
        string $destination,
    ): void {
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            $this->assertAllowedUrl($url);
            $response = $this->pending()->send('GET', $url, [
                'query' => array_filter(
                    $params,
                    static fn (string $value): bool => $value !== '',
                ),
                'sink' => $destination,
            ]);
            if ($response->redirect()) {
                $location = trim((string) $response->header('Location'));
                if ($location === '') {
                    throw new RuntimeException('Redirect не содержит Location.');
                }
                $url = $this->absoluteUrl($url, $location);
                $params = [];
                continue;
            }
            if (!$response->successful()) {
                throw new RuntimeException("HTTP {$response->status()}.");
            }
            return;
        }

        throw new RuntimeException('Превышен лимит redirect при скачивании.');
    }

    /** Проверяет размер и хэши до помещения файла в локальный каталог. */
    private function assertDownloadedFile(string $path, array $package): void
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Официальный сервер вернул пустой файл.');
        }
        $expectedSize = (int) ($package['file_size'] ?? 0);
        if ($expectedSize > 0 && filesize($path) !== $expectedSize) {
            throw new RuntimeException(sprintf(
                'Размер не совпал: ожидалось %d, получено %d.',
                $expectedSize,
                filesize($path),
            ));
        }
        foreach (['md5', 'sha256'] as $algorithm) {
            $expected = strtolower(trim((string) ($package[$algorithm] ?? '')));
            if ($expected !== ''
                && !hash_equals($expected, hash_file($algorithm, $path))) {
                throw new RuntimeException(strtoupper($algorithm) . ' не совпал.');
            }
        }
        $head = file_get_contents($path, false, null, 0, 512);
        if (is_string($head) && preg_match(
            '/^\s*(?:\{|\[|<\?xml|<html)/i',
            $head,
        ) === 1) {
            throw new RuntimeException('Вместо пакета получен документ ошибки.');
        }
    }

    /** Защита от SSRF: разрешены только download-хосты, найденные в APK. */
    private function assertAllowedUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $known = in_array($host, ['services.x-diag.info', '79.174.70.103'], true)
            || str_ends_with($host, '.globdatacdn.com');
        if (!in_array($scheme, ['http', 'https'], true) || !$known) {
            throw new RuntimeException("Запрещённый download host: {$host}.");
        }
    }

    private function looksLikeDownloadEndpoint(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_ends_with($path, '.action')
            || str_ends_with($path, '.php')
            || !str_contains(basename($path), '.');
    }

    private function requiredCredential(string $key): string
    {
        $value = (string) $this->config->get(
            "mdiag-dwt.profiles.xdiag.sync.{$key}",
        );
        // LoginActivity удаляет только U+202C/U+202D из обоих полей ввода.
        // Не обрезаем пробелы и не хешируем пароль в обычном passport-login.
        $value = str_replace(["\u{202C}", "\u{202D}"], '', $value);
        if ($value === '') {
            throw new RuntimeException("Не заполнен X-DIAG {$key} в .env.");
        }

        return $value;
    }

    private function requiredSessionValue(?string $value, string $name): string
    {
        if ($value === null || trim($value) === '') {
            throw new RuntimeException("Login не вернул поле {$name}.");
        }

        return trim($value);
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        $host = (string) parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);

        return $scheme . '://' . $host
            . ($port === null ? '' : ":{$port}")
            . '/' . ltrim($location, '/');
    }
}
