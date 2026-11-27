<?php

use App\Http\Controllers\Api\GoogleFormsWebhookController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ElectionApiController;
use App\Http\Controllers\Api\V1\EventApiController;
use App\Http\Controllers\Api\V1\MembershipPaymentApiController;
use App\Http\Controllers\Api\V1\ReferralApiController;
use App\Http\Middleware\EnsureTemporaryPasswordChanged;
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
        } catch (Throwable) {
            return response()->json([
                'ok' => false,
                'app' => 'CPF',
                'database' => 'disconnected',
            ], 503);
        }
    })->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:api-login')
        ->name('auth.login');

    Route::post('auth/passkey-code', [AuthController::class, 'passkeyCode'])
        ->middleware('throttle:api-passkey-code')
        ->name('auth.passkey-code');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/password/force-change', [AuthController::class, 'forceChangePassword'])
            ->middleware('throttle:10,1')
            ->name('auth.password.force-change');

        Route::middleware(EnsureTemporaryPasswordChanged::class)->group(function () {
            // Campus Events & Officer 2-Stage QR Attendance
            Route::get('events', [EventApiController::class, 'index'])->name('events.index');
            Route::post('events/{event}/scan', [EventApiController::class, 'scan'])
                ->middleware('throttle:60,1')
                ->name('events.scan');

            // Membership Roster, Messenger GC Checklist & Dues / Payments
            Route::get('membership/roster', [MembershipPaymentApiController::class, 'roster'])
                ->name('membership.roster');
            Route::patch('membership/{membership}/toggle-gc', [MembershipPaymentApiController::class, 'toggleGc'])
                ->middleware('throttle:30,1')
                ->name('membership.toggle-gc');
            Route::patch('membership/{membership}/toggle-dues', [MembershipPaymentApiController::class, 'toggleDues'])
                ->middleware('throttle:30,1')
                ->name('membership.toggle-dues');
            Route::get('payments', [MembershipPaymentApiController::class, 'paymentsIndex'])
                ->name('payments.index');
            Route::post('payments/submit', [MembershipPaymentApiController::class, 'submitPayment'])
                ->middleware('throttle:10,1')
                ->name('payments.submit');
            Route::patch('payments/{payment}/validate', [MembershipPaymentApiController::class, 'validatePayment'])
                ->middleware('throttle:30,1')
                ->name('payments.validate');

            // Referrals & Counselor Coordination (Privacy-Safe Peer Endpoints)
            Route::get('referrals/mine', [ReferralApiController::class, 'mine'])
                ->name('referrals.mine');
            Route::post('referrals', [ReferralApiController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('referrals.store');

            // Mobile Elections & Ephemeral Session Live Chat (Cache-Only)
            Route::get('elections/active', [ElectionApiController::class, 'active'])
                ->name('elections.active');
            Route::post('elections/{election}/nominate', [ElectionApiController::class, 'nominate'])
                ->middleware('throttle:20,1')
                ->name('elections.nominate');
            Route::post('elections/{election}/vote', [ElectionApiController::class, 'vote'])
                ->middleware('throttle:10,1')
                ->name('elections.vote');
            Route::get('elections/{election}/live-chat', [ElectionApiController::class, 'liveChatIndex'])
                ->name('elections.live-chat.index');
            Route::post('elections/{election}/live-chat', [ElectionApiController::class, 'liveChatStore'])
                ->middleware('throttle:30,1')
                ->name('elections.live-chat.store');
        });
    });
});
