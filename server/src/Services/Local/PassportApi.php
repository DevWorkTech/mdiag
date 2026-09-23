<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Services\Local;
use DevWorkTech\MDiag\Models\{LocalUser, LocalSession};
use DevWorkTech\MDiag\Services\ProfileRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Hash, RateLimiter};
use Symfony\Component\HttpFoundation\Response;

/** Совместимый HTTP-контракт локальных учётных записей, без внешнего клиента. */
final class PassportApi
{
    /** Точный DTO LoginResponse -> LoginData -> User, который читает APK 7.00.014. */
    public function login(string $provider, Request $request, array $parsed, LocalAuth $auth, Protocol $protocol): Response
    {
        if (!$request->isMethod('POST')) { throw new AccessDenied('invalid_request'); }
        [$user, $session, $token] = $auth->login($provider, $request);
        $this->recordLogin('success');
        return $protocol->reply($parsed, ['code'=>0, 'code_ver'=>2, 'msg'=>'success', 'message'=>'success',
            'data'=>['token'=>$token, 'user'=>$this->userData($user),
                'xmpp'=>['ip'=>parse_url((string)config('mdiag-dwt.base_url'),PHP_URL_HOST),
                    'domain'=>parse_url((string)config('mdiag-dwt.base_url'),PHP_URL_HOST),'port'=>'5222'],
                'notification'=>$user->notification_text]]);
    }

    /** Профиль/мастерская и пароль принадлежат только локальному пользователю. */
    public function account(string $action, Request $request, LocalUser $user, LocalSession $session, array $parsed, Protocol $protocol): ?Response
    {
        $ok=['code'=>0,'code_ver'=>2,'msg'=>'success','message'=>'success'];
        if ($action==='passport_service.logout') {
            $session->delete();
            return $protocol->reply($parsed,$ok);
        }
        if (in_array($action,['userinfo.get_base_info','userinfo.get_base_info_car_logo'],true)) {
            // target_id не даёт читать чужие локальные учётные записи.
            $target=$request->input('target_id');
            if ($target!==null && (string)$target!=='' && (string)$target!==(string)$user->id) {
                throw new AccessDenied('user_disabled');
            }
            return $protocol->reply($parsed,$ok+['data'=>$this->userData($user)]);
        }
        if (in_array($action,['userinfo.set_base','userinfo.set_area','userinfo.set_ext'],true)) {
            if (!$request->isMethod('POST')) { throw new AccessDenied('invalid_request'); }
            // Поля соответствуют UserBaseInfo DTO e0; права/SN/срок не принимаем из тела.
            $fields=['nick_name','country','province','city','address','company','company_name',
                'company_address','company_fax','contact','email','mobile','qq','weixin','signature','sex'];
            $data=$user->profile_data ?? [];
            foreach ($fields as $key) {
                if (!$request->exists($key)) { continue; }
                $value=$request->input($key);
                if (!is_scalar($value) || strlen((string)$value)>2000) { throw new AccessDenied('invalid_request'); }
                $data[$key]=$key==='sex' ? (int)$value : (string)$value;
            }
            $user->update(['profile_data'=>$data]);
            return $protocol->reply($parsed,$ok);
        }
        if ($action==='userinfo.set_password') {
            // Реальные имена полей ChangePassword из APK: pw и chpw.
            $old=$request->input('pw');$new=$request->input('chpw');
            if (!$request->isMethod('POST') || !is_string($old) || !is_string($new)
                || strlen($new)<8 || strlen($new)>4096) { throw new AccessDenied('invalid_request'); }
            if (!Hash::check($old,$user->password)) { throw new AccessDenied('invalid_credentials'); }
            \Illuminate\Support\Facades\DB::transaction(function () use ($user,$new): void {
                $user->update(['password'=>Hash::make($new)]);
                LocalSession::where('user_id',$user->id)->delete();
            });
            return $protocol->reply($parsed,$ok);
        }
        return null;
    }

    /** Только время и причина: без логина, пароля, SN, токена и внешних логгеров. */
    public function recordLogin(string $result): void
    {
        if (!config('app.debug', false) && !(bool) config('mdiag-dwt.auth.diagnostic_log', false)) { return; }
        $line = gmdate('c') . ' login ' . preg_replace('/[^a-z_]/', '', $result) . PHP_EOL;
        // Ошибка записи диагностического файла не должна ломать вход.
        @file_put_contents(storage_path('logs/mdiag-auth.log'), $line, FILE_APPEND | LOCK_EX);
    }

    public function userData(LocalUser $user): array
    {
        $profile=$user->profile_data ?? [];
        return array_merge(['address'=>'','company_name'=>'','company_address'=>'','company_fax'=>'',
            'company'=>'','contact'=>'','country'=>'','province'=>'','city'=>'','email'=>'','mobile'=>'',
            'signature'=>'','qq'=>'','weixin'=>'','sex'=>0,'is_bind_email'=>0,'is_bind_mobile'=>0], $profile, [
            'id'=>$user->id,'user_id'=>(string)$user->id,'user_name'=>$user->login,
            'nick_name'=>$profile['nick_name'] ?? $user->login,'type'=>2,'valid'=>true,
            // Tools.a(long, format) передаёт endTime в java.util.Date: миллисекунды.
            'endTime'=>($user->expires_at?->getTimestamp() ?? 0) * 1000,
            'expires_at'=>$user->expires_at?->toIso8601String(),'notification'=>$user->notification_text]);
    }

    /** Саморегистрация не выдаёт доступ к сканерам или файлам до одобрения оператора. */
    public function register(Request $request, string $provider): Response
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
