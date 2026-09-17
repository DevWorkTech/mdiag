<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Console;
use DevWorkTech\MDiag\Models\{LocalUser, LocalSession};
use DevWorkTech\MDiag\Services\ProfileRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/** Управляет пользователями MDiag без изменения таблицы users основного проекта. */
final class UserCommand extends Command
{
    protected $signature = 'mdiag:user {provider} {login}
        {--create : Создать запись и запросить пароль скрытым вводом}
        {--password : Установить новый пароль и отозвать сессии}
        {--status= : active или blocked}
        {--downloads= : allow или deny}
        {--expires= : Дата окончания или none}
        {--module=* : Разрешённые коды модулей; заменяет текущий список}
        {--all-modules : Разрешить все модули}
        {--no-modules : Запретить все модули}
        {--message= : Сообщение при блокировке}
        {--expired-message= : Сообщение об истечении срока}
        {--module-message= : Сообщение при запрете модуля}
        {--notification= : Уведомление при входе}';
    protected $description = 'Создать или настроить локальную учётную запись MDiag';

    public function handle(ProfileRegistry $profiles): int
    {
        $provider = (string) $this->argument('provider');
        $profiles->get($provider);
        $login = (string) $this->argument('login');
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,128}$/D', $login)) { $this->error('Некорректный логин.'); return self::INVALID; }
        $user = LocalUser::where('provider', $provider)->where('login', $login)->first();
        if ($this->option('create') && $user) { $this->error('Пользователь уже существует.'); return self::INVALID; }
        if (!$this->option('create') && !$user) { $this->error('Пользователь не найден. Укажите --create.'); return self::INVALID; }
        $user ??= new LocalUser(['provider' => $provider, 'login' => $login, 'allowed_modules' => []]);
        // В CLI пароль не передаётся аргументом: не попадает в историю shell и ps.
        if ($this->option('create') || $this->option('password')) {
            $password = $this->secret('Локальный пароль (минимум 8 символов)');
            if (!is_string($password) || strlen($password) < 8 || strlen($password) > 4096) { $this->error('Недопустимая длина пароля.'); return self::INVALID; }
            $user->password = Hash::make($password);
        }
        foreach (['status' => ['active', 'blocked'], 'downloads' => ['allow', 'deny']] as $option => $choices) {
            $value = $this->option($option);
            if ($value !== null && !in_array($value, $choices, true)) { $this->error("Некорректный --{$option}."); return self::INVALID; }
        }
        if ($this->option('status') !== null) { $user->active = $this->option('status') === 'active'; }
        if ($this->option('downloads') !== null) { $user->downloads_allowed = $this->option('downloads') === 'allow'; }
        if ($this->option('expires') !== null) {
            try { $user->expires_at = $this->option('expires') === 'none' ? null : Carbon::parse($this->option('expires')); }
            catch (\Throwable) { $this->error('Некорректная дата.'); return self::INVALID; }
        }
        $modules = $this->option('module');
        if ((int) $this->option('all-modules') + (int) $this->option('no-modules') + (int) ($modules !== []) > 1) { $this->error('Выберите один способ задания модулей.'); return self::INVALID; }
        if ($this->option('all-modules')) { $user->allowed_modules = null; }
        elseif ($this->option('no-modules')) { $user->allowed_modules = []; }
        elseif ($modules !== []) { $user->allowed_modules = array_values(array_unique(array_map('strtoupper', $modules))); }
        foreach (['message' => 'denied_message', 'expired-message' => 'expired_message', 'module-message' => 'module_denied_message', 'notification' => 'notification_text'] as $option => $column) {
            if ($this->option($option) !== null) { $user->{$column} = $this->option($option); }
        }
        $user->save();
        if ($this->option('password') || !$user->active) { LocalSession::where('user_id', $user->id)->delete(); }
        $this->info("Локальный пользователь {$login} сохранён; id={$user->id}.");
        return self::SUCCESS;
    }
}
