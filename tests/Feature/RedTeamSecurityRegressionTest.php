<?php

namespace Tests\Feature;

use App\Actions\Security\IssueMemberPasskey;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\FirstBootSetupController;
use App\Models\AdviserHandover;
use App\Models\Campus;
use App\Models\CampusEvent;
use App\Models\CampusMembership;
use App\Models\MemberInfoChangeRequest;
use App\Models\User;
use App\Notifications\CounselorInviteOtpNotification;
use App\Services\ClubTenureService;
use Database\Seeders\BlankSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RedTeamSecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Campus $alijis;

    private Campus $inactiveCampus;

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

        $this->inactiveCampus = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay Campus',
            'slug' => 'talisay',
            'current_academic_year' => '2026-2027',
            'is_active' => false,
        ]);
    }

    private function createCampusUser(
        CpfRole $role,
        string $name,
        MembershipStatus $status = MembershipStatus::Approved,
        ?Campus $campus = null,
        bool $mustChangePassword = false,
    ): array {
        $campus ??= $this->alijis;

        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('password'),
            'must_change_password' => $mustChangePassword,
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
            'approved_at' => $status === MembershipStatus::Approved ? now() : null,
        ]);

        return [$user, $membership];
    }

    public function test_officer_add_blocks_overwriting_adviser_counselor_or_approved_member_and_blocks_counselor_role(): void
    {
        [$adviser, $adviserMembership] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor, $counselorMembership] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$president] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$member, $memberMembership] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        $basePayload = [
            'first_name' => 'Attacker',
            'last_name' => 'Overwrite',
            'course' => 'BSIT',
            'year_level' => '3',
            'section' => 'A',
            'sex' => 'Male',
            'birthdate' => '2004-01-15',
            'facebook_profile_url' => 'https://www.facebook.com/attacker.overwrite',
            'cellphone_number' => '0917-555-9999',
        ];

        // 1. President attempting to overwrite Club Adviser via officer-add -> 422, Adviser role untouched
        $this->actingAs($president)
            ->postJson('/membership/officer-add', [
                ...$basePayload,
                'active_email' => $adviser->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active_email');
        $this->assertSame(CpfRole::Adviser, $adviserMembership->fresh()->role);

        // 2. President attempting to overwrite Guidance Counselor via officer-add -> 422
        $this->actingAs($president)
            ->postJson('/membership/officer-add', [
                ...$basePayload,
                'active_email' => $counselor->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active_email');
        $this->assertSame(CpfRole::GuidanceCounselor, $counselorMembership->fresh()->role);

        // 3. Adviser attempting to overwrite existing Approved Member via officer-add -> 422
        $this->actingAs($adviser)
            ->postJson('/membership/officer-add', [
                ...$basePayload,
                'active_email' => $member->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('active_email');
        $this->assertSame('Reyes, Katrina M.', $memberMembership->fresh()->full_name_last_first_mi);

        // 4. Adviser attempting to assign guidance_counselor or adviser via officer-add -> 422
        foreach (['guidance_counselor', 'adviser'] as $forbiddenRole) {
            $this->actingAs($adviser)
                ->postJson('/membership/officer-add', [
                    ...$basePayload,
                    'active_email' => "new.{$forbiddenRole}@chmsu.edu.ph",
                    'role' => $forbiddenRole,
                ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('role');
        }
    }

    public function test_first_boot_is_permanent_one_time_latch_and_rejects_inactive_campus(): void
    {
        Campus::query()->delete();
        $this->seed(BlankSystemSeeder::class);

        $this->assertTrue(FirstBootSetupController::needsAdviserSetup());

        $inactive = Campus::where('is_active', false)->firstOrFail();

        // Inactive campus_id is rejected with 422
        $this->postJson('/first-boot/setup-adviser', [
            'campus_id' => $inactive->id,
            'academic_year' => '2026-2027',
            'first_name' => 'Elena',
            'last_name' => 'Montemayor',
            'email' => 'elena.inactive@chmsu.edu.ph',
            'password' => 'FoundingPass2026!',
            'password_confirmation' => 'FoundingPass2026!',
            'sex' => 'Female',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('campus_id');

        // Once ANY user exists (even without an approved Adviser), first-boot is permanently latched
        User::factory()->create(['email' => 'ordinary@chmsu.edu.ph']);
        $this->assertFalse(FirstBootSetupController::needsAdviserSetup());

        $active = Campus::where('is_active', true)->firstOrFail();
        $this->postJson('/first-boot/setup-adviser', [
            'campus_id' => $active->id,
            'academic_year' => '2026-2027',
            'first_name' => 'Intruder',
            'last_name' => 'Takeover',
            'email' => 'intruder@chmsu.edu.ph',
            'password' => 'IntruderPass2026!',
            'password_confirmation' => 'IntruderPass2026!',
            'sex' => 'Male',
        ])->assertForbidden();
    }

    public function test_passkey_issuance_protects_adviser_counselor_and_equal_or_higher_ranks(): void
    {
        [$adviser, $adviserMembership] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor, $counselorMembership] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$president, $presidentMembership] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$vp, $vpMembership] = $this->createCampusUser(CpfRole::VicePresident, 'Bautista, Miguel A.');
        [$secretary, $secretaryMembership] = $this->createCampusUser(CpfRole::Secretary, 'Alvarez, Hannah G.');
        [$member, $memberMembership] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        // Nobody (including Adviser) may issue passkeys for Adviser or GuidanceCounselor via officer endpoint
        foreach ([$adviser, $president, $vp, $secretary] as $actor) {
            $this->actingAs($actor)->postJson("/passkeys/{$adviserMembership->id}/issue")->assertForbidden();
            $this->actingAs($actor)->postJson("/passkeys/{$counselorMembership->id}/issue")->assertForbidden();
        }

        // Equal or higher rank targets are forbidden (must use /my-passkey/regenerate)
        $this->actingAs($secretary)->postJson("/passkeys/{$presidentMembership->id}/issue")->assertForbidden();
        $this->actingAs($secretary)->postJson("/passkeys/{$vpMembership->id}/issue")->assertForbidden();
        $this->actingAs($secretary)->postJson("/passkeys/{$secretaryMembership->id}/issue")->assertForbidden();
        $this->actingAs($vp)->postJson("/passkeys/{$presidentMembership->id}/issue")->assertForbidden();
        $this->actingAs($president)->postJson("/passkeys/{$presidentMembership->id}/issue")->assertForbidden();

        // Lower-ranking student member is allowed; Adviser & Counselor can still self-regenerate
        $this->actingAs($secretary)->postJson("/passkeys/{$memberMembership->id}/issue")->assertOk();
        $this->actingAs($adviser)->postJson('/my-passkey/regenerate', ['regenerate' => true])->assertOk();
        $this->actingAs($counselor)->postJson('/my-passkey/regenerate', ['regenerate' => true])->assertOk();
    }

    public function test_approve_enforces_pending_only_in_policy_and_controller(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [, $approvedMembership] = $this->createCampusUser(CpfRole::Member, 'Approved, Anna B.', MembershipStatus::Approved);
        [, $rejectedMembership] = $this->createCampusUser(CpfRole::Member, 'Rejected, Carl D.', MembershipStatus::Rejected);
        [, $alumniMembership] = $this->createCampusUser(CpfRole::Member, 'Alumni, Dana E.', MembershipStatus::Alumni);
        [, $pendingMembership] = $this->createCampusUser(CpfRole::Member, 'Pending, Evan F.', MembershipStatus::Pending);

        // Policy denies non-pending memberships and allows pending
        $this->assertFalse(Gate::forUser($adviser)->allows('addOrApproveMember', $approvedMembership));
        $this->assertFalse(Gate::forUser($adviser)->allows('addOrApproveMember', $rejectedMembership));
        $this->assertFalse(Gate::forUser($adviser)->allows('addOrApproveMember', $alumniMembership));
        $this->assertTrue(Gate::forUser($adviser)->allows('addOrApproveMember', $pendingMembership));

        // Controller aborts 422 for non-pending without issuing/leaking passkeys
        foreach ([$approvedMembership, $rejectedMembership, $alumniMembership] as $nonPending) {
            $this->actingAs($adviser)
                ->patch("/membership/{$nonPending->id}/approve")
                ->assertStatus(422)
                ->assertSessionMissing('passkey_issued_notice');
        }
    }

    public function test_passkey_login_honors_fortify_2fa_sets_must_change_password_and_prevents_email_enumeration(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$member, $memberMembership] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        $issued = app(IssueMemberPasskey::class)->handle($adviser, $memberMembership, true, false);
        $plainCode = $issued['enrollment_code'];

        // 1. Generic error message across unknown email, bad length, and existing user with wrong code
        $unknownErr = $this->postJson('/login/passkey-code', [
            'email' => 'does-not-exist@chmsu.edu.ph',
            'passkey_code' => 'AAAA-BBBB-CCCC',
        ])->assertStatus(422)->json('errors.passkey_code.0');

        $wrongCodeErr = $this->postJson('/login/passkey-code', [
            'email' => $member->email,
            'passkey_code' => 'ZZZZ-YYYY-XXXX',
        ])->assertStatus(422)->json('errors.passkey_code.0');

        $shortCodeErr = $this->postJson('/login/passkey-code', [
            'email' => $member->email,
            'passkey_code' => 'SHORT',
        ])->assertStatus(422)->json('errors.passkey_code.0');

        $this->assertSame('Invalid email or passkey code.', $unknownErr);
        $this->assertSame($unknownErr, $wrongCodeErr);
        $this->assertSame($unknownErr, $shortCodeErr);

        // 2. User with Fortify 2FA enabled is redirected to two-factor.login (NOT logged in yet)
        $member->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->post('/login/passkey-code', [
            'email' => $member->email,
            'passkey_code' => $plainCode,
        ])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $member->id);

        $this->assertGuest();
        $this->assertTrue((bool) $member->fresh()->must_change_password);
    }

    public function test_public_apply_refuses_existing_email_without_acting_as_existing_user(): void
    {
        [, $pendingMembership] = $this->createCampusUser(CpfRole::Member, 'Pending, User A.', MembershipStatus::Pending);
        [$ordinaryUser] = [User::factory()->create(['email' => 'ordinary.existing@chmsu.edu.ph'])];

        foreach ([$pendingMembership->active_email, $ordinaryUser->email] as $existingEmail) {
            $this->postJson('/membership/apply', [
                'campus_id' => $this->alijis->id,
                'first_name' => 'Spoof',
                'last_name' => 'Applicant',
                'course' => 'BSIT',
                'year_level' => '2',
                'section' => 'A',
                'sex' => 'Male',
                'birthdate' => '2005-03-10',
                'active_email' => $existingEmail,
                'facebook_profile_url' => 'https://www.facebook.com/spoof.applicant',
                'cellphone_number' => '0917-555-0099',
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('active_email');
        }

        $this->assertDatabaseMissing('campus_memberships', [
            'user_id' => $ordinaryUser->id,
        ]);
    }

    public function test_counselor_invitation_blocks_existing_users_hides_secrets_and_verifies_inviter_still_adviser(): void
    {
        Notification::fake();

        [$adviser, $adviserMembership] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$alumniUser] = $this->createCampusUser(CpfRole::Member, 'Former, Alumni X.', MembershipStatus::Alumni);

        // Existing user (even Alumni) cannot be invited as new counselor
        $this->actingAs($adviser)
            ->postJson('/counselor-invitations', [
                'email' => $alumniUser->email,
            ])
            ->assertStatus(422);

        // Fresh email invited -> secrets NOT returned in non-local JSON
        $this->actingAs($adviser)
            ->postJson('/counselor-invitations', [
                'email' => 'revoked.inviter@chmsu.edu.ph',
            ])
            ->assertCreated()
            ->assertJsonMissingPath('otp_code')
            ->assertJsonMissingPath('invite_url');

        $inviteUrl = '';
        $otpCode = '';
        Notification::assertSentOnDemand(
            CounselorInviteOtpNotification::class,
            function (CounselorInviteOtpNotification $n, array $channels, object $notifiable) use (&$inviteUrl, &$otpCode): bool {
                if (($notifiable->routes['mail'] ?? null) !== 'revoked.inviter@chmsu.edu.ph') {
                    return false;
                }
                $inviteUrl = $n->inviteUrl;
                $otpCode = $n->otpCode;

                return true;
            },
        );

        $showRes = $this->get($inviteUrl)->assertOk();
        $acceptUrl = (string) $showRes->viewData('page')['props']['acceptUrl'];

        // Transition inviter away from Club Adviser before acceptance -> accept() must return 403
        app(ClubTenureService::class)->transitionToAlumni($adviserMembership, $adviser, 'adviser_handover');

        auth()->logout();
        $this->postJson($acceptUrl, [
            'otp_code' => $otpCode,
            'first_name' => 'Late',
            'last_name' => 'Counselor',
            'sex' => 'Female',
            'password' => 'CounselorPass2026!',
            'password_confirmation' => 'CounselorPass2026!',
        ])->assertForbidden();
    }

    public function test_role_hierarchy_blocks_vp_demoting_president_counselor_reassignment_and_double_change_review(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [, $counselorMembership] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$president, $presidentMembership] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$vp, $vpMembership] = $this->createCampusUser(CpfRole::VicePresident, 'Bautista, Miguel A.');
        [$member, $memberMembership] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        // VP cannot demote President (rank 80 <= 90)
        $this->actingAs($vp)
            ->patch("/membership/{$presidentMembership->id}/role", ['role' => 'member'])
            ->assertForbidden();
        $this->assertSame(CpfRole::President, $presidentMembership->fresh()->role);

        // President CAN demote VP (rank 90 > 80)
        $this->actingAs($president)
            ->patch("/membership/{$vpMembership->id}/role", ['role' => 'member'])
            ->assertRedirect('/membership');
        $this->assertSame(CpfRole::Member, $vpMembership->fresh()->role);

        // Adviser cannot move a Guidance Counselor into student club roles -> 422
        $this->actingAs($adviser)
            ->patch("/membership/{$counselorMembership->id}/role", ['role' => 'secretary'])
            ->assertStatus(422);
        $this->assertSame(CpfRole::GuidanceCounselor, $counselorMembership->fresh()->role);

        // Reviewing an already-reviewed change request returns 422
        $changeReq = MemberInfoChangeRequest::create([
            'campus_membership_id' => $memberMembership->id,
            'campus_id' => $this->alijis->id,
            'user_id' => $member->id,
            'requested_changes' => ['section' => ['old' => 'A', 'new' => 'B']],
            'reason' => 'Section change',
            'status' => 'pending',
        ]);

        $this->actingAs($adviser)
            ->patch("/membership/change-requests/{$changeReq->id}/review", ['decision' => 'approved'])
            ->assertRedirect('/membership');

        $this->actingAs($adviser)
            ->patch("/membership/change-requests/{$changeReq->id}/review", ['decision' => 'declined'])
            ->assertStatus(422);
    }

    public function test_adviser_handover_invite_new_is_pending_counselor_revokes_expired_and_gates_temp_password_routes(): void
    {
        [$adviser, $adviserMembership] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');

        $initRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'password',
                'first_name' => 'Incoming',
                'last_name' => 'Counselor',
                'email' => 'incoming.handover@chmsu.edu.ph',
                'sex' => 'Female',
                'initial_password' => 'TempCounselor2026!',
            ])
            ->assertCreated();

        $handoverId = (int) $initRes->json('handover.id');
        $code = (string) $initRes->json('handover.confirmation_code');
        $incomingUser = User::where('email', 'incoming.handover@chmsu.edu.ph')->firstOrFail();
        $incomingMembership = $incomingUser->primaryMembership;

        // Incoming invite_new account starts as Pending GuidanceCounselor with has_paid_dues = false
        $this->assertSame(CpfRole::GuidanceCounselor, $incomingMembership->role);
        $this->assertSame(MembershipStatus::Pending, $incomingMembership->status);
        $this->assertFalse((bool) $incomingMembership->has_paid_dues);
        $this->assertNull($incomingUser->verifiedRole());

        // Temporary-password users are gated from profile-photos and settings routes
        $this->actingAs($incomingUser)->get("/profile-photos/{$adviser->id}")->assertRedirect(route('password.force-change.edit'));
        $this->actingAs($incomingUser)->get('/settings/profile')->assertRedirect(route('password.force-change.edit'));
        $this->actingAs($incomingUser)->get('/settings/security')->assertRedirect(route('password.force-change.edit'));

        // Clear temp password and simulate handover expiration -> respond() marks expired and rejects pending membership
        $incomingUser->forceFill(['must_change_password' => false, 'temporary_password_issued_at' => null])->save();
        AdviserHandover::whereKey($handoverId)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($incomingUser)
            ->postJson("/adviser-handover/{$handoverId}/respond", [
                'decision' => 'accepted',
                'confirmation_code' => $code,
            ])
            ->assertStatus(422);

        $this->assertSame('expired', AdviserHandover::findOrFail($handoverId)->status);
        $this->assertSame(MembershipStatus::Rejected, $incomingMembership->fresh()->status);

        // Verify throttle:5,1 is attached to adviser-handover store and cancel routes
        $this->assertContains('throttle:5,1', Route::getRoutes()->getByName('adviser-handover.store')->gatherMiddleware());
        $this->assertContains('throttle:5,1', Route::getRoutes()->getByName('adviser-handover.cancel')->gatherMiddleware());

        // Verify respond() fails with 422 if outgoing adviser is no longer active approved adviser
        [$counselor] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Active, Counselor Z.');
        $init2 = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'mode' => 'existing_counselor',
                'target_membership_id' => $counselor->primaryMembership->id,
                'current_password' => 'password',
            ])
            ->assertCreated();

        app(ClubTenureService::class)->transitionToAlumni($adviserMembership, $adviser, 'stepped_down');

        $this->actingAs($counselor)
            ->postJson('/adviser-handover/'.$init2->json('handover.id').'/respond', [
                'decision' => 'accepted',
                'confirmation_code' => $init2->json('handover.confirmation_code'),
            ])
            ->assertStatus(422);
    }

    public function test_api_v1_enforces_must_change_password_gate_throttles_and_events_contract(): void
    {
        [$officer] = $this->createCampusUser(
            CpfRole::President,
            'Mercado, Sofia R.',
            MembershipStatus::Approved,
            $this->alijis,
            mustChangePassword: true,
        );

        $event = CampusEvent::create([
            'campus_id' => $this->alijis->id,
            'title' => 'Active Gate Event',
            'event_date' => now()->format('Y-m-d'),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'approved',
            'created_by_user_id' => $officer->id,
            'approved_by_user_id' => $officer->id,
            'approved_at' => now(),
        ]);

        $token = $officer->createToken('TempPhone')->plainTextToken;

        // /api/v1/auth/me stays accessible while must_change_password = true
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        // All other /api/v1/* endpoints return JSON 403 must_change_password_required
        foreach ([
            '/api/v1/events',
            '/api/v1/membership/roster',
            '/api/v1/payments',
            '/api/v1/referrals/mine',
            '/api/v1/elections/active',
        ] as $endpoint) {
            app('auth')->forgetGuards();
            $this->withToken($token)
                ->getJson($endpoint)
                ->assertForbidden()
                ->assertJsonPath('code', 'must_change_password_required');
        }

        // Clearing must_change_password unlocks /api/v1/events and verifies can_scan_events + can_scan contract
        $officer->forceFill(['must_change_password' => false])->save();
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('can_scan_events', true)
            ->assertJsonPath('can_scan', true)
            ->assertJsonPath('events.0.id', $event->id)
            ->assertJsonPath('events.0.can_scan_events', true)
            ->assertJsonPath('events.0.can_scan', true);

        // /api/v1/auth/logout stays accessible even if must_change_password is re-flagged
        $officer->forceFill(['must_change_password' => true])->save();
        app('auth')->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('status', 'logged_out');

        // Verify throttle middleware is present on all mutating /api/v1/* endpoints
        $mutatingRouteNames = [
            'api.v1.events.scan',
            'api.v1.membership.toggle-gc',
            'api.v1.membership.toggle-dues',
            'api.v1.payments.submit',
            'api.v1.payments.validate',
            'api.v1.referrals.store',
            'api.v1.elections.nominate',
            'api.v1.elections.vote',
            'api.v1.elections.live-chat.store',
        ];

        foreach ($mutatingRouteNames as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Expected route {$routeName} to exist.");
            $hasThrottle = collect($route->gatherMiddleware())->contains(
                fn (string $mw) => str_starts_with($mw, 'throttle:'),
            );
            $this->assertTrue($hasThrottle, "Expected route {$routeName} to have throttle middleware.");
        }
    }
}
