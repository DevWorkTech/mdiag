<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Sync;

use Illuminate\Support\Facades\{Cache, Crypt};

/**
 * Сессии PHP-клиента поставщика, не локальных планшетов.
 * Используется default cache Laravel, поэтому cache:clear/optimize:clear удаляют
 * также cookies и token. Значение шифруется APP_KEY; пароль не сохраняется.
 */
final class OfficialSessionStore
{
    public function key(string $login): string
    {
        return 'mdiag:official:xdiag:'.hash_hmac('sha256',$login,(string)config('app.key'));
    }
    private function fingerprint(string $password): string
    {
        return hash_hmac('sha256',$password,(string)config('app.key'));
    }
    public function read(string $login, string $password): ?array
    {
        $raw=Cache::get($this->key($login));
        if (!is_string($raw)) { return null; }
        try { $data=json_decode(Crypt::decryptString($raw),true,512,JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { $this->forget($login); return null; }
        if (!is_array($data) || !hash_equals($this->fingerprint($password),(string)($data['credential']??''))
            || ($data['expires_at']??0)<=time() || empty($data['state']['token']) || empty($data['state']['userId'])) { return null; }
        return $data;
    }
    public function write(string $login, string $password, array $state, ?int $expiresAt=null): void
    {
        // TTL не продлевается при каждом использовании. Смена пароля исключает повторное использование.
        $expiresAt ??= time()+max(60,(int)config('mdiag-dwt.official_session_ttl',7200));
        if ($expiresAt<=time()) { $this->forget($login); return; }
        $data=['credential'=>$this->fingerprint($password),'expires_at'=>$expiresAt,'state'=>$state];
        Cache::put($this->key($login),Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR)),$expiresAt-time());
    }
    public function forget(string $login): void { Cache::forget($this->key($login)); }
}
