<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;
use DevWorkTech\MDiag\Services\Local\DebugTrace;
use RuntimeException;

/** Публичные HTML-снимки: скачивает только CLI, HTTP читает только локальный диск. */
final class WebContent
{
    public function modules(): array
    {
        return [new RepairDataModule(), new FaqModule(), new CustomersModule(), new WorkshopModule()];
    }
    private function settings(ContentModule $module, string $key): array
    {
        return (array) config('mdiag-dwt.web_content.modules.'.$module->key().'.'.$key, $module->$key());
    }
    private function root(): string { return storage_path('app/private/mdiag/web'); }

    public function response(string $path, string $method): ?\Symfony\Component\HttpFoundation\Response
    {
        foreach ($this->modules() as $module) {
            foreach ($this->settings($module, 'paths') as $prefix) {
                if ($path !== $prefix && !str_starts_with($path, rtrim($prefix,'/').'/')) { continue; }
                if (!in_array($method, ['GET','HEAD'], true) || in_array($module->key(), ['customers','workshop'], true)) {
                    return response()->json(['code'=>900008,'msg'=>$module->title().': локальный адаптер ещё не реализован.'],501);
                }
                $dir=$this->root().'/'.$module->key();
                $file=$dir.'/current.json';
                if (!is_file($file)) { return response()->json(['code'=>900008,'msg'=>'Локальный HTML-снимок отсутствует. Выполните mdiag:sync xdiag --content='.$module->key()],503); }
                $manifest=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
                $sourcePath='/'.preg_replace('~^[^/]+/?~','',$path);
                $query=request()->getQueryString();
                $key=$sourcePath.($query ? '?'.$query : '');
                $entry=$manifest['files'][$key] ?? null;
                if (!$entry || !preg_match('/^[a-f0-9]{64}$/D',$entry['file'] ?? '') || !is_file($dir.'/'.$entry['file'])) {
                    return response()->json(['code'=>900008,'msg'=>'Ресурс не сохранён локально или требует отдельного API-адаптера.'],404);
                }
                // CSP блокирует внешний трафик даже у динамических скриптов снимка.
                return response(file_get_contents($dir.'/'.$entry['file']))
                    ->header('Content-Type',$entry['type'].(str_starts_with($entry['type'],'text/') ? '; charset=UTF-8' : ''))
                    ->header('X-Content-Type-Options','nosniff')
                    ->header('Content-Security-Policy',"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; form-action 'self'; object-src 'none'; base-uri 'none'; frame-src 'none'");
            }
        }
        return null;
    }

    public function sync(array $selected, bool $listOnly, callable $report): void
    {
        $modules=$this->modules();
        $valid=array_map(fn ($m)=>$m->key(),$modules);
        foreach ($selected as $key) { if (!in_array($key, [...$valid,'all'], true)) { throw new RuntimeException('Неизвестный web-модуль: '.$key); } }
        foreach ($modules as $module) {
            if (!in_array('all',$selected,true) && !in_array($module->key(),$selected,true)) { continue; }
            $sources=$this->settings($module,'sources');
            if (in_array($module->key(),['customers','workshop'],true) || $sources===[]) {
                $report($module->key().': заготовка; заполните адаптер/источники в src/Modules/Web.'); continue;
            }
            foreach ($sources as $url) {
                $p=is_string($url)?parse_url($url):false;
                if (!$p || !in_array($p['scheme']??'', ['https','http'],true)
                    || !in_array(strtolower($p['host']??''),['xdiagpro.com','www.xdiagpro.com','repairdata.xdiagpro.com'],true)
                    || isset($p['user']) || isset($p['pass'])) { throw new RuntimeException('Недопустимый источник web-контента.'); }
                $report($module->key().': '.DebugTrace::url($url));
            }
            if (!$listOnly) {
                (new WebMirror())->sync($sources,$this->root().'/'.$module->key(),
                    '/xdiag/'.explode('/',$this->settings($module,'paths')[0])[0],$report);
            }
        }
    }
}
