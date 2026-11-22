<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Events\OfficerGcMessageSent;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\GuidanceReferral;
use App\Models\OfficerGcChannel;
use App\Models\PaymentDestination;
use App\Models\PaymentSubmission;
use App\Models\PeerQuote;
use App\Models\TimeCapsule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CpfFunctionalRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $talisay;

    private Campus $alijis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay (Main Campus)',
            'slug' => 'talisay',
            'adviser_name' => 'Dr. Elena V. Montemayor, RGC',
            'gc_group_name' => 'CPF Talisay Official Peer GC',
            'is_active' => true,
        ]);

        $this->alijis = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'adviser_name' => 'Prof. Clarissa D. Soriano, RGC',
            'gc_group_name' => 'CPF Alijis Official Peer GC',
            'is_active' => true,
        ]);
    }

    private function createMember(Campus $campus, CpfRole $role, string $name = 'Member, Sample A.'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'must_change_password' => false,
        ]);

        CampusMembership::create([
            'user_id' => $user->id,
            'campus_id' => $campus->id,
            'role' => $role,
            'status' => MembershipStatus::Approved,
            'enrollment_source' => 'officer_added',
            'recorded_email' => $user->email,
            'first_name' => 'Sample',
            'middle_name' => 'A.',
            'last_name' => 'Member',
            'full_name_last_first_mi' => $name,
            'course' => 'BSIT',
            'year_level' => '4',
            'section' => 'A',
            'course_year_section' => 'BSIT 4A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.member',
            'active_fb_account' => $name.' (Portrait)',
            'cellphone_number' => '0917-000-0000',
        ]);

        return $user;
    }

    public function test_fr01_public_landing_and_atomic_membership_application(): void
    {
        $this->get('/')->assertOk();

        // Missing separate fields or invalid Facebook link are rejected
        $this->from('/')->post('/membership/apply', [
            'campus_id' => $this->talisay->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'birthdate' => '2005-03-15',
            'course' => '',
            'year_level' => '3',
            'section' => 'A',
            'active_email' => 'maria.santos@chmsu.edu.ph',
            'cellphone_number' => '0917-123-4567',
            'sex' => 'Female',
            'facebook_profile_url' => 'https://example.com/not-facebook',
        ])->assertSessionHasErrors(['course', 'facebook_profile_url']);

        $response = $this->from('/')->post('/membership/apply', [
            'campus_id' => $this->talisay->id,
            'first_name' => 'Maria',
            'middle_name' => 'L.',
            'last_name' => 'Santos',
            'birthdate' => '2005-03-15',
            'course' => 'BSIS',
            'year_level' => '3',
            'section' => 'A',
            'active_email' => 'maria.santos@chmsu.edu.ph',
            'cellphone_number' => '0917-123-4567',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'facebook_profile_url' => 'https://www.facebook.com/maria.santos.chmsu',
            'active_fb_account' => 'Maria Santos (Blue dress)',
        ]);

        $response->assertRedirect('/');
        $this->assertDatabaseHas('campus_memberships', [
            'campus_id' => $this->talisay->id,
            'first_name' => 'Maria',
            'middle_name' => 'L.',
            'last_name' => 'Santos',
            'full_name_last_first_mi' => 'Santos, Maria L.',
            'course' => 'BSIS',
            'year_level' => '3',
            'section' => 'A',
            'course_year_section' => 'BSIS 3A',
            'facebook_profile_url' => 'https://www.facebook.com/maria.santos.chmsu',
            'status' => 'pending',
            'role' => 'member',
        ]);
    }

    public function test_fr02_and_fr03_six_role_governance_approvals_and_passkey_issuance(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember($this->talisay, CpfRole::President, 'Mercado, Sofia R.');
        $vp = $this->createMember($this->talisay, CpfRole::VicePresident, 'Bautista, Miguel A.');
        $secretary = $this->createMember($this->talisay, CpfRole::Secretary, 'Alvarez, Hannah G.');
        $treasurer = $this->createMember($this->talisay, CpfRole::Treasurer, 'Delgado, Liam C.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $this->assertTrue($adviser->canManageCampusMembers());
        $this->assertTrue($president->canManageCampusMembers());
        $this->assertTrue($vp->canManageCampusMembers());
        $this->assertFalse($secretary->canManageCampusMembers());
        $this->assertFalse($treasurer->canManageCampusMembers());

        $this->assertTrue($adviser->canIssuePasskeys());
        $this->assertTrue($president->canIssuePasskeys());
        $this->assertTrue($vp->canIssuePasskeys());
        $this->assertTrue($secretary->canIssuePasskeys());
        $this->assertFalse($treasurer->canIssuePasskeys());

        $targetMembership = $member->primaryMembership;

        $this->actingAs($treasurer)
            ->postJson("/passkeys/{$targetMembership->id}/issue")
            ->assertForbidden();

        $this->actingAs($secretary)
            ->postJson("/passkeys/{$targetMembership->id}/issue", ['require_2fa' => true])
            ->assertOk()
            ->assertJsonStructure(['status', 'issuance_id', 'enrollment_code', 'require_2fa', 'expires_at']);

        // Only Adviser assigns officer roles (zero Admin role)
        $this->actingAs($president)
            ->patch("/membership/{$targetMembership->id}/role", ['role' => 'secretary'])
            ->assertForbidden();

        $this->actingAs($adviser)
            ->patch("/membership/{$targetMembership->id}/role", ['role' => 'secretary'])
            ->assertRedirect('/membership');
    }

    public function test_fr04_and_fr08_dashboard_qr_quote_and_payment_validation(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser);
        $treasurer = $this->createMember($this->talisay, CpfRole::Treasurer);
        $alijisTreasurer = $this->createMember($this->alijis, CpfRole::Treasurer);
        $member = $this->createMember($this->talisay, CpfRole::Member);

        PeerQuote::create([
            'campus_id' => $this->talisay->id,
            'quote_text' => 'Breathe calmly and take one step today.',
            'author_name' => 'CPF Note',
            'mood_tag' => 'Calm',
            'is_approved' => true,
        ]);

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('qrSvg')
                ->has('memberIdCode')
                ->where('dailyQuote.quote_text', 'Breathe calmly and take one step today.'));

        $destRes = $this->actingAs($adviser)->postJson('/payments/destinations', [
            'channel' => 'GCash',
            'account_name' => 'CPF Talisay',
            'account_number' => '0917-555-8800',
        ])->assertCreated();

        $destinationId = $destRes->json('destination.id');

        $payRes = $this->actingAs($member)->postJson('/payments/submit', [
            'destination_id' => $destinationId,
            'purpose' => 'Membership Fee AY 2026-2027',
            'amount' => 100,
            'reference_number' => 'GCASH-REF-1001',
        ])->assertCreated();

        $paymentId = $payRes->json('payment.id');

        // Cross-campus Treasurer is blocked
        $this->actingAs($alijisTreasurer)
            ->patchJson("/payments/{$paymentId}/validate", ['status' => 'validated'])
            ->assertForbidden();

        // Same-campus Treasurer validates payment
        $this->actingAs($treasurer)
            ->patchJson("/payments/{$paymentId}/validate", [
                'status' => 'validated',
                'treasurer_notes' => 'Verified on GCash ledger.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'validated');
    }

    public function test_fr05_one_room_testing_room_overlap_and_review(): void
    {
        $officer = $this->createMember($this->talisay, CpfRole::President);
        $member = $this->createMember($this->talisay, CpfRole::Member);

        CampusRoom::create([
            'campus_id' => $this->talisay->id,
            'room_code' => 'TESTING_ROOM',
            'room_name' => 'Testing Room',
            'capacity' => 6,
            'is_single_allowed_room' => true,
        ]);

        $date = now()->addDays(2)->format('Y-m-d');

        $reqRes = $this->actingAs($member)->postJson('/testing-room/request', [
            'campus_id' => $this->talisay->id,
            'purpose' => 'Peer Drop-in',
            'request_date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
        ])->assertCreated();

        // Overlapping slot rejected with 422
        $this->actingAs($member)->postJson('/testing-room/request', [
            'campus_id' => $this->talisay->id,
            'purpose' => 'Conflicting Session',
            'request_date' => $date,
            'start_time' => '10:30',
            'end_time' => '11:30',
        ])->assertStatus(422);

        $roomRequestId = $reqRes->json('room_request.id');

        $this->actingAs($officer)
            ->patchJson("/testing-room/{$roomRequestId}/review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_fr06_encrypted_guidance_referrals_and_counselor_confirmation(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser);
        $member = $this->createMember($this->talisay, CpfRole::Member);

        $res = $this->actingAs($member)->postJson('/referrals/submit', [
            'campus_id' => $this->talisay->id,
            'referral_type' => 'self_counseling',
            'student_identifier' => 'Reyes, Katrina M.',
            'student_course_section' => 'BSIT 4A',
            'suggested_priority' => ReferralPriority::Urgent->value,
            'concern_summary' => 'Sensitive exam anxiety note.',
        ])->assertCreated();

        $referralId = $res->json('referral_id');

        $rawRow = DB::table('guidance_referrals')->where('id', $referralId)->first();
        $this->assertNotEquals('Sensitive exam anxiety note.', $rawRow->encrypted_concern_summary);

        // Ordinary member cannot confirm triage
        $this->actingAs($member)
            ->patchJson("/referrals/{$referralId}/confirm", [
                'counselor_confirmed_priority' => ReferralPriority::VeryUrgent->value,
                'status' => 'in_review',
            ])
            ->assertForbidden();

        // Adviser / Counselor confirms clinical priority and logs audit trail
        $this->actingAs($adviser)
            ->patchJson("/referrals/{$referralId}/confirm", [
                'counselor_confirmed_priority' => ReferralPriority::VeryUrgent->value,
                'status' => 'scheduled',
            ])
            ->assertOk()
            ->assertJsonPath('referral.triage_response_target', 'Within 4 hours');

        $this->assertDatabaseHas('referral_audit_logs', [
            'referral_id' => $referralId,
            'actor_user_id' => $adviser->id,
            'action' => 'counselor_confirmed',
        ]);
    }

    public function test_fr07_and_fr10_officers_gc_broadcast_zip_archive_and_reset(): void
    {
        Event::fake([OfficerGcMessageSent::class]);

        $adviser = $this->createMember($this->talisay, CpfRole::Adviser);
        $secretary = $this->createMember($this->talisay, CpfRole::Secretary);
        $member = $this->createMember($this->talisay, CpfRole::Member);

        $channel = OfficerGcChannel::create([
            'campus_id' => $this->talisay->id,
            'academic_year' => '2026-2027',
            'channel_key' => 'campus.talisay.officers-gc.2026-2027',
            'is_active' => true,
        ]);

        // Regular member blocked from Officers Private GC
        $this->actingAs($member)
            ->postJson("/officers-gc/{$channel->id}/messages", ['message_body' => 'Hello'])
            ->assertForbidden();

        // Officer sends message and triggers real-time broadcast event
        $this->actingAs($secretary)
            ->postJson("/officers-gc/{$channel->id}/messages", ['message_body' => 'Checklist review at 3 PM'])
            ->assertCreated();

        Event::assertDispatched(OfficerGcMessageSent::class);

        // Adviser archives ZIP, resets channel, and creates SchoolYearArchive index
        $archiveRes = $this->actingAs($adviser)
            ->postJson("/officers-gc/{$channel->id}/archive")
            ->assertOk()
            ->assertJsonPath('status', 'archived');

        $zipPath = $archiveRes->json('archive.hdd_storage_path');
        $this->assertFileExists($zipPath);
        $this->assertDatabaseCount('officer_gc_messages', 0);
        $this->assertDatabaseHas('officer_gc_channels', ['id' => $channel->id, 'is_active' => false]);
        $this->assertDatabaseHas('school_year_archives', [
            'campus_id' => $this->talisay->id,
            'archive_type' => 'officer_gc',
        ]);
        @unlink($zipPath);
    }

    public function test_fr09_campus_election_one_vote_per_member_per_position(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser);
        $candidateUser = $this->createMember($this->talisay, CpfRole::Member, 'Candidate, Ana B.');
        $voter = $this->createMember($this->talisay, CpfRole::Member, 'Voter, Carl D.');
        $outsider = $this->createMember($this->alijis, CpfRole::Member, 'Outsider, Dan E.');

        $election = Election::create([
            'campus_id' => $this->talisay->id,
            'academic_year' => '2026-2027',
            'title' => 'CPF Talisay Officer Election',
            'status' => 'open',
            'created_by_user_id' => $adviser->id,
        ]);

        $cand = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $candidateUser->id,
            'position' => 'president',
        ]);

        // Cross-campus voter blocked
        $this->actingAs($outsider)
            ->postJson("/elections/{$election->id}/vote", [
                'candidate_id' => $cand->id,
                'position' => 'president',
            ])
            ->assertForbidden();

        // First vote recorded
        $this->actingAs($voter)
            ->postJson("/elections/{$election->id}/vote", [
                'candidate_id' => $cand->id,
                'position' => 'president',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'voted');

        // Second vote for same position returns already_voted without duplicating
        $this->actingAs($voter)
            ->postJson("/elections/{$election->id}/vote", [
                'candidate_id' => $cand->id,
                'position' => 'president',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'already_voted');

        $this->assertDatabaseCount('election_votes', 1);
    }

    public function test_fr10_time_capsule_scheduler_and_unlock_guard(): void
    {
        $member = $this->createMember($this->talisay, CpfRole::Member);

        $pastCapsule = TimeCapsule::create([
            'campus_id' => $this->talisay->id,
            'user_id' => $member->id,
            'title' => 'Past Due Note',
            'encrypted_message' => 'You made it through!',
            'unlock_at' => now()->subMinute(),
            'is_opened' => false,
        ]);

        $futureCapsule = TimeCapsule::create([
            'campus_id' => $this->talisay->id,
            'user_id' => $member->id,
            'title' => 'Future Note',
            'encrypted_message' => 'Still locked.',
            'unlock_at' => now()->addDays(10),
            'is_opened' => false,
        ]);

        // Future capsule cannot be opened early
        $this->actingAs($member)
            ->postJson("/time-capsules/{$futureCapsule->id}/open")
            ->assertStatus(422);

        $this->artisan('cpf:unlock-time-capsules')->assertSuccessful();

        $this->assertTrue($pastCapsule->fresh()->is_opened);
        $this->assertFalse($futureCapsule->fresh()->is_opened);
    }

    public function test_treasurer_and_adviser_basic_dues_checklist(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember($this->talisay, CpfRole::President, 'Mercado, Sofia R.');
        $vicePresident = $this->createMember($this->talisay, CpfRole::from('vice_president'), 'Bautista, Miguel A.');
        $secretary = $this->createMember($this->talisay, CpfRole::Secretary, 'Alvarez, Hannah G.');
        $treasurer = $this->createMember($this->talisay, CpfRole::Treasurer, 'Delgado, Liam C.');
        $auditor = $this->createMember($this->talisay, CpfRole::from('auditor'), 'Mendoza, Rafael D.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');
        $alijisTreasurer = $this->createMember($this->alijis, CpfRole::Treasurer, 'Cruz, Ana B.');

        $targetMembership = $member->primaryMembership;
        $this->assertFalse((bool) $targetMembership->has_paid_dues);

        // Secretary and Auditor cannot mutate payment state on the dues checklist
        $this->actingAs($secretary)
            ->patch("/membership/{$targetMembership->id}/toggle-dues")
            ->assertForbidden();

        $this->actingAs($auditor)
            ->patch("/membership/{$targetMembership->id}/toggle-dues")
            ->assertForbidden();

        // Auditor gets read-only financial audit visibility on Dashboard
        $this->actingAs($auditor)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('canConfirmDues', false)
                ->where('canAuditFinances', true)
                ->where('isOfficerOrAdviser', true));

        // Cross-campus Treasurer is blocked
        $this->actingAs($alijisTreasurer)
            ->patch("/membership/{$targetMembership->id}/toggle-dues")
            ->assertForbidden();

        // Same-campus Treasurer confirms dues paid on basic checklist
        $this->actingAs($treasurer)
            ->from('/membership')
            ->patch("/membership/{$targetMembership->id}/toggle-dues")
            ->assertRedirect('/membership');

        $targetMembership->refresh();
        $this->assertTrue($targetMembership->has_paid_dues);
        $this->assertSame($treasurer->id, $targetMembership->dues_confirmed_by);

        // President and Vice President can also co-sign dues collections per Constitution guidelines
        $this->actingAs($president)
            ->from('/dashboard')
            ->patch("/membership/{$targetMembership->id}/toggle-dues")
            ->assertRedirect('/dashboard');
        $this->assertFalse((bool) $targetMembership->fresh()->has_paid_dues);

        $this->actingAs($vicePresident)
            ->from('/dashboard')
            ->patch("/membership/{$targetMembership->id}/toggle-dues")
            ->assertRedirect('/dashboard');
        $this->assertTrue((bool) $targetMembership->fresh()->has_paid_dues);

        // Club Adviser sees A-Z dues roster on Dashboard and can toggle too
        $this->actingAs($adviser)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('canConfirmDues', true)
                ->where('isOfficerOrAdviser', true)
                ->where('qrUnlocked', true));

        $this->actingAs($adviser)
            ->from('/dashboard')
            ->patch("/membership/{$targetMembership->id}/toggle-dues")
            ->assertRedirect('/dashboard');

        $this->assertFalse((bool) $targetMembership->fresh()->has_paid_dues);
    }

    public function test_google_forms_api_ingests_pending_application_and_passkey_onboarding_gates_attendance_qr(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');

        // Unauthorized webhook secret rejected
        $this->postJson('/api/google-forms/membership', [], [
            'X-CPF-Forms-Secret' => 'wrong-secret',
        ])->assertUnauthorized();

        // Valid Google Forms submission with separate course/year/section and Facebook link
        $secret = (string) config('cpf.google_forms.webhook_secret');
        $webhookRes = $this->postJson('/api/google-forms/membership', [
            'campus_code' => 'TALISAY',
            'first_name' => 'Carlo',
            'middle_name' => 'P.',
            'last_name' => 'Villanueva',
            'course' => 'BS Psychology',
            'year_level' => '2',
            'section' => 'C',
            'sex' => 'Male',
            'gender_identity' => 'Man',
            'birthdate' => '2005-08-20',
            'active_email' => 'carlo.villanueva@chmsu.edu.ph',
            'facebook_profile_url' => 'https://www.facebook.com/carlo.villanueva.cpf',
            'cellphone_number' => '0917-555-0244',
        ], [
            'X-CPF-Forms-Secret' => $secret,
        ])->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('enrollment_source', 'google_forms_api')
            ->assertJsonPath('course_year_section', 'BS Psychology 2C');

        $membershipId = $webhookRes->json('membership_id');
        $pendingMembership = CampusMembership::findOrFail($membershipId);

        // Adviser confirms member -> locked 12-char Passkey Code issued + must_change_password = true (no temporary password)
        $this->actingAs($adviser)
            ->patch("/membership/{$pendingMembership->id}/approve")
            ->assertRedirect('/membership')
            ->assertSessionHas('passkey_issued_notice')
            ->assertSessionMissing('temporary_password_notice');

        $notice = session('passkey_issued_notice');
        $passkeyCode = $notice['passkey_code'];
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $passkeyCode);

        $memberUser = $pendingMembership->user->fresh();
        $this->assertTrue($memberUser->must_change_password);

        // Sign in with Passkey Code as guest (no temporary password needed)
        auth()->logout();
        $this->post('/login/passkey-code', [
            'email' => $memberUser->email,
            'passkey_code' => str_replace('-', '', $passkeyCode),
        ])->assertRedirect();
        $this->assertAuthenticatedAs($memberUser);

        // Before creating personal password, accessing /dashboard redirects to /force-password-change
        $this->actingAs($memberUser)
            ->get('/dashboard')
            ->assertRedirect('/force-password-change');

        $this->actingAs($memberUser)
            ->get('/force-password-change')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/ForcePasswordChange'));

        // Creating personal password (without any temporary/current_password input) clears flag and unlocks Attendance QR on /dashboard
        $this->actingAs($memberUser)
            ->post('/force-password-change', [
                'password' => 'MyCalmPersonalPass2026!',
                'password_confirmation' => 'MyCalmPersonalPass2026!',
            ])->assertRedirect('/dashboard');

        $memberUser->refresh();
        $this->assertFalse($memberUser->must_change_password);

        $this->actingAs($memberUser)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('qrUnlocked', true)
                ->has('qrSvg')
                ->has('memberIdCode'));
    }

    public function test_passkey_code_locking_realtime_regeneration_and_boxed_login(): void
    {
        $secretary = $this->createMember($this->talisay, CpfRole::Secretary, 'Alvarez, Hannah G.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');
        $membership = $member->primaryMembership;

        // First issuance generates and locks a 12-character passkey code
        $first = $this->actingAs($secretary)
            ->postJson("/passkeys/{$membership->id}/issue", ['require_2fa' => true])
            ->assertOk()
            ->assertJsonPath('is_locked', true)
            ->assertJsonPath('regenerated', false);

        $firstCode = $first->json('enrollment_code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $firstCode);

        // Calling issue again without regenerate returns the exact same locked code
        $second = $this->actingAs($secretary)
            ->postJson("/passkeys/{$membership->id}/issue", ['require_2fa' => true])
            ->assertOk()
            ->assertJsonPath('enrollment_code', $firstCode)
            ->assertJsonPath('is_locked', true);

        // Explicitly clicking "Generate New Passkey" revokes the old code and locks a new one
        $regenerated = $this->actingAs($secretary)
            ->postJson("/passkeys/{$membership->id}/issue", ['require_2fa' => true, 'regenerate' => true])
            ->assertOk()
            ->assertJsonPath('is_locked', true)
            ->assertJsonPath('regenerated', true);

        $newCode = $regenerated->json('enrollment_code');
        $this->assertNotSame($firstCode, $newCode);

        // Log out secretary and test boxed Passkey Code login as guest
        auth()->logout();

        // Old revoked code is rejected
        $this->from('/login')
            ->post('/login/passkey-code', [
                'email' => $member->email,
                'passkey_code' => str_replace('-', '', $firstCode),
            ])
            ->assertSessionHasErrors('passkey_code');

        // Active locked code logs member in and marks issuance claimed (one-time use)
        $this->post('/login/passkey-code', [
            'email' => $member->email,
            'passkey_code' => str_replace('-', '', $newCode),
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($member);

        $claimedIssuance = \App\Models\PasskeyIssuance::findOrFail($regenerated->json('issuance_id'));
        $this->assertSame('claimed', $claimedIssuance->status);
        $this->assertNotNull($claimedIssuance->claimed_at);

        // Reusing the already-claimed code after logout is rejected
        auth()->logout();
        $this->from('/login')
            ->post('/login/passkey-code', [
                'email' => $member->email,
                'passkey_code' => str_replace('-', '', $newCode),
            ])
            ->assertSessionHasErrors('passkey_code');
        $this->assertGuest();

        // Re-authenticate member to test self-regeneration
        $this->actingAs($member);

        // Logged-in member can regenerate their own locked passkey in real time
        $selfRegen = $this->actingAs($member)
            ->postJson('/my-passkey/regenerate', ['regenerate' => true])
            ->assertOk()
            ->assertJsonPath('is_locked', true)
            ->assertJsonPath('regenerated', true);

        $this->assertNotSame($newCode, $selfRegen->json('enrollment_code'));
    }

    public function test_all_constitution_officers_access_dashboard_private_gc_and_send_messages(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $auditor = $this->createMember($this->talisay, CpfRole::from('auditor'), 'Mendoza, Rafael D.');
        $businessManager = $this->createMember($this->talisay, CpfRole::from('business_manager'), 'Villanueva, Paolo G.');
        $pio = $this->createMember($this->talisay, CpfRole::from('pio'), 'Soriano, Bea T.');
        $sergeantAtArms = $this->createMember($this->talisay, CpfRole::from('sergeant_at_arms'), 'Castillo, Marco J.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $channel = \App\Models\OfficerGcChannel::updateOrCreate(
            [
                'campus_id' => $this->talisay->id,
                'academic_year' => '2026-2027',
            ],
            [
                'channel_key' => 'campus.talisay.officers-gc.2026-2027',
                'is_active' => true,
            ],
        );

        foreach ([$adviser, $auditor, $businessManager, $pio, $sergeantAtArms] as $officer) {
            $this->actingAs($officer)
                ->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Dashboard')
                    ->where('isOfficerOrAdviser', true)
                    ->where('officerGcChannel.id', $channel->id));

            $this->actingAs($officer)
                ->get('/officers-chat')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('officers-chat/Index')
                    ->where('officerChannel.id', $channel->id));

            $this->actingAs($officer)
                ->postJson("/officers-gc/{$channel->id}/messages", [
                    'message_body' => 'Officer roll call check',
                ])
                ->assertCreated()
                ->assertJsonPath('status', 'sent')
                ->assertJsonPath('message.message_body', 'Officer roll call check');
        }

        // Ordinary members cannot view Officers Private GC on Dashboard, /officers-chat, or post to it
        $this->actingAs($member)
            ->get('/officers-chat')
            ->assertForbidden();

        $this->actingAs($member)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isOfficerOrAdviser', false)
                ->where('officerGcChannel', null));

        $this->actingAs($member)
            ->postJson("/officers-gc/{$channel->id}/messages", [
                'message_body' => 'Unauthorized member post',
            ])
            ->assertForbidden();
    }

    public function test_account_deletion_is_disabled_for_all_users_including_adviser(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $this->actingAs($adviser)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $adviser->id]);

        $this->actingAs($member)
            ->delete('/settings/profile', ['password' => 'password'])
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_official_member_cannot_resubmit_membership_form_and_only_adviser_approves_info_change_requests(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember($this->talisay, CpfRole::President, 'Mercado, Sofia R.');
        $alijisAdviser = $this->createMember($this->alijis, CpfRole::Adviser, 'Soriano, Clarissa D.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');
        $membership = $member->primaryMembership;

        // 1. Official approved member is blocked from submitting another membership application form
        $this->actingAs($member)
            ->from('/membership')
            ->post('/membership/apply', [
                'campus_id' => $this->talisay->id,
                'recorded_email' => $member->email,
                'first_name' => 'Katrina',
                'middle_name' => 'M.',
                'last_name' => 'Reyes',
                'course' => 'BSIT',
                'year_level' => '4',
                'section' => 'A',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '2004-05-10',
                'active_email' => $member->email,
                'facebook_profile_url' => 'https://www.facebook.com/katrina.reyes.cpf',
                'active_fb_account' => 'Katrina Reyes',
                'cellphone_number' => '0917-555-0105',
            ])
            ->assertSessionHasErrors('active_email');

        // 2. Official member submits an Information Change Request (e.g., new year/section & phone)
        $this->actingAs($member)
            ->post('/membership/change-requests', [
                'first_name' => $membership->first_name,
                'middle_name' => $membership->middle_name,
                'last_name' => $membership->last_name,
                'course' => 'BSCS',
                'year_level' => '4',
                'section' => 'B',
                'sex' => $membership->sex,
                'gender_identity' => $membership->gender_identity,
                'birthdate' => $membership->birthdate->format('Y-m-d'),
                'active_email' => $membership->active_email,
                'facebook_profile_url' => 'https://www.facebook.com/katrina.reyes.updated',
                'active_fb_account' => $membership->active_fb_account,
                'cellphone_number' => '0917-999-8888',
                'reason' => 'Shifted to BSCS 4B and updated cellphone number.',
            ])
            ->assertRedirect('/membership');

        $changeRequest = \App\Models\MemberInfoChangeRequest::query()
            ->where('campus_membership_id', $membership->id)
            ->latest()
            ->firstOrFail();

        $this->assertSame('pending', $changeRequest->status);
        $this->assertArrayHasKey('course', $changeRequest->requested_changes);
        $this->assertSame('BSCS', $changeRequest->requested_changes['course']['new']);

        // 3. President, ordinary Member, and cross-campus Adviser are FORBIDDEN from reviewing change requests
        $this->actingAs($president)
            ->patch("/membership/change-requests/{$changeRequest->id}/review", ['decision' => 'approved'])
            ->assertForbidden();

        $this->actingAs($member)
            ->patch("/membership/change-requests/{$changeRequest->id}/review", ['decision' => 'approved'])
            ->assertForbidden();

        $this->actingAs($alijisAdviser)
            ->patch("/membership/change-requests/{$changeRequest->id}/review", ['decision' => 'approved'])
            ->assertForbidden();

        // 4. Same-campus Club Adviser approves the change request -> profile updates automatically
        $this->actingAs($adviser)
            ->patch("/membership/change-requests/{$changeRequest->id}/review", [
                'decision' => 'approved',
                'adviser_notes' => 'Verified with CHMSU registrar.',
            ])
            ->assertRedirect('/membership');

        $changeRequest->refresh();
        $membership->refresh();

        $this->assertSame('approved', $changeRequest->status);
        $this->assertSame($adviser->id, $changeRequest->reviewed_by);
        $this->assertSame('Verified with CHMSU registrar.', $changeRequest->adviser_notes);
        $this->assertSame('BSCS', $membership->course);
        $this->assertSame('B', $membership->section);
        $this->assertSame('BSCS 4B', $membership->course_year_section);
        $this->assertSame('0917-999-8888', $membership->cellphone_number);
    }

    public function test_approving_application_delivers_passkey_code_to_email_one_time_only(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');

        $applicantUser = User::factory()->create([
            'name' => 'Tibo-oc, Paul Felippe G.',
            'email' => 'paulfelippegtibooc@gmail.com',
            'email_verified_at' => now(),
        ]);

        $pending = CampusMembership::create([
            'user_id' => $applicantUser->id,
            'campus_id' => $this->talisay->id,
            'role' => CpfRole::Member,
            'status' => MembershipStatus::Pending,
            'enrollment_source' => 'online_form',
            'recorded_email' => 'paulfelippegtibooc@gmail.com',
            'first_name' => 'Paul Felippe',
            'middle_name' => 'G.',
            'last_name' => 'Tibo-oc',
            'full_name_last_first_mi' => 'Tibo-oc, Paul Felippe G.',
            'course' => 'BSIT',
            'year_level' => '4',
            'section' => 'A',
            'course_year_section' => 'BSIT 4A',
            'sex' => 'Male',
            'gender_identity' => 'Man',
            'birthdate' => '2004-06-15',
            'active_email' => 'paulfelippegtibooc@gmail.com',
            'facebook_profile_url' => 'https://www.facebook.com/paulfelippe.tibooc',
            'active_fb_account' => 'Paul Felippe Tibo-oc',
            'cellphone_number' => '0917-555-0199',
        ]);

        // First approval delivers the passkey code via email once
        $this->actingAs($adviser)
            ->patch("/membership/{$pending->id}/approve")
            ->assertRedirect('/membership')
            ->assertSessionHas('passkey_issued_notice.emailed', true)
            ->assertSessionHas('passkey_issued_notice.email_status', 'sent');

        $notice = session('passkey_issued_notice');
        $passkeyCode = $notice['passkey_code'];

        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\MembershipApprovedPasskeyNotification::class,
            function (\App\Notifications\MembershipApprovedPasskeyNotification $notification, array $channels, object $notifiable) use ($passkeyCode): bool {
                return ($notifiable->routes['mail'] ?? null) === 'paulfelippegtibooc@gmail.com'
                    && $notification->passkeyCode === $passkeyCode;
            },
        );
        \Illuminate\Support\Facades\Notification::assertSentOnDemandTimes(
            \App\Notifications\MembershipApprovedPasskeyNotification::class,
            1,
        );

        // Calling approve a second time does NOT send another email (strictly one-time-only delivery)
        $this->actingAs($adviser)
            ->patch("/membership/{$pending->id}/approve")
            ->assertRedirect('/membership')
            ->assertSessionHas('passkey_issued_notice.emailed', false)
            ->assertSessionHas('passkey_issued_notice.email_status', 'already_sent');

        \Illuminate\Support\Facades\Notification::assertSentOnDemandTimes(
            \App\Notifications\MembershipApprovedPasskeyNotification::class,
            1,
        );
    }

    public function test_placeholder_smtp_password_reports_failure_without_stamping_emailed_at(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.password', 'PASTE_YOUR_16_CHAR_GOOGLE_APP_PASSWORD_HERE');

        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');

        $applicantUser = User::factory()->create([
            'name' => 'Dela Cruz, Ana R.',
            'email' => 'ana.delacruz.smtp@chmsu.edu.ph',
            'email_verified_at' => now(),
        ]);

        $pending = CampusMembership::create([
            'user_id' => $applicantUser->id,
            'campus_id' => $this->talisay->id,
            'role' => CpfRole::Member,
            'status' => MembershipStatus::Pending,
            'enrollment_source' => 'online_form',
            'recorded_email' => 'ana.delacruz.smtp@chmsu.edu.ph',
            'first_name' => 'Ana',
            'middle_name' => 'R.',
            'last_name' => 'Dela Cruz',
            'full_name_last_first_mi' => 'Dela Cruz, Ana R.',
            'course' => 'BSIT',
            'year_level' => '2',
            'section' => 'A',
            'course_year_section' => 'BSIT 2A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2006-02-10',
            'active_email' => 'ana.delacruz.smtp@chmsu.edu.ph',
            'facebook_profile_url' => 'https://www.facebook.com/ana.delacruz.cpf',
            'active_fb_account' => 'Ana Dela Cruz',
            'cellphone_number' => '0917-555-0299',
        ]);

        // Placeholder SMTP password triggers clear error without sending or stamping emailed_at
        $this->actingAs($adviser)
            ->patch("/membership/{$pending->id}/approve")
            ->assertRedirect('/membership')
            ->assertSessionHas('passkey_issued_notice.emailed', false)
            ->assertSessionHas('passkey_issued_notice.email_status', 'failed');

        $notice = session('passkey_issued_notice');
        $this->assertNotEmpty($notice['email_error']);
        \Illuminate\Support\Facades\Notification::assertNothingSent();
        $this->assertDatabaseHas('passkey_issuances', [
            'target_user_id' => $applicantUser->id,
            'emailed_at' => null,
        ]);

        // Once SMTP password is configured, approval delivers the one-time email cleanly
        config()->set('mail.mailers.smtp.username', 'test_smtp_user');
        config()->set('mail.mailers.smtp.password', 'real16charapppass');

        $this->actingAs($adviser)
            ->patch("/membership/{$pending->id}/approve")
            ->assertRedirect('/membership')
            ->assertSessionHas('passkey_issued_notice.emailed', true)
            ->assertSessionHas('passkey_issued_notice.email_status', 'sent');

        \Illuminate\Support\Facades\Notification::assertSentOnDemandTimes(
            \App\Notifications\MembershipApprovedPasskeyNotification::class,
            1,
        );
    }

    public function test_google_forms_webhook_health_and_real_form_payload_normalization(): void
    {
        $secret = (string) config('cpf.google_forms.webhook_secret');

        // Health endpoint rejects missing/wrong secret and succeeds with valid header
        $this->getJson('/api/google-forms/health')->assertUnauthorized();

        $this->getJson('/api/google-forms/health', [
            'X-CPF-Forms-Secret' => $secret,
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('active_campus_code', 'TALISAY');

        // Normalizes real Google Forms strings ("4th Year", lowercase "female", custom "Other:" gender, missing https://, MM/DD/YYYY date)
        $res = $this->postJson('/api/google-forms/membership', [
            'first_name' => 'Maria Clara',
            'middle_name' => 'D.',
            'last_name' => 'Santos',
            'course' => 'BSIT',
            'year_level' => '4th Year',
            'section' => 'A',
            'sex' => 'female',
            'gender_identity' => 'Genderfluid',
            'birthdate' => '05/14/2005',
            'active_email' => 'maria.clara.forms@chmsu.edu.ph',
            'facebook_profile_url' => 'facebook.com/maria.clara.cpf',
            'cellphone_number' => '0917-555-0888',
        ], [
            'X-CPF-Forms-Secret' => $secret,
        ])->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('enrollment_source', 'google_forms_api')
            ->assertJsonPath('course_year_section', 'BSIT 4A');

        $membership = CampusMembership::findOrFail($res->json('membership_id'));
        $this->assertSame('Others', $membership->gender_identity);
        $this->assertSame('Genderfluid', $membership->gender_identity_other);
        $this->assertSame('https://facebook.com/maria.clara.cpf', $membership->facebook_profile_url);
        $this->assertSame('2005-05-14', $membership->birthdate->format('Y-m-d'));

        // Explicitly targeting an inactive (on-hold) campus is rejected with 422
        $inactiveCampus = \App\Models\Campus::create([
            'code' => 'ON_HOLD',
            'name' => 'On Hold Campus',
            'slug' => 'on-hold',
            'is_active' => false,
        ]);

        $this->postJson('/api/google-forms/membership', [
            'campus_id' => $inactiveCampus->id,
            'first_name' => 'Test',
            'last_name' => 'Inactive',
            'course' => 'BSIT',
            'year_level' => '1',
            'section' => 'A',
            'sex' => 'Male',
            'birthdate' => '2006-01-01',
            'active_email' => 'inactive.campus.test@chmsu.edu.ph',
            'facebook_profile_url' => 'https://www.facebook.com/inactive.test',
            'cellphone_number' => '0917-555-0000',
        ], [
            'X-CPF-Forms-Secret' => $secret,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('campus_id');
    }

    public function test_campus_hub_counselor_availability_and_anonymous_monthly_calendar_bookings(): void
    {
        $counselors = \App\Models\CampusCounselor::ensureDefaultCounselorsForCampus($this->talisay->id);
        $this->assertCount(3, $counselors);

        $katrina = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');
        $otherMember = $this->createMember($this->talisay, CpfRole::Member, 'Bautista, Miguel A.');

        $targetDate = now()->next(\Carbon\CarbonInterface::WEDNESDAY)->format('Y-m-d');
        $chosenCounselor = $counselors->first();

        $this->actingAs($katrina)->postJson('/testing-room/request', [
            'campus_id' => $this->talisay->id,
            'counselor_id' => $chosenCounselor->id,
            'purpose' => 'Private Emotional Distress Discussion',
            'request_date' => $targetDate,
            'start_time' => '08:30',
            'end_time' => '10:00',
        ])->assertCreated();

        $response = $this->actingAs($otherMember)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('campus/Operations')
                ->has('counselors', 3)
                ->has('calendarBookings', 1)
                ->where('calendarBookings.0.date', $targetDate)
                ->where('calendarBookings.0.start_time', '08:30')
                ->where('calendarBookings.0.end_time', '10:00')
                ->where('calendarBookings.0.counselor_id', $chosenCounselor->id)
                ->where('calendarBookings.0.status', 'booked')
                ->missing('calendarBookings.0.requester_name')
                ->missing('calendarBookings.0.purpose')
                ->missing('calendarBookings.0.requested_by_user_id'));

        // Strictly verify neither Katrina's name nor session purpose leaks into /campus-hub for other members
        $response->assertDontSee('Reyes, Katrina M.');
        $response->assertDontSee('Private Emotional Distress Discussion');
    }

    public function test_campus_counselors_private_gc_is_adviser_counselor_only(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $secretary = $this->createMember($this->talisay, CpfRole::Secretary, 'Alvarez, Hannah G.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $this->actingAs($member)
            ->postJson('/counselors-gc/messages', ['message_body' => 'Should be blocked'])
            ->assertForbidden();

        $this->actingAs($secretary)
            ->postJson('/counselors-gc/messages', ['message_body' => 'Officer should also be blocked'])
            ->assertForbidden();

        $this->actingAs($adviser)
            ->postJson('/counselors-gc/messages', ['message_body' => 'Counseling slot coordination for Wednesday morning.'])
            ->assertCreated();

        $this->assertDatabaseHas('counselor_gc_messages', [
            'campus_id' => $this->talisay->id,
            'sender_user_id' => $adviser->id,
            'message_body' => 'Counseling slot coordination for Wednesday morning.',
        ]);
    }

    public function test_parliamentary_election_nominations_decline_opt_in_year_guard_tie_runoff_and_skip(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $memberA = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');
        $memberB = $this->createMember($this->talisay, CpfRole::Member, 'Bautista, Miguel A.');
        $memberC = $this->createMember($this->talisay, CpfRole::Member, 'Cruz, Angelo D.');
        $memberYear2 = $this->createMember($this->talisay, CpfRole::Member, 'Villanueva, Paolo R.');

        CampusMembership::where('user_id', $memberYear2->id)->update([
            'year_level' => '2',
            'course_year_section' => 'BSIT 2B',
        ]);

        // Adviser activates sequential election starting with President
        $this->actingAs($adviser)
            ->postJson('/elections', [
                'title' => 'CPF Senate Officers AY 2026-2027',
                'academic_year' => '2026-2027',
                'status' => 'open',
            ])
            ->assertCreated();

        $election = Election::latest('id')->firstOrFail();
        $this->assertSame('president', $election->current_position);
        $this->assertSame('nomination', $election->current_phase);

        // Self-nomination auto-accepts
        $this->actingAs($memberA)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberA->id])
            ->assertCreated();

        $candA = ElectionCandidate::where('election_id', $election->id)
            ->where('user_id', $memberA->id)
            ->where('position', 'president')
            ->firstOrFail();
        $this->assertSame('accepted', $candA->nomination_status);

        // Peer nomination starts pending
        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberB->id])
            ->assertCreated();

        $candB = ElectionCandidate::where('election_id', $election->id)
            ->where('user_id', $memberB->id)
            ->where('position', 'president')
            ->firstOrFail();
        $this->assertSame('pending', $candB->nomination_status);

        // Declining locks Member B out of nominations across any position until opting back in
        $this->actingAs($memberB)
            ->patchJson("/elections/{$election->id}/candidates/{$candB->id}/respond", ['decision' => 'declined'])
            ->assertOk();

        $this->assertSame('declined', $candB->fresh()->nomination_status);
        $this->assertDatabaseHas('election_opt_outs', [
            'election_id' => $election->id,
            'user_id' => $memberB->id,
        ]);

        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberB->id])
            ->assertStatus(422);

        // Changing mind restores eligibility, allowing Member B to be nominated & accept
        $this->actingAs($memberB)
            ->postJson("/elections/{$election->id}/opt-in")
            ->assertOk();

        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberB->id])
            ->assertCreated();

        $candB = ElectionCandidate::where('election_id', $election->id)
            ->where('user_id', $memberB->id)
            ->where('position', 'president')
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($memberB)
            ->patchJson("/elections/{$election->id}/candidates/{$candB->id}/respond", ['decision' => 'accepted'])
            ->assertOk();
        $this->assertSame('accepted', $candB->fresh()->nomination_status);

        // Pre-nominate Member A for Vice President before President is finalized to verify automatic revocation upon election
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/candidates", [
                'user_id' => $memberA->id,
                'position' => 'vice_president',
            ])
            ->assertCreated();

        $preCandVpA = ElectionCandidate::where('election_id', $election->id)
            ->where('user_id', $memberA->id)
            ->where('position', 'vice_president')
            ->firstOrFail();

        // Only the campus Club Adviser can close nominations (regular members receive 403)
        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/close-nominations")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the campus Club Adviser can close nominations.');

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/close-nominations")
            ->assertOk();

        $election->refresh();
        $this->assertSame('voting', $election->current_phase);

        // Late nomination response after nominations closed is rejected with 422
        $latePending = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $memberC->id,
            'position' => 'president',
            'nominated_by_user_id' => $memberA->id,
            'nomination_status' => 'pending',
        ]);
        $this->actingAs($memberC)
            ->patchJson("/elections/{$election->id}/candidates/{$latePending->id}/respond", ['decision' => 'accepted'])
            ->assertStatus(422);

        // Club Adviser presides and cannot cast floor votes
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/vote", ['candidate_id' => $candA->id, 'position' => 'president'])
            ->assertForbidden();

        // Voting for a non-current position is rejected with 422
        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/vote", ['candidate_id' => $preCandVpA->id, 'position' => 'vice_president'])
            ->assertStatus(422);

        // Tie vote (1 each) -> finalizing opens automatic tie-breaker runoff round 2
        $this->actingAs($memberA)
            ->postJson("/elections/{$election->id}/vote", ['candidate_id' => $candA->id, 'position' => 'president'])
            ->assertOk();
        $this->actingAs($memberB)
            ->postJson("/elections/{$election->id}/vote", ['candidate_id' => $candB->id, 'position' => 'president'])
            ->assertOk();

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/finalize-position")
            ->assertOk();

        $election->refresh();
        $this->assertSame('tie_breaker', $election->current_phase);
        $this->assertSame(2, (int) $election->round_number);

        // Runoff round votes resolve tie -> President recorded on billboard and moves to Vice President
        $this->actingAs($memberA)
            ->postJson("/elections/{$election->id}/vote", ['candidate_id' => $candA->id, 'position' => 'president'])
            ->assertOk();
        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/vote", ['candidate_id' => $candA->id, 'position' => 'president'])
            ->assertOk();
        $this->actingAs($memberB)
            ->postJson("/elections/{$election->id}/vote", ['candidate_id' => $candB->id, 'position' => 'president'])
            ->assertOk();

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/finalize-position")
            ->assertOk();

        $election->refresh();
        $this->assertSame('vice_president', $election->current_position);
        $this->assertSame('nomination', $election->current_phase);
        $this->assertSame($memberA->id, $election->position_results['president']['winner_user_id'] ?? null);

        // Elected President's pre-existing nomination for Vice President was automatically revoked
        $this->assertSame('declined', $preCandVpA->fresh()->nomination_status);

        // Elected President cannot self-nominate or be nominated for Vice President
        $this->actingAs($memberA)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberA->id])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'You have already been elected to an officer position in this election and cannot self-nominate for another seat.',
            );

        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberA->id])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'This member has already been elected to an officer position in this election.',
            );

        // Strict year-level guard blocks Year 2 member from 4th Year Representative, allows Year 4 member
        $election->update([
            'current_position' => 'fourth_year_rep',
            'current_phase' => 'nomination',
            'round_number' => 1,
        ]);

        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberYear2->id])
            ->assertStatus(422);

        $this->actingAs($memberC)
            ->postJson("/elections/{$election->id}/nominate", ['nominee_user_id' => $memberB->id])
            ->assertCreated();

        // Optional representative seat can be cleanly skipped by Adviser
        $election->update([
            'current_position' => 'third_year_rep',
            'current_phase' => 'nomination',
            'round_number' => 1,
        ]);

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/skip-position")
            ->assertOk();

        $election->refresh();
        $this->assertSame('skipped', $election->position_results['third_year_rep']['status'] ?? null);
        $this->assertSame('fourth_year_rep', $election->current_position);

        // Skipping final optional seat marks election completed/closed and blocks reopening
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/skip-position")
            ->assertOk();

        $election->refresh();
        $this->assertSame('completed', $election->current_phase);
        $this->assertSame('closed', $election->status);

        $this->actingAs($adviser)
            ->patchJson("/elections/{$election->id}/status", ['status' => 'open'])
            ->assertStatus(422);
    }

    public function test_governance_hardening_email_verification_adviser_promotion_guard_and_guest_credentials_privacy(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');
        $memberMembership = $member->primaryMembership;

        // 1. Officer direct-add rejects assigning 'adviser' role and stamps email_verified_at cleanly
        $this->actingAs($adviser)
            ->from('/membership')
            ->post('/membership/officer-add', [
                'role' => 'adviser',
                'first_name' => 'Unauthorized',
                'last_name' => 'AdviserAttempt',
                'course' => 'BSIT',
                'year_level' => '4',
                'section' => 'A',
                'sex' => 'Female',
                'birthdate' => '2004-01-10',
                'active_email' => 'unauth.adviser.attempt@chmsu.edu.ph',
                'facebook_profile_url' => 'https://www.facebook.com/unauth.attempt',
                'cellphone_number' => '0917-111-2222',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($adviser)
            ->post('/membership/officer-add', [
                'role' => 'secretary',
                'first_name' => 'Bianca',
                'middle_name' => 'F.',
                'last_name' => 'Navarro',
                'course' => 'BSIS',
                'year_level' => '3',
                'section' => 'A',
                'sex' => 'Female',
                'birthdate' => '2005-02-14',
                'active_email' => 'bianca.verified.test@chmsu.edu.ph',
                'facebook_profile_url' => 'https://www.facebook.com/bianca.navarro.cpf',
                'cellphone_number' => '0917-333-4444',
            ])
            ->assertRedirect('/membership');

        $addedUser = User::query()->where('email', 'bianca.verified.test@chmsu.edu.ph')->firstOrFail();
        $this->assertNotNull($addedUser->email_verified_at);

        // 2. Updating a student member's role to 'adviser' directly is blocked (422)
        $this->actingAs($adviser)
            ->patchJson("/membership/{$memberMembership->id}/role", ['role' => 'adviser'])
            ->assertStatus(422);

        // 3. Guest /my-credentials never exposes another user's private info
        auth()->logout();
        $this->get('/my-credentials')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('credentials/Index')
                ->where('owner', null));
    }
}
