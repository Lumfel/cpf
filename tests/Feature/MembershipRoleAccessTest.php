<?php

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\User;

beforeEach(function () {
    $this->campus = Campus::create([
        'code' => 'TALISAY',
        'name' => 'CHMSU Talisay (Main Campus)',
        'slug' => 'talisay',
        'adviser_name' => 'Dr. Elena V. Montemayor, RGC',
        'gc_group_name' => 'CPF Talisay Official Peer GC',
        'is_active' => true,
    ]);
});

function createMemberWithRole(Campus $campus, CpfRole $role, string $name = 'Test, Member A.'): User
{
    $user = User::factory()->create(['name' => $name]);

    CampusMembership::create([
        'user_id' => $user->id,
        'campus_id' => $campus->id,
        'role' => $role,
        'status' => MembershipStatus::Approved,
        'enrollment_source' => 'officer_added',
        'recorded_email' => $user->email,
        'first_name' => 'Sample', 'middle_name' => 'A.', 'last_name' => 'Member',
        'full_name_last_first_mi' => $name,
        'course_year_section' => 'BSIT 4A',
        'sex' => 'Female',
        'gender_identity' => 'Woman',
        'birthdate' => '2004-05-10',
        'active_email' => $user->email,
        'active_fb_account' => $name.' (Smiling portrait)',
        'cellphone_number' => '0917-000-0000',
    ]);

    return $user;
}

test('ordinary registered user can submit the online CPF membership form', function () {
    $user = User::factory()->create(['email' => 'student@chmsu.edu.ph']);

    $response = $this->actingAs($user)->post('/membership/apply', [
        'campus_id' => $this->campus->id,
        'recorded_email' => 'student@chmsu.edu.ph',
        'first_name' => 'Maria', 'middle_name' => 'L.', 'last_name' => 'Santos',
        'full_name_last_first_mi' => 'Santos, Maria L.',
        'course_year_section' => 'BSIS 3A',
        'sex' => 'Female',
        'gender_identity' => 'Woman',
        'birthdate' => '2005-03-15',
        'active_email' => 'student@chmsu.edu.ph',
        'active_fb_account' => 'Maria Santos (Blue dress photo)',
        'cellphone_number' => '0917-123-4567',
    ]);

    $response->assertRedirect('/membership');
    $this->assertDatabaseHas('campus_memberships', [
        'user_id' => $user->id,
        'first_name' => 'Maria', 'middle_name' => 'L.', 'last_name' => 'Santos',
        'full_name_last_first_mi' => 'Santos, Maria L.',
        'status' => 'pending',
        'role' => 'member',
    ]);
});

test('adviser, president, and vice president can approve members while secretary can issue passkeys and treasurer cannot', function () {
    $adviser = createMemberWithRole($this->campus, CpfRole::Adviser, 'Montemayor, Elena V.');
    $president = createMemberWithRole($this->campus, CpfRole::President, 'Mercado, Sofia R.');
    $secretary = createMemberWithRole($this->campus, CpfRole::Secretary, 'Alvarez, Hannah G.');
    $treasurer = createMemberWithRole($this->campus, CpfRole::Treasurer, 'Delgado, Liam C.');

    expect($adviser->canManageCampusMembers())->toBeTrue()
        ->and($president->canManageCampusMembers())->toBeTrue()
        ->and($secretary->canManageCampusMembers())->toBeFalse()
        ->and($secretary->canIssuePasskeys())->toBeTrue()
        ->and($treasurer->canManageCampusMembers())->toBeFalse();
});

test('only adviser is in charge of assigning officer roles (no admin role)', function () {
    $adviser = createMemberWithRole($this->campus, CpfRole::Adviser, 'Montemayor, Elena V.');
    $president = createMemberWithRole($this->campus, CpfRole::President, 'Mercado, Sofia R.');
    $memberUser = createMemberWithRole($this->campus, CpfRole::Member, 'Reyes, Katrina M.');
    $membership = $memberUser->primaryMembership;

    // President cannot promote a member to Secretary
    $this->actingAs($president)
        ->patch("/membership/{$membership->id}/role", ['role' => 'secretary'])
        ->assertForbidden();

    // Club Adviser can promote a member to Secretary
    $this->actingAs($adviser)
        ->patch("/membership/{$membership->id}/role", ['role' => 'secretary'])
        ->assertRedirect('/membership');

    expect($membership->fresh()->role)->toBe(CpfRole::Secretary);
});
