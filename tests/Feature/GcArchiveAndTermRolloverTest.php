<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\CounselorGcMessage;
use App\Models\Election;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Models\SchoolYearArchive;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use ZipArchive;

class GcArchiveAndTermRolloverTest extends TestCase
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

    private function createMember(CpfRole $role, string $name): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);

        CampusMembership::create([
            'user_id' => $user->id,
            'campus_id' => $this->alijis->id,
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

        return $user;
    }

    public function test_archiving_officers_gc_immediately_opens_fresh_active_channel_for_next_term(): void
    {
        $adviser = $this->createMember(CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember(CpfRole::President, 'Mercado, Sofia R.');

        $channel = OfficerGcChannel::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'channel_key' => 'campus.alijis.officers-gc.2026-2027',
            'is_active' => true,
        ]);

        OfficerGcMessage::create([
            'channel_id' => $channel->id,
            'sender_user_id' => $president->id,
            'sender_role' => 'president',
            'message_body' => "Line 1 agenda\nLine 2 turnover notes",
        ]);

        $res = $this->actingAs($adviser)
            ->postJson("/officers-gc/{$channel->id}/archive")
            ->assertOk()
            ->assertJsonPath('status', 'archived')
            ->assertJsonPath('next_channel.is_active', true)
            ->assertJsonPath('next_channel.academic_year', '2027-2028');

        $zipPath = (string) $res->json('archive.hdd_storage_path');
        $nextChannelId = (int) $res->json('next_channel.id');

        try {
            $this->assertFileExists($zipPath);
            $this->assertFalse($channel->fresh()->is_active);
            $this->assertNotSame($channel->id, $nextChannelId);

            // Officers can immediately message in the freshly opened next-term channel
            $this->actingAs($president)
                ->postJson("/officers-gc/{$nextChannelId}/messages", [
                    'message_body' => 'Welcome to the new term channel!',
                ])
                ->assertCreated();
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }

    public function test_completing_campus_election_auto_archives_officers_gc_and_opens_next_term(): void
    {
        $adviser = $this->createMember(CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember(CpfRole::President, 'Mercado, Sofia R.');

        $channel = OfficerGcChannel::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'channel_key' => 'campus.alijis.officers-gc.2026-2027',
            'is_active' => true,
        ]);

        OfficerGcMessage::create([
            'channel_id' => $channel->id,
            'sender_user_id' => $president->id,
            'sender_role' => 'president',
            'message_body' => 'Final officer coordination before election closes.',
        ]);

        // Election at final optional position (fourth_year_rep); skipping completes the election
        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'AY 2026-2027 General Election',
            'status' => 'open',
            'current_position' => 'fourth_year_rep',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $adviser->id,
        ]);

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/skip-position")
            ->assertOk()
            ->assertJsonPath('election.status', 'closed')
            ->assertJsonPath('election.current_phase', 'completed');

        $archive = OfficerGcArchive::query()->where('campus_id', $this->alijis->id)->first();
        $this->assertNotNull($archive);

        try {
            $this->assertFileExists($archive->hdd_storage_path);
            $this->assertFalse($channel->fresh()->is_active);
            $this->assertDatabaseHas('officer_gc_channels', [
                'campus_id' => $this->alijis->id,
                'academic_year' => '2027-2028',
                'is_active' => true,
            ]);
        } finally {
            if ($archive && is_file($archive->hdd_storage_path)) {
                @unlink($archive->hdd_storage_path);
            }
        }
    }

    public function test_counselors_private_gc_manual_archive_is_adviser_only_and_clears_thread(): void
    {
        $adviser = $this->createMember(CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember(CpfRole::President, 'Mercado, Sofia R.');
        $member = $this->createMember(CpfRole::Member, 'Reyes, Katrina M.');

        CounselorGcMessage::create([
            'campus_id' => $this->alijis->id,
            'sender_user_id' => $adviser->id,
            'sender_role' => 'adviser',
            'message_body' => "Confidential triage note\nFollow up Thursday 2 PM",
        ]);

        // Officer and regular member are blocked (403)
        $this->actingAs($president)->postJson('/counselors-gc/archive')->assertForbidden();
        $this->actingAs($member)->postJson('/counselors-gc/archive')->assertForbidden();

        // Adviser exports SHA-256 ZIP archive and clears the Counselors GC thread
        $res = $this->actingAs($adviser)
            ->postJson('/counselors-gc/archive')
            ->assertOk()
            ->assertJsonPath('status', 'archived')
            ->assertJsonPath('total_messages', 1)
            ->assertJsonPath('archive.archive_type', 'counselor_gc');

        $zipPath = (string) $res->json('archive.hdd_storage_path');

        try {
            $this->assertFileExists($zipPath);
            $this->assertSame(hash_file('sha256', $zipPath), $res->json('archive.sha256_checksum'));
            $this->assertDatabaseCount('counselor_gc_messages', 0);
            $this->assertDatabaseHas('school_year_archives', [
                'campus_id' => $this->alijis->id,
                'archive_type' => 'counselor_gc',
            ]);

            $zip = new ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true);
            $transcript = $zip->getFromName('transcript.json');
            $zip->close();
            $this->assertStringContainsString('Confidential triage note', (string) $transcript);
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }
    }
}
