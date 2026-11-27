<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay (Main Campus)',
            'slug' => 'talisay',
            'adviser_name' => 'Dr. Elena V. Montemayor, RGC',
            'gc_group_name' => 'CPF Talisay Official Peer GC',
            'is_active' => true,
        ]);
    }

    private function createMemberWithRole(Campus $campus, CpfRole $role, string $name = 'Test, Member A.'): User
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
            'active_fb_account' => $name.' (Smiling portrait)',
            'cellphone_number' => '0917-000-0000',
        ]);

        return $user;
    }

    public function test_ordinary_user_and_guest_can_submit_public_cpf_membership_form(): void
    {
        $this->createMemberWithRole($this->campus, CpfRole::Adviser, 'Montemayor, Elena V.');

        $this->from('/')
            ->post('/membership/apply', [
                'campus_id' => $this->campus->id,
                'first_name' => 'Maria',
                'middle_name' => 'L.',
                'last_name' => 'Santos',
                'course' => 'BSIS',
                'year_level' => '3',
                'section' => 'A',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '2005-03-15',
                'active_email' => 'maria.guest@chmsu.edu.ph',
                'facebook_profile_url' => 'https://www.facebook.com/maria.santos.cpf',
                'active_fb_account' => 'Maria Santos (Blue dress photo)',
                'cellphone_number' => '0917-123-4567',
            ])
            ->assertRedirect('/');

        $this->assertDatabaseHas('campus_memberships', [
            'campus_id' => $this->campus->id,
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'course_year_section' => 'BSIS 3A',
            'status' => 'pending',
            'role' => 'member',
        ]);

        $user = User::factory()->create([
            'email' => 'student@chmsu.edu.ph',
            'must_change_password' => false,
        ]);

        $this->actingAs($user)
            ->from('/membership')
            ->post('/membership/apply', [
                'campus_id' => $this->campus->id,
                'first_name' => 'Carlos',
                'middle_name' => 'R.',
                'last_name' => 'Rivera',
                'course' => 'BSIT',
                'year_level' => '2',
                'section' => 'B',
                'sex' => 'Male',
                'gender_identity' => 'Man',
                'birthdate' => '2006-04-20',
                'active_email' => 'student@chmsu.edu.ph',
                'facebook_profile_url' => 'https://www.facebook.com/carlos.rivera.cpf',
                'active_fb_account' => 'Carlos Rivera',
                'cellphone_number' => '0917-222-3333',
            ])
            ->assertRedirect('/membership');

        $this->assertDatabaseHas('campus_memberships', [
            'user_id' => $user->id,
            'full_name_last_first_mi' => 'Rivera, Carlos R.',
            'course_year_section' => 'BSIT 2B',
            'status' => 'pending',
            'role' => 'member',
        ]);
    }

    public function test_adviser_president_and_vp_can_approve_members_while_secretary_can_issue_passkeys_and_treasurer_cannot(): void
    {
        $adviser = $this->createMemberWithRole($this->campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMemberWithRole($this->campus, CpfRole::President, 'Mercado, Sofia R.');
        $secretary = $this->createMemberWithRole($this->campus, CpfRole::Secretary, 'Alvarez, Hannah G.');
        $treasurer = $this->createMemberWithRole($this->campus, CpfRole::Treasurer, 'Delgado, Liam C.');

        $this->assertTrue($adviser->canManageCampusMembers());
        $this->assertTrue($president->canManageCampusMembers());
        $this->assertFalse($secretary->canManageCampusMembers());
        $this->assertTrue($secretary->canIssuePasskeys());
        $this->assertFalse($treasurer->canManageCampusMembers());
    }

    public function test_manual_role_switching_is_disabled_in_favor_of_elections_handover_and_invites(): void
    {
        $adviser = $this->createMemberWithRole($this->campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMemberWithRole($this->campus, CpfRole::President, 'Mercado, Sofia R.');
        $memberUser = $this->createMemberWithRole($this->campus, CpfRole::Member, 'Reyes, Katrina M.');
        $membership = $memberUser->primaryMembership;

        // President cannot manually switch a member's role (403)
        $this->actingAs($president)
            ->patchJson("/membership/{$membership->id}/role", ['role' => 'secretary'])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Officer roles are assigned through General Assembly Elections, Adviser Handover, or Counselor Invitations.',
            );

        // Even the Club Adviser cannot manually override roles via PATCH /membership/{id}/role (422)
        $this->actingAs($adviser)
            ->patchJson("/membership/{$membership->id}/role", ['role' => 'secretary'])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Officer roles are assigned through General Assembly Elections, Adviser Handover, or Counselor Invitations.',
            );

        $this->assertSame(CpfRole::Member, $membership->fresh()->role);
    }

    public function test_unauthenticated_requests_to_officer_membership_routes_redirect_to_login(): void
    {
        $memberUser = $this->createMemberWithRole($this->campus, CpfRole::Member, 'Reyes, Katrina M.');
        $membershipId = $memberUser->primaryMembership->id;

        $this->get('/membership')->assertRedirect('/login');
        $this->post('/membership/officer-add', [])->assertRedirect('/login');
        $this->patch("/membership/{$membershipId}/approve")->assertRedirect('/login');
        $this->patch("/membership/{$membershipId}/toggle-gc")->assertRedirect('/login');
        $this->patch("/membership/{$membershipId}/toggle-dues")->assertRedirect('/login');
        $this->patch("/membership/{$membershipId}/role", ['role' => 'secretary'])->assertRedirect('/login');
    }

    public function test_passkey_code_login_is_rate_limited_to_five_attempts_per_minute(): void
    {
        $server = ['REMOTE_ADDR' => '10.99.99.99'];

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables($server)
                ->from('/login')
                ->post('/login/passkey-code', [
                    'email' => 'rate-limit@chmsu.edu.ph',
                    'passkey_code' => 'AAAA-BBBB-CCCC',
                ])
                ->assertSessionHasErrors('passkey_code');
        }

        $this->withServerVariables($server)
            ->post('/login/passkey-code', [
                'email' => 'rate-limit@chmsu.edu.ph',
                'passkey_code' => 'AAAA-BBBB-CCCC',
            ])
            ->assertStatus(429);
    }

    public function test_verified_officers_can_view_minimal_member_qr_card_without_extra_pii(): void
    {
        $adviser = $this->createMemberWithRole($this->campus, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMemberWithRole($this->campus, CpfRole::President, 'Mercado, Sofia R.');
        $memberUser = $this->createMemberWithRole($this->campus, CpfRole::Member, 'Tibo-oc, Paul Felippe G.');
        $targetMembership = $memberUser->primaryMembership;

        // Pending membership cannot generate a QR card yet
        $pendingUser = User::factory()->create(['must_change_password' => false]);
        $pendingMembership = CampusMembership::create([
            'user_id' => $pendingUser->id,
            'campus_id' => $this->campus->id,
            'role' => CpfRole::Member,
            'status' => MembershipStatus::Pending,
            'enrollment_source' => 'online_form',
            'recorded_email' => $pendingUser->email,
            'first_name' => 'Pending',
            'last_name' => 'Applicant',
            'full_name_last_first_mi' => 'Applicant, Pending',
            'course' => 'BSIT',
            'year_level' => '2',
            'section' => 'B',
            'course_year_section' => 'BSIT 2B',
            'sex' => 'Male',
            'birthdate' => '2005-01-01',
            'active_email' => $pendingUser->email,
            'active_fb_account' => 'Pending Applicant',
            'cellphone_number' => '0917-111-1111',
        ]);

        // Unauthenticated -> 401/redirect
        $this->getJson("/membership/{$targetMembership->id}/qr")->assertUnauthorized();

        // Ordinary member -> 403 Forbidden
        $this->actingAs($memberUser)
            ->getJson("/membership/{$targetMembership->id}/qr")
            ->assertForbidden();

        // Officer from another campus -> 403 Forbidden
        $otherCampus = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'adviser_name' => 'Another Adviser',
            'gc_group_name' => 'CPF Alijis GC',
            'is_active' => true,
        ]);
        $otherPresident = $this->createMemberWithRole($otherCampus, CpfRole::President, 'Cruz, Ana B.');
        $this->actingAs($otherPresident)
            ->getJson("/membership/{$targetMembership->id}/qr")
            ->assertForbidden();

        // Pending member -> 422
        $this->actingAs($adviser)
            ->getJson("/membership/{$pendingMembership->id}/qr")
            ->assertStatus(422);

        // Verified Campus Officer receives ONLY QR SVG + Name + Course + Year + Section
        $response = $this->actingAs($president)
            ->getJson("/membership/{$targetMembership->id}/qr")
            ->assertOk()
            ->assertJsonPath('full_name', 'Tibo-oc, Paul Felippe G.')
            ->assertJsonPath('course', 'BSIT')
            ->assertJsonPath('year_level', '4')
            ->assertJsonPath('section', 'A')
            ->assertJsonPath('course_year_section', 'BSIT 4A')
            ->assertJsonMissingPath('active_email')
            ->assertJsonMissingPath('cellphone_number')
            ->assertJsonMissingPath('birthdate')
            ->assertJsonMissingPath('facebook_profile_url')
            ->assertJsonMissingPath('role');

        $this->assertStringContainsString('<svg', (string) $response->json('qr_svg'));
    }
}
