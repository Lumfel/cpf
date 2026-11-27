<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\DuesAuditLog;
use App\Models\OfficerGcChannel;
use App\Models\User;
use App\Services\ProfanityFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfAssessmentGovernanceAndChatTest extends TestCase
{
    use RefreshDatabase;

    private function makeApprovedMember(Campus $campus, CpfRole $role, string $name, string $email): array
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
            'course_year_section' => 'BSIT 2-A',
            'sex' => 'Female',
            'birthdate' => '2004-05-15',
            'facebook_profile_url' => 'https://facebook.com/chmsu.peer',
            'active_fb_account' => $name,
            'cellphone_number' => '09171234567',
            'approved_at' => now(),
        ]);

        return [$user, $membership];
    }

    public function test_multilingual_profanity_filter_blocks_en_es_hil_ceb_tl_including_leetspeak_and_passes_clean_text(): void
    {
        $filter = app(ProfanityFilterService::class);

        $this->assertTrue($filter->containsProfanity('What the f u c k is going on'));
        $this->assertTrue($filter->containsProfanity('Eres un pendejo cabron'));
        $this->assertTrue($filter->containsProfanity('Grabe ka g@go putangina'));
        $this->assertTrue($filter->containsProfanity('Yudiputa linti ka gid'));
        $this->assertTrue($filter->containsProfanity('Pisti y@wa atay ni'));

        $this->assertFalse($filter->containsProfanity('Good morning class! Please assist the Guidance Counselor at the Testing Room.'));
        $this->assertFalse($filter->containsProfanity('Maayong aga mga peer facilitators sa CHMSU Alijis!'));
    }

    public function test_only_club_adviser_can_toggle_campus_mandatory_tour_enabled_setting(): void
    {
        $campus = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'current_academic_year' => '2026-2027',
            'is_active' => true,
            'mandatory_tour_enabled' => true,
        ]);

        [$adviser] = $this->makeApprovedMember($campus, CpfRole::Adviser, 'Adviser', 'adviser@chmsu.edu.ph');
        [$president] = $this->makeApprovedMember($campus, CpfRole::President, 'President', 'pres@chmsu.edu.ph');

        $this->actingAs($president)
            ->patch('/settings/campus-tour', ['mandatory_tour_enabled' => false])
            ->assertStatus(403);

        $this->actingAs($adviser)
            ->patch('/settings/campus-tour', ['mandatory_tour_enabled' => false])
            ->assertRedirect();

        $this->assertFalse($campus->fresh()->mandatory_tour_enabled);
    }

    public function test_guidance_counselor_is_blocked_from_officers_gc_but_can_use_counselors_gc_and_1_on_1_counselor_dm_with_profanity_guard(): void
    {
        $campus = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'current_academic_year' => '2026-2027',
            'is_active' => true,
        ]);

        [$adviser] = $this->makeApprovedMember($campus, CpfRole::Adviser, 'Adviser', 'adviser@chmsu.edu.ph');
        [$rgc] = $this->makeApprovedMember($campus, CpfRole::GuidanceCounselor, 'Counselor A', 'rgc@chmsu.edu.ph');

        $channel = OfficerGcChannel::create([
            'campus_id' => $campus->id,
            'academic_year' => '2026-2027',
            'channel_key' => "campus.{$campus->id}.officers-gc.2026-2027",
            'is_active' => true,
        ]);

        $this->actingAs($rgc)
            ->postJson("/officers-gc/{$channel->id}/messages", ['message_body' => 'Hello officers'])
            ->assertStatus(403);

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

        $this->actingAs($rgc)
            ->postJson("/counselors-dm/{$adviser->id}/messages", ['message_body' => 'This is yawa gago'])
            ->assertStatus(422);
    }

    public function test_dues_toggle_writes_immutable_dues_audit_log_and_adviser_privilege_suspension_blocks_rogue_officer_and_revokes_tokens(): void
    {
        $campus = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'current_academic_year' => '2026-2027',
            'is_active' => true,
        ]);

        [$adviser] = $this->makeApprovedMember($campus, CpfRole::Adviser, 'Adviser', 'adviser@chmsu.edu.ph');
        [$treasurer, $treasurerMembership] = $this->makeApprovedMember($campus, CpfRole::Treasurer, 'Treasurer', 'treasurer@chmsu.edu.ph');
        [$auditor] = $this->makeApprovedMember($campus, CpfRole::Auditor, 'Auditor', 'auditor@chmsu.edu.ph');
        [, $memberMembership] = $this->makeApprovedMember($campus, CpfRole::Member, 'Student', 'student@chmsu.edu.ph');

        $treasurer->createToken('mobile-test');
        $this->assertSame(1, $treasurer->tokens()->count());

        $this->actingAs($treasurer)
            ->patch("/membership/{$memberMembership->id}/toggle-dues", ['reason' => 'Cash paid during orientation'])
            ->assertRedirect();

        $this->assertSame(1, DuesAuditLog::count());

        $this->actingAs($auditor)
            ->getJson('/membership/dues-audit-logs')
            ->assertOk()
            ->assertJsonCount(1, 'logs');

        $this->actingAs($adviser)
            ->patchJson("/membership/{$treasurerMembership->id}/suspend-privileges", [
                'suspend' => true,
                'reason' => 'Financial audit review in progress',
            ])
            ->assertOk()
            ->assertJson(['status' => 'suspended']);

        $this->assertSame(0, $treasurer->fresh()->tokens()->count());
        $this->assertFalse($treasurer->fresh()->canValidatePayments());

        $this->actingAs($treasurer->fresh())
            ->patch("/membership/{$memberMembership->id}/toggle-dues", ['reason' => 'Attempt while suspended'])
            ->assertStatus(403);
    }

    public function test_new_low_end_server_scheduled_automation_commands_execute_cleanly(): void
    {
        $this->artisan('cpf:purge-expired-election-chat')->assertSuccessful();
        $this->artisan('cpf:alert-stale-referrals')->assertSuccessful();
        $this->artisan('cpf:expire-stale-invites')->assertSuccessful();
        $this->artisan('cpf:remind-upcoming-events')->assertSuccessful();
    }
}
