<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;
use Illuminate\Support\Facades\Http;
use DevWorkTech\MDiag\Services\Local\DebugTrace;
use RuntimeException;

/** Публичные текстовые снимки: скачивает только CLI, HTTP читает только локальный диск. */
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
                $file=$this->root().'/'.$module->key().'/current.txt';
                if (!is_file($file)) { return response()->json(['code'=>900008,'msg'=>'Локальный снимок отсутствует. Выполните mdiag:sync xdiag --content='.$module->key()],503); }
                return response(file_get_contents($file))->header('Content-Type','text/plain; charset=UTF-8')->header('X-Content-Type-Options','nosniff');
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
            $texts=[];
            foreach ($sources as $url) {
                $p=is_string($url)?parse_url($url):false;
                if (!$p || !in_array($p['scheme']??'', ['https','http'],true)
                    || !in_array(strtolower($p['host']??''),['xdiagpro.com','www.xdiagpro.com','repairdata.xdiagpro.com'],true)
                    || isset($p['user']) || isset($p['pass'])) { throw new RuntimeException('Недопустимый источник web-контента.'); }
                $report($module->key().': '.DebugTrace::url($url));
                if ($listOnly) { continue; }
                DebugTrace::write('content_outgoing',['url'=>DebugTrace::url($url)]);
                $response=Http::connectTimeout(10)->timeout(40)->withOptions(['allow_redirects'=>false,'stream'=>true])->get($url);
                if (!$response->successful()) { throw new RuntimeException('Web-контент: HTTP '.$response->status()); }
                $stream=$response->toPsrResponse()->getBody(); $html='';
                try { while (!$stream->eof() && strlen($html)<=2097152) { $part=$stream->read(8192); if ($part==='') { break; } $html.=$part; } }
                finally { $stream->close(); }
                if (strlen($html)>2097152) { throw new RuntimeException('Web-страница превышает 2 MiB.'); }
                // Не запускаем скрипты/формы скачанной страницы и не сохраняем внешние assets.
                $html=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$html);
                $texts[]=html_entity_decode(strip_tags(str_replace(['</p>','<br>','</div>'],"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8');
            }
            if (!$listOnly) {
                $dir=$this->root().'/'.$module->key();
                if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) { throw new RuntimeException('Не удалось создать каталог снимков.'); }
                $body=implode("\n\n",$texts); $version=hash('sha256',$body);
                if (file_put_contents($dir.'/'.$version.'.txt',$body,LOCK_EX)===false) { throw new RuntimeException('Ошибка записи версии.'); }
                $tmp=tempnam($dir,'current-');
                if ($tmp===false || file_put_contents($tmp,$body)===false || !rename($tmp,$dir.'/current.txt')) { throw new RuntimeException('Ошибка публикации снимка.'); }
                $report($module->key().': сохранено '.$version);
            }
        }
    }
}
