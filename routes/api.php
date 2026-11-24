<?php

use App\Http\Controllers\Api\GoogleFormsWebhookController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventApiController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('google-forms/health', [GoogleFormsWebhookController::class, 'health'])
    ->name('api.google-forms.health');

Route::post('google-forms/membership', [GoogleFormsWebhookController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('api.google-forms.membership');

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', function () {
        try {
            DB::connection()->getPdo();

            return response()->json([
                'ok' => true,
                'app' => 'CPF',
                'database' => 'connected',
            ]);
        } catch (\Throwable) {
            return response()->json([
                'ok' => false,
                'app' => 'CPF',
                'database' => 'disconnected',
            ], 503);
        }
    })->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('auth.login');

    Route::post('auth/passkey-code', [AuthController::class, 'passkeyCode'])
        ->middleware('throttle:5,1')
        ->name('auth.passkey-code');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('events', [EventApiController::class, 'index'])->name('events.index');
        Route::post('events/{event}/scan', [EventApiController::class, 'scan'])->name('events.scan');
    });
});
