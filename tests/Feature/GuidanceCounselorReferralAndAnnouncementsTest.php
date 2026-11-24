<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Http\Controllers\FirstBootSetupController;
use App\Models\Campus;
use App\Models\CampusAnnouncement;
use App\Models\CampusCounselor;
use App\Models\CampusEvent;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\CounselorInvitation;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\GuidanceReferral;
use App\Models\OfficerGcChannel;
use App\Models\PaymentDestination;
use App\Models\RoomRequest;
use App\Models\User;
use App\Notifications\CounselorInviteOtpNotification;
use Database\Seeders\BlankSystemSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuidanceCounselorReferralAndAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Montemayor, Elena V.',
            'gc_group_name' => 'CPF Alijis Official Peer GC',
            'is_active' => true,
        ]);

        CampusRoom::create([
            'campus_id' => $this->campus->id,
            'room_code' => 'TESTING_ROOM',
            'room_name' => 'Testing Room',
            'capacity' => 6,
            'is_single_allowed_room' => true,
        ]);
    }

    private function createCampusUser(
        CpfRole $role,
        string $name,
        MembershipStatus $status = MembershipStatus::Approved,
        ?Campus $campus = null,
    ): User {
        $campus ??= $this->campus;

        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);

        CampusMembership::create([
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
            'course_year_section' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true)
                ? 'Guidance Services / Counselor'
                : 'BSIT 4A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.cpf.user',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-555-0001',
        ]);

        return $user;
    }

    public function test_guidance_counselor_role_has_coordination_only_access_and_is_blocked_from_club_governance(): void
    {
        $adviser = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        $counselor = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        $member = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');
        $pendingUser = $this->createCampusUser(CpfRole::Member, 'Applicant, Pending P.', MembershipStatus::Pending);

        $this->assertFalse(CpfRole::GuidanceCounselor->isOfficerOrAdviser());
        $this->assertTrue(CpfRole::GuidanceCounselor->canCoordinateGuidance());
        $this->assertTrue(CpfRole::Adviser->canCoordinateGuidance());
        $this->assertFalse(CpfRole::President->canCoordinateGuidance());
        $this->assertTrue($counselor->canCoordinateGuidance());

        // Allowed coordination surfaces: Campus Hub, Counselors Private GC, Testing Room review, and Guidance Referrals
        $this->actingAs($counselor)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('campus/Operations')
                ->where('canCoordinateGuidance', true)
                ->where('isOfficerOrAdviser', false)
                ->where('isClubAdviser', false));

        $this->actingAs($counselor)
            ->postJson('/counselors-gc/messages', [
                'message_body' => 'Wednesday afternoon intake slots are open.',
            ])
            ->assertCreated();

        $roomRes = $this->actingAs($member)->postJson('/testing-room/request', [
            'campus_id' => $this->campus->id,
            'purpose' => 'Peer Drop-in Check',
            'request_date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '13:00',
            'end_time' => '14:00',
        ])->assertCreated();

        $this->actingAs($counselor)
            ->patchJson('/testing-room/'.$roomRes->json('room_request.id').'/review', [
                'status' => 'approved',
            ])
            ->assertOk();

        $refRes = $this->actingAs($member)->postJson('/referrals/submit', [
            'campus_id' => $this->campus->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Mendoza, Carlo T.',
            'suggested_priority' => ReferralPriority::Urgent->value,
            'concern_summary' => 'Needs calm check-in after midterms.',
        ])->assertCreated();

        $this->actingAs($counselor)
            ->patchJson('/referrals/'.$refRes->json('referral_id').'/confirm', [
                'feedback_status' => 'ongoing',
                'safe_scheduling_note' => 'Scheduled at Guidance Office room.',
            ])
            ->assertOk()
            ->assertJsonPath('feedback_status', 'ongoing');

        $this->actingAs($counselor)
            ->postJson('/counselors-gc/archive')
            ->assertOk()
            ->assertJsonPath('status', 'archived');

        // Strictly blocked from Member Approvals & Dues
        $pendingMembership = $pendingUser->primaryMembership;
        $memberMembership = $member->primaryMembership;

        $this->actingAs($counselor)
            ->patch("/membership/{$pendingMembership->id}/approve")
            ->assertForbidden();

        $this->actingAs($counselor)
            ->patch("/membership/{$memberMembership->id}/toggle-dues")
            ->assertForbidden();

        $destination = PaymentDestination::create([
            'campus_id' => $this->campus->id,
            'channel' => 'GCash',
            'account_name' => 'CPF Chapter',
            'account_number' => '09170001111',
            'is_active' => true,
            'approved_by_adviser_id' => $adviser->id,
        ]);

        $this->actingAs($counselor)
            ->postJson('/payments/submit', [
                'destination_id' => $destination->id,
                'purpose' => 'Dues',
                'amount' => 100,
                'reference_number' => 'REF-BLOCKED',
            ])
            ->assertForbidden();

        // Strictly blocked from Officers Private GC
        $officerChannel = OfficerGcChannel::create([
            'campus_id' => $this->campus->id,
            'academic_year' => '2026-2027',
            'channel_key' => 'campus.alijis.officers-gc.2026-2027',
            'is_active' => true,
        ]);

        $this->actingAs($counselor)->get('/officers-chat')->assertForbidden();
        $this->actingAs($counselor)
            ->postJson("/officers-gc/{$officerChannel->id}/messages", ['message_body' => 'Blocked'])
            ->assertForbidden();

        // Strictly blocked from Elections & Voting
        $election = Election::create([
            'campus_id' => $this->campus->id,
            'academic_year' => '2026-2027',
            'title' => 'CPF General Election',
            'status' => 'open',
            'current_position' => 'president',
            'current_phase' => 'voting',
            'round_number' => 1,
            'created_by_user_id' => $adviser->id,
        ]);

        $cand = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $member->id,
            'position' => 'president',
            'nomination_status' => 'accepted',
        ]);

        $this->actingAs($counselor)->get('/community-hub')->assertForbidden();
        $this->actingAs($counselor)
            ->postJson("/elections/{$election->id}/vote", [
                'candidate_id' => $cand->id,
                'position' => 'president',
            ])
            ->assertForbidden();

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/candidates", [
                'user_id' => $counselor->id,
                'position' => 'president',
            ])
            ->assertStatus(422);

        // Strictly blocked from Club Events (web + API v1)
        $event = CampusEvent::create([
            'campus_id' => $this->campus->id,
            'title' => 'Student Peer Workshop',
            'description' => 'Student club event',
            'location' => 'Activity Center',
            'event_date' => now()->format('Y-m-d'),
            'start_time' => '08:00',
            'end_time' => '17:00',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($counselor)->get('/events-activities')->assertForbidden();
        $this->actingAs($counselor)
            ->postJson('/events-activities', [
                'title' => 'Blocked Event',
                'event_date' => now()->addDay()->format('Y-m-d'),
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertForbidden();
        $this->actingAs($counselor)
            ->postJson("/events-activities/{$event->id}/scan", [
                'qr_payload' => 'CPF-ALIJIS-2026-0001',
                'scan_type' => 'login',
            ])
            ->assertForbidden();

        Sanctum::actingAs($counselor);
        $this->getJson('/api/v1/events')->assertForbidden();
    }

    public function test_counselor_email_signed_link_and_24h_otp_invite_registers_guidance_counselor_role(): void
    {
        Notification::fake();

        $adviser = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');

        // Student officer cannot invite counselors
        $this->actingAs($president)
            ->postJson('/counselor-invitations', ['email' => 'new.counselor@chmsu.edu.ph'])
            ->assertForbidden();

        // Club Adviser invites counselor by email -> sends signed link + 6-digit OTP via email without leaking secrets in non-local JSON
        $this->actingAs($adviser)
            ->postJson('/counselor-invitations', [
                'email' => 'ramon.counselor@chmsu.edu.ph',
                'counselor_name' => 'Ramon P. Velasco, RGC',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'invited')
            ->assertJsonMissingPath('invite_url')
            ->assertJsonMissingPath('otp_code');

        $inviteUrl = '';
        $otpCode = '';
        Notification::assertSentOnDemand(
            CounselorInviteOtpNotification::class,
            function (CounselorInviteOtpNotification $n, array $channels, object $notifiable) use (&$inviteUrl, &$otpCode): bool {
                if (($notifiable->routes['mail'] ?? null) !== 'ramon.counselor@chmsu.edu.ph') {
                    return false;
                }
                $inviteUrl = $n->inviteUrl;
                $otpCode = $n->otpCode;

                return true;
            },
        );
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otpCode);
        $this->assertNotEmpty($inviteUrl);

        $invitation = CounselorInvitation::query()->where('email', 'ramon.counselor@chmsu.edu.ph')->firstOrFail();

        // Unsigned URL rejected
        $this->get("/counselor-invitations/{$invitation->token}")->assertForbidden();

        // Valid signed link opens Inertia onboarding page
        $showRes = $this->get($inviteUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/AcceptCounselorInvite')
                ->where('email', 'ramon.counselor@chmsu.edu.ph')
                ->has('acceptUrl'));

        $acceptUrl = (string) $showRes->viewData('page')['props']['acceptUrl'];

        // Wrong OTP rejected with 422
        auth()->logout();
        $this->postJson($acceptUrl, [
            'otp_code' => $otpCode === '000000' ? '999999' : '000000',
            'first_name' => 'Ramon',
            'middle_name' => 'P.',
            'last_name' => 'Velasco',
            'sex' => 'Male',
            'password' => 'CounselorSecure2026!',
            'password_confirmation' => 'CounselorSecure2026!',
        ])->assertStatus(422)->assertJsonValidationErrors('otp_code');

        // Correct 6-digit OTP registers user directly with guidance_counselor role
        $this->postJson($acceptUrl, [
            'otp_code' => $otpCode,
            'first_name' => 'Ramon',
            'middle_name' => 'P.',
            'last_name' => 'Velasco',
            'sex' => 'Male',
            'cellphone_number' => '0917-555-0888',
            'specialty' => 'Academic Resilience Counseling',
            'password' => 'CounselorSecure2026!',
            'password_confirmation' => 'CounselorSecure2026!',
        ])->assertCreated()
            ->assertJsonPath('status', 'registered')
            ->assertJsonPath('role', 'guidance_counselor');

        $registeredCounselor = User::query()->where('email', 'ramon.counselor@chmsu.edu.ph')->firstOrFail();
        $this->assertSame(CpfRole::GuidanceCounselor, $registeredCounselor->verifiedRole());
        $this->assertNotNull($invitation->fresh()->accepted_at);

        // Expired (>24h) invite is rejected
        $this->actingAs($adviser)
            ->postJson('/counselor-invitations', ['email' => 'expired.counselor@chmsu.edu.ph'])
            ->assertCreated();

        $expiredUrl = '';
        Notification::assertSentOnDemand(
            CounselorInviteOtpNotification::class,
            function (CounselorInviteOtpNotification $n, array $channels, object $notifiable) use (&$expiredUrl): bool {
                if (($notifiable->routes['mail'] ?? null) !== 'expired.counselor@chmsu.edu.ph') {
                    return false;
                }
                $expiredUrl = $n->inviteUrl;

                return true;
            },
        );

        $this->travel(25)->hours();
        $this->get($expiredUrl)->assertForbidden();
    }

    public function test_adviser_handover_supports_selecting_existing_guidance_counselor_without_breaking_first_boot(): void
    {
        // Verify First-Boot setup is active on blank system and permanently latches once any user exists
        $this->seed(BlankSystemSeeder::class);
        $this->assertTrue(FirstBootSetupController::needsAdviserSetup());

        $alijis = Campus::where('code', 'ALIJIS')->firstOrFail();
        $existingCounselor = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.', MembershipStatus::Approved, $alijis);
        $this->assertFalse(FirstBootSetupController::needsAdviserSetup());

        // Now create Club Adviser and a student President
        $adviser = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.', MembershipStatus::Approved, $alijis);
        $president = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.', MembershipStatus::Approved, $alijis);
        $this->assertFalse(FirstBootSetupController::needsAdviserSetup());

        // Outgoing Adviser sees registered GuidanceCounselor in shared Inertia props
        $this->actingAs($adviser)
            ->get('/membership')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pendingAdviserHandover.availableGuidanceCounselors', 1)
                ->where('pendingAdviserHandover.availableGuidanceCounselors.0.membership_id', $existingCounselor->primaryMembership->id));

        // Selecting a student officer in existing_counselor mode is rejected (422)
        $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'mode' => 'existing_counselor',
                'target_membership_id' => $president->primaryMembership->id,
                'current_password' => 'password',
            ])
            ->assertStatus(422);

        // Selecting the existing GuidanceCounselor succeeds and cancelling preserves their GuidanceCounselor role
        $cancelInit = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'mode' => 'existing_counselor',
                'target_membership_id' => $existingCounselor->primaryMembership->id,
                'current_password' => 'password',
                'handover_notes' => 'Draft turnover check.',
            ])
            ->assertCreated();

        $this->actingAs($adviser)
            ->deleteJson('/adviser-handover/'.$cancelInit->json('handover.id'))
            ->assertOk();

        $this->assertSame(CpfRole::GuidanceCounselor, $existingCounselor->fresh()->verifiedRole());

        // Re-initiating and accepting promotes existing GuidanceCounselor to Club Adviser
        $initRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'mode' => 'existing_counselor',
                'target_membership_id' => $existingCounselor->primaryMembership->id,
                'current_password' => 'password',
                'handover_notes' => 'Official AY handover.',
            ])
            ->assertCreated();

        $this->actingAs($existingCounselor)
            ->postJson('/adviser-handover/'.$initRes->json('handover.id').'/respond', [
                'decision' => 'accepted',
                'confirmation_code' => $initRes->json('handover.confirmation_code'),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $this->assertSame(CpfRole::Adviser, $existingCounselor->fresh()->verifiedRole());
        $this->assertTrue($adviser->fresh()->isAlumni());
    }

    public function test_peer_referral_free_slot_scheduling_rejects_overlaps_and_hides_confidential_clinical_notes(): void
    {
        $counselorUser = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        $peerMember = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        $counselorSchedule = CampusCounselor::ensureDefaultCounselorsForCampus($this->campus->id)->firstOrFail();
        $slotDate = now()->addDays(3)->format('Y-m-d');

        // 1. Existing Testing Room booking blocks overlapping referral slot with 422
        RoomRequest::create([
            'campus_id' => $this->campus->id,
            'room_id' => CampusRoom::where('campus_id', $this->campus->id)->value('id'),
            'counselor_id' => $counselorSchedule->id,
            'requested_by_user_id' => $peerMember->id,
            'purpose' => 'Booked Counseling Window',
            'request_date' => $slotDate,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => 'approved',
        ]);

        $this->actingAs($peerMember)
            ->postJson('/referrals/submit', [
                'campus_id' => $this->campus->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Non-Member Client, Juan P.',
                'student_course_section' => 'BSED 2B',
                'suggested_priority' => ReferralPriority::Routine->value,
                'concern_summary' => 'Sensitive family stress details.',
                'counselor_id' => $counselorSchedule->id,
                'preferred_date' => $slotDate,
                'preferred_start_time' => '09:30',
                'preferred_end_time' => '10:30',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_start_time');

        // 2. Free slot succeeds with plain client identifier (no CPF member account required)
        $submitRes = $this->actingAs($peerMember)
            ->postJson('/referrals/submit', [
                'campus_id' => $this->campus->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Non-Member Client, Juan P.',
                'student_course_section' => 'BSED 2B',
                'suggested_priority' => ReferralPriority::SemiUrgent->value,
                'concern_summary' => 'Confidential intake summary for counselor eyes only.',
                'counselor_id' => $counselorSchedule->id,
                'preferred_date' => $slotDate,
                'preferred_start_time' => '10:30',
                'preferred_end_time' => '11:30',
            ])
            ->assertCreated()
            ->assertJsonPath('feedback_status', 'pending');

        $referralId = (int) $submitRes->json('referral_id');

        // Subsequent overlapping referral is also rejected with 422
        $this->actingAs($peerMember)
            ->postJson('/referrals/submit', [
                'campus_id' => $this->campus->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Another Student, Maria L.',
                'suggested_priority' => ReferralPriority::Routine->value,
                'concern_summary' => 'Second request overlapping.',
                'counselor_id' => $counselorSchedule->id,
                'preferred_date' => $slotDate,
                'preferred_start_time' => '11:00',
                'preferred_end_time' => '12:00',
            ])
            ->assertStatus(422);

        // 3. Guidance Counselor updates status to ongoing + completed with clinical notes AND safe scheduling note
        $this->actingAs($counselorUser)
            ->patchJson("/referrals/{$referralId}/confirm", [
                'feedback_status' => 'ongoing',
                'safe_scheduling_note' => 'Client met at Testing Room; follow-up set next week.',
                'confidential_clinical_notes' => 'STRICTLY CONFIDENTIAL DSM/clinical counselor session assessment.',
            ])
            ->assertOk()
            ->assertJsonPath('feedback_status', 'ongoing')
            ->assertJsonMissingPath('referral.confidential_clinical_notes')
            ->assertJsonMissingPath('referral.encrypted_concern_summary');

        // Clinical notes are encrypted at rest in DB
        $rawReferral = DB::table('guidance_referrals')->where('id', $referralId)->first();
        $this->assertNotEquals(
            'STRICTLY CONFIDENTIAL DSM/clinical counselor session assessment.',
            $rawReferral->confidential_clinical_notes,
        );

        // Counselor can read clinical notes in counselorInbox
        $this->actingAs($counselorUser)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counselorInbox.0.feedback_status', 'ongoing')
                ->where('counselorInbox.0.confidential_clinical_notes', 'STRICTLY CONFIDENTIAL DSM/clinical counselor session assessment.'));

        // Counselor marks completed
        $this->actingAs($counselorUser)
            ->patchJson("/referrals/{$referralId}/confirm", [
                'feedback_status' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('feedback_status', 'completed');

        // 4. Referring peer sees ONLY safe status + scheduled slot + safe scheduling note — ZERO clinical notes leaked
        $peerResponse = $this->actingAs($peerMember)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('myReferrals.0.student_identifier', 'Non-Member Client, Juan P.')
                ->where('myReferrals.0.feedback_status', 'completed')
                ->where('myReferrals.0.safe_scheduling_note', 'Client met at Testing Room; follow-up set next week.')
                ->where('myReferrals.0.preferred_date', $slotDate)
                ->where('myReferrals.0.preferred_start_time', '10:30')
                ->where('myReferrals.0.preferred_end_time', '11:30')
                ->missing('myReferrals.0.concern_summary')
                ->missing('myReferrals.0.encrypted_concern_summary')
                ->missing('myReferrals.0.confidential_clinical_notes'));

        $peerResponse->assertDontSee('STRICTLY CONFIDENTIAL DSM/clinical counselor session assessment.');
        $peerResponse->assertDontSee('Confidential intake summary for counselor eyes only.');
    }

    public function test_pio_and_secretary_can_publish_templated_announcements_while_other_roles_are_blocked(): void
    {
        $adviser = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        $pio = $this->createCampusUser(CpfRole::Pio, 'Navarro, Bianca F.');
        $secretary = $this->createCampusUser(CpfRole::Secretary, 'Alvarez, Hannah G.');
        $president = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        $counselor = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        $member = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        $templates = CampusAnnouncement::templates();
        $this->assertSame(
            ['general_assembly', 'workshop_reminder', 'dues_notice', 'wellness_checkin'],
            array_keys($templates),
        );

        // Unauthorized roles (President, GuidanceCounselor, Member) are blocked with 403
        foreach ([$president, $counselor, $member] as $unauthorized) {
            $this->actingAs($unauthorized)
                ->postJson('/announcements', [
                    'template_key' => 'general_assembly',
                    'title' => 'Unauthorized Notice',
                    'body' => 'Should be blocked.',
                ])
                ->assertForbidden();
        }

        // PIO customizes & publishes Workshop Reminder template
        $this->actingAs($pio)
            ->postJson('/announcements', [
                'template_key' => 'workshop_reminder',
                'title' => 'Active Listening Workshop — Friday 2 PM',
                'body' => 'Bring your Attendance QR ID and reflection journal.',
                'schedule_label' => 'Friday • 2:00 PM – 4:00 PM',
                'location' => 'Guidance Training Room',
                'is_pinned' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('announcement.template_label', 'Workshop Reminder');

        // Secretary publishes Dues Notice template
        $this->actingAs($secretary)
            ->postJson('/announcements', [
                'template_key' => 'dues_notice',
                'title' => '2nd Semester Chapter Dues Reminder',
                'body' => 'Coordinate with our Treasurer this week.',
            ])
            ->assertCreated();

        // Adviser publishes Wellness Check-in template
        $this->actingAs($adviser)
            ->postJson('/announcements', [
                'template_key' => 'wellness_checkin',
                'title' => 'Finals Week Calm Check-in',
                'body' => 'Drop by the Testing Room anytime during open hours.',
            ])
            ->assertCreated();

        // Campus member sees published announcements on Dashboard
        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canPublishAnnouncements', false)
                ->has('announcementTemplates', 4)
                ->has('campusAnnouncements', 3)
                ->where('campusAnnouncements.0.title', 'Active Listening Workshop — Friday 2 PM'));
    }

    public function test_database_seeder_provisions_verified_officer_peer_member_and_coordination_guidance_counselor(): void
    {
        Campus::query()->delete();
        $this->seed(DatabaseSeeder::class);

        $counselor = User::where('email', 'counselor@cpf.chmsu.edu.ph')->first();
        $president = User::where('email', 'president@cpf.chmsu.edu.ph')->first();
        $pio = User::where('email', 'pio@cpf.chmsu.edu.ph')->first();
        $member = User::where('email', 'katrina@cpf.chmsu.edu.ph')->first();

        $this->assertNotNull($counselor);
        $this->assertSame(CpfRole::GuidanceCounselor, $counselor->verifiedRole());
        $this->assertFalse($counselor->verifiedRole()->isOfficerOrAdviser());
        $this->assertTrue($counselor->canCoordinateGuidance());

        $this->assertNotNull($president);
        $this->assertSame(CpfRole::President, $president->verifiedRole());
        $this->assertTrue($president->verifiedRole()->isOfficerOrAdviser());

        $this->assertNotNull($pio);
        $this->assertTrue($pio->canPublishAnnouncements());

        $this->assertNotNull($member);
        $this->assertSame(CpfRole::Member, $member->verifiedRole());

        $this->assertGreaterThanOrEqual(2, CampusAnnouncement::count());
    }
}
