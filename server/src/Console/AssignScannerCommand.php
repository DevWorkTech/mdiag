<?php

declare(strict_types=1);
namespace DevWorkTech\MDiag\Console;
use DevWorkTech\MDiag\Models\{LocalUser, ScannerAccessRule};
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Привязывает SN к локальному владельцу; сам SN не является паролем. */
final class AssignScannerCommand extends Command
{
    protected $signature = 'mdiag:scanner:assign {provider} {login} {serial}
        {--expires= : Дата или none}
        {--message= : Сообщение при ограничении}
        {--transfer : Разрешить перенос от другого локального пользователя}';
    protected $description = 'Привязать сканер к локальному пользователю';
    public function handle(): int
    {
        $provider = (string) $this->argument('provider');
        $user = LocalUser::where('provider', $provider)->where('login', $this->argument('login'))->firstOrFail();
        $serial = strtoupper((string) $this->argument('serial'));
        if (!preg_match('/^[A-Z0-9._:-]{4,128}$/D', $serial)) { $this->error('Некорректный SN.'); return self::INVALID; }
        $rule = ScannerAccessRule::firstOrNew(['provider' => $provider, 'serial' => $serial]);
        if ($rule->user_id && $rule->user_id !== $user->id && !$this->option('transfer')) { $this->error('У сканера уже есть владелец. Для переноса укажите --transfer.'); return self::INVALID; }
        $rule->user_id = $user->id;
        if (!$rule->exists) { $rule->downloads_allowed = true; }
        if ($this->option('expires') !== null) {
            try { $rule->expires_at = $this->option('expires') === 'none' ? null : Carbon::parse($this->option('expires')); }
            catch (\Throwable) { $this->error('Некорректная дата.'); return self::INVALID; }
        }
        if ($this->option('message') !== null) { $rule->denied_message = $this->option('message'); }
        $rule->save();
        $this->info('Сканер привязан. Разрешения пользователя также применяются.');
        return self::SUCCESS;
    }
}
