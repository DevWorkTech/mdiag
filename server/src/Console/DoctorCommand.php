<?php
declare(strict_types=1);
namespace DevWorkTech\MDiag\Console;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/** Проверяет установку модуля без обращения к внешним серверам и без вывода секретов. */
final class DoctorCommand extends Command
{
    protected $signature = 'mdiag:doctor';
    protected $description = 'Проверить домен, маршруты и запись локальных журналов MDiag';
    public function handle(): int
    {
        $domain=(string)config('mdiag-dwt.http.domain');
        $base=(string)config('mdiag-dwt.base_url');
        $this->line('Домен модуля: '.$domain);
        $this->line('Проверка с планшета: '.rtrim($base,'/').'/xdiag/health');
        $this->line('APP_DEBUG: '.(config('app.debug')?'true':'false'));
        $this->line('Ревизия протокола: transport2 / APK 7.00.014-mdiag2');
        try {
            $users=\DevWorkTech\MDiag\Models\LocalUser::where('provider','xdiag')->where('active',true)->count();
            $this->line('Активных локальных пользователей XDiag: '.$users);
            if ($users===0) { $this->warn('Создайте пользователя через mdiag:user: учётная запись поставщика не является локальной.'); }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('mdiag_users','profile_data')) {
                $this->error('Не применена миграция профиля: выполните php artisan migrate.');
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('База модуля недоступна: '.get_class($e));
            return self::FAILURE;
        }
        $routes=[];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string)$route->getName(), (string)config('mdiag-dwt.http.route_name_prefix','mdiag-dwt.'))) {
                $routes[]=[$route->getDomain(),$route->uri()];
            }
        }
        $this->table(['Домен route','URI'],$routes);
        $dir=storage_path('logs');
        if (!is_dir($dir)) { @mkdir($dir,0770,true); }
        // Создаём журнал явно, даже если на планшете запрос не доходит до PHP.
        $ok=@file_put_contents($dir.'/mdiag-auth.log',gmdate('c')." doctor log-check\n",FILE_APPEND|LOCK_EX)!==false;
        $this->line('Запись mdiag-auth.log: '.($ok?'OK':'ОШИБКА — проверьте владельца storage/logs'));
        $valid=$domain!=='' && parse_url($base,PHP_URL_HOST)===$domain && $routes!==[];
        if (!$valid) { $this->error('Домен, base_url или маршруты не согласованы. Очистите config/route cache.'); }
        $this->comment('Это проверка PHP/CLI. Доступность с планшета подтверждает только HTTP-запрос к health.');
        return $ok && $valid ? self::SUCCESS : self::FAILURE;
    }
}
