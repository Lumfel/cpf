<?php

use App\Http\Controllers\AdviserHandoverController;
use App\Http\Controllers\AlumniArchiveController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\Auth\PasskeyCodeLoginController;
use App\Http\Controllers\CampusOperationsController;
use App\Http\Controllers\CommunityHubController;
use App\Http\Controllers\CpfFeatureController;
use App\Http\Controllers\CpfV4Controller;
use App\Http\Controllers\Credentials\BlockchainCredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventActivityController;
use App\Http\Controllers\FirstBootSetupController;
use App\Http\Controllers\Membership\CampusMembershipController;
use App\Http\Controllers\MobileReleaseController;
use App\Http\Controllers\OfficersChatController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Middleware\EnsureTemporaryPasswordChanged;
use App\Models\Campus;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// =========================================================================
// MAIN CODE PROPER — PUBLIC & AUTHENTICATED ROUTES
// =========================================================================

Route::get('/', function () {
    $campuses = [];
    try {
        $campuses = Campus::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'slug'])
            ->toArray();
    } catch (\Throwable) {
        // Fallback gracefully to default campus list if DB is not migrated yet
    }

    return Inertia::render('Landing', [
        'campuses' => $campuses,
    ]);
})->name('home');

// Public Android APK Download (for cpf_mobile QR install)
Route::get('download/android', [MobileReleaseController::class, 'android'])
    ->name('download.android');

// Public Blockchain Credential Verification (Achievements, Certificates, Awards)
Route::get('my-credentials', [BlockchainCredentialController::class, 'index'])
    ->name('credentials.index');
Route::get('credentials/verify/{codeOrHash}', [BlockchainCredentialController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->name('credentials.verify');

Route::post('membership/apply', [CampusMembershipController::class, 'storeApplication'])
    ->middleware('throttle:10,1')
    ->name('membership.apply');

Route::post('first-boot/setup-adviser', [FirstBootSetupController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('first-boot.setup-adviser');

Route::post('login/passkey-code', [PasskeyCodeLoginController::class, 'store'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('login.passkey-code');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('profile-photos/{targetUser}', [ProfileController::class, 'showPhoto'])
        ->name('profile-photos.show');
    Route::get('force-password-change', [ForcePasswordChangeController::class, 'edit'])
        ->name('password.force-change.edit');
    Route::post('force-password-change', [ForcePasswordChangeController::class, 'update'])
        ->name('password.force-change.update');

    Route::middleware(EnsureTemporaryPasswordChanged::class)->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('policy-and-bylaws', fn () => Inertia::render('PolicyAndBylaws'))
            ->name('policy-and-bylaws');
        Route::post('adviser-handover', [AdviserHandoverController::class, 'store'])->name('adviser-handover.store');
        Route::post('adviser-handover/{handover}/respond', [AdviserHandoverController::class, 'respond'])
            ->middleware('throttle:5,1')
            ->name('adviser-handover.respond');
        Route::delete('adviser-handover/{handover}', [AdviserHandoverController::class, 'cancel'])->name('adviser-handover.cancel');
        Route::get('events-activities', [EventActivityController::class, 'index'])->name('events.index');
        Route::post('events-activities', [EventActivityController::class, 'store'])->name('events.store');
        Route::patch('events-activities/{event}/approve', [EventActivityController::class, 'approve'])->name('events.approve');
        Route::patch('events-activities/{event}/schedule', [EventActivityController::class, 'updateSchedule'])->name('events.schedule');
        Route::patch('events-activities/{event}/cancel', [EventActivityController::class, 'cancel'])->name('events.cancel');
        Route::post('events-activities/{event}/scan', [EventActivityController::class, 'scanQr'])->name('events.scan');
        Route::post('events-activities/{event}/manual-attendance', [EventActivityController::class, 'manualAttendance'])
            ->name('events.manual-attendance');
        Route::post('events-activities/{event}/reflection', [EventActivityController::class, 'storeReflection'])->name('events.reflection');
        Route::patch('events-activities/{event}/reflection-setting', [EventActivityController::class, 'updateReflectionSetting'])->name('events.reflection-setting');
        Route::patch('events-activities/{event}/reflection-setting/review', [EventActivityController::class, 'reviewReflectionSetting'])->name('events.reflection-setting.review');
        Route::patch('events-activities/notifications/{notification}/read', [EventActivityController::class, 'markNotificationRead'])->name('events.notifications.read');
        Route::post('events-activities/notifications/read-all', [EventActivityController::class, 'markAllNotificationsRead'])->name('events.notifications.read-all');
        Route::get('membership', [CampusMembershipController::class, 'index'])->name('membership.index');
        Route::get('membership/{membership}/qr', [CampusMembershipController::class, 'showQr'])->name('membership.qr');
        Route::post('membership/officer-add', [CampusMembershipController::class, 'storeOfficerMember'])->name('membership.officer-add');
        Route::patch('membership/{membership}/approve', [CampusMembershipController::class, 'approve'])->name('membership.approve');
        Route::patch('membership/{membership}/toggle-gc', [CampusMembershipController::class, 'toggleGc'])->name('membership.toggle-gc');
        Route::patch('membership/{membership}/toggle-dues', [CampusMembershipController::class, 'toggleDues'])->name('membership.toggle-dues');
        Route::patch('membership/{membership}/role', [CampusMembershipController::class, 'updateRole'])->name('membership.update-role');
        Route::post('membership/{membership}/transition-alumni', [CampusMembershipController::class, 'transitionToAlumni'])
            ->name('membership.transition-alumni');
        Route::get('alumni-archive', [AlumniArchiveController::class, 'index'])
            ->name('alumni-archive.index');
        Route::get('alumni-archive/gc/{archive}/transcript', [AlumniArchiveController::class, 'gcTranscript'])
            ->name('alumni-archive.gc-transcript');
        Route::post('membership/change-requests', [CampusMembershipController::class, 'storeChangeRequest'])
            ->name('membership.change-requests.store');
        Route::patch('membership/change-requests/{changeRequest}/review', [CampusMembershipController::class, 'reviewChangeRequest'])
            ->name('membership.change-requests.review');
        Route::get('officers-chat', [OfficersChatController::class, 'index'])->name('officers-chat.index');
        Route::get('campus-hub', [CampusOperationsController::class, 'index'])->name('campus.operations');
        Route::get('community-hub', [CommunityHubController::class, 'index'])->name('community.index');

        Route::post('passkeys/{membership}/issue', [CpfFeatureController::class, 'issuePasskey'])->name('passkeys.issue');
        Route::post('my-passkey/regenerate', [CpfFeatureController::class, 'regenerateOwnPasskey'])->name('my-passkey.regenerate');
        Route::post('testing-room/request', [CpfFeatureController::class, 'requestTestingRoom'])->name('testing-room.request');
        Route::patch('testing-room/{roomRequest}/review', [CpfFeatureController::class, 'reviewTestingRoom'])->name('testing-room.review');
        Route::post('referrals/submit', [CpfFeatureController::class, 'submitReferral'])->name('referrals.submit');
        Route::patch('referrals/{referral}/confirm', [CpfFeatureController::class, 'confirmReferral'])->name('referrals.confirm');
        Route::post('officers-gc/{channel}/messages', [CpfFeatureController::class, 'sendOfficerGcMessage'])->name('officers-gc.send');
        Route::post('officers-gc/{channel}/archive', [CpfFeatureController::class, 'archiveOfficerGc'])->name('officers-gc.archive');
        Route::post('counselors-gc/messages', [CpfFeatureController::class, 'sendCounselorGcMessage'])->name('counselors-gc.send');

        // v4 Community, Treasurer & Governance routes
        Route::post('achievements', [CpfV4Controller::class, 'storeAchievement'])->name('achievements.store');
        Route::post('time-capsules', [CpfV4Controller::class, 'storeTimeCapsule'])->name('time-capsules.store');
        Route::post('time-capsules/{capsule}/open', [CpfV4Controller::class, 'openTimeCapsule'])->name('time-capsules.open');
        Route::post('elections', [CpfV4Controller::class, 'storeElection'])->name('elections.store');
        Route::post('elections/{election}/candidates', [CpfV4Controller::class, 'storeCandidate'])->name('elections.candidates.store');
        Route::post('elections/{election}/nominate', [CpfV4Controller::class, 'nominateCandidate'])->name('elections.nominate');
        Route::patch('elections/{election}/candidates/{candidate}/respond', [CpfV4Controller::class, 'respondNomination'])->name('elections.candidates.respond');
        Route::post('elections/{election}/opt-in', [CpfV4Controller::class, 'optInNominations'])->name('elections.opt-in');
        Route::post('elections/{election}/close-nominations', [CpfV4Controller::class, 'closeNominations'])->name('elections.close-nominations');
        Route::post('elections/{election}/skip-position', [CpfV4Controller::class, 'skipOptionalPosition'])->name('elections.skip-position');
        Route::post('elections/{election}/finalize-position', [CpfV4Controller::class, 'finalizePosition'])->name('elections.finalize-position');
        Route::patch('elections/{election}/status', [CpfV4Controller::class, 'updateElectionStatus'])->name('elections.status');
        Route::post('elections/{election}/vote', [CpfV4Controller::class, 'castVote'])->name('elections.vote');
        Route::post('payments/submit', [CpfV4Controller::class, 'submitPayment'])->name('payments.submit');
        Route::post('payments/destinations', [CpfV4Controller::class, 'storePaymentDestination'])->name('payments.destinations.store');
        Route::patch('payments/destinations/{destination}/approve', [CpfV4Controller::class, 'approvePaymentDestination'])->name('payments.destinations.approve');
        Route::patch('payments/{payment}/validate', [CpfV4Controller::class, 'validatePayment'])->name('payments.validate');
        Route::post('quotes', [CpfV4Controller::class, 'storeQuote'])->name('quotes.store');

        // v5 Blockchain Credentials (Achievements, Digital Certificates, Awards)
        Route::post('credentials/achievements', [BlockchainCredentialController::class, 'storeAchievement'])
            ->name('credentials.achievements.store');
        Route::post('credentials/certificates', [BlockchainCredentialController::class, 'storeCertificate'])
            ->name('credentials.certificates.store');
        Route::post('credentials/awards', [BlockchainCredentialController::class, 'storeAward'])
            ->name('credentials.awards.store');
        Route::get('credentials/chain-audit', [BlockchainCredentialController::class, 'auditChain'])
            ->name('credentials.chain-audit');
    });
});

require __DIR__.'/settings.php';

// =========================================================================
// ISOLATED PROTOTYPE ROUTES (Disabled in production via CPF_PROTOTYPE_ENABLED=false)
// =========================================================================
if (config('cpf.prototype.enabled')) {
    require __DIR__.'/prototype.php';
}
