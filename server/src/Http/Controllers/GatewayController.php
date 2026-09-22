<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Http\Controllers;

use DevWorkTech\MDiag\Models\LocalUser;
use DevWorkTech\MDiag\Services\ProfileRegistry;
use DevWorkTech\MDiag\Services\Local\{AccessDenied, ErrorCatalog, LocalAuth, LocalCatalog, Protocol};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, RateLimiter};
use Symfony\Component\HttpFoundation\Response;

/**
 * Строго локальная граница HTTP. Здесь отсутствуют UpstreamProxy, HTTP-клиент,
 * SOAP-клиент поставщика, очередь синхронизации и выдача внешних снимков.
 * Неизвестные action/path/method завершаются локальной ошибкой.
 */
final class GatewayController
{
    public function handle(Request $request, ProfileRegistry $profiles, LocalAuth $auth,
        LocalCatalog $catalog, Protocol $protocol, ErrorCatalog $errors, string $profile, ?string $path = null): Response
    {
        $parsed = ['method' => null, 'params' => [], 'auth' => []];
        $provider = strtolower($profile);
        try {
            [$provider] = $profiles->byPrefix($provider);
            $parsed = $protocol->parse($request);
            $action = (string) $request->input('action', '');
            if ($action === 'config_service.urls') { return $this->configuration($provider, $profiles); }
            if (in_array($action, ['passport_service.register', 'passport_service.reg_user'], true)) {
                return $this->register($request, $provider);
            }
            if ($action === 'passport_service.login') {
                if (!$request->isMethod('POST')) { throw new AccessDenied('invalid_request'); }
                [$user, $session, $token] = $auth->login($provider, $request);
                $this->recordLogin('success');
                return $protocol->reply($parsed, ['code' => 0, 'msg' => 'success', 'data' => [
                    'token' => $token, 'user' => $this->userData($user),
                    'xmpp' => ['ip' => parse_url((string) config('mdiag-dwt.base_url'), PHP_URL_HOST), 'domain' => parse_url((string) config('mdiag-dwt.base_url'), PHP_URL_HOST), 'port' => '5222'],
                    'notification' => $user->notification_text,
                ]]);
            }
            [$user, $session] = $auth->authenticate($provider, $request, $parsed);
            if ($action === 'passport_service.logout') {
                $session->delete();
                return $protocol->reply($parsed, ['code' => 0, 'msg' => 'success']);
            }
            if ($action === 'userinfo.get_base_info') {
                return $protocol->reply($parsed, ['code' => 0, 'msg' => 'success', 'data' => $this->userData($user)]);
            }
            $method = $parsed['method'];
            if ($method === 'getRegisteredProductsForPad') {
                return $protocol->reply($parsed, ['code' => 0, 'message' => 'success', 'productDTOs' => $catalog->products($user)]);
            }
            $params = $parsed['params'];
            $serial = is_scalar($params['serialNo'] ?? null) ? (string) $params['serialNo'] : '';
            // Только штатные пути загрузки, без выдачи файлов по произвольному пути.
            $downloads = LocalCatalog::DOWNLOAD_PATHS;
            if (in_array(ltrim((string) $path, '/'), $downloads, true)) {
                return $catalog->download($user, $catalog->scanner($user, $serial), (string) ($params['versionDetailId'] ?? ''));
            }
            $methods = [
                'queryLatestDiagSofts' => ['diagnostic', true],
                'queryLatestDiagSoftsIncrCdn' => ['diagnostic', true],
                'queryLatestDiagSoftsIncrCdnAllFunc' => ['diagnostic', true],
                'queryLatestDiagSoftsBaseAllFunc' => ['diagnostic', true],
                'queryHistoryDiagSofts' => ['diagnostic', false],
                'queryLatestPublicSofts' => ['public', true],
            ];
            if (isset($methods[$method ?? ''])) {
                [$kind, $latest] = $methods[$method];
                $scanner = $catalog->scanner($user, $serial);
                $rows = $catalog->versions($user, $scanner, $kind, $latest, $params, $session->token_ciphertext);
                // Новая оболочка читает отдельное поле xdigPadSoftIncrList.
                // Отдаём полные локальные архивы: delta-файлы не синтезируем.
                $incr = in_array($method, ['queryLatestDiagSoftsIncrCdn', 'queryLatestDiagSoftsIncrCdnAllFunc'], true);
                return $protocol->reply($parsed, ['code' => 0, 'message' => 'success',
                    'isCDNWork' => '0', ($incr ? 'xdigPadSoftIncrList' : 'xdigPadSoftList') => $rows]);
            }
            throw new AccessDenied('unsupported');
        } catch (AccessDenied $e) {
            if ($request->input('action') === 'passport_service.login') {
                $this->recordLogin($e->reason);
            }
            $response = $errors->respond($provider, $parsed, $e, $protocol);
            if (in_array(ltrim((string) $path, '/'), LocalCatalog::DOWNLOAD_PATHS, true)) {
                $response->setStatusCode($e->reason === 'not_found' ? 404 : ($e->reason === 'session_invalid' ? 401 : 403));
            }
            return $response;
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $errors->respond($provider, $parsed, new AccessDenied('unsupported'), $protocol);
        } catch (\Throwable) {
            if ($request->input('action') === 'passport_service.login') {
                $this->recordLogin('internal_error');
            }
            // Не вызываем report(): глобальный трекер Laravel может отправить запрос наружу.
            // Не включаем текст SQL/пароль/токен в ответ планшету.
            return $protocol->reply($parsed, ['code' => 900012, 'msg' => 'Ошибка локального сервера.',
                'message' => 'Ошибка локального сервера.', 'data' => null], 500);
        }
    }

    /** Только время и причина: без логина, пароля, SN, токена и внешних логгеров. */
    private function recordLogin(string $result): void
    {
        if (!(bool) config('mdiag-dwt.auth.diagnostic_log', false)) { return; }
        $line = gmdate('c') . ' login ' . preg_replace('/[^a-z_]/', '', $result) . PHP_EOL;
        // Ошибка записи диагностического файла не должна ломать вход.
        @file_put_contents(storage_path('logs/mdiag-auth.log'), $line, FILE_APPEND | LOCK_EX);
    }

    private function userData(LocalUser $user): array
    {
        return ['id' => $user->id, 'user_id' => (string) $user->id, 'user_name' => $user->login,
            'nick_name' => $user->login, 'type' => 2, 'valid' => true,
            'expires_at' => $user->expires_at?->toIso8601String(),
            'notification' => $user->notification_text];
    }

    /** Все bootstrap-адреса формируются от настроенного домена, без внешних origin. */
    private function configuration(string $provider, ProfileRegistry $profiles): Response
    {
        if ($provider !== 'xdiag') { throw new AccessDenied('unsupported'); }
        $paths = json_decode(file_get_contents(__DIR__ . '/../../../resources/xdiag-routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $urls = [];
        foreach ($paths as $key => $path) {
            $urls[] = ['key' => $key, 'value' => $profiles->localBase($provider) . '/' . ltrim($path, '/')];
        }
        return response()->json(['code' => 0, 'msg' => 'success', 'data' => ['urls' => $urls, 'version' => '22', 'area' => '1']]);
    }

    /** Саморегистрация не выдаёт доступ к сканерам или файлам до одобрения оператора. */
    private function register(Request $request, string $provider): Response
    {
        if (!config('mdiag-dwt.auth.registration_enabled', false)) { throw new AccessDenied('registration_disabled'); }
        $key = 'mdiag:register:' . hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) { throw new AccessDenied('rate_limited'); }
        RateLimiter::hit($key, 3600);
        $name = $request->input('login_key', $request->input('username'));
        $password = $request->input('password');
        if (!$request->isMethod('POST') || !is_string($name) || !preg_match('/^[A-Za-z0-9_.@-]{3,128}$/D', $name)
            || !is_string($password) || strlen($password) < 8 || strlen($password) > 4096) { throw new AccessDenied('invalid_request'); }
        if (LocalUser::where('provider', $provider)->where('login', $name)->exists()) { throw new AccessDenied('login_taken'); }
        LocalUser::create(['provider' => $provider, 'login' => $name, 'password' => Hash::make($password), 'allowed_modules' => []]);
        return response()->json(['code' => 0, 'msg' => 'Учётная запись создана. Ожидайте разрешения администратора.']);
    }
}
