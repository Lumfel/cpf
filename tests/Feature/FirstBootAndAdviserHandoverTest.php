<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\AdviserHandover;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\ClubServiceTenure;
use App\Models\OfficerGcChannel;
use App\Models\PeerQuote;
use App\Models\User;
use Database\Seeders\BlankSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FirstBootAndAdviserHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function createCampusAndMember(CpfRole $role, string $name, ?Campus $campus = null): array
    {
        $campus ??= Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Montemayor, Elena V.',
            'gc_group_name' => 'CPF Alijis Official Peer GC',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('password'),
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
        ]);

        return [$campus, $user, $membership];
    }

    public function test_blank_system_seeder_triggers_first_boot_setup_and_locks_after_adviser_creation(): void
    {
        $this->seed(BlankSystemSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(0, CampusMembership::count());
        $this->assertSame(4, Campus::count());

        // Blank environment automatically surfaces the First-Boot Adviser Setup modal prop on / and /login
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Landing')
                ->where('systemBootstrap.needsAdviserSetup', true));

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('systemBootstrap.needsAdviserSetup', true));

        $alijis = Campus::where('code', 'ALIJIS')->firstOrFail();

        // Completing "Enter as Club Adviser" creates the founding Adviser, provisions campus resources, and signs in
        $this->post('/first-boot/setup-adviser', [
            'campus_id' => $alijis->id,
            'campus_name' => 'CHMSU Alijis Campus',
            'academic_year' => '2026-2027',
            'first_name' => 'Elena',
            'middle_name' => 'V.',
            'last_name' => 'Montemayor',
            'email' => 'elena.founding@chmsu.edu.ph',
            'password' => 'FoundingAdviser2026!',
            'password_confirmation' => 'FoundingAdviser2026!',
            'sex' => 'Female',
            'cellphone_number' => '0917-555-0101',
        ])->assertRedirect('/dashboard');

        $adviser = User::where('email', 'elena.founding@chmsu.edu.ph')->firstOrFail();
        $this->assertNotNull($adviser->email_verified_at);
        $this->assertAuthenticatedAs($adviser);
        $this->assertSame(CpfRole::Adviser, $adviser->verifiedRole());

        // Campus room, 3 default counselors, Officers GC channel, and calming quote are ready immediately
        $this->assertSame('Montemayor, Elena V.', $alijis->fresh()->adviser_name);
        $this->assertSame(1, CampusRoom::where('campus_id', $alijis->id)->where('is_single_allowed_room', true)->count());
        $this->assertSame(3, CampusCounselor::where('campus_id', $alijis->id)->count());
        $this->assertSame(1, OfficerGcChannel::where('campus_id', $alijis->id)->where('is_active', true)->count());
        $this->assertGreaterThanOrEqual(1, PeerQuote::where('is_approved', true)->count());

        // All main portal spaces load smoothly right after first boot
        foreach (['/dashboard', '/membership', '/campus-hub', '/events-activities', '/community-hub', '/officers-chat'] as $route) {
            $this->actingAs($adviser)->get($route)->assertOk();
        }

        // First-boot endpoint and modal are permanently locked once an Adviser exists
        auth()->logout();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('systemBootstrap.needsAdviserSetup', false));

        $this->post('/first-boot/setup-adviser', [
            'first_name' => 'Intruder',
            'last_name' => 'Fake',
            'email' => 'intruder@chmsu.edu.ph',
            'password' => 'Intruder123!',
            'password_confirmation' => 'Intruder123!',
            'sex' => 'Male',
            'academic_year' => '2026-2027',
        ])->assertForbidden();
    }

    public function test_sole_adviser_cannot_be_demoted_directly_via_role_endpoint_and_president_cannot_demote_adviser(): void
    {
        [$campus, $adviser, $adviserMembership] = $this->createCampusAndMember(CpfRole::Adviser, 'Montemayor, Elena V.');
        [, $president] = $this->createCampusAndMember(CpfRole::President, 'Mercado, Sofia R.', $campus);

        // President cannot demote the Club Adviser
        $this->actingAs($president)
            ->patch("/membership/{$adviserMembership->id}/role", ['role' => 'member'])
            ->assertForbidden();

        // Sole Club Adviser cannot accidentally demote themselves without a successor handover
        $this->actingAs($adviser)
            ->patch("/membership/{$adviserMembership->id}/role", ['role' => 'member'])
            ->assertStatus(422);

        $this->assertSame(CpfRole::Adviser, $adviserMembership->fresh()->role);
    }

    public function test_two_step_adviser_handover_to_incoming_counselor_transfers_authority_atomically(): void
    {
        [$campus, $adviser] = $this->createCampusAndMember(CpfRole::Adviser, 'Montemayor, Elena V.');
        [, , $presidentMembership] = $this->createCampusAndMember(CpfRole::President, 'Mercado, Sofia R.', $campus);
        [, $otherMember] = $this->createCampusAndMember(CpfRole::Member, 'Reyes, Katrina M.', $campus);

        // Officer / existing-member handover is disallowed (Guidance Counselor details are required)
        $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'password',
                'mode' => 'existing_member',
                'target_membership_id' => $presidentMembership->id,
            ])
            ->assertStatus(422);

        // Incorrect current password is rejected
        $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'wrong-password',
                'first_name' => 'Elena',
                'middle_name' => 'R',
                'last_name' => 'Navarro',
                'email' => 'elena.navarro.adviser@chmsu.edu.ph',
                'sex' => 'Female',
                'initial_password' => 'CounselorPass2026!',
            ])
            ->assertStatus(422);

        // Missing or invalid sex selection is rejected
        $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'password',
                'first_name' => 'Elena',
                'middle_name' => 'R',
                'last_name' => 'Navarro',
                'email' => 'elena.navarro.adviser@chmsu.edu.ph',
                'sex' => 'Other',
                'initial_password' => 'CounselorPass2026!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('sex');

        // Adviser initiates transfer to incoming Guidance Counselor with handover verification code
        $initRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'password',
                'outgoing_role' => 'alumni',
                'first_name' => 'Elena',
                'middle_name' => 'R',
                'last_name' => 'Navarro',
                'email' => 'elena.navarro.adviser@chmsu.edu.ph',
                'sex' => 'Female',
                'cellphone_number' => '0917-555-0199',
                'initial_password' => 'CounselorPass2026!',
                'handover_notes' => 'Official turnover for AY 2026-2027.',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending');

        $handoverId = $initRes->json('handover.id');
        $code = $initRes->json('handover.confirmation_code');
        $this->assertSame(6, strlen($code));

        $counselor = User::query()->where('email', 'elena.navarro.adviser@chmsu.edu.ph')->firstOrFail();
        $this->assertTrue($counselor->must_change_password);
        $this->assertNotNull($counselor->temporary_password_issued_at);
        $this->assertSame('Female', $counselor->primaryMembership?->sex);
        $this->assertSame('Woman', $counselor->primaryMembership?->gender_identity);

        // Incoming counselor must rotate the initial password before accessing protected pages
        $this->actingAs($counselor)
            ->get('/dashboard')
            ->assertRedirect(route('password.force-change.edit'));

        $this->actingAs($counselor)
            ->post('/force-password-change', [
                'password' => 'NewAdviserSecret2026!',
                'password_confirmation' => 'NewAdviserSecret2026!',
            ])
            ->assertRedirect(route('dashboard'));

        $counselor = $counselor->fresh();
        $this->assertFalse($counselor->must_change_password);
        $this->assertNull($counselor->temporary_password_issued_at);

        // Designated incoming counselor sees handover on Dashboard (without leaking confirmation code)
        $this->actingAs($counselor)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pendingAdviserHandover.incoming.id', $handoverId)
                ->missing('pendingAdviserHandover.incoming.confirmation_code'));

        // Unrelated user cannot accept or decline someone else's handover
        $this->actingAs($otherMember)
            ->postJson("/adviser-handover/{$handoverId}/respond", [
                'decision' => 'accepted',
                'confirmation_code' => $code,
            ])
            ->assertForbidden();

        // Wrong verification code rejected
        $this->actingAs($counselor)
            ->postJson("/adviser-handover/{$handoverId}/respond", [
                'decision' => 'accepted',
                'confirmation_code' => 'WRONG1',
            ])
            ->assertStatus(422);

        // Incoming counselor confirms with exact verification code -> atomic swap
        $this->actingAs($counselor)
            ->postJson("/adviser-handover/{$handoverId}/respond", [
                'decision' => 'accepted',
                'confirmation_code' => $code,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');

        $counselor = $counselor->fresh();
        $adviser = $adviser->fresh();

        $this->assertSame(CpfRole::Adviser, $counselor->verifiedRole());
        $this->assertSame(
            'Guidance Services / Adviser',
            $counselor->primaryMembership?->course_year_section,
        );
        // Outgoing Adviser keeps their account as Former Club Adviser (Alumni) with zero active control
        $this->assertNull($adviser->verifiedRole());
        $this->assertTrue($adviser->isAlumni());
        $this->assertSame(MembershipStatus::Alumni, $adviser->primaryMembership?->status);
        $this->assertSame(CpfRole::Adviser, $adviser->primaryMembership?->role);
        $this->assertSame('adviser_handover', $adviser->primaryMembership?->alumni_reason);

        $adviserTenure = ClubServiceTenure::query()
            ->where('user_id', $adviser->id)
            ->where('end_reason', 'adviser_handover')
            ->firstOrFail();
        $this->assertTrue($adviserTenure->verifyHash());
        $this->assertSame('Navarro, Elena R.', $campus->fresh()->adviser_name);

        // New Adviser holds Adviser powers; former Adviser loses active control but retains Alumni Archive access
        $this->actingAs($counselor)->postJson('/elections', [
            'title' => 'New AY Election',
            'academic_year' => '2026-2027',
            'status' => 'open',
        ])->assertCreated();

        $this->actingAs($adviser)->postJson('/elections', [
            'title' => 'Former Adviser Blocked',
            'academic_year' => '2026-2027',
            'status' => 'open',
        ])->assertForbidden();

        $this->actingAs($adviser)
            ->get('/alumni-archive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('archive/Index')
                ->where('isAlumni', true));
    }

    public function test_adviser_handover_supports_cancellation_and_decline(): void
    {
        [$campus, $adviser] = $this->createCampusAndMember(CpfRole::Adviser, 'Montemayor, Elena V.');

        // 1. Adviser initiates handover to brand-new incoming counselor, then cancels it
        $newRes = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'password',
                'first_name' => 'Marco',
                'middle_name' => 'L.',
                'last_name' => 'Villanueva',
                'email' => 'marco.incoming.adviser@chmsu.edu.ph',
                'sex' => 'Male',
                'cellphone_number' => '0917-555-0200',
                'initial_password' => 'CounselorPass2026!',
            ])
            ->assertCreated();

        $createdHandoverId = $newRes->json('handover.id');
        $marco = User::query()->where('email', 'marco.incoming.adviser@chmsu.edu.ph')->firstOrFail();
        $this->assertTrue($marco->must_change_password);
        $this->assertSame('Male', $marco->primaryMembership?->sex);
        $this->assertSame('Man', $marco->primaryMembership?->gender_identity);

        $this->actingAs($adviser)
            ->deleteJson("/adviser-handover/{$createdHandoverId}")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertSame('cancelled', AdviserHandover::findOrFail($createdHandoverId)->status);
        $this->assertSame(MembershipStatus::Rejected, $marco->fresh()->primaryMembership?->status);
        $this->assertNull($marco->fresh()->verifiedRole());

        // 2. Adviser initiates handover to another incoming counselor, who declines -> Adviser remains Adviser
        $declineInit = $this->actingAs($adviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'password',
                'first_name' => 'Clarisse',
                'middle_name' => 'T.',
                'last_name' => 'Bautista',
                'email' => 'clarisse.declining.adviser@chmsu.edu.ph',
                'sex' => 'Female',
                'cellphone_number' => '0917-555-0201',
                'initial_password' => 'CounselorPass2026!',
            ])
            ->assertCreated();

        $declineHandoverId = $declineInit->json('handover.id');
        $decliningCounselor = User::query()->where('email', 'clarisse.declining.adviser@chmsu.edu.ph')->firstOrFail();
        $this->assertTrue($decliningCounselor->must_change_password);

        $this->actingAs($decliningCounselor)
            ->get('/dashboard')
            ->assertRedirect(route('password.force-change.edit'));

        $this->actingAs($decliningCounselor)
            ->post('/force-password-change', [
                'password' => 'DeclineAdviserPass2026!',
                'password_confirmation' => 'DeclineAdviserPass2026!',
            ])
            ->assertRedirect(route('dashboard'));

        $decliningCounselor = $decliningCounselor->fresh();

        $this->actingAs($decliningCounselor)
            ->postJson("/adviser-handover/{$declineHandoverId}/respond", [
                'decision' => 'declined',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'declined');

        $this->assertSame(CpfRole::Adviser, $adviser->fresh()->verifiedRole());
        $this->assertSame(MembershipStatus::Rejected, $decliningCounselor->fresh()->primaryMembership?->status);
        $this->assertNull($decliningCounselor->fresh()->verifiedRole());

        // Declined handover cannot be responded to a second time
        $this->actingAs($decliningCounselor)
            ->postJson("/adviser-handover/{$declineHandoverId}/respond", [
                'decision' => 'declined',
            ])
            ->assertStatus(422);
    }
}
