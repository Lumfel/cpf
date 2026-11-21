<?php

use App\Http\Controllers\Api\GoogleFormsWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('google-forms/health', [GoogleFormsWebhookController::class, 'health'])
    ->name('api.google-forms.health');

Route::post('google-forms/membership', [GoogleFormsWebhookController::class, 'store'])
    ->name('api.google-forms.membership');
