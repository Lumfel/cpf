<?php

use App\Http\Controllers\Prototype\CpfPrototypeController;
use App\Http\Controllers\Prototype\DevRoleSwitcherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Isolated CPF Prototype Routes
|--------------------------------------------------------------------------
|
| Loaded ONLY when config('cpf.prototype.enabled') is true.
| Completely separated from main application routes in routes/web.php.
|
*/

$prefix = trim((string) config('cpf.prototype.route_prefix', 'prototype'), '/');

Route::get($prefix, [CpfPrototypeController::class, 'index'])->name('prototype.index');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::post('dev/switch-role/{role}', [DevRoleSwitcherController::class, 'switch'])->name('dev.switch-role');
});
