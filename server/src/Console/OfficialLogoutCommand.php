<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Console;
use Illuminate\Console\Command;
use DevWorkTech\MDiag\Services\Sync\XDiagOfficialClient;

/** Удаляет сессию консольного клиента. Новый login произойдёт при следующей синхронизации. */
final class OfficialLogoutCommand extends Command
{
    protected $signature='mdiag:logout {provider=xdiag : Профиль официального клиента}';
    protected $description='Очистить token и cookies PHP-клиента синхронизации';
    public function handle(XDiagOfficialClient $client): int
    {
        if ($this->argument('provider')!=='xdiag') { $this->error('Пока поддерживается xdiag.'); return self::INVALID; }
        try { $client->logout(); }
        catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $this->info('Сессия PHP-клиента XDiag удалена из кеша. Следующая синхронизация выполнит новый вход.');
        return self::SUCCESS;
    }
}
