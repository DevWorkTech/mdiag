<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Http\Controllers;

use DevWorkTech\MDiag\Services\ProfileRegistry;
use DevWorkTech\MDiag\Services\Local\{AccessDenied, ErrorCatalog, LocalAuth, LocalCatalog, Protocol, PassportApi, BootstrapApi, DebugTrace};
use Illuminate\Http\Request;
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
            if ($path === 'health' && in_array($request->method(), ['GET','HEAD'], true)) {
                return response()->json(['code'=>0,'service'=>'MDiag','profile'=>$provider,'protocol'=>'7.00.014','revision'=>'transport2'])->header('Cache-Control','no-store');
            }
            if ($provider === 'xdiag') {
                $web = app(\DevWorkTech\MDiag\Modules\Web\WebContent::class)->response(ltrim((string)$path, '/'), $request->method());
                if ($web !== null) { return $web; }
            }
            $parsed = $protocol->parse($request);
            \DevWorkTech\MDiag\Services\Local\DebugTrace::write('dispatch', ['soap_method'=>$parsed['method']]);
            $action = trim((string) $request->input('action', ''));
            $passport = app(PassportApi::class);
            if ($action === 'config_service.urls') { return app(BootstrapApi::class)->configuration($provider, $profiles); }
            if (in_array($action, ['passport_service.register', 'passport_service.reg_user'], true)) {
                return $passport->register($request, $provider);
            }
            if ($action === 'passport_service.login') {
                return $passport->login($provider,$request,$parsed,$auth,$protocol);
            }
            [$user, $session] = $auth->authenticate($provider, $request, $parsed);
            $accountResponse=$passport->account($action,$request,$user,$session,$parsed,$protocol);
            if ($accountResponse!==null) { return $accountResponse; }
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
            DebugTrace::write('unsupported_command', ['action'=>$action,'soap_method'=>$method,'url'=>DebugTrace::url($request->url())]);
            throw new AccessDenied('unsupported');
        } catch (AccessDenied $e) {
            if ($request->input('action') === 'passport_service.login') {
                app(PassportApi::class)->recordLogin($e->reason);
            }
            $response = $errors->respond($provider, $parsed, $e, $protocol);
            if (in_array(ltrim((string) $path, '/'), LocalCatalog::DOWNLOAD_PATHS, true)) {
                $response->setStatusCode($e->reason === 'not_found' ? 404 : ($e->reason === 'session_invalid' ? 401 : 403));
            }
            return $response;
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $errors->respond($provider, $parsed, new AccessDenied('unsupported'), $protocol);
        } catch (\Throwable $exception) {
            \DevWorkTech\MDiag\Services\Local\DebugTrace::write('handler_error', ['class'=>get_class($exception),'file'=>basename($exception->getFile()),'line'=>$exception->getLine()]);
            if ($request->input('action') === 'passport_service.login') {
                app(PassportApi::class)->recordLogin('internal_error');
            }
            // Не вызываем report(): глобальный трекер Laravel может отправить запрос наружу.
            // Не включаем текст SQL/пароль/токен в ответ планшету.
            return $protocol->reply($parsed, ['code' => 900012, 'msg' => 'Ошибка локального сервера.',
                'message' => 'Ошибка локального сервера.', 'data' => null], 500);
        }
    }

}
