<?php

namespace Tests\Feature;

use App\Actions\Security\IssueMemberPasskey;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusMembership;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\PaymentDestination;
use App\Models\PeerQuote;
use App\Models\User;
use App\Services\ClubTenureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileApiV1Test extends TestCase
{
    use RefreshDatabase;

    private Campus $alijis;

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
    }

    private function createVerifiedMember(
        Campus $campus,
        CpfRole $role,
        string $name,
        bool $mustChangePassword = false,
    ): array {
        $parts = array_map('trim', explode(',', $name, 2));
        $lastName = $parts[0] !== '' ? $parts[0] : 'User';
        $firstName = $parts[1] ?? 'Sample';

        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('SecretPass2026!'),
            'must_change_password' => $mustChangePassword,
        ]);

        $membership = CampusMembership::create([
            'user_id' => $user->id,
            'campus_id' => $campus->id,
            'role' => $role,
            'status' => MembershipStatus::Approved,
            'enrollment_source' => 'officer_added',
            'recorded_email' => $user->email,
            'first_name' => $firstName,
            'middle_name' => 'A.',
            'last_name' => $lastName,
            'full_name_last_first_mi' => $name,
            'course' => 'BSIT',
            'year_level' => '4',
            'section' => 'A',
            'course_year_section' => 'BSIT 4A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.user',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-000-0000',
            'approved_at' => now()->subDays(10),
        ]);

        app(ClubTenureService::class)->openTenure($membership, $user, $membership->approved_at);

        return [$user, $membership];
    }

    public function test_api_v1_health_endpoint_and_cors_headers_work_for_flutter_web(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:51738',
        ])
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'app' => 'CPF',
                'database' => 'connected',
            ])
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $this->withHeaders([
            'Origin' => 'http://localhost:51738',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type',
        ])
            ->options('/api/v1/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_api_v1_login_me_and_logout_lifecycle(): void
    {
        [$officer] = $this->createVerifiedMember($this->alijis, CpfRole::President, 'Mercado, Sofia R.');

        PeerQuote::create([
            'campus_id' => $this->alijis->id,
            'submitted_by_user_id' => $officer->id,
            'quote_text' => 'Gentle steps still move you forward.',
            'author_name' => 'CPF Alijis',
            'mood_tag' => 'Calm',
            'is_approved' => true,
        ]);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', [
            'email' => $officer->email,
            'password' => 'WrongPassword!',
            'device_name' => 'Pixel 8',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $officer->email,
            'password' => 'SecretPass2026!',
            'device_name' => 'Pixel 8',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $officer->email)
            ->assertJsonPath('verified_role', 'president')
            ->assertJsonPath('campus.code', 'ALIJIS')
            ->assertJsonPath('permissions.can_scan_attendance', true)
            ->assertJsonPath('qr_unlocked', true)
            ->assertJsonPath('daily_quote.quote_text', 'Gentle steps still move you forward.');

        $token = (string) $loginRes->json('token');
        $this->assertNotEmpty($token);

        $raw = "CPF|campus:ALIJIS|user:{$officer->id}|role:president";
        $sig = substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, 16);
        $this->assertSame("{$raw}|sig:{$sig}", $loginRes->json('qr_payload'));

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $officer->id)
            ->assertJsonPath('verified_role', 'president')
            ->assertJsonPath('qr_payload', "{$raw}|sig:{$sig}");

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('status', 'logged_out');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->bearer($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    private function bearer(string $token): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    public function test_api_v1_passkey_code_claims_atomically_and_returns_former_profile_for_alumni(): void
    {
        [$adviser] = $this->createVerifiedMember($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$member, $membership] = $this->createVerifiedMember(
            $this->alijis,
            CpfRole::Member,
            'Reyes, Katrina M.',
            mustChangePassword: true,
        );

        $issued = app(IssueMemberPasskey::class)->handle($adviser, $membership, true, false);
        $plainCode = $issued['enrollment_code'];

        $passkeyRes = $this->postJson('/api/v1/auth/passkey-code', [
            'email' => $member->email,
            'passkey_code' => str_replace('-', '', $plainCode),
            'device_name' => 'Samsung A55',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $member->id)
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('verified_role', 'member')
            ->assertJsonPath('qr_unlocked', false)
            ->assertJsonPath('qr_payload', null);

        $this->postJson('/api/v1/auth/passkey-code', [
            'email' => $member->email,
            'passkey_code' => $plainCode,
            'device_name' => 'Samsung A55',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('passkey_code');

        app(ClubTenureService::class)->transitionToAlumni($membership, $adviser, 'graduated');

        $this->bearer((string) $passkeyRes->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('is_alumni', true)
            ->assertJsonPath('verified_role', null)
            ->assertJsonPath('former_role', 'member')
            ->assertJsonPath('former_role_label', 'Former Member')
            ->assertJsonPath('permissions.can_scan_attendance', false)
            ->assertJsonPath('qr_unlocked', false)
            ->assertJsonPath('qr_payload', null);
    }

    public function test_api_v1_events_listing_and_two_stage_qr_scan_reuses_same_rules(): void
    {
        [$saa] = $this->createVerifiedMember($this->alijis, CpfRole::SergeantAtArms, 'Mendoza, Rafael J.');
        [$member] = $this->createVerifiedMember($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');

        $talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay Campus',
            'slug' => 'talisay',
            'current_academic_year' => '2026-2027',
            'is_active' => true,
        ]);
        [$otherOfficer] = $this->createVerifiedMember($talisay, CpfRole::President, 'Cruz, Angelo D.');

        $eventDate = now()->addDays(2)->toDateString();
        $event = CampusEvent::create([
            'campus_id' => $this->alijis->id,
            'title' => 'Mobile Sync General Assembly',
            'event_date' => $eventDate,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'approved',
            'created_by_user_id' => $saa->id,
            'approved_by_user_id' => $saa->id,
            'approved_at' => now(),
        ]);

        $this->getJson('/api/v1/events')->assertUnauthorized();

        $saaToken = $saa->createToken('Officer Phone')->plainTextToken;
        $memberToken = $member->createToken('Member Phone')->plainTextToken;
        $otherOfficerToken = $otherOfficer->createToken('Other Campus Phone')->plainTextToken;

        $this->travelTo(Carbon::parse($eventDate.' 09:30:00'));

        try {
            $raw = "CPF|campus:ALIJIS|user:{$member->id}|role:member";
            $sig = substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, 16);
            $signedQr = "{$raw}|sig:{$sig}";

            $this->bearer($memberToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'login',
                ])
                ->assertForbidden();

            $this->bearer($otherOfficerToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'login',
                ])
                ->assertForbidden();

            $this->bearer($saaToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'logout',
                ])
                ->assertStatus(422);

            $this->bearer($saaToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'login',
                ])
                ->assertCreated()
                ->assertJsonPath('status', 'logged_in');

            $this->bearer($memberToken)
                ->getJson('/api/v1/events')
                ->assertOk()
                ->assertJsonPath('can_scan_events', false)
                ->assertJsonPath('events.0.id', $event->id)
                ->assertJsonPath('events.0.effective_status', 'active')
                ->assertJsonPath('events.0.can_scan', false)
                ->assertJsonPath('events.0.my_attendance.status', 'logged_in');

            $this->bearer($saaToken)
                ->getJson('/api/v1/events')
                ->assertOk()
                ->assertJsonPath('can_scan_events', true)
                ->assertJsonPath('events.0.can_scan', true);

            $this->bearer($saaToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'logout',
                ])
                ->assertOk()
                ->assertJsonPath('status', 'logged_out');

            $attendance = CampusEventAttendance::where('campus_event_id', $event->id)
                ->where('user_id', $member->id)
                ->firstOrFail();

            $this->assertNotNull($attendance->logged_in_at);
            $this->assertNotNull($attendance->logged_out_at);

            $this->bearer($memberToken)
                ->getJson('/api/v1/events')
                ->assertOk()
                ->assertJsonPath('events.0.my_attendance.status', 'completed');
        } finally {
            $this->travelBack();
        }
    }

    public function test_download_android_serves_apk_when_present_and_returns_clean_404_when_missing(): void
    {
        $apkDir = storage_path('app/releases');
        $apkPath = $apkDir.DIRECTORY_SEPARATOR.'cpf-android-latest.apk';
        $hadExisting = is_file($apkPath);
        $backupContent = $hadExisting ? file_get_contents($apkPath) : null;

        try {
            if ($hadExisting) {
                @unlink($apkPath);
            }

            $this->get('/download/android')
                ->assertNotFound()
                ->assertSee('CPF Mobile APK Coming Soon');

            $this->getJson('/download/android')
                ->assertNotFound()
                ->assertJsonPath('status', 'coming_soon')
                ->assertJsonPath('platform', 'android');

            @mkdir($apkDir, 0755, true);
            file_put_contents($apkPath, "PK\x03\x04dummy-apk-payload");

            $downloadRes = $this->get('/download/android')->assertOk();
            $this->assertSame(
                'application/vnd.android.package-archive',
                $downloadRes->headers->get('Content-Type'),
            );
        } finally {
            if ($hadExisting && $backupContent !== null) {
                file_put_contents($apkPath, $backupContent);
            } elseif (! $hadExisting && is_file($apkPath)) {
                @unlink($apkPath);
            }
        }
    }

    public function test_api_v1_membership_roster_gc_dues_toggles_and_payments_workflow(): void
    {
        Storage::fake('local');

        [$adviser] = $this->createVerifiedMember($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$secretary] = $this->createVerifiedMember($this->alijis, CpfRole::Secretary, 'Alvarez, Hannah G.');
        [$treasurer] = $this->createVerifiedMember($this->alijis, CpfRole::Treasurer, 'Villanueva, Marco T.');
        [$member, $memberMembership] = $this->createVerifiedMember($this->alijis, CpfRole::Member, 'Zamora, Katrina M.');

        $adviserToken = $adviser->createToken('Adviser')->plainTextToken;
        $secretaryToken = $secretary->createToken('Secretary')->plainTextToken;
        $treasurerToken = $treasurer->createToken('Treasurer')->plainTextToken;
        $memberToken = $member->createToken('Member')->plainTextToken;

        // Regular member cannot view roster (403)
        $this->bearer($memberToken)
            ->getJson('/api/v1/membership/roster')
            ->assertForbidden();

        // Officer sees A-Z sorted approved roster (Alvarez -> Montemayor -> Villanueva -> Zamora)
        $rosterRes = $this->bearer($secretaryToken)
            ->getJson('/api/v1/membership/roster')
            ->assertOk()
            ->assertJsonPath('permissions.can_confirm_messenger_gc', true)
            ->assertJsonPath('permissions.can_validate_payments', false);

        $names = array_column($rosterRes->json('roster'), 'full_name_last_first_mi');
        $this->assertSame([
            'Alvarez, Hannah G.',
            'Montemayor, Elena V.',
            'Villanueva, Marco T.',
            'Zamora, Katrina M.',
        ], $names);

        // Secretary can toggle Messenger GC, but Treasurer cannot
        $this->bearer($treasurerToken)
            ->patchJson("/api/v1/membership/{$memberMembership->id}/toggle-gc")
            ->assertForbidden();

        $this->bearer($secretaryToken)
            ->patchJson("/api/v1/membership/{$memberMembership->id}/toggle-gc")
            ->assertOk()
            ->assertJsonPath('is_in_messenger_gc', true);

        // Treasurer can toggle dues, but Secretary cannot
        $this->bearer($secretaryToken)
            ->patchJson("/api/v1/membership/{$memberMembership->id}/toggle-dues")
            ->assertForbidden();

        $this->bearer($treasurerToken)
            ->patchJson("/api/v1/membership/{$memberMembership->id}/toggle-dues")
            ->assertOk()
            ->assertJsonPath('has_paid_dues', true);

        // Approved payment destination & receipt upload
        $destination = PaymentDestination::create([
            'campus_id' => $this->alijis->id,
            'channel' => 'GCash',
            'account_name' => 'CPF Alijis Chapter',
            'account_number' => '0917-123-4567',
            'is_active' => true,
            'approved_by_adviser_id' => $adviser->id,
        ]);

        $submitRes = $this->bearer($memberToken)
            ->post('/api/v1/payments/submit', [
                'destination_id' => $destination->id,
                'purpose' => 'AY 2026-2027 Membership Dues',
                'amount' => 150,
                'reference_number' => 'GCASH-554433',
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('status', 'submitted');

        $paymentId = (int) $submitRes->json('payment.id');

        // Member sees own submission, but empty validation_queue
        $this->bearer($memberToken)
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(1, 'destinations')
            ->assertJsonCount(1, 'my_submissions')
            ->assertJsonCount(0, 'validation_queue')
            ->assertJsonPath('my_submissions.0.has_receipt', true);

        // Treasurer sees validation_queue and validates receipt
        $this->bearer($treasurerToken)
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('permissions.can_validate_payments', true)
            ->assertJsonCount(1, 'validation_queue')
            ->assertJsonPath('validation_queue.0.id', $paymentId);

        $this->bearer($treasurerToken)
            ->patchJson("/api/v1/payments/{$paymentId}/validate", [
                'status' => 'validated',
                'treasurer_notes' => 'Confirmed in chapter ledger.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'validated');

        $this->assertNotEmpty($adviserToken);
    }

    public function test_api_v1_referrals_validates_counselor_availability_and_returns_privacy_safe_mine_view(): void
    {
        [$counselorUser] = $this->createVerifiedMember($this->alijis, CpfRole::GuidanceCounselor, 'Soriano, Clarissa D.');
        [$peer] = $this->createVerifiedMember($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');

        $counselor = CampusCounselor::ensureDefaultCounselorsForCampus($this->alijis->id)->firstOrFail();
        // Default first counselor has vacant windows: 08:30-10:00, 10:30-12:00, 13:30-15:00 on Mon-Fri (days 1..5).
        $nextWeekday = now()->addDay();
        while ($nextWeekday->isWeekend()) {
            $nextWeekday = $nextWeekday->addDay();
        }
        $weekdayDate = $nextWeekday->toDateString();

        $peerToken = $peer->createToken('Peer')->plainTextToken;

        // 1. Slot outside counselor's weekly_vacant_slots (e.g. 16:30-17:30) fails with 422
        $this->bearer($peerToken)
            ->postJson('/api/v1/referrals', [
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Client, Juan P.',
                'suggested_priority' => ReferralPriority::Routine->value,
                'concern_summary' => 'Private intake concern.',
                'counselor_id' => $counselor->id,
                'preferred_date' => $weekdayDate,
                'preferred_start_time' => '16:30',
                'preferred_end_time' => '17:30',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_start_time');

        // 2. Valid slot inside counselor's 08:30-10:00 window succeeds (201)
        $created = $this->bearer($peerToken)
            ->postJson('/api/v1/referrals', [
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Client, Juan P.',
                'student_course_section' => 'BSIT 2A',
                'suggested_priority' => ReferralPriority::SemiUrgent->value,
                'concern_summary' => 'CONFIDENTIAL PEER INTAKE TEXT',
                'counselor_id' => $counselor->id,
                'preferred_date' => $weekdayDate,
                'preferred_start_time' => '08:30',
                'preferred_end_time' => '09:30',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'submitted')
            ->assertJsonPath('feedback_status', 'pending')
            ->assertJsonPath('suggested_slot.start_time', '08:30');

        $referralId = (int) $created->json('referral_id');

        // 3. Overlapping slot on the same date fails with 422
        $this->bearer($peerToken)
            ->postJson('/api/v1/referrals', [
                'referral_type' => 'self_counseling',
                'student_identifier' => 'Reyes, Katrina M.',
                'concern_summary' => 'Overlapping slot check.',
                'counselor_id' => $counselor->id,
                'preferred_date' => $weekdayDate,
                'preferred_start_time' => '09:00',
                'preferred_end_time' => '10:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preferred_start_time');

        // 4. Counselor confirms referral on web route with confidential clinical notes
        $this->actingAs($counselorUser)
            ->patchJson("/referrals/{$referralId}/confirm", [
                'feedback_status' => 'ongoing',
                'safe_scheduling_note' => 'Confirmed at Guidance Testing Room.',
                'confidential_clinical_notes' => 'STRICTLY SECRET CLINICAL ASSESSMENT',
            ])
            ->assertOk();

        // 5. GET /api/v1/referrals/mine exposes ONLY safe status + confirmed slot — zero clinical leakage
        $mineRes = $this->bearer($peerToken)
            ->getJson('/api/v1/referrals/mine')
            ->assertOk()
            ->assertJsonPath('referrals.0.id', $referralId)
            ->assertJsonPath('referrals.0.status', 'ongoing')
            ->assertJsonPath('referrals.0.feedback_status', 'ongoing')
            ->assertJsonPath('referrals.0.safe_scheduling_note', 'Confirmed at Guidance Testing Room.')
            ->assertJsonPath('referrals.0.confirmed_slot.start_time', '08:30')
            ->assertJsonMissingPath('referrals.0.concern_summary')
            ->assertJsonMissingPath('referrals.0.encrypted_concern_summary')
            ->assertJsonMissingPath('referrals.0.confidential_clinical_notes');

        $mineRes->assertDontSee('STRICTLY SECRET CLINICAL ASSESSMENT');
        $mineRes->assertDontSee('CONFIDENTIAL PEER INTAKE TEXT');
    }

    public function test_api_v1_elections_nominate_vote_and_ephemeral_cache_live_chat_flushes_on_close(): void
    {
        [$adviser] = $this->createVerifiedMember($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$memberA] = $this->createVerifiedMember($this->alijis, CpfRole::Member, 'Mercado, Sofia R.');
        [$memberB] = $this->createVerifiedMember($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');

        $adviserToken = $adviser->createToken('Adviser')->plainTextToken;
        $tokenA = $memberA->createToken('Member A')->plainTextToken;
        $tokenB = $memberB->createToken('Member B')->plainTextToken;

        // No active election initially
        $this->bearer($tokenA)
            ->getJson('/api/v1/elections/active')
            ->assertOk()
            ->assertJsonPath('active_election', null);

        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'CPF Mobile General Election',
            'status' => 'open',
            'current_position' => 'president',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $adviser->id,
        ]);

        $cache = Election::liveChatCache();
        $cacheKey = Election::liveChatCacheKey($election->id);
        $cache->forget($cacheKey);

        // Self-nominate Member A & Member B via API
        $this->bearer($tokenA)
            ->postJson("/api/v1/elections/{$election->id}/nominate", [
                'nominee_user_id' => $memberA->id,
                'platform' => 'Peer wellness outreach.',
            ])
            ->assertCreated()
            ->assertJsonPath('candidate.nomination_status', 'accepted');

        $this->bearer($tokenB)
            ->postJson("/api/v1/elections/{$election->id}/nominate", [
                'nominee_user_id' => $memberB->id,
                'platform' => 'Inclusive peer workshops.',
            ])
            ->assertCreated()
            ->assertJsonPath('candidate.nomination_status', 'accepted');

        // Move to voting phase and verify GET /api/v1/elections/active + POST vote
        $election->update(['current_phase' => 'voting']);
        $candidateA = ElectionCandidate::where('election_id', $election->id)
            ->where('user_id', $memberA->id)
            ->firstOrFail();

        $this->bearer($tokenB)
            ->postJson("/api/v1/elections/{$election->id}/vote", [
                'candidate_id' => $candidateA->id,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'voted');

        $this->bearer($tokenB)
            ->getJson('/api/v1/elections/active')
            ->assertOk()
            ->assertJsonPath('active_election.id', $election->id)
            ->assertJsonPath('active_election.voting_status.has_voted_current_round', true)
            ->assertJsonPath('active_election.voting_status.my_current_vote_candidate_id', $candidateA->id);

        // Ephemeral live chat stores ONLY in dedicated non-DB ephemeral_chat store (capped at 100 messages)
        for ($i = 1; $i <= 105; $i++) {
            $cache->put($cacheKey, array_merge($cache->get($cacheKey, []), [[
                'id' => "seed-{$i}",
                'election_id' => $election->id,
                'sender_user_id' => $memberA->id,
                'sender_name' => 'Mercado, Sofia R.',
                'sender_role' => 'member',
                'sender_role_label' => 'Verified Peer Member',
                'message_body' => "Message #{$i}",
                'sent_at' => now()->toIso8601String(),
            ]]), now()->addHours(2));
        }

        // Posting a new message trims the cache array down to max 100 messages
        $this->bearer($tokenA)
            ->postJson("/api/v1/elections/{$election->id}/live-chat", [
                'message_body' => 'Good luck to all nominees!',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('messages_count', 100);

        $chatRes = $this->bearer($tokenB)
            ->getJson("/api/v1/elections/{$election->id}/live-chat")
            ->assertOk()
            ->assertJsonCount(100, 'messages');

        $this->assertSame('Good luck to all nominees!', $chatRes->json('messages.99.message_body'));
        $this->assertTrue($cache->has($cacheKey));
        $this->assertFalse(Cache::store('database')->has($cacheKey));

        // Closing the election automatically flushes the live-chat cache key and blocks further chat
        $this->actingAs($adviser)
            ->patchJson("/elections/{$election->id}/status", [
                'status' => 'closed',
            ])
            ->assertOk();

        $this->assertFalse($cache->has($cacheKey));

        $this->bearer($tokenA)
            ->getJson("/api/v1/elections/{$election->id}/live-chat")
            ->assertStatus(422);

        $this->bearer($adviserToken)
            ->postJson("/api/v1/elections/{$election->id}/live-chat", [
                'message_body' => 'Should be rejected because session closed.',
            ])
            ->assertStatus(422);
    }
}
