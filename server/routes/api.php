<?php

declare(strict_types=1);

use DevWorkTech\MDiag\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

// Домен и middleware задаёт MDiagServiceProvider. Этот файл ничего не
// добавляет в routes/api.php основного проекта и не меняет его группы.
// Первый сегмент определяет приложение, остальной официальный path сохраняется.
Route::any('{profile}/{path?}', [GatewayController::class, 'handle'])
    ->where([
        'profile' => 'xdiag|xpro7|xpro5|diagzone|prodiag',
        'path' => '.*',
    ])
    ->name('gateway');
