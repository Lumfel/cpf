<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusEvent;
use App\Models\CampusEventNotification;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\ClubServiceTenure;
use App\Models\DuesAuditLog;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\PaymentDestination;
use App\Models\PaymentSubmission;
use App\Models\User;
use App\Notifications\CounselorInviteOtpNotification;
use App\Services\ClubTenureService;
use Carbon\CarbonInterface;
use Database\Seeders\BlankSystemSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Wave2And3FixesTest extends TestCase
{
    use RefreshDatabase;

    private function setupCampus(string $code = 'ALIJIS', string $name = 'CHMSU Alijis Campus'): Campus
    {
        $campus = Campus::create([
            'code' => $code,
            'name' => $name,
            'slug' => strtolower($code),
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Montemayor, Elena V.',
            'gc_group_name' => "CPF {$code} Official Peer GC",
            'is_active' => true,
        ]);

        CampusRoom::create([
            'campus_id' => $campus->id,
            'room_code' => 'TESTING_ROOM',
            'room_name' => 'Testing Room',
            'capacity' => 6,
            'is_single_allowed_room' => true,
        ]);

        return $campus;
    }

    /**
     * @return array{0: User, 1: CampusMembership}
     */
    private function createCampusUser(
        Campus $campus,
        CpfRole $role,
        string $name,
        string $yearLevel = '4',
    ): array {
        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('SecretPass2026!'),
            'must_change_password' => false,
        ]);

        $membership = CampusMembership::create([
            'user_id' => $user->id,
            'campus_id' => $campus->id,
            'role' => $role,
            'status' => MembershipStatus::Approved,
            'enrollment_source' => 'officer_added',
            'recorded_email' => $user->email,
            'first_name' => 'Sample',
            'middle_name' => 'A.',
            'last_name' => 'User',
            'full_name_last_first_mi' => $name,
            'course' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Guidance Services' : 'BSIT',
            'year_level' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Faculty' : $yearLevel,
            'section' => $role === CpfRole::GuidanceCounselor ? 'Guidance Counselor' : 'A',
            'course_year_section' => "BSIT {$yearLevel}A",
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.user',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-555-0001',
            'approved_at' => now()->subMonth(),
            'has_paid_dues' => false,
        ]);

        app(ClubTenureService::class)->openTenure($membership, $user, $membership->approved_at);

        return [$user, $membership];
    }

    public function test_h04_validate_payment_updates_dues_writes_audit_log_streams_private_receipt_and_surfaces_dashboard_queue(): void
    {
        Storage::fake('local');

        $campusA = $this->setupCampus('ALIJIS', 'CHMSU Alijis Campus');
        $campusB = $this->setupCampus('TALISAY', 'CHMSU Talisay Campus');

        [$adviser] = $this->createCampusUser($campusA, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$treasurer] = $this->createCampusUser($campusA, CpfRole::Treasurer, 'Delgado, Liam C.');
        [$auditor] = $this->createCampusUser($campusA, CpfRole::Auditor, 'Castillo, Marco D.');
        [$member, $memberMembership] = $this->createCampusUser($campusA, CpfRole::Member, 'Reyes, Katrina M.');
        [$otherMember] = $this->createCampusUser($campusA, CpfRole::Member, 'Santos, Paolo C.');
        [$otherCampusAuditor] = $this->createCampusUser($campusB, CpfRole::Auditor, 'Cruz, Angelo D.');

        $dest = PaymentDestination::create([
            'campus_id' => $campusA->id,
            'channel' => 'GCash',
            'account_name' => 'CPF Alijis',
            'account_number' => '09170001111',
            'is_active' => true,
            'approved_by_adviser_id' => $adviser->id,
        ]);

        $submitRes = $this->actingAs($member)
            ->post('/payments/submit', [
                'destination_id' => $dest->id,
                'purpose' => 'AY 2026-2027 Chapter Dues',
                'amount' => 150,
                'reference_number' => 'GCASH-H04-001',
                'receipt' => UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 fake-receipt-bytes'),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $paymentId = (int) $submitRes->json('payment.id');
        $payment = PaymentSubmission::findOrFail($paymentId);

        // Dashboard surfaces paymentValidationQueue with private receipt_url for Treasurer, Auditor, and Adviser
        foreach ([$treasurer, $auditor, $adviser] as $oversightUser) {
            $this->actingAs($oversightUser)
                ->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Dashboard')
                    ->has('paymentValidationQueue', 1)
                    ->where('paymentValidationQueue.0.id', $paymentId)
                    ->where('paymentValidationQueue.0.receipt_url', "/payments/{$paymentId}/receipt"));
        }

        // Private receipt streaming: submitter + same-campus financial auditors allowed; other member & cross-campus auditor blocked (403)
        $this->actingAs($member)->get("/payments/{$paymentId}/receipt")->assertOk();
        $this->actingAs($auditor)->get("/payments/{$paymentId}/receipt")->assertOk();
        $this->actingAs($treasurer)->get("/payments/{$paymentId}/receipt")->assertOk();
        $this->actingAs($otherMember)->get("/payments/{$paymentId}/receipt")->assertForbidden();
        $this->actingAs($otherCampusAuditor)->get("/payments/{$paymentId}/receipt")->assertForbidden();

        // Mobile API v1 receipt streaming enforces identical rules
        $memberToken = $member->createToken('Member')->plainTextToken;
        $otherToken = $otherMember->createToken('Other')->plainTextToken;

        app('auth')->forgetGuards();
        $this->withToken($memberToken)->get("/api/v1/payments/{$paymentId}/receipt")->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($otherToken)->get("/api/v1/payments/{$paymentId}/receipt")->assertForbidden();

        // Missing file returns 404
        $noFilePayment = PaymentSubmission::create([
            'campus_id' => $campusA->id,
            'user_id' => $member->id,
            'destination_id' => $dest->id,
            'purpose' => 'Missing receipt test',
            'amount' => 100,
            'reference_number' => 'GCASH-MISSING',
            'receipt_hdd_path' => 'receipts/nonexistent.pdf',
            'status' => 'pending',
        ]);
        $this->actingAs($member)->get("/payments/{$noFilePayment->id}/receipt")->assertNotFound();

        // Validating pending payment updates membership dues flags & creates DuesAuditLog atomically
        $this->actingAs($treasurer)
            ->patchJson("/payments/{$paymentId}/validate", [
                'status' => 'validated',
                'treasurer_notes' => 'Verified on GCash statement.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'validated');

        $memberMembership->refresh();
        $this->assertTrue($memberMembership->has_paid_dues);
        $this->assertSame($treasurer->id, $memberMembership->dues_confirmed_by);
        $this->assertNotNull($memberMembership->dues_confirmed_at);

        $auditLog = DuesAuditLog::where('payment_submission_id', $payment->id)->first();
        $this->assertNotNull($auditLog);
        $this->assertSame($treasurer->id, $auditLog->actor_user_id);
        $this->assertSame($member->id, $auditLog->target_user_id);
        $this->assertFalse($auditLog->old_status);
        $this->assertTrue($auditLog->new_status);

        // Re-validating an already validated payment is rejected with 422
        $this->actingAs($treasurer)
            ->patchJson("/payments/{$paymentId}/validate", ['status' => 'rejected'])
            ->assertStatus(422);
    }

    public function test_h07_and_m02_mobile_election_completed_slate_parity_endpoints_and_atomic_live_chat_lock(): void
    {
        $campus = $this->setupCampus();
        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$memberA] = $this->createCampusUser($campus, CpfRole::Member, 'Mercado, Sofia R.');
        [$memberB] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.');

        $adviserToken = $adviser->createToken('Adviser')->plainTextToken;
        $tokenA = $memberA->createToken('MemberA')->plainTextToken;
        $tokenB = $memberB->createToken('MemberB')->plainTextToken;

        $election = Election::create([
            'campus_id' => $campus->id,
            'academic_year' => '2026-2027',
            'title' => 'Mobile Parity General Election',
            'status' => 'open',
            'current_position' => 'fourth_year_rep',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [
                'president' => [
                    'status' => 'elected',
                    'winner_user_id' => $memberA->id,
                    'winner_name' => 'Mercado, Sofia R.',
                    'votes' => 12,
                    'won_by' => 'vote',
                ],
            ],
            'created_by_user_id' => $adviser->id,
        ]);

        // M-02: Ephemeral live chat uses atomic cache lock and preserves sequential appends
        $cache = Election::liveChatCache();
        $cache->forget(Election::liveChatCacheKey($election->id));

        app('auth')->forgetGuards();
        $this->withToken($tokenA)
            ->postJson("/api/v1/elections/{$election->id}/live-chat", ['message_body' => 'First locked message'])
            ->assertCreated()
            ->assertJsonPath('messages_count', 1);

        app('auth')->forgetGuards();
        $this->withToken($tokenB)
            ->postJson("/api/v1/elections/{$election->id}/live-chat", ['message_body' => 'Second locked message'])
            ->assertCreated()
            ->assertJsonPath('messages_count', 2);

        // H-07: Pending nominee responds via PATCH /api/v1/elections/{election}/candidates/{candidate}/respond
        $pendingCand = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $memberB->id,
            'position' => 'fourth_year_rep',
            'nominated_by_user_id' => $memberA->id,
            'nomination_status' => 'pending',
        ]);

        app('auth')->forgetGuards();
        $this->withToken($tokenB)
            ->patchJson("/api/v1/elections/{$election->id}/candidates/{$pendingCand->id}/respond", [
                'decision' => 'accepted',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        // Move to voting, cast vote, and Adviser finalizes via POST /api/v1/elections/{election}/finalize-position
        $election->update(['current_phase' => 'voting']);

        app('auth')->forgetGuards();
        $this->withToken($tokenA)
            ->postJson("/api/v1/elections/{$election->id}/vote", [
                'candidate_id' => $pendingCand->id,
            ])
            ->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($adviserToken)
            ->postJson("/api/v1/elections/{$election->id}/finalize-position")
            ->assertOk()
            ->assertJsonPath('status', 'position_finalized')
            ->assertJsonPath('election.current_phase', 'completed');

        // H-07: With no active open election, GET /api/v1/elections/active returns completed_election + populated seats[*].result
        app('auth')->forgetGuards();
        $this->withToken($tokenA)
            ->getJson('/api/v1/elections/active')
            ->assertOk()
            ->assertJsonPath('active_election', null)
            ->assertJsonPath('completed_election.id', $election->id)
            ->assertJsonPath('completed_election.current_phase', 'completed')
            ->assertJsonPath('seats.0.key', 'president')
            ->assertJsonPath('seats.0.result.winner_user_id', $memberA->id)
            ->assertJsonPath('seats.11.key', 'fourth_year_rep')
            ->assertJsonPath('seats.11.result.winner_user_id', $memberB->id);
    }

    public function test_m03_and_l04_unify_counselor_slots_canonical_schema_operating_hours_120_min_cap_and_one_pending_per_day(): void
    {
        Notification::fake();

        $campus = $this->setupCampus();
        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$student] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.');

        // 1. M-03: Invited counselor stores canonical 'days' arrays instead of 'day' strings
        $this->actingAs($adviser)
            ->postJson('/counselor-invitations', ['email' => 'canonical.rgc@chmsu.edu.ph'])
            ->assertCreated();

        $inviteUrl = '';
        $otpCode = '';
        Notification::assertSentOnDemand(
            CounselorInviteOtpNotification::class,
            function (CounselorInviteOtpNotification $n, array $channels, object $notifiable) use (&$inviteUrl, &$otpCode): bool {
                if (($notifiable->routes['mail'] ?? null) !== 'canonical.rgc@chmsu.edu.ph') {
                    return false;
                }
                $inviteUrl = $n->inviteUrl;
                $otpCode = $n->otpCode;

                return true;
            },
        );

        $acceptUrl = (string) $this->get($inviteUrl)->assertOk()->viewData('page')['props']['acceptUrl'];
        auth()->logout();

        $this->postJson($acceptUrl, [
            'otp_code' => $otpCode,
            'first_name' => 'Clarissa',
            'last_name' => 'Soriano',
            'sex' => 'Female',
            'password' => 'CounselorPass2026!',
            'password_confirmation' => 'CounselorPass2026!',
        ])->assertCreated();

        $invitedCounselor = CampusCounselor::where('campus_id', $campus->id)
            ->where('name', 'Soriano, Clarissa')
            ->firstOrFail();

        $this->assertSame([
            ['start' => '09:00', 'end' => '11:30', 'label' => '9:00 AM – 11:30 AM', 'days' => [1, 5]],
            ['start' => '13:30', 'end' => '16:00', 'label' => '1:30 PM – 4:00 PM', 'days' => [3]],
        ], $invitedCounselor->weekly_vacant_slots);

        $nextSaturday = now()->next(CarbonInterface::SATURDAY)->format('Y-m-d');
        $nextTuesday = now()->next(CarbonInterface::TUESDAY)->format('Y-m-d');
        $nextMonday = now()->next(CarbonInterface::MONDAY)->format('Y-m-d');

        // 2. L-04: Weekend booking rejected (422)
        $this->actingAs($student)->postJson('/testing-room/request', [
            'campus_id' => $campus->id,
            'purpose' => 'Weekend attempt',
            'request_date' => $nextSaturday,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertStatus(422);

        // 3. L-04: Outside 08:00-17:00 operating hours rejected (422)
        $this->actingAs($student)->postJson('/testing-room/request', [
            'campus_id' => $campus->id,
            'purpose' => 'Too late attempt',
            'request_date' => $nextMonday,
            'start_time' => '16:30',
            'end_time' => '17:30',
        ])->assertStatus(422);

        // 4. L-04: > 120-minute duration rejected (422) on both testing-room and referrals
        $this->actingAs($student)->postJson('/testing-room/request', [
            'campus_id' => $campus->id,
            'purpose' => 'Too long booking',
            'request_date' => $nextMonday,
            'start_time' => '09:00',
            'end_time' => '11:30',
        ])->assertStatus(422);

        // 5. M-03: Outside counselor's canonical Mon/Fri & Wed schedule (Tuesday!) rejected on BOTH Web testing-room & Web referral
        $this->actingAs($student)->postJson('/testing-room/request', [
            'campus_id' => $campus->id,
            'counselor_id' => $invitedCounselor->id,
            'purpose' => 'Tuesday mismatch',
            'request_date' => $nextTuesday,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertStatus(422);

        $this->actingAs($student)->postJson('/referrals/submit', [
            'campus_id' => $campus->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Student X',
            'suggested_priority' => ReferralPriority::Routine->value,
            'concern_summary' => 'Needs counseling.',
            'counselor_id' => $invitedCounselor->id,
            'preferred_date' => $nextTuesday,
            'preferred_start_time' => '09:00',
            'preferred_end_time' => '10:00',
        ])->assertStatus(422);

        // 6. Valid Monday 09:00-10:30 booking (90 min, within Mon 09:00-11:30) succeeds
        $this->actingAs($student)->postJson('/testing-room/request', [
            'campus_id' => $campus->id,
            'counselor_id' => $invitedCounselor->id,
            'purpose' => 'Valid Monday counseling',
            'request_date' => $nextMonday,
            'start_time' => '09:00',
            'end_time' => '10:30',
        ])->assertCreated();

        // 7. L-04: Second active pending booking by same student on the SAME day is rejected (422) even in a non-overlapping slot
        $this->actingAs($student)->postJson('/testing-room/request', [
            'campus_id' => $campus->id,
            'purpose' => 'Second pending booking same day',
            'request_date' => $nextMonday,
            'start_time' => '14:00',
            'end_time' => '15:00',
        ])->assertStatus(422);
    }

    public function test_m06_atomic_event_lifecycle_sync_prevents_duplicate_notifications_and_excludes_counselors(): void
    {
        $campus = $this->setupCampus();
        [$adviser] = $this->createCampusUser($campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor] = $this->createCampusUser($campus, CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$member] = $this->createCampusUser($campus, CpfRole::Member, 'Reyes, Katrina M.');

        $eventDate = now()->addDays(2)->toDateString();
        $event = CampusEvent::create([
            'campus_id' => $campus->id,
            'title' => 'Atomic Lifecycle Assembly',
            'event_date' => $eventDate,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        // Two stale model instances (simulating concurrent GET/cron requests) both seeing activated_at === null
        $staleA = CampusEvent::findOrFail($event->id);
        $staleB = CampusEvent::findOrFail($event->id);

        $activeTime = Carbon::parse($eventDate.' 09:30:00');
        $staleA->syncLifecycleTimestamps($activeTime);
        $staleB->syncLifecycleTimestamps($activeTime);

        // Exactly 1 event_activated notification per eligible member/adviser, and ZERO for GuidanceCounselor
        $this->assertSame(1, CampusEventNotification::where('user_id', $member->id)->where('type', 'event_activated')->count());
        $this->assertSame(1, CampusEventNotification::where('user_id', $adviser->id)->where('type', 'event_activated')->count());
        $this->assertSame(0, CampusEventNotification::where('user_id', $counselor->id)->count());

        // Same atomic protection on completion
        $staleC = CampusEvent::findOrFail($event->id);
        $staleD = CampusEvent::findOrFail($event->id);
        $completedTime = Carbon::parse($eventDate.' 12:00:00');

        $staleC->syncLifecycleTimestamps($completedTime);
        $staleD->syncLifecycleTimestamps($completedTime);

        $this->assertSame(1, CampusEventNotification::where('user_id', $member->id)->where('type', 'event_completed')->count());
        $this->assertSame(0, CampusEventNotification::where('user_id', $counselor->id)->count());
    }

    public function test_l03_removes_fictional_counselors_from_blank_setup_registers_real_advisers_and_credits_year_level_reps(): void
    {
        // 1. Blank setup does NOT create fictional demo counselors; First-Boot registers only the real Adviser
        $this->seed(BlankSystemSeeder::class);
        $campus = Campus::where('code', 'ALIJIS')->firstOrFail();

        $this->assertSame(0, CampusCounselor::ensureDefaultCounselorsForCampus($campus->id)->count());

        $this->post('/first-boot/setup-adviser', [
            'campus_id' => $campus->id,
            'academic_year' => '2026-2027',
            'first_name' => 'Real',
            'last_name' => 'Adviser',
            'email' => 'real.adviser@chmsu.edu.ph',
            'password' => 'RealAdviser2026!',
            'password_confirmation' => 'RealAdviser2026!',
            'sex' => 'Female',
        ])->assertRedirect('/dashboard');

        $this->assertSame(1, CampusCounselor::where('campus_id', $campus->id)->count());
        $this->assertDatabaseHas('campus_counselors', [
            'campus_id' => $campus->id,
            'name' => 'Adviser, Real',
        ]);
        $this->assertDatabaseMissing('campus_counselors', [
            'name' => 'Mr. Nathaniel P. Mendoza, RPm',
        ]);

        // 2. Incoming Handover Adviser is also registered in campus_counselors automatically upon acceptance
        $realAdviser = User::where('email', 'real.adviser@chmsu.edu.ph')->firstOrFail();
        $initRes = $this->actingAs($realAdviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'RealAdviser2026!',
                'first_name' => 'Successor',
                'last_name' => 'Counselor',
                'email' => 'successor.l03@chmsu.edu.ph',
                'sex' => 'Male',
                'initial_password' => 'SuccessorPass2026!',
            ])
            ->assertCreated();

        $successor = User::where('email', 'successor.l03@chmsu.edu.ph')->firstOrFail();
        $successor->forceFill(['must_change_password' => false, 'temporary_password_issued_at' => null])->save();

        $handoverId = (int) $initRes->json('handover.id');
        $this->actingAs($successor)
            ->postJson("/adviser-handover/{$handoverId}/respond", [
                'decision' => 'accepted',
                'confirmation_code' => \App\Models\AdviserHandover::findOrFail($handoverId)->confirmation_code,
            ])
            ->assertOk();

        $this->assertDatabaseHas('campus_counselors', [
            'campus_id' => $campus->id,
            'name' => 'Counselor, Successor',
        ]);

        // 3. applyElectionTermTurnover records verified ClubServiceTenure seat credit for elected first_year_rep..fourth_year_rep
        [$yearRepMember, $yearRepMembership] = $this->createCampusUser($campus, CpfRole::Member, 'Junior, FirstRep A.', '1');

        $election = Election::create([
            'campus_id' => $campus->id,
            'academic_year' => '2027-2028',
            'title' => 'AY 2027-2028 Rep Election',
            'status' => 'closed',
            'current_position' => null,
            'current_phase' => 'completed',
            'round_number' => 1,
            'position_results' => [
                'first_year_rep' => [
                    'status' => 'elected',
                    'winner_user_id' => $yearRepMember->id,
                    'winner_name' => 'Junior, FirstRep A.',
                    'votes' => 9,
                    'won_by' => 'vote',
                ],
            ],
            'created_by_user_id' => $successor->id,
        ]);

        $turnover = app(ClubTenureService::class)->applyElectionTermTurnover($election, $successor);
        $this->assertContains($yearRepMember->id, $turnover['year_rep_user_ids']);

        $repTenure = ClubServiceTenure::query()
            ->where('campus_membership_id', $yearRepMembership->id)
            ->where('elected_seat', 'first_year_rep')
            ->first();

        $this->assertNotNull($repTenure);
        $this->assertSame(CpfRole::Member, $repTenure->role);
        $this->assertSame('2027-2028', $repTenure->academic_year);
        $this->assertTrue($repTenure->verifyHash());

        $summary = app(ClubTenureService::class)->buildTenureSummary($yearRepMembership->fresh('tenures'));
        $repSegment = collect($summary['roles'])->firstWhere('elected_seat', 'first_year_rep');
        $this->assertNotNull($repSegment);
        $this->assertSame('1st Year Representative', $repSegment['role_label']);

        // 4. Demo DatabaseSeeder still seeds the 3 sample counselors for demo environments
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseHas('campus_counselors', [
            'campus_id' => $campus->id,
            'name' => 'Mr. Nathaniel P. Mendoza, RPm',
        ]);
    }
}
