<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use DevWorkTech\MDiag\Services\Local\DebugTrace;

/** Охватывает все маршруты модуля, в том числе bootstrap и неизвестные команды до login. */
final class TraceRequests
{
    public function handle(Request $request, Closure $next)
    {
        // APK отправляет случайный ID до TLS-вызова. Один ID связывает оба журнала.
        $incoming=$request->header('X-MDiag-Request-Id');
        $id=is_string($incoming) && preg_match('/^[a-f0-9]{32}$/D',$incoming)
            ? $incoming : bin2hex(random_bytes(16));
        $request->attributes->set('mdiag_request_id',$id);
        $start = microtime(true);
        $action = $request->input('action');
        $action = is_string($action) && preg_match('/^[a-zA-Z0-9_.]{1,100}$/D', $action) ? $action : null;
        $ok = DebugTrace::write('incoming', ['id'=>$id,'method'=>$request->method(),
            'url'=>DebugTrace::url($request->url()),'action'=>$action,
            'bytes'=>strlen($request->getContent()),
            'content_type'=>$request->getContentTypeFormat(),
            'client'=>$request->header('X-MDiag-Client')==='7.00.014-mdiag2' ? '7.00.014-mdiag2' : 'unknown',
            // Только имена полей, без логина, пароля, SN, token и заголовков.
            'fields'=>array_values(array_filter(array_keys($request->all()),
                static fn ($key)=>is_string($key) && preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$key)))]);
        try {
            $response = $next($request);
            $code = null;
            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $value = $response->getData(true)['code'] ?? null;
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d{1,10}$/D', $value))) { $code=$value; }
            }
            DebugTrace::write('response', ['id'=>$id,'status'=>$response->getStatusCode(),
                'code'=>$code,'ms'=>round((microtime(true)-$start)*1000)]);
            $response->headers->set('X-MDiag-Request-Id', $id);
            $response->headers->set('X-MDiag-Revision', 'transport2');
            if (config('app.debug')) { $response->headers->set('X-MDiag-Trace', $ok ? 'written' : 'write-failed'); }
            return $response;
        } catch (\Throwable $e) {
            DebugTrace::write('exception',['id'=>$id,'class'=>get_class($e)]);
            throw $e;
        }
    }
}
