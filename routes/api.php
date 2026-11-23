<?php

use App\Http\Controllers\Api\GoogleFormsWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('google-forms/health', [GoogleFormsWebhookController::class, 'health'])
    ->name('api.google-forms.health');

Route::post('google-forms/membership', [GoogleFormsWebhookController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('api.google-forms.membership');
