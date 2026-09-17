<?php

declare(strict_types=1);

namespace DevWorkTech\MDiag;

use DevWorkTech\MDiag\Console\AddPackageCommand;
use DevWorkTech\MDiag\Console\ScannerAccessCommand;
use DevWorkTech\MDiag\Console\ScannerListCommand;
use DevWorkTech\MDiag\Console\SyncPackagesCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Подключает конфигурацию, маршруты, миграции и artisan-команды пакета.
 */
final class MDiagServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mdiag-dwt.php', 'mdiag-dwt');
    }

    public function boot(): void
    {
        $this->registerIsolatedRoutes();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/mdiag-dwt.php' => config_path('mdiag-dwt.php'),
        ], 'mdiag-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AddPackageCommand::class,
                \DevWorkTech\MDiag\Console\UserCommand::class,
                \DevWorkTech\MDiag\Console\AssignScannerCommand::class,
                ScannerAccessCommand::class,
                ScannerListCommand::class,
                SyncPackagesCommand::class,
            ]);
        }
    }

    /**
     * Регистрирует gateway только на выделенном домене компонента.
     *
     * Маршруты не подключаются к RouteServiceProvider основного проекта и не
     * получают его prefix/name автоматически. Поэтому существующие routes/api,
     * web.php и маршруты других самописных модулей остаются нетронутыми.
     */
    private function registerIsolatedRoutes(): void
    {
        if (!(bool) config('mdiag-dwt.http.enabled', true)) {
            return;
        }

        $domain = trim((string) config(
            'mdiag-dwt.http.domain',
            config('mdiag-dwt.local_domain', ''),
        ));
        if ($domain === '') {
            return;
        }
        $middleware = array_values(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            (array) config('mdiag-dwt.http.middleware', ['api']),
        )));
        $namePrefix = (string) config(
            'mdiag-dwt.http.route_name_prefix',
            'mdiag-dwt.',
        );

        $routes = Route::middleware($middleware)->as($namePrefix);
        $routes->domain($domain);
        $routes->group(__DIR__ . '/../routes/api.php');
    }
}
