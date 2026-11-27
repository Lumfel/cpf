<?php

use App\Http\Controllers\Prototype\CpfPrototypeController;
use App\Http\Controllers\Prototype\DemoBotController;
use App\Http\Controllers\Prototype\DevRoleSwitcherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Isolated CPF Prototype Routes
|--------------------------------------------------------------------------
|
| Loaded ONLY in non-production environments when config('cpf.prototype.enabled')
| is true. Completely separated from main application routes in routes/web.php.
|
*/

if (app()->isProduction() || ! config('cpf.prototype.enabled')) {
    return;
}

$prefix = trim((string) config('cpf.prototype.route_prefix', 'prototype'), '/');

Route::middleware(['auth', 'verified'])->group(function () use ($prefix) {
    Route::get($prefix, [CpfPrototypeController::class, 'index'])->name('prototype.index');

    Route::prefix($prefix.'/demo-bots')->name('prototype.demo-bots.')->group(function () {
        Route::get('/', [DemoBotController::class, 'index'])->name('index');
        Route::post('/gc', [DemoBotController::class, 'runGcBot'])->name('gc');
        Route::post('/election', [DemoBotController::class, 'runElectionBot'])->name('election');
        Route::post('/awarding', [DemoBotController::class, 'runAwardingBot'])->name('awarding');
        Route::post('/reset', [DemoBotController::class, 'reset'])->name('reset');
    });

    Route::post('dev/switch-role/{role}', [DevRoleSwitcherController::class, 'switch'])->name('dev.switch-role');
});
