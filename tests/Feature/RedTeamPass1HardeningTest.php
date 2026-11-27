<?php

namespace Tests\Feature;

use App\Actions\Membership\AddOrApproveCampusMember;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Models\AdviserHandover;
use App\Models\Campus;
use App\Models\CampusEvent;
use App\Models\CampusEventNotification;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\CounselorInvitation;
use App\Models\Election;
use App\Models\GuidanceReferral;
use App\Models\MemberInfoChangeRequest;
use App\Models\OfficerGcChannel;
use App\Models\PaymentDestination;
use App\Models\PaymentSubmission;
use App\Models\RoomRequest;
use App\Models\User;
use App\Services\ClubTenureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RedTeamPass1HardeningTest extends TestCase
{
    use RefreshDatabase;

    private Campus $alijis;

    private Campus $talisay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alijis = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Montemayor, Elena V.',
            'gc_group_name' => 'CPF Alijis Official Peer GC',
            'is_active' => true,
        ]);

        $this->talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay Campus',
            'slug' => 'talisay',
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Other, Adviser A.',
            'gc_group_name' => 'CPF Talisay Official Peer GC',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: CampusMembership}
     */
    private function createCampusUser(
        CpfRole $role,
        string $name,
        MembershipStatus $status = MembershipStatus::Approved,
        ?Campus $campus = null,
        ?string $section = null,
    ): array {
        $campus ??= $this->alijis;

        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('SecretPass2026!'),
            'must_change_password' => false,
            'email_verified_at' => now(),
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
            'last_name' => 'User',
            'full_name_last_first_mi' => $name,
            'course' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Guidance Services' : 'BSIT',
            'year_level' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Faculty' : '3',
            'section' => $section ?? ($role === CpfRole::GuidanceCounselor ? 'Guidance Counselor' : 'A'),
            'course_year_section' => 'BSIT 3A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.cpf',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-555-0001',
            'approved_at' => $status === MembershipStatus::Approved ? now() : null,
        ]);

        return [$user, $membership];
    }

    private function seedSecurityArtifacts(User $user, string $label): void
    {
        $user->createToken("mobile-{$label}");
        DB::table('passkeys')->insert([
            'user_id' => $user->id,
            'name' => "Passkey {$label}",
            'credential_id' => "cred-{$label}-{$user->id}",
            'credential' => json_encode(['id' => "cred-{$label}"]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => "sess-{$label}-{$user->id}",
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['REC-1111'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function test_critical_01_blocks_officer_approval_of_pending_incoming_adviser_and_expires_linked_membership(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$president] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [, $pendingStudent] = $this->createCampusUser(CpfRole::Member, 'Student, Pending A.', MembershipStatus::Pending);

        // Initiate invite_new Adviser Handover -> creates Pending GuidanceCounselor with section = 'Incoming Adviser'
        $initRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'SecretPass2026!',
                'first_name' => 'Incoming',
                'last_name' => 'Adviser',
                'email' => 'incoming.crit01@chmsu.edu.ph',
                'sex' => 'Female',
                'initial_password' => 'IncomingPass2026!',
            ])
            ->assertCreated();

        $handoverId = (int) $initRes->json('handover.id');
        $incomingUser = User::where('email', 'incoming.crit01@chmsu.edu.ph')->firstOrFail();
        $incomingMembership = $incomingUser->primaryMembership;

        // Also test a Pending Member row whose section is 'Incoming Adviser'
        [, $pendingIncomingSectionMember] = $this->createCampusUser(
            CpfRole::Member,
            'Edge, Incoming B.',
            MembershipStatus::Pending,
            $this->alijis,
            'Incoming Adviser',
        );

        // Policy denies addOrApproveMember for Pending Incoming Adviser / Counselor, allows regular Pending Member
        $this->assertFalse(Gate::forUser($president)->allows('addOrApproveMember', $incomingMembership));
        $this->assertFalse(Gate::forUser($adviser)->allows('addOrApproveMember', $incomingMembership));
        $this->assertFalse(Gate::forUser($president)->allows('addOrApproveMember', $pendingIncomingSectionMember));
        $this->assertTrue(Gate::forUser($president)->allows('addOrApproveMember', $pendingStudent));

        // Membership index hides Pending non-Member (GuidanceCounselor) from pendingApplications
        $this->actingAs($president)
            ->get('/membership')
            ->assertOk()
            ->assertInertia(function ($page) use ($incomingMembership, $pendingStudent): void {
                $ids = collect($page->toArray()['props']['pendingApplications'])->pluck('id')->all();
                $this->assertContains($pendingStudent->id, $ids);
                $this->assertNotContains($incomingMembership->id, $ids);
            });

        // PATCH /membership/{id}/approve returns 403 for President & Adviser on Pending Incoming Adviser
        $this->actingAs($president)
            ->patch("/membership/{$incomingMembership->id}/approve")
            ->assertForbidden();
        $this->actingAs($adviser)
            ->patch("/membership/{$incomingMembership->id}/approve")
            ->assertForbidden();
        $this->assertSame(MembershipStatus::Pending, $incomingMembership->fresh()->status);

        // Direct action call aborts 422 for non-Member role
        try {
            app(AddOrApproveCampusMember::class)->approveApplication($president, $incomingMembership);
            $this->fail('Expected 422 HttpException when approving non-Member membership.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // cpf:expire-stale-invites expires stale handover AND marks linked Pending Incoming Adviser membership Rejected
        AdviserHandover::whereKey($handoverId)->update(['expires_at' => now()->subMinute()]);
        $this->artisan('cpf:expire-stale-invites')->assertSuccessful();

        $this->assertSame('expired', AdviserHandover::findOrFail($handoverId)->status);
        $this->assertSame(MembershipStatus::Rejected, $incomingMembership->fresh()->status);
    }

    public function test_critical_02_enforces_conflict_freeze_across_all_surfaces_kills_sessions_and_clears_on_turnover(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$president, $presidentMembership] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$auditor, $auditorMembership] = $this->createCampusUser(CpfRole::Auditor, 'Delgado, Liam C.');
        [$pio, $pioMembership] = $this->createCampusUser(CpfRole::Pio, 'Santos, Bea L.');
        [$member, $memberMembership] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');
        [$pendingMember, $pendingMembership] = $this->createCampusUser(CpfRole::Member, 'New, Applicant C.', MembershipStatus::Pending);

        $channel = OfficerGcChannel::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'channel_key' => "campus.{$this->alijis->id}.officers-gc.2026-2027",
            'is_active' => true,
        ]);

        $room = CampusRoom::create([
            'campus_id' => $this->alijis->id,
            'room_code' => 'TESTING_ROOM',
            'room_name' => 'Testing Room',
            'capacity' => 6,
            'is_single_allowed_room' => true,
        ]);

        $roomRequest = RoomRequest::create([
            'campus_id' => $this->alijis->id,
            'room_id' => $room->id,
            'requested_by_user_id' => $member->id,
            'purpose' => 'Peer facilitation study session',
            'request_date' => now()->addDays(2)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => 'pending',
        ]);

        $event = CampusEvent::create([
            'campus_id' => $this->alijis->id,
            'title' => 'Active Attendance Check',
            'event_date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        // Seed active Sanctum token & DB session for President before suspension
        $president->createToken('PresidentPhone');
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => 'president-active-session',
            'user_id' => $president->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Browser',
            'payload' => base64_encode('session'),
            'last_activity' => time(),
        ]);

        // Adviser suspends President, Auditor, and PIO
        $this->actingAs($adviser)
            ->patchJson("/membership/{$presidentMembership->id}/suspend-privileges", [
                'suspend' => true,
                'reason' => 'Conflict of interest freeze',
            ])
            ->assertOk();

        // Both Sanctum tokens and DB sessions are wiped immediately on suspend
        $this->assertSame(0, $president->fresh()->tokens()->count());
        $this->assertDatabaseMissing(config('session.table', 'sessions'), [
            'id' => 'president-active-session',
        ]);

        $auditorMembership->update([
            'privileges_suspended_at' => now(),
            'suspension_reason' => 'Audit review',
            'suspended_by' => $adviser->id,
        ]);
        $pioMembership->update([
            'privileges_suspended_at' => now(),
            'suspension_reason' => 'Comms freeze',
            'suspended_by' => $adviser->id,
        ]);

        $president->refresh();
        $auditor->refresh();
        $pio->refresh();

        // User helpers enforce suspension
        $this->assertFalse($president->isActiveOfficerOrAdviser());
        $this->assertFalse($president->canProposeEvents());
        $this->assertFalse($auditor->canAuditFinances());
        $this->assertFalse($pio->canPublishAnnouncements());

        // CampusMembershipPolicy enforces suspension
        $this->assertFalse(Gate::forUser($president)->allows('viewRoster', CampusMembership::class));
        $this->assertFalse(Gate::forUser($president)->allows('addOrApproveMember', $pendingMembership));
        $this->assertFalse(Gate::forUser($president)->allows('assignRole', [$memberMembership, CpfRole::Member]));

        // Controllers & routes enforce suspension
        $this->actingAs($president)->get('/officers-chat')->assertForbidden();
        $this->actingAs($president)->getJson("/officers-gc/{$channel->id}/messages")->assertForbidden();
        $this->actingAs($president)->postJson("/officers-gc/{$channel->id}/messages", ['message_body' => 'Blocked'])->assertForbidden();
        $this->actingAs($president)->patchJson("/testing-room/{$roomRequest->id}/review", ['status' => 'approved'])->assertForbidden();
        $this->actingAs($president)->postJson("/events-activities/{$event->id}/manual-attendance", [
            'user_id' => $member->id,
            'scan_type' => 'login',
        ])->assertForbidden();
        $this->actingAs($president)->postJson("/events-activities/{$event->id}/scan", [
            'qr_payload' => 'CPF|campus:ALIJIS|user:1|role:member|sig:0000000000000000',
            'scan_type' => 'login',
        ])->assertForbidden();

        // Quote submitted by suspended officer is NOT auto-approved
        $quoteRes = $this->actingAs($president)
            ->postJson('/quotes', ['quote_text' => 'Take a gentle breath today.'])
            ->assertCreated();
        $this->assertFalse((bool) $quoteRes->json('quote.is_approved'));

        // Mobile API endpoints enforce suspension & expose privileges_suspended
        $freshToken = $president->createToken('PostFreezePhone')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($freshToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('privileges_suspended', true)
            ->assertJsonPath('permissions.is_officer_or_adviser', false)
            ->assertJsonPath('permissions.can_scan_attendance', false)
            ->assertJsonPath('permissions.can_propose_events', false);

        app('auth')->forgetGuards();
        $this->withToken($freshToken)
            ->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('can_scan_events', false)
            ->assertJsonPath('can_scan', false);

        app('auth')->forgetGuards();
        $this->withToken($freshToken)
            ->getJson('/api/v1/membership/roster')
            ->assertForbidden();

        // Broadcast channel callback blocks suspended officer
        $channels = app('Illuminate\Broadcasting\BroadcastManager')->driver()->getChannels();
        $officerChannelCallback = $channels['campus.{campusId}.officers-gc.{academicYear}'] ?? null;
        $this->assertNotNull($officerChannelCallback);
        $this->assertFalse($officerChannelCallback($president, $this->alijis->id, '2026-2027'));

        // Election term turnover clears suspension fields on retained, promoted, and demoted officers
        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2027-2028',
            'title' => 'AY 2027-2028 Turnover Election',
            'status' => 'closed',
            'position_results' => [
                'president' => ['status' => 'elected', 'winner_user_id' => $president->id],
                'vice_president' => ['status' => 'elected', 'winner_user_id' => $auditor->id],
            ],
            'created_by_user_id' => $adviser->id,
        ]);

        app(ClubTenureService::class)->applyElectionTermTurnover($election, $adviser);

        foreach ([$presidentMembership, $auditorMembership, $pioMembership] as $m) {
            $fresh = $m->fresh();
            $this->assertNull($fresh->privileges_suspended_at);
            $this->assertNull($fresh->suspension_reason);
            $this->assertNull($fresh->suspended_by);
        }
    }

    public function test_high_01_migration_000023_and_atomic_idempotent_stale_referral_and_event_reminder_commands(): void
    {
        // Migration 000023 references preferred_end_time (not non-existent counselor_confirmed_at)
        $migrationSql = file_get_contents(
            database_path('migrations/2026_11_26_000023_add_self_assessment_governance_and_counselor_dm.php'),
        );
        $this->assertIsString($migrationSql);
        $this->assertStringContainsString("->after('preferred_end_time')", $migrationSql);
        $this->assertStringNotContainsString("->after('counselor_confirmed_at')", $migrationSql);

        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Soriano, Clarissa D.');
        [$member] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        // 1. Stale unconfirmed emergency referral (should alert)
        $staleEmergency = GuidanceReferral::create([
            'campus_id' => $this->alijis->id,
            'submitted_by_user_id' => $member->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Student Emergency',
            'suggested_priority' => ReferralPriority::Emergency,
            'triage_response_target' => ReferralPriority::Emergency->responseTarget(),
            'encrypted_concern_summary' => 'Needs immediate guidance support.',
            'status' => 'submitted',
            'feedback_status' => 'pending',
        ]);
        DB::table('guidance_referrals')->where('id', $staleEmergency->id)->update(['created_at' => now()->subHours(30)]);

        // 2. Stale referral where suggested was routine, counselor confirmed very_urgent (should alert)
        $staleConfirmedVeryUrgent = GuidanceReferral::create([
            'campus_id' => $this->alijis->id,
            'submitted_by_user_id' => $member->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Student Escalated',
            'suggested_priority' => ReferralPriority::Routine,
            'counselor_confirmed_priority' => ReferralPriority::VeryUrgent,
            'triage_response_target' => ReferralPriority::VeryUrgent->responseTarget(),
            'encrypted_concern_summary' => 'Escalated by counselor.',
            'status' => 'submitted',
            'feedback_status' => 'pending',
        ]);
        DB::table('guidance_referrals')->where('id', $staleConfirmedVeryUrgent->id)->update(['created_at' => now()->subHours(26)]);

        // 3. Stale referral downgraded by counselor from urgent -> routine (should NOT alert)
        $downgradedRoutine = GuidanceReferral::create([
            'campus_id' => $this->alijis->id,
            'submitted_by_user_id' => $member->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Student Downgraded',
            'suggested_priority' => ReferralPriority::Urgent,
            'counselor_confirmed_priority' => ReferralPriority::Routine,
            'triage_response_target' => ReferralPriority::Routine->responseTarget(),
            'encrypted_concern_summary' => 'Downgraded to routine.',
            'status' => 'submitted',
            'feedback_status' => 'pending',
        ]);
        DB::table('guidance_referrals')->where('id', $downgradedRoutine->id)->update(['created_at' => now()->subHours(30)]);

        // Run SLA alert command twice -> alerts exactly once per qualifying referral (2 referrals x 2 recipients = 4 rows)
        $this->artisan('cpf:alert-stale-referrals')->assertSuccessful();
        $this->artisan('cpf:alert-stale-referrals')->assertSuccessful();

        $this->assertNotNull($staleEmergency->fresh()->sla_alerted_at);
        $this->assertNotNull($staleConfirmedVeryUrgent->fresh()->sla_alerted_at);
        $this->assertNull($downgradedRoutine->fresh()->sla_alerted_at);
        $this->assertSame(
            4,
            CampusEventNotification::where('type', 'stale_referral_sla_alert')->count(),
        );

        // Upcoming event within 24 hours -> reminder sent once (Adviser + Member = 2, excluding GuidanceCounselor)
        $upcomingEvent = CampusEvent::create([
            'campus_id' => $this->alijis->id,
            'title' => 'Peer Listening Workshop',
            'event_date' => now()->addHours(12)->toDateString(),
            'start_time' => '13:00',
            'end_time' => '15:00',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        $this->artisan('cpf:remind-upcoming-events')->assertSuccessful();
        $this->artisan('cpf:remind-upcoming-events')->assertSuccessful();

        $this->assertNotNull($upcomingEvent->fresh()->reminder_sent_at);
        $this->assertSame(
            2,
            CampusEventNotification::where('type', 'event_upcoming_reminder')
                ->where('campus_event_id', $upcomingEvent->id)
                ->count(),
        );
        $this->assertDatabaseMissing('campus_event_notifications', [
            'type' => 'event_upcoming_reminder',
            'user_id' => $counselor->id,
        ]);
    }

    public function test_high_02_profile_email_sync_adviser_lockout_guard_change_request_sync_and_reused_account_wipe(): void
    {
        [$adviser, $adviserMembership] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$president] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$member, $memberMembership] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        // 1. Non-Adviser CPF membership holders receive 403 on direct PATCH /settings/profile
        foreach ([$president, $member] as $nonAdviser) {
            $this->actingAs($nonAdviser)
                ->patch(route('profile.update'), [
                    'name' => 'Unauthorized Rename',
                    'email' => "bypass.{$nonAdviser->id}@chmsu.edu.ph",
                ])
                ->assertForbidden();
        }

        // 2. Club Adviser email update syncs campus_memberships.active_email and preserves email_verified_at in log/array mode
        config(['mail.default' => 'log']);
        $this->actingAs($adviser)
            ->patch(route('profile.update'), [
                'name' => 'Montemayor, Elena V.',
                'email' => 'elena.adviser.updated@chmsu.edu.ph',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('elena.adviser.updated@chmsu.edu.ph', $adviser->fresh()->email);
        $this->assertSame('elena.adviser.updated@chmsu.edu.ph', $adviserMembership->fresh()->active_email);
        $this->assertNotNull($adviser->fresh()->email_verified_at);

        // When mailer is smtp, Adviser email change nulls email_verified_at as expected
        config(['mail.default' => 'smtp']);
        $this->actingAs($adviser)
            ->patch(route('profile.update'), [
                'name' => 'Montemayor, Elena V.',
                'email' => 'elena.adviser.smtp@chmsu.edu.ph',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertNull($adviser->fresh()->email_verified_at);
        $this->assertSame('elena.adviser.smtp@chmsu.edu.ph', $adviserMembership->fresh()->active_email);
        $adviser->forceFill(['email_verified_at' => now()])->save();

        // 3. reviewChangeRequest rejects duplicate user email (422) and syncs users.email when unique
        $duplicateReq = MemberInfoChangeRequest::create([
            'campus_membership_id' => $memberMembership->id,
            'campus_id' => $this->alijis->id,
            'user_id' => $member->id,
            'requested_changes' => [
                'active_email' => ['old' => $member->email, 'new' => $president->email],
            ],
            'reason' => 'Trying to claim another member email',
            'status' => 'pending',
        ]);

        $this->actingAs($adviser)
            ->patchJson("/membership/change-requests/{$duplicateReq->id}/review", ['decision' => 'approved'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active_email');

        $validReq = MemberInfoChangeRequest::create([
            'campus_membership_id' => $memberMembership->id,
            'campus_id' => $this->alijis->id,
            'user_id' => $member->id,
            'requested_changes' => [
                'active_email' => ['old' => $member->email, 'new' => 'katrina.synced@chmsu.edu.ph'],
            ],
            'reason' => 'Switched to official CHMSU email',
            'status' => 'pending',
        ]);

        $this->actingAs($adviser)
            ->patch("/membership/change-requests/{$validReq->id}/review", ['decision' => 'approved'])
            ->assertRedirect('/membership');

        $this->assertSame('katrina.synced@chmsu.edu.ph', $member->fresh()->email);
        $this->assertSame('katrina.synced@chmsu.edu.ph', $memberMembership->fresh()->active_email);

        // 4. AdviserHandoverController::store (invite_new): email_verified_at = null until accepted, code hidden unless mail.default === 'log', and reused account wiped
        [$reusedHandoverUser] = $this->createCampusUser(CpfRole::Member, 'Rejected, Handover A.', MembershipStatus::Rejected);
        $this->seedSecurityArtifacts($reusedHandoverUser, 'handover');

        config(['mail.default' => 'smtp']);
        $handoverRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'SecretPass2026!',
                'first_name' => 'Reused',
                'last_name' => 'Incoming',
                'email' => $reusedHandoverUser->email,
                'sex' => 'Female',
                'initial_password' => 'HandoverTemp2026!',
            ])
            ->assertCreated()
            ->assertJsonMissingPath('handover.confirmation_code');

        $reusedHandoverUser->refresh();
        $this->assertNull($reusedHandoverUser->email_verified_at);
        $this->assertSame(0, $reusedHandoverUser->tokens()->count());
        $this->assertSame(0, $reusedHandoverUser->passkeys()->count());
        $this->assertNull($reusedHandoverUser->two_factor_secret);
        $this->assertNull($reusedHandoverUser->two_factor_recovery_codes);
        $this->assertNull($reusedHandoverUser->two_factor_confirmed_at);
        $this->assertDatabaseMissing(config('session.table', 'sessions'), ['user_id' => $reusedHandoverUser->id]);

        // When mail.default === 'log', invite_new exposes confirmation_code in-app
        config(['mail.default' => 'log']);
        $logHandoverRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'SecretPass2026!',
                'first_name' => 'LogMode',
                'last_name' => 'Incoming',
                'email' => 'logmode.incoming@chmsu.edu.ph',
                'sex' => 'Female',
                'initial_password' => 'LogModeTemp2026!',
            ])
            ->assertCreated();
        $this->assertNotEmpty($logHandoverRes->json('handover.confirmation_code'));

        $logIncoming = User::where('email', 'logmode.incoming@chmsu.edu.ph')->firstOrFail();
        $this->assertNull($logIncoming->email_verified_at);

        // Accepting handover sets email_verified_at
        $logIncoming->forceFill(['must_change_password' => false])->save();
        $this->actingAs($logIncoming)
            ->postJson('/adviser-handover/'.$logHandoverRes->json('handover.id').'/respond', [
                'decision' => 'accepted',
                'confirmation_code' => $logHandoverRes->json('handover.confirmation_code'),
            ])
            ->assertOk();
        $this->assertNotNull($logIncoming->fresh()->email_verified_at);

        // 5. CounselorInvitationController::accept wipes reused rejected user's security artifacts
        [$reusedCounselorUser] = $this->createCampusUser(CpfRole::Member, 'Rejected, Counselor B.', MembershipStatus::Rejected);
        $this->seedSecurityArtifacts($reusedCounselorUser, 'counselor');

        $invitation = CounselorInvitation::create([
            'campus_id' => $this->alijis->id,
            'invited_by_user_id' => $logIncoming->id,
            'email' => $reusedCounselorUser->email,
            'counselor_name' => 'Counselor, Reused',
            'token' => 'test-counselor-wipe-token-12345678901234567890',
            'otp_hash' => Hash::make('123456'),
            'failed_attempts' => 0,
            'expires_at' => now()->addHours(12),
        ]);

        $acceptUrl = URL::temporarySignedRoute(
            'counselor-invitations.accept',
            $invitation->expires_at,
            ['token' => $invitation->token],
        );

        auth()->logout();
        $this->postJson($acceptUrl, [
            'otp_code' => '123456',
            'first_name' => 'Reused',
            'last_name' => 'Counselor',
            'sex' => 'Female',
            'password' => 'NewCounselor2026!',
            'password_confirmation' => 'NewCounselor2026!',
        ])->assertCreated();

        $reusedCounselorUser->refresh();
        $this->assertSame(0, $reusedCounselorUser->tokens()->count());
        $this->assertSame(0, $reusedCounselorUser->passkeys()->count());
        $this->assertNull($reusedCounselorUser->two_factor_secret);
        $this->assertNull($reusedCounselorUser->two_factor_recovery_codes);
        $this->assertNull($reusedCounselorUser->two_factor_confirmed_at);
        $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => "sess-counselor-{$reusedCounselorUser->id}"]);

        // 6. AddOrApproveCampusMember::addDirectly wipes reused rejected user's security artifacts
        [$reusedMemberUser] = $this->createCampusUser(CpfRole::Member, 'Rejected, Member C.', MembershipStatus::Rejected);
        $this->seedSecurityArtifacts($reusedMemberUser, 'member');

        $this->actingAs($logIncoming->fresh())
            ->post('/membership/officer-add', [
                'first_name' => 'Readded',
                'last_name' => 'Member',
                'course' => 'BSIT',
                'year_level' => '2',
                'section' => 'B',
                'sex' => 'Female',
                'birthdate' => '2005-02-14',
                'active_email' => $reusedMemberUser->email,
                'facebook_profile_url' => 'https://www.facebook.com/readded.member',
                'cellphone_number' => '0917-555-8888',
            ])
            ->assertRedirect(route('membership.index'));

        $reusedMemberUser->refresh();
        $this->assertSame(0, $reusedMemberUser->tokens()->count());
        $this->assertSame(0, $reusedMemberUser->passkeys()->count());
        $this->assertNull($reusedMemberUser->two_factor_secret);
        $this->assertNull($reusedMemberUser->two_factor_recovery_codes);
        $this->assertNull($reusedMemberUser->two_factor_confirmed_at);
        $this->assertDatabaseMissing(config('session.table', 'sessions'), ['id' => "sess-member-{$reusedMemberUser->id}"]);
    }

    public function test_high_03_officer_add_protects_alumni_memberships_from_overwrite(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$alumniUser, $alumniMembership] = $this->createCampusUser(
            CpfRole::President,
            'Former, Alumni President',
            MembershipStatus::Alumni,
        );

        $this->actingAs($adviser)
            ->postJson('/membership/officer-add', [
                'first_name' => 'Overwrite',
                'last_name' => 'Attempt',
                'course' => 'BSIT',
                'year_level' => '4',
                'section' => 'A',
                'sex' => 'Male',
                'birthdate' => '2003-04-10',
                'active_email' => $alumniUser->email,
                'facebook_profile_url' => 'https://www.facebook.com/overwrite.alumni',
                'cellphone_number' => '0917-555-7777',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active_email');

        $fresh = $alumniMembership->fresh();
        $this->assertSame(MembershipStatus::Alumni, $fresh->status);
        $this->assertSame(CpfRole::President, $fresh->role);
        $this->assertSame('Former, Alumni President', $fresh->full_name_last_first_mi);
    }

    public function test_high_04_toggle_dues_validates_payment_submission_and_manual_reason_on_web_and_api(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$treasurer] = $this->createCampusUser(CpfRole::Treasurer, 'Villanueva, Marco T.');
        [$auditor] = $this->createCampusUser(CpfRole::Auditor, 'Delgado, Liam C.');
        [$memberA, $membershipA] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');
        [$memberB] = $this->createCampusUser(CpfRole::Member, 'Santos, Carla P.');
        [$talisayMember] = $this->createCampusUser(CpfRole::Member, 'Cruz, Other Campus', MembershipStatus::Approved, $this->talisay);

        $destAlijis = PaymentDestination::create([
            'campus_id' => $this->alijis->id,
            'channel' => 'GCash',
            'account_name' => 'CPF Alijis',
            'account_number' => '0917-111-1111',
            'is_active' => true,
            'approved_by_adviser_id' => $adviser->id,
        ]);
        $destTalisay = PaymentDestination::create([
            'campus_id' => $this->talisay->id,
            'channel' => 'GCash',
            'account_name' => 'CPF Talisay',
            'account_number' => '0917-222-2222',
            'is_active' => true,
            'approved_by_adviser_id' => $adviser->id,
        ]);

        $pendingSub = PaymentSubmission::create([
            'campus_id' => $this->alijis->id,
            'user_id' => $memberA->id,
            'destination_id' => $destAlijis->id,
            'purpose' => 'Membership Dues',
            'amount' => 150,
            'reference_number' => 'PEND-001',
            'receipt_hdd_path' => 'receipts/campus_1/pending.pdf',
            'status' => 'pending',
        ]);
        $otherUserValidatedSub = PaymentSubmission::create([
            'campus_id' => $this->alijis->id,
            'user_id' => $memberB->id,
            'destination_id' => $destAlijis->id,
            'purpose' => 'Membership Dues',
            'amount' => 150,
            'reference_number' => 'OTHER-002',
            'receipt_hdd_path' => 'receipts/campus_1/other.pdf',
            'status' => 'validated',
        ]);
        $otherCampusValidatedSub = PaymentSubmission::create([
            'campus_id' => $this->talisay->id,
            'user_id' => $talisayMember->id,
            'destination_id' => $destTalisay->id,
            'purpose' => 'Membership Dues',
            'amount' => 150,
            'reference_number' => 'CAMPUS-003',
            'receipt_hdd_path' => 'receipts/campus_2/other.pdf',
            'status' => 'validated',
        ]);
        $validReceiptBackedSub = PaymentSubmission::create([
            'campus_id' => $this->alijis->id,
            'user_id' => $memberA->id,
            'destination_id' => $destAlijis->id,
            'purpose' => 'Membership Dues',
            'amount' => 150,
            'reference_number' => 'VALID-004',
            'receipt_hdd_path' => 'receipts/campus_1/valid.pdf',
            'status' => 'validated',
        ]);
        $validNoReceiptSub = PaymentSubmission::create([
            'campus_id' => $this->alijis->id,
            'user_id' => $memberA->id,
            'destination_id' => $destAlijis->id,
            'purpose' => 'Cash Dues',
            'amount' => 150,
            'reference_number' => 'CASH-005',
            'receipt_hdd_path' => null,
            'status' => 'validated',
        ]);

        // 1. Missing or <5 char reason without payment_submission_id aborts 422 on Web & API
        $this->actingAs($treasurer)
            ->patchJson("/membership/{$membershipA->id}/toggle-dues", ['reason' => 'ok'])
            ->assertStatus(422);

        $treasurerToken = $treasurer->createToken('TreasurerPhone')->plainTextToken;
        app('auth')->forgetGuards();
        $this->withToken($treasurerToken)
            ->patchJson("/api/v1/membership/{$membershipA->id}/toggle-dues", ['reason' => '1234'])
            ->assertStatus(422);

        // 2. Invalid payment_submission_id (pending, another user, another campus) aborts 422
        foreach ([$pendingSub->id, $otherUserValidatedSub->id, $otherCampusValidatedSub->id] as $badSubId) {
            $this->actingAs($treasurer)
                ->patchJson("/membership/{$membershipA->id}/toggle-dues", [
                    'payment_submission_id' => $badSubId,
                ])
                ->assertStatus(422);
        }

        // 3. Valid receipt-backed validated submission succeeds with NO manual override alert
        app('auth')->forgetGuards();
        $this->withToken($treasurerToken)
            ->patchJson("/api/v1/membership/{$membershipA->id}/toggle-dues", [
                'payment_submission_id' => $validReceiptBackedSub->id,
            ])
            ->assertOk()
            ->assertJsonPath('has_paid_dues', true);

        $this->assertSame(0, CampusEventNotification::where('type', 'dues_manual_override_alert')->count());

        // Toggle back off with manual reason (newState = false -> no alert)
        $this->actingAs($treasurer)
            ->patch("/membership/{$membershipA->id}/toggle-dues", [
                'reason' => 'Reverting dues for receipt test',
            ])
            ->assertRedirect();
        $this->assertFalse((bool) $membershipA->fresh()->has_paid_dues);
        $this->assertSame(0, CampusEventNotification::where('type', 'dues_manual_override_alert')->count());

        // 4. Validated submission WITHOUT receipt triggers Adviser + Auditor alert (2 rows)
        $this->actingAs($treasurer)
            ->patch("/membership/{$membershipA->id}/toggle-dues", [
                'payment_submission_id' => $validNoReceiptSub->id,
            ])
            ->assertRedirect();
        $this->assertTrue((bool) $membershipA->fresh()->has_paid_dues);
        $this->assertSame(2, CampusEventNotification::where('type', 'dues_manual_override_alert')->count());
        $this->assertDatabaseHas('campus_event_notifications', [
            'type' => 'dues_manual_override_alert',
            'user_id' => $adviser->id,
        ]);
        $this->assertDatabaseHas('campus_event_notifications', [
            'type' => 'dues_manual_override_alert',
            'user_id' => $auditor->id,
        ]);
    }
}
