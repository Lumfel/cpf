<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\ClubServiceTenure;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Models\User;
use App\Services\ClubTenureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ElectionLifecycleAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Campus $alijis;

    private Campus $talisay;

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

        $this->talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay Campus',
            'slug' => 'talisay',
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Soriano, Clarissa D.',
            'gc_group_name' => 'CPF Talisay Official Peer GC',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: CampusMembership}
     */
    private function createCampusUser(
        Campus $campus,
        CpfRole $role,
        string $name,
        MembershipStatus $status = MembershipStatus::Approved,
        string $yearLevel = '4',
    ): array {
        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('password'),
            'must_change_password' => false,
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
            'year_level' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Faculty' : $yearLevel,
            'section' => $role === CpfRole::GuidanceCounselor ? 'Guidance Counselor' : 'A',
            'course_year_section' => "BSIT {$yearLevel}A",
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.user',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-555-0001',
            'approved_at' => $status === MembershipStatus::Approved ? now()->subMonths(6) : null,
        ]);

        if ($status === MembershipStatus::Approved) {
            app(ClubTenureService::class)->openTenure($membership, $user, $membership->approved_at);
        }

        return [$user, $membership];
    }

    public function test_c1_completing_election_applies_roles_and_term_turnover_before_opening_next_officers_gc(): void
    {
        [$adviser, $adviserMembership] = $this->createCampusUser($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor, $counselorMembership] = $this->createCampusUser($this->alijis, CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$outgoingPresident, $outgoingPresidentMembership] = $this->createCampusUser($this->alijis, CpfRole::President, 'Mercado, Sofia R.');
        [$reelectedToVp, $reelectedToVpMembership] = $this->createCampusUser($this->alijis, CpfRole::Secretary, 'Alvarez, Hannah G.');
        [$retainedTreasurer, $retainedTreasurerMembership] = $this->createCampusUser($this->alijis, CpfRole::Treasurer, 'Delgado, Liam C.');
        [$incomingPresident, $incomingPresidentMembership] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');
        [$fourthYearRep] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Senior, Alex R.', MembershipStatus::Approved, '4');

        $oldChannel = OfficerGcChannel::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'channel_key' => 'campus.alijis.officers-gc.2026-2027',
            'is_active' => true,
        ]);

        OfficerGcMessage::create([
            'channel_id' => $oldChannel->id,
            'sender_user_id' => $outgoingPresident->id,
            'sender_role' => 'president',
            'message_body' => 'Outgoing board final handover.',
        ]);

        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2027-2028',
            'title' => 'AY 2027-2028 CPF Officer Election',
            'status' => 'open',
            'current_position' => 'fourth_year_rep',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [
                'president' => [
                    'status' => 'elected',
                    'winner_user_id' => $incomingPresident->id,
                    'winner_name' => 'Reyes, Katrina M.',
                    'votes' => 14,
                    'won_by' => 'vote',
                ],
                'vice_president' => [
                    'status' => 'elected',
                    'winner_user_id' => $reelectedToVp->id,
                    'winner_name' => 'Alvarez, Hannah G.',
                    'votes' => 11,
                    'won_by' => 'vote',
                ],
                'treasurer' => [
                    'status' => 'elected',
                    'winner_user_id' => $retainedTreasurer->id,
                    'winner_name' => 'Delgado, Liam C.',
                    'votes' => 10,
                    'won_by' => 'vote',
                ],
                'secretary' => [
                    'status' => 'vacant',
                    'winner_user_id' => null,
                    'votes' => 0,
                    'won_by' => 'vacant',
                ],
            ],
            'created_by_user_id' => $adviser->id,
        ]);

        ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $fourthYearRep->id,
            'position' => 'fourth_year_rep',
            'nomination_status' => 'accepted',
        ]);

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/close-nominations")
            ->assertOk()
            ->assertJsonPath('status', 'elected_unopposed')
            ->assertJsonPath('election.current_phase', 'completed')
            ->assertJsonPath('election.status', 'closed');

        $archive = OfficerGcArchive::query()->where('campus_id', $this->alijis->id)->firstOrFail();

        try {
            $election->refresh();
            $this->assertNotNull($election->auto_archived_at);

            // 1. Newly elected Member promoted to President
            $this->assertSame(CpfRole::President, $incomingPresidentMembership->fresh()->role);
            // 2. Outgoing Secretary elected VP transitioned to VicePresident
            $this->assertSame(CpfRole::VicePresident, $reelectedToVpMembership->fresh()->role);
            // 3. Re-elected Treasurer stays Treasurer with fresh tenure segment
            $this->assertSame(CpfRole::Treasurer, $retainedTreasurerMembership->fresh()->role);
            // 4. Un-reelected outgoing President demoted to regular Member
            $this->assertSame(CpfRole::Member, $outgoingPresidentMembership->fresh()->role);
            // 5. Adviser and Guidance Counselor completely untouched
            $this->assertSame(CpfRole::Adviser, $adviserMembership->fresh()->role);
            $this->assertSame(CpfRole::GuidanceCounselor, $counselorMembership->fresh()->role);

            // 6. Verified ClubServiceTenure history recorded accurately
            $outgoingTenures = ClubServiceTenure::query()
                ->where('campus_membership_id', $outgoingPresidentMembership->id)
                ->orderBy('id')
                ->get();
            $this->assertCount(2, $outgoingTenures);
            $this->assertSame(CpfRole::President, $outgoingTenures[0]->role);
            $this->assertSame('term_completed', $outgoingTenures[0]->end_reason);
            $this->assertNotNull($outgoingTenures[0]->ended_at);
            $this->assertTrue($outgoingTenures[0]->verifyHash());
            $this->assertSame(CpfRole::Member, $outgoingTenures[1]->role);
            $this->assertNull($outgoingTenures[1]->ended_at);
            $this->assertSame('2027-2028', $outgoingTenures[1]->academic_year);
            $this->assertTrue($outgoingTenures[1]->verifyHash());

            $incomingTenures = ClubServiceTenure::query()
                ->where('campus_membership_id', $incomingPresidentMembership->id)
                ->orderBy('id')
                ->get();
            $this->assertCount(2, $incomingTenures);
            $this->assertSame(CpfRole::Member, $incomingTenures[0]->role);
            $this->assertNotNull($incomingTenures[0]->ended_at);
            $this->assertSame(CpfRole::President, $incomingTenures[1]->role);
            $this->assertNull($incomingTenures[1]->ended_at);
            $this->assertSame('2027-2028', $incomingTenures[1]->academic_year);
            $this->assertTrue($incomingTenures[1]->verifyHash());

            // 7. New Officers GC channel opened; incoming President can post, outgoing President is blocked (403)
            $this->assertFalse($oldChannel->fresh()->is_active);
            $nextChannel = OfficerGcChannel::query()
                ->where('campus_id', $this->alijis->id)
                ->where('is_active', true)
                ->firstOrFail();

            $this->actingAs($incomingPresident->fresh())
                ->postJson("/officers-gc/{$nextChannel->id}/messages", [
                    'message_body' => 'Welcome newly elected board!',
                ])
                ->assertCreated();

            $this->actingAs($outgoingPresident->fresh())
                ->postJson("/officers-gc/{$nextChannel->id}/messages", [
                    'message_body' => 'Outgoing officer should no longer have GC access.',
                ])
                ->assertForbidden();
        } finally {
            if (is_file((string) $archive->hdd_storage_path)) {
                @unlink((string) $archive->hdd_storage_path);
            }
        }
    }

    public function test_h1_and_m3_pause_never_archives_gc_blocks_completed_to_draft_and_enforces_one_open_election_per_campus(): void
    {
        [$alijisAdviser] = $this->createCampusUser($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$alijisPresident, $alijisPresidentMembership] = $this->createCampusUser($this->alijis, CpfRole::President, 'Mercado, Sofia R.');
        [$talisayAdviser] = $this->createCampusUser($this->talisay, CpfRole::Adviser, 'Soriano, Clarissa D.');

        $channel = OfficerGcChannel::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'channel_key' => 'campus.alijis.officers-gc.2026-2027',
            'is_active' => true,
        ]);

        OfficerGcMessage::create([
            'channel_id' => $channel->id,
            'sender_user_id' => $alijisPresident->id,
            'sender_role' => 'president',
            'message_body' => 'Must survive election pause!',
        ]);

        // 1. Open first election on Alijis
        $firstRes = $this->actingAs($alijisAdviser)
            ->postJson('/elections', [
                'title' => 'First Open Election',
                'academic_year' => '2026-2027',
                'status' => 'open',
            ])
            ->assertCreated();
        $firstId = (int) $firstRes->json('election.id');

        // 2. Creating a second concurrent open election on Alijis is rejected (422)
        $this->actingAs($alijisAdviser)
            ->postJson('/elections', [
                'title' => 'Duplicate Concurrent Election',
                'academic_year' => '2026-2027',
                'status' => 'open',
            ])
            ->assertStatus(422);

        // Creating a draft election is allowed, but trying to open it while first is open returns 422
        $draftRes = $this->actingAs($alijisAdviser)
            ->postJson('/elections', [
                'title' => 'Draft Next Election',
                'academic_year' => '2027-2028',
                'status' => 'draft',
            ])
            ->assertCreated();
        $draftId = (int) $draftRes->json('election.id');

        $this->actingAs($alijisAdviser)
            ->patchJson("/elections/{$draftId}/status", ['status' => 'open'])
            ->assertStatus(422);

        // Different campus (Talisay) CAN open its own concurrent election independently
        $this->actingAs($talisayAdviser)
            ->postJson('/elections', [
                'title' => 'Talisay Independent Election',
                'academic_year' => '2026-2027',
                'status' => 'open',
            ])
            ->assertCreated();

        // 3. Pausing/closing the open election NEVER archives Officers GC or demotes officers
        $this->actingAs($alijisAdviser)
            ->patchJson("/elections/{$firstId}/status", ['status' => 'closed'])
            ->assertOk();

        $this->assertTrue($channel->fresh()->is_active);
        $this->assertDatabaseCount('officer_gc_archives', 0);
        $this->assertDatabaseCount('officer_gc_messages', 1);
        $this->assertSame(CpfRole::President, $alijisPresidentMembership->fresh()->role);
        $this->assertNull(Election::findOrFail($firstId)->auto_archived_at);

        // Now that the first election is paused/closed, the draft can be opened cleanly
        $this->actingAs($alijisAdviser)
            ->patchJson("/elections/{$draftId}/status", ['status' => 'open'])
            ->assertOk();

        // 4. A completed election cannot transition back to open OR draft (422)
        $completedElection = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2025-2026',
            'title' => 'Completed Historical Election',
            'status' => 'closed',
            'current_position' => null,
            'current_phase' => 'completed',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $alijisAdviser->id,
        ]);

        $this->actingAs($alijisAdviser)
            ->patchJson("/elections/{$completedElection->id}/status", ['status' => 'draft'])
            ->assertStatus(422);

        $this->actingAs($alijisAdviser)
            ->patchJson("/elections/{$completedElection->id}/status", ['status' => 'open'])
            ->assertStatus(422);
    }

    public function test_h2_small_chapter_can_declare_mandatory_seat_vacant_when_zero_candidates_accepted(): void
    {
        [$adviser] = $this->createCampusUser($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$memberA] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');

        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'Small Chapter Election',
            'status' => 'open',
            'current_position' => 'pio',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $adviser->id,
        ]);

        // Pending (unaccepted) nomination still leaves 0 accepted candidates
        $pending = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $memberA->id,
            'position' => 'pio',
            'nomination_status' => 'pending',
        ]);

        // close-nominations without declare_vacant still returns 422
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/close-nominations")
            ->assertStatus(422);

        // skip-position on mandatory seat with 0 accepted declares seat vacant and advances to sergeant_at_arms
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/skip-position")
            ->assertOk()
            ->assertJsonPath('status', 'position_vacant')
            ->assertJsonPath('election.current_position', 'sergeant_at_arms')
            ->assertJsonPath('election.position_results.pio.status', 'vacant');

        $this->assertSame('declined', $pending->fresh()->nomination_status);

        // Mandatory seat WITH an accepted candidate CANNOT be skipped/vacated (422)
        ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $memberA->id,
            'position' => 'sergeant_at_arms',
            'nomination_status' => 'accepted',
        ]);

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/skip-position")
            ->assertStatus(422);

        // Also test close-nominations with declare_vacant=true when 0 accepted on another mandatory seat
        $election->update([
            'current_position' => 'auditor',
            'current_phase' => 'nomination',
        ]);

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/close-nominations", ['declare_vacant' => true])
            ->assertOk()
            ->assertJsonPath('status', 'position_vacant')
            ->assertJsonPath('election.position_results.auditor.status', 'vacant')
            ->assertJsonPath('election.current_position', 'business_manager');
    }

    public function test_h4_m1_and_m4_finalize_after_closes_at_store_candidate_nomination_only_and_strict_tie_break(): void
    {
        [$adviser] = $this->createCampusUser($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$memberA] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');
        [$memberB] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Bautista, Miguel A.');
        [$memberC] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Cruz, Angelo D.');
        [$voter1] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Santos, Paolo C.');

        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'Timing & Tie-Break Election',
            'status' => 'open',
            'current_position' => 'president',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addHour(),
            'created_by_user_id' => $adviser->id,
        ]);

        // M1: storeCandidate works during nomination phase
        $candARes = $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/candidates", [
                'user_id' => $memberA->id,
                'position' => 'president',
            ])
            ->assertCreated();
        $candBRes = $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/candidates", [
                'user_id' => $memberB->id,
                'position' => 'president',
            ])
            ->assertCreated();

        $candAId = (int) $candARes->json('candidate.id');
        $candBId = (int) $candBRes->json('candidate.id');

        // Move to voting phase -> M1: storeCandidate MUST be rejected (422)
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/close-nominations")
            ->assertOk();

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/candidates", [
                'user_id' => $memberC->id,
                'position' => 'president',
            ])
            ->assertStatus(422);

        // Tie vote 1-1 in round 1
        $this->actingAs($memberA)->postJson("/elections/{$election->id}/vote", [
            'candidate_id' => $candAId,
            'position' => 'president',
        ])->assertOk();
        $this->actingAs($memberB)->postJson("/elections/{$election->id}/vote", [
            'candidate_id' => $candBId,
            'position' => 'president',
        ])->assertOk();

        // M4: Manual tie-break resolution rejected during normal voting phase (422)
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/finalize-position", [
                'tie_resolution' => 'adviser_tiebreak',
                'winner_candidate_id' => $candBId,
            ])
            ->assertStatus(422);

        // Finalize round 1 -> enters tie_breaker round 2
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/finalize-position")
            ->assertOk()
            ->assertJsonPath('status', 'tie_runoff_started')
            ->assertJsonPath('election.current_phase', 'tie_breaker');

        // M1: storeCandidate also rejected during tie_breaker phase (422)
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/candidates", [
                'user_id' => $memberC->id,
                'position' => 'president',
            ])
            ->assertStatus(422);

        // Cast 1 vote for Candidate A in runoff round 2 -> A leads 1-0 (NO LONGER TIED)
        $this->actingAs($voter1)->postJson("/elections/{$election->id}/vote", [
            'candidate_id' => $candAId,
            'position' => 'president',
        ])->assertOk();

        // M4: Adviser attempting adviser_tiebreak (picking B) or draw_lots when A already leads MUST return 422 (never silently override!)
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/finalize-position", [
                'tie_resolution' => 'adviser_tiebreak',
                'winner_candidate_id' => $candBId,
            ])
            ->assertStatus(422);

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/finalize-position", [
                'tie_resolution' => 'draw_lots',
            ])
            ->assertStatus(422);

        // Election is still in tie_breaker for president (was not silently finalized)
        $this->assertSame('president', $election->fresh()->current_position);

        // Second voter casts for Candidate B -> genuine 1-1 tie in round 2!
        $this->actingAs($memberB)->postJson("/elections/{$election->id}/vote", [
            'candidate_id' => $candBId,
            'position' => 'president',
        ])->assertOk();

        // H4: Now expire closes_at -> new floor votes MUST be blocked (422), while finalizePosition() MUST STILL SUCCEED!
        $election->update(['closes_at' => now()->subMinute()]);

        $this->actingAs($memberC)->postJson("/elections/{$election->id}/vote", [
            'candidate_id' => $candAId,
            'position' => 'president',
        ])->assertStatus(422);

        // Adviser breaks the genuine 1-1 tie AFTER closes_at -> succeeds with adviser_tiebreak for Candidate B
        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/finalize-position", [
                'tie_resolution' => 'adviser_tiebreak',
                'winner_candidate_id' => $candBId,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'position_finalized')
            ->assertJsonPath('election.position_results.president.winner_user_id', $memberB->id)
            ->assertJsonPath('election.position_results.president.won_by', 'adviser_tiebreak')
            ->assertJsonPath('election.current_position', 'vice_president');
    }

    public function test_h3_ephemeral_chat_never_touches_database_cache_and_flushes_on_pause_expiry_and_completion(): void
    {
        // Force default Laravel cache store to 'database' to prove Rule 6 holds even in production DB-cache config
        config()->set('cache.default', 'database');

        [$adviser] = $this->createCampusUser($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$member] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');

        $token = $member->createToken('Mobile')->plainTextToken;

        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'Ephemeral Chat Compliance Election',
            'status' => 'open',
            'current_position' => 'fourth_year_rep',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'opens_at' => now()->subMinutes(5),
            'closes_at' => now()->addMinutes(30),
            'created_by_user_id' => $adviser->id,
        ]);

        $cache = Election::liveChatCache();
        $cacheKey = Election::liveChatCacheKey($election->id);
        $cache->forget($cacheKey);

        // 1. Store live chat message -> stored in ephemeral_chat file store, ZERO rows in DB `cache` table
        $this->withToken($token)
            ->postJson("/api/v1/elections/{$election->id}/live-chat", [
                'message_body' => 'Strictly ephemeral message!',
            ])
            ->assertCreated();

        $this->assertTrue($cache->has($cacheKey));
        $this->assertSame(0, DB::table('cache')->count());

        // 2. Pausing election flushes ephemeral chat
        $this->actingAs($adviser)
            ->patchJson("/elections/{$election->id}/status", ['status' => 'closed'])
            ->assertOk();
        $this->assertFalse($cache->has($cacheKey));

        // Reopen & post again
        $this->actingAs($adviser)
            ->patchJson("/elections/{$election->id}/status", ['status' => 'open'])
            ->assertOk();

        app('auth')->forgetGuards();
        $this->withToken($token)
            ->postJson("/api/v1/elections/{$election->id}/live-chat", [
                'message_body' => 'Will flush on expiry.',
            ])
            ->assertCreated();
        $this->assertTrue($cache->has($cacheKey));

        // 3. Expiry (closes_at passes) flushes ephemeral chat and blocks reads/writes
        DB::table('elections')->where('id', $election->id)->update(['closes_at' => now()->subSecond()]);

        app('auth')->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/v1/elections/{$election->id}/live-chat")
            ->assertStatus(422);
        $this->assertFalse($cache->has($cacheKey));

        // 4. Re-extend closes_at, post again, then complete election -> flushes ephemeral chat
        $election->refresh();
        $election->update(['closes_at' => now()->addMinutes(30)]);

        app('auth')->forgetGuards();
        $this->withToken($token)
            ->postJson("/api/v1/elections/{$election->id}/live-chat", [
                'message_body' => 'Will flush on completion.',
            ])
            ->assertCreated();
        $this->assertTrue($cache->has($cacheKey));

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/skip-position")
            ->assertOk()
            ->assertJsonPath('election.current_phase', 'completed');

        $this->assertFalse($cache->has($cacheKey));
        $this->assertSame(0, DB::table('cache')->count());
    }

    public function test_m5_community_hub_blocks_unassigned_pending_rejected_and_alumni_users_with_zero_campus_fallback(): void
    {
        [$alijisMember] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Alijis, Approved A.');
        [$talisayMember] = $this->createCampusUser($this->talisay, CpfRole::Member, 'Talisay, Approved B.');
        [$pendingUser] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Pending, User P.', MembershipStatus::Pending);
        [$rejectedUser] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Rejected, User R.', MembershipStatus::Rejected);
        [$alumniUser] = $this->createCampusUser($this->alijis, CpfRole::Member, 'Alumni, User L.', MembershipStatus::Alumni);

        $unassignedUser = User::factory()->create([
            'name' => 'Unassigned, Outsider X.',
            'must_change_password' => false,
        ]);

        // Unassigned, Pending, Rejected, and Alumni users are ALL blocked (403) — never fallback to Campus #1
        foreach ([$unassignedUser, $pendingUser, $rejectedUser, $alumniUser] as $blockedUser) {
            $this->actingAs($blockedUser)
                ->get('/community-hub')
                ->assertForbidden();
        }

        // Approved Talisay member sees ONLY Talisay campus roster, NEVER Campus #1 (Alijis)
        $res = $this->actingAs($talisayMember)
            ->get('/community-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('community/Index')
                ->where('campusId', $this->talisay->id));

        $res->assertSee('Talisay, Approved B.');
        $res->assertDontSee('Alijis, Approved A.');
        $this->assertNotNull($alijisMember->id);
    }
}
