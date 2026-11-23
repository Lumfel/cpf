<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusMembership;
use App\Models\ClubServiceTenure;
use App\Models\DigitalCertificate;
use App\Models\MemberAchievement;
use App\Models\MemberAward;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Models\TimeCapsule;
use App\Models\User;
use App\Services\ClubTenureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class AlumniArchiveAndTenureTest extends TestCase
{
    use RefreshDatabase;

    private function createCampusAndMember(
        CpfRole $role,
        string $name,
        ?Campus $campus = null,
        ?string $approvedAt = null,
    ): array {
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

        $approved = $approvedAt ? \Illuminate\Support\Carbon::parse($approvedAt) : now()->subMonths(6);

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
            'course' => $role === CpfRole::Adviser ? 'Guidance Services' : 'BSIT',
            'year_level' => $role === CpfRole::Adviser ? 'Faculty' : '4',
            'section' => $role === CpfRole::Adviser ? 'Adviser' : 'A',
            'course_year_section' => $role === CpfRole::Adviser ? 'Guidance Services / Adviser' : 'BSIT 4A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2003-04-12',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.user',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-000-0000',
            'approved_at' => $approved,
        ]);

        app(ClubTenureService::class)->openTenure($membership, $user, $approved);

        return [$campus, $user, $membership];
    }

    public function test_outgoing_adviser_retains_read_only_archive_access_for_awards_times_and_archived_gcs_with_zero_control(): void
    {
        [$campus, $outgoingAdviser, $outgoingMembership] = $this->createCampusAndMember(
            CpfRole::Adviser,
            'Montemayor, Elena V.',
            approvedAt: now()->subYears(2)->toDateTimeString(),
        );
        [, $president] = $this->createCampusAndMember(CpfRole::President, 'Mercado, Sofia R.', $campus);

        // Seed Awards, Achievements, Certificates, Time Capsules, Attended Events, and an Archived GC ZIP
        $award = MemberAward::create([
            'campus_id' => $campus->id,
            'user_id' => $outgoingAdviser->id,
            'award_code' => 'CPF-AWD-LEGACY01',
            'title' => 'Distinguished Founding Club Adviser Award',
            'award_category' => 'Leadership & Service',
            'academic_year' => '2025-2026',
            'awarded_on' => now()->subMonths(3)->toDateString(),
            'citation' => 'Guided peer facilitators with integrity for two academic years.',
            'issued_by_user_id' => $outgoingAdviser->id,
            'blockchain_status' => 'anchored',
            'credential_hash' => str_repeat('a', 64),
        ]);

        MemberAchievement::create([
            'campus_id' => $campus->id,
            'user_id' => $outgoingAdviser->id,
            'achievement_code' => 'CPF-ACH-LEGACY01',
            'title' => '100% Chapter Retention Milestone',
            'category' => 'Mentorship',
            'achieved_on' => now()->subMonths(4)->toDateString(),
            'verified_by_user_id' => $outgoingAdviser->id,
            'blockchain_status' => 'anchored',
            'credential_hash' => str_repeat('b', 64),
        ]);

        DigitalCertificate::create([
            'campus_id' => $campus->id,
            'user_id' => $outgoingAdviser->id,
            'certificate_code' => 'CPF-CERT-LEGACY01',
            'title' => 'Certificate of Mentorship',
            'event_name' => 'CHMSU Peer Wellness Colloquium',
            'issued_on' => now()->subMonths(5)->toDateString(),
            'issued_by_user_id' => $outgoingAdviser->id,
            'blockchain_status' => 'anchored',
            'credential_hash' => str_repeat('c', 64),
        ]);

        $unlockedCapsule = TimeCapsule::create([
            'campus_id' => $campus->id,
            'user_id' => $outgoingAdviser->id,
            'title' => 'Note to My Future Self After Turnover',
            'letter_style' => 'letter_in_sunrise',
            'encrypted_message' => 'Thank you for serving the CHMSU Peer Facilitators with heart.',
            'unlock_at' => now()->subDay(),
            'is_opened' => false,
        ]);

        $event = CampusEvent::create([
            'campus_id' => $campus->id,
            'title' => 'Annual Peer Facilitators Assembly',
            'event_date' => now()->subMonth()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '12:00',
            'status' => 'approved',
            'created_by_user_id' => $outgoingAdviser->id,
            'approved_by_user_id' => $outgoingAdviser->id,
            'approved_at' => now()->subMonths(2),
        ]);

        CampusEventAttendance::create([
            'campus_event_id' => $event->id,
            'user_id' => $outgoingAdviser->id,
            'logged_in_at' => now()->subMonth(),
            'logged_in_by_user_id' => $president->id,
            'logged_out_at' => now()->subMonth()->addHours(3),
            'logged_out_by_user_id' => $president->id,
        ]);

        $channel = OfficerGcChannel::create([
            'campus_id' => $campus->id,
            'academic_year' => '2025-2026',
            'channel_key' => 'campus.alijis.officers-gc.2025-2026',
            'is_active' => true,
        ]);
        OfficerGcMessage::create([
            'channel_id' => $channel->id,
            'sender_user_id' => $president->id,
            'sender_role' => 'president',
            'message_body' => 'Ready for end-of-year officer turnover notes.',
            'created_at' => now()->subWeeks(2),
        ]);

        $tempArchiveDir = storage_path('app/test-alumni-archives');
        @mkdir($tempArchiveDir, 0755, true);
        $this->actingAs($outgoingAdviser)
            ->postJson("/officers-gc/{$channel->id}/archive")
            ->assertOk();

        // Create a real ZIP archive with transcript in storage for gcTranscript test
        $gcArchive = OfficerGcArchive::query()->where('campus_id', $campus->id)->firstOrFail();

        // Perform 2-step Adviser handover to incoming counselor
        $handoverRes = $this->actingAs($outgoingAdviser)
            ->postJson('/adviser-handover', [
                'current_password' => 'password',
                'first_name' => 'Elena',
                'middle_name' => 'R.',
                'last_name' => 'Navarro',
                'email' => 'elena.successor@chmsu.edu.ph',
                'sex' => 'Female',
                'initial_password' => 'SuccessorPass2026!',
            ])
            ->assertCreated();

        $handoverId = $handoverRes->json('handover.id');
        $code = $handoverRes->json('handover.confirmation_code');
        $successor = User::query()->where('email', 'elena.successor@chmsu.edu.ph')->firstOrFail();
        $successor->forceFill(['must_change_password' => false])->save();

        $this->actingAs($successor)
            ->postJson("/adviser-handover/{$handoverId}/respond", [
                'decision' => 'accepted',
                'confirmation_code' => $code,
            ])
            ->assertOk();

        $outgoingAdviser = $outgoingAdviser->fresh();
        $outgoingMembership = $outgoingMembership->fresh();

        // 1. Account preserved as Former Club Adviser (Alumni) with zero active control
        $this->assertTrue($outgoingAdviser->isAlumni());
        $this->assertNull($outgoingAdviser->verifiedRole());
        $this->assertSame(MembershipStatus::Alumni, $outgoingMembership->status);
        $this->assertSame(CpfRole::Adviser, $outgoingMembership->role);

        // 2. Zero operational control across system endpoints
        $this->actingAs($outgoingAdviser)->get('/officers-chat')->assertForbidden();
        $this->actingAs($outgoingAdviser)->postJson('/elections', [
            'title' => 'Blocked Election',
            'academic_year' => '2026-2027',
        ])->assertForbidden();
        $this->actingAs($outgoingAdviser)->postJson('/events-activities', [
            'title' => 'Blocked Event',
            'event_date' => now()->addDays(3)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '11:00',
        ])->assertForbidden();
        $this->actingAs($outgoingAdviser)->postJson('/testing-room/request', [
            'purpose' => 'Blocked Request',
            'request_date' => now()->addDay()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertForbidden();
        $this->actingAs($outgoingAdviser)->postJson('/quotes', [
            'quote_text' => 'Blocked Quote',
        ])->assertForbidden();
        $this->actingAs($outgoingAdviser)->postJson('/time-capsules', [
            'campus_id' => $campus->id,
            'title' => 'Blocked New Capsule',
            'message' => 'Alumni cannot create active submissions.',
            'unlock_at' => now()->addMonth()->toDateTimeString(),
        ])->assertForbidden();

        // 3. Full read-only access to Alumni Archive (Awards, Times, Archived GCs, Tenure, Time Capsule unlock)
        $this->actingAs($outgoingAdviser)
            ->get('/alumni-archive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('archive/Index')
                ->where('isAlumni', true)
                ->where('myTenure.is_fully_verified', true)
                ->where('myTenure.events_attended_count', 1)
                ->where('myTenure.awards_count', 1)
                ->has('myAwards', 1)
                ->where('myAwards.0.award_code', $award->award_code)
                ->has('myAchievements', 1)
                ->has('myCertificates', 1)
                ->has('timeCapsules', 1)
                ->has('myAttendedEvents', 1)
                ->has('gcArchives', 1)
                ->has('alumniDirectory', 1)
                ->where('alumniDirectory.0.category', 'adviser')
                ->where('alumniDirectory.0.former_role_label', 'Former Club Adviser'));

        // Read Archived GC transcript ZIP
        $this->actingAs($outgoingAdviser)
            ->getJson("/alumni-archive/gc/{$gcArchive->id}/transcript")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('messages.0.message_body', 'Ready for end-of-year officer turnover notes.');

        // Can open their own unlocked Time Capsule
        $this->actingAs($outgoingAdviser)
            ->postJson("/time-capsules/{$unlockedCapsule->id}/open")
            ->assertOk()
            ->assertJsonPath('status', 'opened');

        // Dashboard reflects Alumni read-only state and Credentials page renders former role cleanly
        $this->actingAs($outgoingAdviser)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isAlumni', true)
                ->where('qrUnlocked', false));

        $this->actingAs($outgoingAdviser)
            ->get('/my-credentials')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('owner.roleLabel', 'Former Club Adviser'));

        // Cleanup archive file created on disk
        if (is_file($gcArchive->hdd_storage_path)) {
            @unlink($gcArchive->hdd_storage_path);
        }
        @rmdir($tempArchiveDir);
    }

    public function test_dynamic_former_officers_and_members_directory_tracks_multi_role_tenure_and_enforces_security(): void
    {
        [$campus, $adviser, $adviserMembership] = $this->createCampusAndMember(
            CpfRole::Adviser,
            'Montemayor, Elena V.',
            approvedAt: now()->subYears(2)->toDateTimeString(),
        );
        [, $president] = $this->createCampusAndMember(
            CpfRole::President,
            'Mercado, Sofia R.',
            $campus,
            approvedAt: now()->subMonths(14)->toDateTimeString(),
        );
        [, $risingOfficer, $officerMembership] = $this->createCampusAndMember(
            CpfRole::Member,
            'Santos, Gabriel K.',
            $campus,
            approvedAt: now()->subMonths(10)->toDateTimeString(),
        );
        [, $gradMember, $gradMembership] = $this->createCampusAndMember(
            CpfRole::Member,
            'Reyes, Katrina M.',
            $campus,
            approvedAt: now()->subMonths(8)->toDateTimeString(),
        );

        // Second campus to test cross-campus isolation
        $talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay Campus',
            'slug' => 'talisay',
            'current_academic_year' => '2026-2027',
            'is_active' => true,
        ]);
        [, $otherCampusMember] = $this->createCampusAndMember(CpfRole::Member, 'Cruz, Angelo D.', $talisay);

        // Non-advisers (even President or Member) cannot transition someone to Alumni
        $this->actingAs($president)
            ->postJson("/membership/{$gradMembership->id}/transition-alumni", ['reason' => 'graduated'])
            ->assertForbidden();

        // Sole Adviser cannot be transitioned via this endpoint (must use Adviser Handover)
        $this->actingAs($adviser)
            ->postJson("/membership/{$adviserMembership->id}/transition-alumni", ['reason' => 'graduated'])
            ->assertStatus(422);

        // Club Adviser promotes Santos to Secretary, then re-elects/promotes to President,
        // then steps down to Vice President before finishing service — highest position held must remain President!
        $this->actingAs($adviser)
            ->patch("/membership/{$officerMembership->id}/role", ['role' => CpfRole::Secretary->value])
            ->assertRedirect();

        $this->actingAs($adviser)
            ->patch("/membership/{$officerMembership->id}/role", ['role' => CpfRole::President->value])
            ->assertRedirect();

        $this->actingAs($adviser)
            ->patch("/membership/{$officerMembership->id}/role", ['role' => CpfRole::VicePresident->value])
            ->assertRedirect();

        // Transition both former officer and former member to Former archive status
        $this->actingAs($adviser)
            ->postJson("/membership/{$officerMembership->id}/transition-alumni", [
                'reason' => 'term_completed',
                'remarks' => 'Completed Secretary, President & VP service.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'alumni');

        $this->actingAs($adviser)
            ->postJson("/membership/{$gradMembership->id}/transition-alumni", [
                'reason' => 'graduated',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'alumni');

        // Verify cryptographic tenure segments recorded accurately (Member -> Secretary -> President -> VP)
        $officerTenures = ClubServiceTenure::query()
            ->where('campus_membership_id', $officerMembership->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $officerTenures);
        $this->assertSame(CpfRole::Member, $officerTenures[0]->role);
        $this->assertSame(CpfRole::Secretary, $officerTenures[1]->role);
        $this->assertSame(CpfRole::President, $officerTenures[2]->role);
        $this->assertSame(CpfRole::VicePresident, $officerTenures[3]->role);
        $this->assertSame('term_completed', $officerTenures[3]->end_reason);
        $this->assertTrue($officerTenures->every(fn (ClubServiceTenure $t) => $t->ended_at !== null && $t->verifyHash()));

        // Highest position precedence: referred to as "Former President" even though final role was Vice President
        $risingOfficer = $risingOfficer->fresh();
        $gradMember = $gradMember->fresh();
        $this->assertSame(CpfRole::President, $risingOfficer->formerRole());
        $this->assertSame('Former President', $risingOfficer->formerRoleLabel());
        $this->assertSame(CpfRole::Member, $gradMember->formerRole());
        $this->assertSame('Former Member', $gradMember->formerRoleLabel());

        // Both lose active permissions
        $this->assertNull($risingOfficer->verifiedRole());
        $this->assertNull($gradMember->verifiedRole());
        $this->actingAs($risingOfficer)->get('/officers-chat')->assertForbidden();
        $this->actingAs($gradMember)->postJson('/membership/change-requests', [])->assertForbidden();

        // Former & active campus members see dynamic Former Officers & Members directory with highest title
        $this->actingAs($gradMember)
            ->get('/alumni-archive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('archive/Index')
                ->has('alumniDirectory', 2)
                ->where('alumniDirectory', function ($dir) {
                    $items = collect($dir);
                    $officer = $items->firstWhere('former_role', 'president');
                    $member = $items->firstWhere('former_role', 'member');

                    return $officer !== null
                        && $member !== null
                        && $officer['former_role_label'] === 'Former President'
                        && $member['former_role_label'] === 'Former Member'
                        && $officer['category'] === 'officer'
                        && $member['category'] === 'member'
                        && count($officer['tenure']['roles']) === 4
                        && $officer['tenure']['is_fully_verified'] === true
                        && $member['tenure']['is_fully_verified'] === true;
                }));

        // Cross-campus isolation on archived GC transcripts
        $zipPath = storage_path('app/test-alumni-cross-campus.zip');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('transcript.json', '[]');
        $zip->close();

        $alijisArchive = OfficerGcArchive::create([
            'campus_id' => $campus->id,
            'academic_year' => '2025-2026',
            'zip_file_name' => 'test-alumni-cross-campus.zip',
            'hdd_storage_path' => $zipPath,
            'sha256_checksum' => hash_file('sha256', $zipPath),
            'total_messages' => 0,
            'archived_by_user_id' => $adviser->id,
            'archived_at' => now(),
        ]);

        $this->actingAs($otherCampusMember)
            ->getJson("/alumni-archive/gc/{$alijisArchive->id}/transcript")
            ->assertForbidden();

        @unlink($zipPath);
    }
}
