<?php

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\DuesAuditLog;
use App\Models\OfficerGcChannel;
use App\Models\User;
use App\Services\ProfanityFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeApprovedMember(Campus $campus, CpfRole $role, string $name, string $email): array
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => $email,
        'email_verified_at' => now(),
        'must_change_password' => false,
    ]);

    $membership = CampusMembership::create([
        'user_id' => $user->id,
        'campus_id' => $campus->id,
        'role' => $role,
        'status' => MembershipStatus::Approved,
        'enrollment_source' => 'manual',
        'recorded_email' => $email,
        'active_email' => $email,
        'first_name' => $name,
        'last_name' => 'CHMSU',
        'full_name_last_first_mi' => "CHMSU, {$name}",
        'approved_at' => now(),
    ]);

    return [$user, $membership];
}

test('multilingual profanity filter blocks EN/ES/HIL/CEB/TL including leetspeak and passes clean text', function () {
    $filter = app(ProfanityFilterService::class);

    // Restricted terms across all 5 languages + obfuscation
    expect($filter->containsProfanity('What the f u c k is going on'))->toBeTrue();
    expect($filter->containsProfanity('Eres un pendejo cabron'))->toBeTrue();
    expect($filter->containsProfanity('Grabe ka g@go putangina'))->toBeTrue();
    expect($filter->containsProfanity('Yudiputa linti ka gid'))->toBeTrue();
    expect($filter->containsProfanity('Pisti y@wa atay ni'))->toBeTrue();

    // Clean academic/campus phrases must not false-positive
    expect($filter->containsProfanity('Good morning class! Please assist the Guidance Counselor at the Testing Room.'))->toBeFalse();
    expect($filter->containsProfanity('Maayong aga mga peer facilitators sa CHMSU Alijis!'))->toBeFalse();
});

test('only Club Adviser can toggle campus mandatory_tour_enabled setting', function () {
    $campus = Campus::create([
        'code' => 'ALIJIS',
        'name' => 'CHMSU Alijis Campus',
        'slug' => 'alijis',
        'current_academic_year' => '2026-2027',
        'is_active' => true,
        'mandatory_tour_enabled' => true,
    ]);

    [$adviser] = makeApprovedMember($campus, CpfRole::Adviser, 'Adviser', 'adviser@chmsu.edu.ph');
    [$president] = makeApprovedMember($campus, CpfRole::President, 'President', 'pres@chmsu.edu.ph');

    $this->actingAs($president)
        ->patch('/settings/campus-tour', ['mandatory_tour_enabled' => false])
        ->assertStatus(403);

    $this->actingAs($adviser)
        ->patch('/settings/campus-tour', ['mandatory_tour_enabled' => false])
        ->assertRedirect();

    expect($campus->fresh()->mandatory_tour_enabled)->toBeFalse();
});

test('guidance counselor is blocked from Officers GC but can use Counselors GC and 1-on-1 Counselor DM with profanity guard', function () {
    $campus = Campus::create([
        'code' => 'ALIJIS',
        'name' => 'CHMSU Alijis Campus',
        'slug' => 'alijis',
        'current_academic_year' => '2026-2027',
        'is_active' => true,
    ]);

    [$adviser] = makeApprovedMember($campus, CpfRole::Adviser, 'Adviser', 'adviser@chmsu.edu.ph');
    [$rgc] = makeApprovedMember($campus, CpfRole::GuidanceCounselor, 'Counselor A', 'rgc@chmsu.edu.ph');

    $channel = OfficerGcChannel::create([
        'campus_id' => $campus->id,
        'academic_year' => '2026-2027',
        'channel_key' => "campus.{$campus->id}.officers-gc.2026-2027",
        'is_active' => true,
    ]);

    // Blocked from Officers GC
    $this->actingAs($rgc)
        ->postJson("/officers-gc/{$channel->id}/messages", ['message_body' => 'Hello officers'])
        ->assertStatus(403);

    // Allowed in Counselors GC & 1-on-1 DM
    $this->actingAs($rgc)
        ->postJson('/counselors-gc/messages', ['message_body' => 'Testing Room slot confirmed for 10 AM.'])
        ->assertStatus(201);

    $this->actingAs($rgc)
        ->postJson("/counselors-dm/{$adviser->id}/messages", ['message_body' => 'Quick check-in on Tier 2 referral.'])
        ->assertStatus(201);

    $this->actingAs($adviser)
        ->getJson("/counselors-dm/{$rgc->id}/messages")
        ->assertOk()
        ->assertJsonCount(1, 'messages');

    // Profanity blocked in Counselor DM
    $this->actingAs($rgc)
        ->postJson("/counselors-dm/{$adviser->id}/messages", ['message_body' => 'This is yawa gago'])
        ->assertStatus(422);
});

test('dues toggle writes immutable DuesAuditLog and Adviser privilege suspension blocks rogue officer and revokes tokens', function () {
    $campus = Campus::create([
        'code' => 'ALIJIS',
        'name' => 'CHMSU Alijis Campus',
        'slug' => 'alijis',
        'current_academic_year' => '2026-2027',
        'is_active' => true,
    ]);

    [$adviser] = makeApprovedMember($campus, CpfRole::Adviser, 'Adviser', 'adviser@chmsu.edu.ph');
    [$treasurer, $treasurerMembership] = makeApprovedMember($campus, CpfRole::Treasurer, 'Treasurer', 'treasurer@chmsu.edu.ph');
    [$auditor] = makeApprovedMember($campus, CpfRole::Auditor, 'Auditor', 'auditor@chmsu.edu.ph');
    [, $memberMembership] = makeApprovedMember($campus, CpfRole::Member, 'Student', 'student@chmsu.edu.ph');

    $treasurer->createToken('mobile-test');
    expect($treasurer->tokens()->count())->toBe(1);

    // Treasurer toggles dues -> creates immutable DuesAuditLog
    $this->actingAs($treasurer)
        ->patch("/membership/{$memberMembership->id}/toggle-dues", ['reason' => 'Cash paid during orientation'])
        ->assertRedirect();

    expect(DuesAuditLog::count())->toBe(1);

    // Auditor can inspect immutable dues audit trail
    $this->actingAs($auditor)
        ->getJson('/membership/dues-audit-logs')
        ->assertOk()
        ->assertJsonCount(1, 'logs');

    // Adviser triggers Conflict Freeze (Suspend Officer Privileges)
    $this->actingAs($adviser)
        ->patchJson("/membership/{$treasurerMembership->id}/suspend-privileges", [
            'suspend' => true,
            'reason' => 'Financial audit review in progress',
        ])
        ->assertOk()
        ->assertJson(['status' => 'suspended']);

    expect($treasurer->fresh()->tokens()->count())->toBe(0);
    expect($treasurer->fresh()->canValidatePayments())->toBeFalse();

    // Suspended Treasurer is now blocked (403) from toggling dues
    $this->actingAs($treasurer->fresh())
        ->patch("/membership/{$memberMembership->id}/toggle-dues", ['reason' => 'Attempt while suspended'])
        ->assertStatus(403);
});

test('new low-end-server scheduled automation commands execute cleanly', function () {
    $this->artisan('cpf:purge-expired-election-chat')->assertSuccessful();
    $this->artisan('cpf:alert-stale-referrals')->assertSuccessful();
    $this->artisan('cpf:expire-stale-invites')->assertSuccessful();
    $this->artisan('cpf:remind-upcoming-events')->assertSuccessful();
});
