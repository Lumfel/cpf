<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Membership\SubmitOnlineMembershipApplication;
use App\Actions\Security\IssueMemberPasskey;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Http\Controllers\FirstBootSetupController;
use App\Models\AdviserHandover;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\ClubServiceTenure;
use App\Models\CounselorInvitation;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Models\PasskeyIssuance;
use App\Models\PaymentDestination;
use App\Models\RoomRequest;
use App\Models\SchoolYearArchive;
use App\Models\User;
use App\Notifications\CounselorInviteOtpNotification;
use App\Services\ClubTenureService;
use Database\Seeders\BlankSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;
use ZipArchive;

class Wave1SecurityPrivacyLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function setupCampus(string $code = 'ALIJIS', string $name = 'CHMSU Alijis Campus'): Campus
    {
        return Campus::create([
            'code' => $code,
            'name' => $name,
            'slug' => strtolower($code),
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Montemayor, Elena V.',
            'gc_group_name' => "CPF {$code} Official Peer GC",
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: CampusMembership}
     */
    private function createCampusUser(
        Campus $campus,
        CpfRole $role,
        string $name,
        MembershipStatus $status = MembershipStatus::Approved,
        bool $mustChangePassword = false,
    ): array {
        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('SecretPass2026!'),
            'must_change_password' => $mustChangePassword,
            'temporary_password_issued_at' => $mustChangePassword ? now() : null,
        ]);

        $membership = CampusMembership::create([
            'user_id' => $user->id,
            'campus_id' => $campus->id,
            'role' => $role,
            'status' => $status,
            'enrollment_source' => 'officer_added',
            'recorded_email' => $user->email,
            'first_name' => 'Sample',
            'middle_name' => 'A.',
            'last_name' => 'Member',
            'full_name_last_first_mi' => $name,
            'course' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Guidance Services' : 'BSIT',
            'year_level' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Faculty' : '4',
            'section' => $role === CpfRole::GuidanceCounselor ? 'Guidance Counselor' : 'A',
            'course_year_section' => 'BSIT 4A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.user',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-555-0001',
            'approved_at' => $status === MembershipStatus::Approved ? now()->subMonth() : null,
        ]);

        if ($status === MembershipStatus::Approved) {
            app(ClubTenureService::class)->openTenure($membership, $user, $membership->approved_at);
        }

        return [$user, $membership];
    }

    public function test_c01_restricts_archived_officers_gc_transcripts_verifies_zip_sha256_and_hides_counselor_gc_archives(): void
    {
        $campus = $this->setupCampus();
        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$president] = $this->createCampusUser($campus, CpfRole::President, 'Mercado, Sofia R.');
        [$member] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.');
        [$formerOfficer, $formerOfficerMembership] = $this->createCampusUser($campus, CpfRole::Secretary, 'Alvarez, Hannah G.');
        [$formerMember, $formerMemberMembership] = $this->createCampusUser($campus, CpfRole::Member, 'Santos, Paolo C.');

        app(ClubTenureService::class)->transitionToAlumni($formerOfficerMembership, $adviser, 'term_completed');
        app(ClubTenureService::class)->transitionToAlumni($formerMemberMembership, $adviser, 'graduated');

        $inactiveChannel = OfficerGcChannel::create([
            'campus_id' => $campus->id,
            'academic_year' => '2025-2026',
            'channel_key' => 'campus.alijis.officers-gc.2025-2026',
            'is_active' => false,
            'reset_at' => now()->subDay(),
        ]);
        OfficerGcMessage::create([
            'channel_id' => $inactiveChannel->id,
            'sender_user_id' => $president->id,
            'sender_role' => 'president',
            'message_body' => 'Confidential officer deliberation.',
        ]);

        SchoolYearArchive::create([
            'campus_id' => $campus->id,
            'academic_year' => '2025-2026',
            'archive_type' => 'officer_gc',
            'title' => 'Officers GC 2025-2026',
            'zip_file_name' => 'officers_gc.zip',
            'hdd_storage_path' => storage_path('app/archives/officers_gc.zip'),
            'sha256_checksum' => str_repeat('1', 64),
            'archived_by_user_id' => $adviser->id,
            'archived_at' => now(),
        ]);
        SchoolYearArchive::create([
            'campus_id' => $campus->id,
            'academic_year' => '2025-2026',
            'archive_type' => 'counselor_gc',
            'title' => 'Counselors Confidential GC 2025-2026',
            'zip_file_name' => 'counselor_gc.zip',
            'hdd_storage_path' => storage_path('app/archives/counselor_gc.zip'),
            'sha256_checksum' => str_repeat('2', 64),
            'archived_by_user_id' => $adviser->id,
            'archived_at' => now(),
        ]);

        $zipPath = storage_path('app/test-c01-officers-gc.zip');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('transcript.json', json_encode([
            ['id' => 1, 'sender_name' => 'Mercado, Sofia R.', 'sender_role' => 'president', 'message_body' => 'ZIP transcript line'],
        ]));
        $zip->close();

        $archive = OfficerGcArchive::create([
            'campus_id' => $campus->id,
            'academic_year' => '2025-2026',
            'zip_file_name' => 'test-c01-officers-gc.zip',
            'hdd_storage_path' => $zipPath,
            'sha256_checksum' => (string) hash_file('sha256', $zipPath),
            'total_messages' => 1,
            'archived_by_user_id' => $adviser->id,
            'archived_at' => now(),
        ]);

        try {
            foreach ([$member->fresh(), $formerMember->fresh()] as $restrictedViewer) {
                $this->actingAs($restrictedViewer)
                    ->get('/alumni-archive')
                    ->assertOk()
                    ->assertInertia(fn ($page) => $page
                        ->has('inactiveGcChannels', 0)
                        ->has('schoolYearArchives', 1)
                        ->where('schoolYearArchives.0.archive_type', 'officer_gc'));

                $this->actingAs($restrictedViewer)
                    ->getJson("/alumni-archive/gc/{$archive->id}/transcript")
                    ->assertForbidden();
            }

            $this->actingAs($member)
                ->get('/community-hub')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('schoolYearArchives', 1)
                    ->where('schoolYearArchives.0.archive_type', 'officer_gc'));

            foreach ([$president->fresh(), $formerOfficer->fresh()] as $authorizedViewer) {
                $this->actingAs($authorizedViewer)
                    ->get('/alumni-archive')
                    ->assertOk()
                    ->assertInertia(fn ($page) => $page
                        ->has('inactiveGcChannels', 1)
                        ->has('schoolYearArchives', 1));

                $this->actingAs($authorizedViewer)
                    ->getJson("/alumni-archive/gc/{$archive->id}/transcript")
                    ->assertOk()
                    ->assertJsonPath('available', true)
                    ->assertJsonPath('messages.0.message_body', 'ZIP transcript line');
            }

            // Tampered ZIP checksum rejected (422) before reading transcript.json
            $archive->update(['sha256_checksum' => str_repeat('f', 64)]);

            $this->actingAs($president)
                ->getJson("/alumni-archive/gc/{$archive->id}/transcript")
                ->assertStatus(422);
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    public function test_c02_mobile_force_password_change_and_reset_user_password_clear_temp_flags(): void
    {
        $campus = $this->setupCampus();
        [$member] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.', MembershipStatus::Approved, mustChangePassword: true);
        [$counselor] = $this->createCampusUser($campus, CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.', MembershipStatus::Approved, mustChangePassword: true);

        $memberToken = $member->createToken('Mobile')->plainTextToken;
        $counselorToken = $counselor->createToken('CounselorMobile')->plainTextToken;

        $this->withToken($memberToken)
            ->getJson('/api/v1/events')
            ->assertForbidden()
            ->assertJsonPath('code', 'must_change_password_required');

        app('auth')->forgetGuards();
        $this->withToken($memberToken)
            ->postJson('/api/v1/auth/password/force-change', [
                'password' => 'NewMobilePass2026!',
                'password_confirmation' => 'Mismatch!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        app('auth')->forgetGuards();
        $this->withToken($memberToken)
            ->postJson('/api/v1/auth/password/force-change', [
                'password' => 'NewMobilePass2026!',
                'password_confirmation' => 'NewMobilePass2026!',
            ])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonPath('qr_unlocked', true)
            ->assertJsonPath('verified_role', 'member');

        $member->refresh();
        $this->assertFalse($member->must_change_password);
        $this->assertNull($member->temporary_password_issued_at);
        $this->assertTrue(Hash::check('NewMobilePass2026!', $member->password));

        app('auth')->forgetGuards();
        $this->withToken($counselorToken)
            ->postJson('/api/v1/auth/password/force-change', [
                'password' => 'CounselorMobilePass2026!',
                'password_confirmation' => 'CounselorMobilePass2026!',
            ])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonPath('qr_unlocked', false);

        [$resetTarget] = $this->createCampusUser($campus, CpfRole::Secretary, 'Alvarez, Hannah G.', MembershipStatus::Approved, mustChangePassword: true);
        app(ResetUserPassword::class)->reset($resetTarget, [
            'password' => 'ResetClearedPass2026!',
            'password_confirmation' => 'ResetClearedPass2026!',
        ]);

        $resetTarget->refresh();
        $this->assertFalse($resetTarget->must_change_password);
        $this->assertNull($resetTarget->temporary_password_issued_at);
    }

    public function test_c03_hardens_first_boot_adviser_latch_gates_apply_endpoints_and_disables_open_register(): void
    {
        $this->seed(BlankSystemSeeder::class);
        config()->set('cpf.google_forms.webhook_secret', 'wave1-secret');

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Attacker',
            'email' => 'attacker@chmsu.edu.ph',
            'password' => 'AttackerPass2026!',
            'password_confirmation' => 'AttackerPass2026!',
        ])->assertNotFound();

        $orphanUser = User::factory()->create(['email' => 'orphan@chmsu.edu.ph']);
        $campus = Campus::where('is_active', true)->firstOrFail();
        CampusMembership::create([
            'user_id' => $orphanUser->id,
            'campus_id' => $campus->id,
            'role' => CpfRole::Adviser,
            'status' => MembershipStatus::Pending,
            'enrollment_source' => 'online_form',
            'recorded_email' => $orphanUser->email,
            'first_name' => 'Pending',
            'last_name' => 'Adviser',
            'full_name_last_first_mi' => 'Adviser, Pending',
            'course' => 'Guidance Services',
            'year_level' => 'Faculty',
            'section' => 'Adviser',
            'course_year_section' => 'Guidance Services / Adviser',
            'sex' => 'Female',
            'birthdate' => '1990-01-01',
            'active_email' => $orphanUser->email,
            'facebook_profile_url' => 'https://www.facebook.com/pending.adviser',
            'active_fb_account' => 'Pending Adviser',
            'cellphone_number' => '0917-000-0000',
        ]);

        $this->assertTrue(FirstBootSetupController::needsAdviserSetup());

        $this->postJson('/membership/apply', [
            'campus_id' => $campus->id,
            'first_name' => 'PreBoot',
            'last_name' => 'Student',
        ])->assertStatus(503);

        $this->postJson('/api/google-forms/membership', [
            'campus_id' => $campus->id,
            'first_name' => 'PreBoot',
            'last_name' => 'Form',
        ], ['X-CPF-Forms-Secret' => 'wave1-secret'])->assertStatus(503);

        $this->post('/first-boot/setup-adviser', [
            'campus_id' => $campus->id,
            'academic_year' => '2026-2027',
            'first_name' => 'Elena',
            'last_name' => 'Montemayor',
            'email' => 'elena.c03@chmsu.edu.ph',
            'password' => 'FoundingAdviser2026!',
            'password_confirmation' => 'FoundingAdviser2026!',
            'sex' => 'Female',
        ])->assertRedirect('/dashboard');

        $this->assertFalse(FirstBootSetupController::needsAdviserSetup());
    }

    public function test_c04_masks_direct_counseling_bookings_and_restricts_review_to_guidance_coordinators(): void
    {
        $campus = $this->setupCampus();
        CampusRoom::create([
            'campus_id' => $campus->id,
            'room_code' => 'TESTING_ROOM',
            'room_name' => 'Testing Room',
            'capacity' => 6,
            'is_single_allowed_room' => true,
        ]);

        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselorUser] = $this->createCampusUser($campus, CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$president] = $this->createCampusUser($campus, CpfRole::President, 'Mercado, Sofia R.');
        [$secretary] = $this->createCampusUser($campus, CpfRole::Secretary, 'Alvarez, Hannah G.');
        [$requester] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.');

        $counselorSchedule = CampusCounselor::ensureDefaultCounselorsForCampus($campus->id)->firstOrFail();

        $reqRes = $this->actingAs($requester)->postJson('/testing-room/request', [
            'campus_id' => $campus->id,
            'counselor_id' => $counselorSchedule->id,
            'purpose' => 'Direct Private Counseling Consultation',
            'request_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertCreated();

        $booking = RoomRequest::findOrFail((int) $reqRes->json('room_request.id'));
        $this->assertSame($counselorSchedule->id, $booking->counselor_id);

        foreach ([$president, $secretary] as $studentOfficer) {
            $hubRes = $this->actingAs($studentOfficer)
                ->get('/campus-hub')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('roomRequests.0.id', $booking->id)
                    ->where('roomRequests.0.requester_name', 'Confidential Booking')
                    ->where('roomRequests.0.purpose', 'Booked')
                    ->where('roomRequests.0.review_notes', null));

            $hubRes->assertDontSee('Direct Private Counseling Consultation');
            $hubRes->assertDontSee('Reyes, Katrina M.');

            $this->actingAs($studentOfficer)
                ->patchJson("/testing-room/{$booking->id}/review", ['status' => 'approved'])
                ->assertForbidden();
        }

        $this->actingAs($requester)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roomRequests.0.purpose', 'Direct Private Counseling Consultation')
                ->where('roomRequests.0.requester_name', 'Reyes, Katrina M.'));

        $this->actingAs($counselorUser)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roomRequests.0.purpose', 'Direct Private Counseling Consultation')
                ->where('roomRequests.0.requester_name', 'Reyes, Katrina M.'));

        $this->actingAs($adviser)
            ->patchJson("/testing-room/{$booking->id}/review", [
                'status' => 'approved',
                'review_notes' => 'Confirmed confidential intake.',
            ])
            ->assertOk();
    }

    public function test_c05_blocks_unapproved_accounts_hides_event_checklists_and_scopes_quote_approval(): void
    {
        $campusA = $this->setupCampus('ALIJIS', 'CHMSU Alijis Campus');
        $campusB = $this->setupCampus('TALISAY', 'CHMSU Talisay Campus');

        [$adviser] = $this->createCampusUser($campusA, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$president] = $this->createCampusUser($campusA, CpfRole::President, 'Mercado, Sofia R.');
        [$member] = $this->createCampusUser($campusA, CpfRole::Member, 'Reyes, Katrina M.');
        [$pendingUser] = $this->createCampusUser($campusA, CpfRole::Member, 'Pending, Applicant P.', MembershipStatus::Pending);
        [$rejectedUser] = $this->createCampusUser($campusA, CpfRole::Member, 'Rejected, Applicant R.', MembershipStatus::Rejected);
        $noMembershipUser = User::factory()->create(['must_change_password' => false]);

        $this->from('/')
            ->post('/membership/apply', [
                'campus_id' => $campusA->id,
                'first_name' => 'Newbie',
                'last_name' => 'Applicant',
                'course' => 'BSIT',
                'year_level' => '1',
                'section' => 'A',
                'sex' => 'Female',
                'birthdate' => '2006-05-10',
                'active_email' => 'newbie.c05@chmsu.edu.ph',
                'facebook_profile_url' => 'https://www.facebook.com/newbie.c05',
                'cellphone_number' => '0917-555-1234',
            ])
            ->assertRedirect('/');

        $createdApplicant = User::where('email', 'newbie.c05@chmsu.edu.ph')->firstOrFail();
        $this->assertNull($createdApplicant->email_verified_at);
        $this->assertTrue($createdApplicant->must_change_password);

        $dest = PaymentDestination::create([
            'campus_id' => $campusA->id,
            'channel' => 'GCash',
            'account_name' => 'CPF Alijis',
            'account_number' => '09170001111',
            'is_active' => true,
            'approved_by_adviser_id' => $adviser->id,
        ]);

        foreach ([$pendingUser, $rejectedUser, $noMembershipUser] as $unverified) {
            $this->actingAs($unverified)->get('/events-activities')->assertForbidden();
            $this->actingAs($unverified)->get('/campus-hub')->assertForbidden();
            $this->actingAs($unverified)->postJson('/testing-room/request', [
                'campus_id' => $campusA->id,
                'purpose' => 'Blocked',
                'request_date' => now()->addDays(2)->toDateString(),
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])->assertForbidden();
            $this->actingAs($unverified)->postJson('/referrals/submit', [
                'campus_id' => $campusA->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Blocked',
                'suggested_priority' => ReferralPriority::Routine->value,
                'concern_summary' => 'Blocked',
            ])->assertForbidden();
            $this->actingAs($unverified)->postJson('/achievements', [
                'campus_id' => $campusA->id,
                'title' => 'Blocked',
                'category' => 'Service',
                'achieved_on' => now()->toDateString(),
            ])->assertForbidden();
            $this->actingAs($unverified)->postJson('/time-capsules', [
                'campus_id' => $campusA->id,
                'title' => 'Blocked',
                'message' => 'Blocked',
                'unlock_at' => now()->addDays(5)->toDateTimeString(),
            ])->assertForbidden();
            $this->actingAs($unverified)->postJson('/payments/submit', [
                'destination_id' => $dest->id,
                'purpose' => 'Blocked',
                'amount' => 100,
                'reference_number' => 'REF-BLOCKED',
            ])->assertForbidden();
            $this->actingAs($unverified)->postJson('/quotes', [
                'quote_text' => 'Blocked quote',
            ])->assertForbidden();

            $apiToken = $unverified->createToken('Test')->plainTextToken;
            app('auth')->forgetGuards();
            $this->withToken($apiToken)->postJson('/api/v1/referrals', [
                'campus_id' => $campusA->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Blocked API',
                'concern_summary' => 'Blocked API',
            ])->assertForbidden();
        }

        $this->actingAs($member)->postJson('/achievements', [
            'campus_id' => $campusB->id,
            'title' => 'Cross Campus Blocked',
            'category' => 'Service',
            'achieved_on' => now()->toDateString(),
        ])->assertForbidden();

        $event = CampusEvent::create([
            'campus_id' => $campusA->id,
            'title' => 'Checklist Privacy Event',
            'event_date' => now()->addDays(2)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);
        CampusEventAttendance::create([
            'campus_event_id' => $event->id,
            'user_id' => $member->id,
            'logged_in_at' => now(),
            'logged_in_by_user_id' => $president->id,
        ]);

        $this->actingAs($member)
            ->get('/events-activities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events.0.checklist', 0)
                ->where('events.0.my_logged_in_at', fn ($val) => is_string($val) && $val !== ''));

        $this->actingAs($president)
            ->get('/events-activities')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events.0.checklist', 3));

        $this->actingAs($member)
            ->postJson('/quotes', ['quote_text' => 'Member pending quote'])
            ->assertCreated()
            ->assertJsonPath('quote.campus_id', $campusA->id)
            ->assertJsonPath('quote.is_approved', false);

        $this->actingAs($president)
            ->postJson('/quotes', ['quote_text' => 'Officer approved quote'])
            ->assertCreated()
            ->assertJsonPath('quote.campus_id', $campusA->id)
            ->assertJsonPath('quote.is_approved', true);
    }

    public function test_h01_rejects_unsigned_qr_fallback_and_excludes_counselors_from_event_attendance(): void
    {
        $campus = $this->setupCampus();
        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor] = $this->createCampusUser($campus, CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$member] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.');

        $eventDate = now()->addDays(2)->toDateString();
        $event = CampusEvent::create([
            'campus_id' => $campus->id,
            'title' => 'Strict HMAC QR Event',
            'event_date' => $eventDate,
            'start_time' => '08:00',
            'end_time' => '17:00',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        $this->travelTo(Carbon::parse($eventDate.' 10:00:00'));

        try {
            $unsignedCode = sprintf('CPF-ALIJIS-%s-%04d', now()->format('Y'), $member->id);
            $this->actingAs($adviser)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => $unsignedCode,
                    'scan_type' => 'login',
                ])
                ->assertStatus(422);

            $counselorRaw = "CPF|campus:ALIJIS|user:{$counselor->id}|role:guidance_counselor";
            $counselorSig = substr(hash_hmac('sha256', $counselorRaw, (string) config('app.key')), 0, 16);
            $this->actingAs($adviser)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => "{$counselorRaw}|sig:{$counselorSig}",
                    'scan_type' => 'login',
                ])
                ->assertStatus(422);

            $this->actingAs($adviser)
                ->postJson("/events-activities/{$event->id}/manual-attendance", [
                    'user_id' => $counselor->id,
                    'scan_type' => 'login',
                ])
                ->assertStatus(422);

            $memberRaw = "CPF|campus:ALIJIS|user:{$member->id}|role:member";
            $memberSig = substr(hash_hmac('sha256', $memberRaw, (string) config('app.key')), 0, 16);
            $this->actingAs($adviser)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => "{$memberRaw}|sig:{$memberSig}",
                    'scan_type' => 'login',
                ])
                ->assertCreated();
        } finally {
            $this->travelBack();
        }
    }

    public function test_h02_allows_reinviting_non_approved_non_alumni_users_and_retains_outgoing_adviser_as_counselor_by_default(): void
    {
        Notification::fake();

        $campus = $this->setupCampus();
        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');

        $firstInit = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'SecretPass2026!',
                'first_name' => 'Marco',
                'last_name' => 'Villanueva',
                'email' => 'marco.reinvite@chmsu.edu.ph',
                'sex' => 'Male',
                'initial_password' => 'TempCounselorPass1!',
            ])
            ->assertCreated()
            ->assertJsonPath('handover.outgoing_role', 'guidance_counselor');

        $this->actingAs($adviser)
            ->deleteJson('/adviser-handover/'.$firstInit->json('handover.id'))
            ->assertOk();

        $reInviteInit = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'SecretPass2026!',
                'first_name' => 'Marco',
                'last_name' => 'Villanueva',
                'email' => 'marco.reinvite@chmsu.edu.ph',
                'sex' => 'Male',
                'initial_password' => 'TempCounselorPass2!',
            ])
            ->assertCreated();

        $marco = User::where('email', 'marco.reinvite@chmsu.edu.ph')->firstOrFail();
        $marco->forceFill(['must_change_password' => false, 'temporary_password_issued_at' => null])->save();

        $this->actingAs($marco)
            ->postJson('/adviser-handover/'.$reInviteInit->json('handover.id').'/respond', [
                'decision' => 'accepted',
                'confirmation_code' => $reInviteInit->json('handover.confirmation_code'),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $adviser->refresh();
        $marco->refresh();

        $this->assertSame(CpfRole::Adviser, $marco->verifiedRole());
        $this->assertFalse($adviser->isAlumni());
        $this->assertSame(CpfRole::GuidanceCounselor, $adviser->verifiedRole());
        $this->assertSame(MembershipStatus::Approved, $adviser->primaryMembership->status);
        $this->assertTrue(
            ClubServiceTenure::where('user_id', $adviser->id)
                ->where('role', CpfRole::GuidanceCounselor->value)
                ->whereNull('ended_at')
                ->exists(),
        );

        [$rejectedApplicant] = $this->createCampusUser($campus, CpfRole::Member, 'Rejected, Applicant X.', MembershipStatus::Rejected);

        $this->actingAs($marco)
            ->postJson('/counselor-invitations', ['email' => $adviser->email])
            ->assertStatus(422);

        $this->actingAs($marco)
            ->postJson('/counselor-invitations', [
                'email' => $rejectedApplicant->email,
                'counselor_name' => 'Applicant X, RGC',
            ])
            ->assertCreated();

        $inviteUrl = '';
        $otpCode = '';
        Notification::assertSentOnDemand(
            CounselorInviteOtpNotification::class,
            function (CounselorInviteOtpNotification $n, array $channels, object $notifiable) use ($rejectedApplicant, &$inviteUrl, &$otpCode): bool {
                if (($notifiable->routes['mail'] ?? null) !== $rejectedApplicant->email) {
                    return false;
                }
                $inviteUrl = $n->inviteUrl;
                $otpCode = $n->otpCode;

                return true;
            },
        );

        $showRes = $this->get($inviteUrl)->assertOk();
        $acceptUrl = (string) $showRes->viewData('page')['props']['acceptUrl'];

        auth()->logout();
        $this->postJson($acceptUrl, [
            'otp_code' => $otpCode,
            'first_name' => 'Reused',
            'last_name' => 'Counselor',
            'sex' => 'Female',
            'password' => 'CounselorPass2026!',
            'password_confirmation' => 'CounselorPass2026!',
        ])->assertCreated();

        $this->assertSame(CpfRole::GuidanceCounselor, $rejectedApplicant->fresh()->verifiedRole());
    }

    public function test_h03_enforces_trusted_proxies_otp_handover_lockout_mobile_2fa_and_fail_closed_webhook(): void
    {
        Notification::fake();

        $campus = $this->setupCampus();
        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$member, $memberMembership] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.');

        // 1. Fail-closed webhook when secret is empty
        config()->set('cpf.google_forms.webhook_secret', '');
        $this->getJson('/api/google-forms/health', ['X-CPF-Forms-Secret' => ''])->assertUnauthorized();
        $this->postJson('/api/google-forms/membership', [], ['X-CPF-Forms-Secret' => ''])->assertUnauthorized();

        // 2. Spoofed X-Forwarded-For from untrusted proxy does not bypass email|ip rate limiter
        for ($i = 1; $i <= 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
                ->withHeaders(['X-Forwarded-For' => "203.0.113.{$i}"])
                ->postJson('/api/v1/auth/login', [
                    'email' => $member->email,
                    'password' => 'WrongPassword!',
                    'device_name' => 'TestDevice',
                ])
                ->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.99'])
            ->postJson('/api/v1/auth/login', [
                'email' => $member->email,
                'password' => 'WrongPassword!',
                'device_name' => 'TestDevice',
            ])
            ->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->postJson('/api/v1/auth/login', [
                'email' => $adviser->email,
                'password' => 'SecretPass2026!',
                'device_name' => 'AdviserDevice',
            ])
            ->assertOk();

        // 3. Per-record 5-failed-attempt lockout on counselor_invitations (committed outside rollback)
        $this->actingAs($adviser)
            ->postJson('/counselor-invitations', ['email' => 'lockout.invite@chmsu.edu.ph'])
            ->assertCreated();

        $inviteUrl = '';
        $otpCode = '';
        Notification::assertSentOnDemand(
            CounselorInviteOtpNotification::class,
            function (CounselorInviteOtpNotification $n, array $channels, object $notifiable) use (&$inviteUrl, &$otpCode): bool {
                if (($notifiable->routes['mail'] ?? null) !== 'lockout.invite@chmsu.edu.ph') {
                    return false;
                }
                $inviteUrl = $n->inviteUrl;
                $otpCode = $n->otpCode;

                return true;
            },
        );

        $acceptUrl = (string) $this->get($inviteUrl)->assertOk()->viewData('page')['props']['acceptUrl'];
        $wrongOtp = $otpCode === '111111' ? '222222' : '111111';

        auth()->logout();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.10.1.{$attempt}"])
                ->postJson($acceptUrl, [
                    'otp_code' => $wrongOtp,
                    'first_name' => 'Lock',
                    'last_name' => 'Target',
                    'sex' => 'Female',
                    'password' => 'CounselorPass2026!',
                    'password_confirmation' => 'CounselorPass2026!',
                ])
                ->assertStatus(422);
        }

        $lockedInvite = CounselorInvitation::where('email', 'lockout.invite@chmsu.edu.ph')->firstOrFail();
        $this->assertSame(5, $lockedInvite->failed_attempts);
        $this->assertFalse($lockedInvite->isPendingAndActive());

        $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.99'])
            ->postJson($acceptUrl, [
                'otp_code' => $otpCode,
                'first_name' => 'Lock',
                'last_name' => 'Target',
                'sex' => 'Female',
                'password' => 'CounselorPass2026!',
                'password_confirmation' => 'CounselorPass2026!',
            ])
            ->assertStatus(422);

        // 4. Per-record 5-failed-attempt lockout on adviser_handovers (committed outside rollback)
        $handoverRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'SecretPass2026!',
                'first_name' => 'Handover',
                'last_name' => 'Lockout',
                'email' => 'handover.lockout@chmsu.edu.ph',
                'sex' => 'Female',
                'initial_password' => 'TempHandover2026!',
            ])
            ->assertCreated();

        $handoverId = (int) $handoverRes->json('handover.id');
        $realCode = (string) $handoverRes->json('handover.confirmation_code');
        $incoming = User::where('email', 'handover.lockout@chmsu.edu.ph')->firstOrFail();
        $incoming->forceFill(['must_change_password' => false, 'temporary_password_issued_at' => null])->save();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withoutMiddleware(ThrottleRequests::class)
                ->actingAs($incoming)
                ->postJson("/adviser-handover/{$handoverId}/respond", [
                    'decision' => 'accepted',
                    'confirmation_code' => 'ZZZZZZ',
                ])
                ->assertStatus(422);
        }

        $lockedHandover = AdviserHandover::findOrFail($handoverId);
        $this->assertSame(5, $lockedHandover->failed_attempts);
        $this->assertSame('locked', $lockedHandover->status);

        $this->withoutMiddleware(ThrottleRequests::class)
            ->actingAs($incoming)
            ->postJson("/adviser-handover/{$handoverId}/respond", [
                'decision' => 'accepted',
                'confirmation_code' => $realCode,
            ])
            ->assertStatus(422);

        // 5. Mobile API login & passkey-code enforce Fortify 2FA without burning single-use passkey early
        $secretKey = app(Google2FA::class)->generateSecretKey();
        $member->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secretKey),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['REC-CODE-0001'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.1'])
            ->postJson('/api/v1/auth/login', [
                'email' => $member->email,
                'password' => 'SecretPass2026!',
                'device_name' => 'Phone',
            ])
            ->assertStatus(422)
            ->assertJsonPath('two_factor_required', true);

        $validTotp = app(Google2FA::class)->getCurrentOtp($secretKey);
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.1'])
            ->postJson('/api/v1/auth/login', [
                'email' => $member->email,
                'password' => 'SecretPass2026!',
                'device_name' => 'Phone',
                'code' => $validTotp,
            ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer');

        $issued = app(IssueMemberPasskey::class)->handle($adviser, $memberMembership, true, true);
        $plainPasskey = $issued['enrollment_code'];
        $issuanceId = $issued['issuance']->id;

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.2'])
            ->postJson('/api/v1/auth/passkey-code', [
                'email' => $member->email,
                'passkey_code' => $plainPasskey,
                'device_name' => 'Phone',
            ])
            ->assertStatus(422)
            ->assertJsonPath('two_factor_required', true);

        $this->assertSame('issued', PasskeyIssuance::findOrFail($issuanceId)->status);

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.2'])
            ->postJson('/api/v1/auth/passkey-code', [
                'email' => $member->email,
                'passkey_code' => $plainPasskey,
                'device_name' => 'Phone',
                'code' => '000000',
            ])
            ->assertStatus(422)
            ->assertJsonPath('two_factor_required', true);

        $this->assertSame('issued', PasskeyIssuance::findOrFail($issuanceId)->status);

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.2'])
            ->postJson('/api/v1/auth/passkey-code', [
                'email' => $member->email,
                'passkey_code' => $plainPasskey,
                'device_name' => 'Phone',
                'recovery_code' => 'REC-CODE-0001',
            ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertSame('claimed', PasskeyIssuance::findOrFail($issuanceId)->status);
        $this->assertNotContains('REC-CODE-0001', $member->fresh()->recoveryCodes());
    }

    public function test_h06_blocks_alumni_counselor_and_adviser_rows_from_form_or_webhook_overwrites(): void
    {
        $campus = $this->setupCampus();
        config()->set('cpf.google_forms.webhook_secret', 'h06-webhook-secret');

        [$adviser, $adviserMembership] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor, $counselorMembership] = $this->createCampusUser($campus, CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$alumni, $alumniMembership] = $this->createCampusUser($campus, CpfRole::President, 'Former, President P.');
        app(ClubTenureService::class)->transitionToAlumni($alumniMembership, $adviser, 'graduated');

        [$revokedCounselor, $revokedCounselorMembership] = $this->createCampusUser(
            $campus,
            CpfRole::GuidanceCounselor,
            'Revoked, Counselor R.',
            MembershipStatus::Rejected,
        );

        $payloadBase = [
            'campus_id' => $campus->id,
            'first_name' => 'Spoof',
            'last_name' => 'Overwrite',
            'course' => 'BSIT',
            'year_level' => '2',
            'section' => 'A',
            'sex' => 'Male',
            'birthdate' => '2005-04-10',
            'facebook_profile_url' => 'https://www.facebook.com/spoof.overwrite',
            'cellphone_number' => '0917-555-9999',
        ];

        foreach ([
            [$adviser, $adviserMembership, CpfRole::Adviser, MembershipStatus::Approved],
            [$counselor, $counselorMembership, CpfRole::GuidanceCounselor, MembershipStatus::Approved],
            [$alumni->fresh(), $alumniMembership->fresh(), CpfRole::President, MembershipStatus::Alumni],
            [$revokedCounselor, $revokedCounselorMembership, CpfRole::GuidanceCounselor, MembershipStatus::Rejected],
        ] as [$protectedUser, $protectedMembership, $expectedRole, $expectedStatus]) {
            $this->postJson('/api/google-forms/membership', [
                ...$payloadBase,
                'active_email' => $protectedUser->email,
            ], ['X-CPF-Forms-Secret' => 'h06-webhook-secret'])
                ->assertStatus(422);

            $this->actingAs($protectedUser)
                ->postJson('/membership/apply', [
                    ...$payloadBase,
                    'active_email' => $protectedUser->email,
                ])
                ->assertStatus(422);

            try {
                app(SubmitOnlineMembershipApplication::class)->handle(
                    $protectedUser,
                    [...$payloadBase, 'active_email' => $protectedUser->email],
                );
                $this->fail('Expected ValidationException was not thrown.');
            } catch (ValidationException) {
                // Expected 422 validation rejection
            }

            $protectedMembership->refresh();
            $this->assertSame($expectedRole, $protectedMembership->role);
            $this->assertSame($expectedStatus, $protectedMembership->status);
        }
    }
}
