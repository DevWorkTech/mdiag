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
        $id = bin2hex(random_bytes(8));
        $start = microtime(true);
        $action = $request->input('action');
        $action = is_string($action) && preg_match('/^[a-zA-Z0-9_.]{1,100}$/D', $action) ? $action : null;
        $ok = DebugTrace::write('incoming', ['id'=>$id,'method'=>$request->method(),
            'url'=>DebugTrace::url($request->url()),'action'=>$action,
            'bytes'=>strlen($request->getContent())]);
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
            if (config('app.debug')) { $response->headers->set('X-MDiag-Trace', $ok ? 'written' : 'write-failed'); }
            return $response;
        } catch (\Throwable $e) {
            DebugTrace::write('exception',['id'=>$id,'class'=>get_class($e)]);
            throw $e;
        }
    }
}
