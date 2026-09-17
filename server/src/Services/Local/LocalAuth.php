<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;
use DevWorkTech\MDiag\Models\LocalSession;
use DevWorkTech\MDiag\Models\LocalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Проверяет только локальную БД. Не содержит HTTP/SOAP клиента поставщика. */
final class LocalAuth
{
    public function login(string $provider, Request $request): array
    {
        $name = (string) $request->input('login_key', $request->input('username', ''));
        $password = (string) $request->input('password', '');
        [$name, $password] = array_map(static fn ($s) => str_replace(["\u{202C}", "\u{202D}"], '', $s), [$name, $password]);
        if ($name === '' || $password === '' || strlen($name) > 128 || strlen($password) > 4096) { throw new AccessDenied('invalid_request'); }
        // Ни логин, ни пароль не записываются в ключ/журнал в открытом виде.
        $key = 'mdiag:login:' . hash('sha256', $provider . '|' . $request->ip() . '|' . $name);
        if (RateLimiter::tooManyAttempts($key, (int) config('mdiag-dwt.auth.login_attempts', 10))) { throw new AccessDenied('rate_limited'); }
        RateLimiter::hit($key, (int) config('mdiag-dwt.auth.login_decay_seconds', 60));
        $user = LocalUser::where('provider', $provider)->where('login', $name)->first();
        if (!$user || !Hash::check($password, $user->password)) { throw new AccessDenied('invalid_credentials'); }
        $this->checkUser($user);
        RateLimiter::clear($key);
        return DB::transaction(function () use ($user): array {
            // Блокировка ограничивает число сессий и при одновременном входе.
            LocalUser::whereKey($user->id)->lockForUpdate()->first();
            LocalSession::where('user_id', $user->id)->where('expires_at', '<=', now())->delete();
            $max = max(1, (int) config('mdiag-dwt.auth.max_sessions', 5));
            $remove = LocalSession::where('user_id', $user->id)->latest('id')->skip($max - 1)->take(10000)->pluck('id');
            LocalSession::whereIn('id', $remove)->delete();
            $token = bin2hex(random_bytes(32));
            $session = LocalSession::create([
                'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
                'token_ciphertext' => $token,
                'expires_at' => now()->addHours(max(1, (int) config('mdiag-dwt.auth.session_hours', 12))),
            ]);
            return [$user, $session, $token];
        });
    }

    /** Токен или штатная SOAP-подпись проверяются перед любым чтением каталога/файла. */
    public function authenticate(string $provider, Request $request, array $parsed): array
    {
        $token = $request->bearerToken() ?: $request->input('token');
        $session = null;
        if (is_string($token) && strlen($token) === 64) {
            $session = LocalSession::where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        } else {
            $signature = new XDiagSignature();
            [$cc, $sign] = $signature->identity($request, $parsed);
            if (ctype_digit($cc) && preg_match('/^[a-f0-9]{32}$/iD', $sign)) {
                foreach (LocalSession::where('user_id', $cc)->where('expires_at', '>', now())->latest('id')->take(20)->get() as $candidate) {
                    if ($signature->verify($request, $parsed, $candidate->token_ciphertext, $sign)) {
                        $session = $candidate;
                        break;
                    }
                }
            }
        }
        if (!$session) { throw new AccessDenied('session_invalid'); }
        $user = LocalUser::whereKey($session->user_id)->where('provider', $provider)->first();
        if (!$user) { throw new AccessDenied('session_invalid'); }
        $this->checkUser($user);
        return [$user, $session];
    }

    /** Изменение флагов/срока в БД вступает в силу для уже выданных сессий. */
    public function checkUser(LocalUser $user): void
    {
        if (!$user->active) { throw new AccessDenied('user_disabled', $user->denied_message); }
        if ($user->expires_at && $user->expires_at->lessThanOrEqualTo(now())) { throw new AccessDenied('subscription_expired', $user->expired_message); }
    }
}
