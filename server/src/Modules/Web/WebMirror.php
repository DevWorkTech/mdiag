<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Modules\Web;

use GuzzleHttp\Psr7\{Uri, UriResolver};
use Illuminate\Support\Facades\Http;
use DevWorkTech\MDiag\Services\Local\DebugTrace;
use RuntimeException;

/**
 * Снимок публичного сайта. Только CLI обращается наружу; HTTP отдаёт готовые файлы.
 * Это зеркало HTML/CSS/JS/изображений, а не эмулятор неизвестной серверной логики.
 * Каждый redirect проверяется до запроса. Cookie/пароли планшетов сюда не передаются.
 */
final class WebMirror
{
    private string $host;
    private string $prefix;
    private array $queue = [];
    private array $queued = [];

    private function checked(string $url): string
    {
        $u = new Uri($url);
        if (!in_array($u->getScheme(), ['http','https'], true) || strtolower($u->getHost()) !== $this->host
            || $u->getUserInfo() !== '' || !in_array($u->getPort(), [null,80,443], true)) {
            throw new RuntimeException('Web: источник/redirect вне разрешённого origin: '.DebugTrace::url($url));
        }
        return (string)$u->withFragment('');
    }

    private function resolve(string $base, string $relative): string
    {
        return (string)UriResolver::resolve(new Uri($base), new Uri(html_entity_decode($relative, ENT_QUOTES | ENT_HTML5)));
    }

    /** Ключ включает query: разные версии/страницы не перезаписывают друг друга. */
    public static function key(string $url): string
    {
        $u = new Uri($url);
        return ($u->getPath() ?: '/').($u->getQuery() !== '' ? '?'.$u->getQuery() : '');
    }

    private function local(string $url): string { return $this->prefix.self::key($url); }

    private function enqueue(string $url, int $depth): void
    {
        $url = $this->checked($url);
        if (isset($this->queued[$url])) { return; }
        if (count($this->queued) >= 128) { throw new RuntimeException('Web: более 128 ресурсов. Снимок не опубликован.'); }
        $this->queued[$url] = true;
        $this->queue[] = [$url,$depth];
    }

    /** Перенаправления выполняем вручную: запрет приватных/сторонних адресов проверяется на каждом шаге. */
    private function fetch(string $url, callable $report): array
    {
        $seen = [];
        for ($hop = 0; $hop <= 5; $hop++) {
            $url = $this->checked($url);
            if (isset($seen[$url])) { throw new RuntimeException('Web: цикл перенаправлений.'); }
            $seen[$url] = true;
            DebugTrace::write('content_outgoing', ['url'=>DebugTrace::url($url)]);
            $r = Http::connectTimeout(10)->timeout(40)->withOptions(['allow_redirects'=>false,'stream'=>true,'verify'=>(bool)config('mdiag-dwt.verify_tls',false)])->get($url);
            $stream = $r->toPsrResponse()->getBody();
            if (in_array($r->status(), [301,302,303,307,308], true)) {
                $stream->close();
                $location = $r->header('Location');
                if (!$location || $hop === 5) { throw new RuntimeException('Web: отсутствует Location или превышен лимит redirect.'); }
                $next = $this->checked($this->resolve($url, $location));
                $report('Web: HTTP '.$r->status().' → '.DebugTrace::url($next));
                $url = $next;
                continue;
            }
            if (!$r->successful()) { $stream->close(); throw new RuntimeException('Web-контент: HTTP '.$r->status().' '.DebugTrace::url($url)); }
            $body = '';
            try {
                while (!$stream->eof()) {
                    $part = $stream->read(8192);
                    if ($part === '') { break; }
                    $body .= $part;
                    if (strlen($body) > 8388608) { throw new RuntimeException('Web: ресурс превышает 8 MiB.'); }
                }
            } finally { $stream->close(); }
            $type = strtolower(trim(explode(';', $r->header('Content-Type') ?: 'application/octet-stream')[0]));
            return [$url,$body,$type];
        }
        throw new RuntimeException('Web: redirect не завершён.');
    }

    private function reference(string $value, string $base, int $depth): string
    {
        if ($value === '' || str_starts_with($value, '#') || preg_match('~^(data:|mailto:|tel:)~i', $value)) { return $value; }
        try {
            $url = $this->checked($this->resolve($base, $value));
            if ($depth <= 3) { $this->enqueue($url, $depth); }
            return $this->local($url);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '128 ресурсов')) { throw $e; }
            // Сторонний ресурс не скачиваем; CSP не разрешает браузеру обойти локальное зеркало.
            return 'about:blank';
        } catch (\InvalidArgumentException $e) { return 'about:blank'; }
    }

    private function css(string $text, string $base, int $depth): string
    {
        return preg_replace_callback('~url\(\s*([\'"]?)(.*?)\1\s*\)~is',
            fn ($m) => 'url("'.str_replace('"','%22',$this->reference($m[2],$base,$depth+1)).'")', $text);
    }

    private function html(string $text, string $base, int $depth): string
    {
        // Не заменяем хорошую локальную базу парковочной страницей, даже если upstream вернул 200.
        if (preg_match('~domain may be for sale|assets\.abovedomains\.com|sedoparking|buy this domain~i', $text)) {
            throw new RuntimeException('Web: источник возвращает парковочную страницу домена вместо ремонтной базы. Старый снимок сохранён.');
        }
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="UTF-8">'.$text, LIBXML_NONET); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $bases = $doc->getElementsByTagName('base');
        if ($bases->length) { $base = $this->checked($this->resolve($base, $bases->item(0)->getAttribute('href'))); }
        while ($bases->length) { $bases->item(0)->parentNode->removeChild($bases->item(0)); }
        foreach ($doc->getElementsByTagName('*') as $node) {
            foreach (['src','href','poster'] as $attr) {
                if ($node->hasAttribute($attr)) { $node->setAttribute($attr, $this->reference($node->getAttribute($attr),$base,$depth+1)); }
            }
            // Динамические формы требуют отдельного локального API; не отправляем данные upstream.
            if ($node->hasAttribute('action')) { $node->setAttribute('action', $this->local($base)); }
            $node->removeAttribute('srcset');
            $node->removeAttribute('integrity'); // После локального переписывания CSS старый SRI уже неверен.
            if ($node->hasAttribute('style')) { $node->setAttribute('style',$this->css($node->getAttribute('style'),$base,$depth)); }
            if ($node->tagName === 'style') { $node->nodeValue = $this->css($node->textContent,$base,$depth); }
            if ($node->tagName === 'meta' && strtolower($node->getAttribute('http-equiv')) === 'refresh') { $node->setAttribute('content',''); }
        }
        return $doc->saveHTML();
    }

    /** Публикуем manifest атомарно только после всех загрузок. Ошибка оставляет предыдущий снимок рабочим. */
    public function sync(array $sources, string $directory, string $prefix, callable $report): void
    {
        $this->prefix = $prefix;
        $this->queue = $this->queued = [];
        $this->host = strtolower((string)parse_url($sources[0], PHP_URL_HOST));
        if (!in_array($this->host,['xdiagpro.com','www.xdiagpro.com','repairdata.xdiagpro.com'],true)) { throw new RuntimeException('Недопустимый источник web-контента.'); }
        foreach ($sources as $url) { $this->enqueue($url,0); }
        if (!is_dir($directory) && !mkdir($directory,0770,true) && !is_dir($directory)) { throw new RuntimeException('Не удалось создать каталог web.'); }
        $manifest = ['files'=>[], 'created_at'=>gmdate('c')]; $total = 0;
        for ($index = 0; $index < count($this->queue); $index++) {
            [$original,$depth] = $this->queue[$index];
            [$url,$body,$type] = $this->fetch($original,$report);
            $total += strlen($body);
            if ($total > 67108864) { throw new RuntimeException('Web: снимок превышает 64 MiB.'); }
            if ($type === 'text/html' || preg_match('~^\s*<!doctype html|^\s*<html~i',$body)) { $type='text/html'; $body=$this->html($body,$url,$depth); }
            elseif ($type === 'text/css') { $body=$this->css($body,$url,$depth); }
            $hash = hash('sha256',$body);
            if (file_put_contents($directory.'/'.$hash,$body,LOCK_EX) === false) { throw new RuntimeException('Ошибка записи web-ресурса.'); }
            $entry = ['file'=>$hash,'type'=>$type];
            $manifest['files'][self::key($original)] = $entry;
            $manifest['files'][self::key($url)] = $entry;
            // Приложение открывает стартовую страницу как с source=app, так и без параметра.
            if (in_array($original,$sources,true)) { $manifest['files'][(new Uri($original))->getPath() ?: '/'] = $entry; }
        }
        $json = json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $version = hash('sha256',$json);
        if (file_put_contents($directory.'/'.$version.'.json',$json,LOCK_EX) === false) { throw new RuntimeException('Ошибка записи manifest.'); }
        $tmp = tempnam($directory,'manifest-');
        if ($tmp === false || file_put_contents($tmp,$json) === false || !rename($tmp,$directory.'/current.json')) { throw new RuntimeException('Ошибка публикации manifest.'); }
        $report('Web: сохранено '.count($manifest['files']).' адресов, версия '.$version);
    }
}
