<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\CounselorGcMessage;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\GuidanceReferral;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\OfficerGcMessage;
use App\Models\PaymentDestination;
use App\Models\RoomRequest;
use App\Models\SchoolYearArchive;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RedTeamPrivacyArchiveBookingElectionTest extends TestCase
{
    use RefreshDatabase;

    private Campus $alijis;

    private CampusRoom $testingRoom;

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

        $this->testingRoom = CampusRoom::create([
            'campus_id' => $this->alijis->id,
            'room_code' => 'TESTING_ROOM',
            'room_name' => 'Testing Room',
            'capacity' => 6,
            'is_single_allowed_room' => true,
        ]);
    }

    private function createCampusUser(
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
            'campus_id' => $this->alijis->id,
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
            'approved_at' => $status === MembershipStatus::Approved ? now() : null,
        ]);

        return [$user, $membership];
    }

    public function test_ra10173_referral_linked_room_request_masks_details_blocks_officer_review_and_blocks_counselor_gc_transcript(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$president] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$member] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');
        [$otherMember] = $this->createCampusUser(CpfRole::Member, 'Santos, Paolo C.');

        $counselorEntry = CampusCounselor::registerRealCounselor($this->alijis->id, 'Delos Santos, Clarissa R.');
        $slotDate = now()->next(CarbonInterface::WEDNESDAY)->format('Y-m-d');

        // Peer submits referral with a Testing Room slot
        $refRes = $this->actingAs($member)->postJson('/referrals/submit', [
            'campus_id' => $this->alijis->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Confidential Student X',
            'suggested_priority' => ReferralPriority::Urgent->value,
            'concern_summary' => 'Highly sensitive mental health note.',
            'counselor_id' => $counselorEntry->id,
            'preferred_date' => $slotDate,
            'preferred_start_time' => '09:00',
            'preferred_end_time' => '10:00',
        ])->assertCreated();

        $referral = GuidanceReferral::findOrFail((int) $refRes->json('referral_id'));
        $linkedRoomRequest = RoomRequest::findOrFail((int) $referral->room_request_id);

        // 1. Student Officer (President) CANNOT see referral-linked room purpose/requester/review_notes
        $presidentRes = $this->actingAs($president)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roomRequests.0.id', $linkedRoomRequest->id)
                ->where('roomRequests.0.purpose', 'Booked')
                ->where('roomRequests.0.requester_name', 'Confidential Booking')
                ->where('roomRequests.0.review_notes', null));
        $presidentRes->assertDontSee('Guidance Referral Consultation');
        $presidentRes->assertDontSee('Reyes, Katrina M.');

        // 2. Student Officer (President) is FORBIDDEN from reviewing referral-linked RoomRequest
        $this->actingAs($president)
            ->patchJson("/testing-room/{$linkedRoomRequest->id}/review", [
                'status' => 'approved',
                'review_notes' => 'Should be blocked',
            ])
            ->assertForbidden();

        // 3. Guidance Counselor CAN see full details and approve the referral-linked RoomRequest
        $this->actingAs($counselor)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roomRequests.0.purpose', 'Guidance Referral Consultation')
                ->where('roomRequests.0.requester_name', 'Reyes, Katrina M.'));

        $this->actingAs($counselor)
            ->patchJson("/testing-room/{$linkedRoomRequest->id}/review", [
                'status' => 'approved',
                'review_notes' => 'Counselor intake room confirmed.',
            ])
            ->assertOk();

        // 4. Other regular member does NOT see review_notes on someone else's approved RoomRequest
        $this->actingAs($otherMember)
            ->get('/campus-hub')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roomRequests.0.purpose', 'Booked')
                ->where('roomRequests.0.requester_name', 'Confidential Booking')
                ->where('roomRequests.0.review_notes', null));

        // 5. Non-Adviser GuidanceCounselor is blocked from Officers GC archive & transcripts (Rule 2)
        $archive = OfficerGcArchive::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2025-2026',
            'zip_file_name' => 'cpf_campus_1_officers_gc_2025-2026.zip',
            'hdd_storage_path' => storage_path('app/archives/nonexistent.zip'),
            'sha256_checksum' => str_repeat('a', 64),
            'total_messages' => 5,
            'archived_by_user_id' => $adviser->id,
            'archived_at' => now(),
        ]);

        $this->assertFalse($counselor->canAccessAlumniArchive());
        $this->actingAs($counselor)->get('/alumni-archive')->assertForbidden();
        $this->actingAs($counselor)->getJson("/alumni-archive/gc/{$archive->id}/transcript")->assertForbidden();
    }

    public function test_gc_archives_prevent_rearchive_conflict_empty_counselor_archive_and_hide_storage_paths(): void
    {
        Storage::fake('local');

        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$president] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$treasurer] = $this->createCampusUser(CpfRole::Treasurer, 'Delgado, Liam C.');
        [$member] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

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
            'message_body' => 'Term wrap-up notes.',
        ]);

        // 1. Archiving active Officers GC succeeds and hides hdd_storage_path
        $res = $this->actingAs($adviser)
            ->postJson("/officers-gc/{$channel->id}/archive")
            ->assertOk()
            ->assertJsonMissingPath('archive.hdd_storage_path');

        $archiveRecord = OfficerGcArchive::findOrFail((int) $res->json('archive.id'));
        $zipPath = (string) $archiveRecord->hdd_storage_path;

        try {
            $this->assertFileExists($zipPath);

            // Re-archiving the already-inactive channel returns 409 Conflict
            $this->actingAs($adviser)
                ->postJson("/officers-gc/{$channel->id}/archive")
                ->assertStatus(409);

            // Sending a message to the archived/inactive channel returns 403
            $this->actingAs($president)
                ->postJson("/officers-gc/{$channel->id}/messages", ['message_body' => 'Late msg'])
                ->assertForbidden();
        } finally {
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }

        // 2. Archiving empty Counselors Private GC returns 422
        $this->actingAs($adviser)
            ->postJson('/counselors-gc/archive')
            ->assertStatus(422);

        // With a message, Counselors Private GC archive succeeds and hides hdd_storage_path
        CounselorGcMessage::create([
            'campus_id' => $this->alijis->id,
            'sender_user_id' => $adviser->id,
            'sender_role' => 'adviser',
            'message_body' => 'Counselor coordination note.',
        ]);

        $cRes = $this->actingAs($adviser)
            ->postJson('/counselors-gc/archive')
            ->assertOk()
            ->assertJsonMissingPath('archive.hdd_storage_path');

        $cArchive = SchoolYearArchive::findOrFail((int) $cRes->json('archive.id'));
        if (is_file((string) $cArchive->hdd_storage_path)) {
            @unlink((string) $cArchive->hdd_storage_path);
        }

        // 3. PaymentSubmission hides receipt_hdd_path on submit & validate responses
        $dest = PaymentDestination::create([
            'campus_id' => $this->alijis->id,
            'channel' => 'GCash',
            'account_name' => 'CPF Alijis',
            'account_number' => '0917-000-0001',
            'is_active' => true,
            'approved_by_adviser_id' => $adviser->id,
        ]);

        $payRes = $this->actingAs($member)
            ->post('/payments/submit', [
                'destination_id' => $dest->id,
                'purpose' => 'AY Dues',
                'amount' => 100,
                'reference_number' => 'GCASH-9988',
                'receipt' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('payment.has_receipt', true)
            ->assertJsonMissingPath('payment.receipt_hdd_path');

        $paymentId = (int) $payRes->json('payment.id');

        $this->actingAs($treasurer)
            ->patchJson("/payments/{$paymentId}/validate", ['status' => 'validated'])
            ->assertOk()
            ->assertJsonPath('payment.has_receipt', true)
            ->assertJsonMissingPath('payment.receipt_hdd_path');
    }

    public function test_room_review_and_referral_ongoing_reject_conflicts_and_strict_membership_and_photo_guards(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselor] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Delos Santos, Clarissa R.');
        [$member] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');
        [$pendingUser] = $this->createCampusUser(CpfRole::Member, 'Pending, Applicant P.', MembershipStatus::Pending);
        $unassignedUser = User::factory()->create(['must_change_password' => false]);

        $slotDate = now()->addDays(4)->format('Y-m-d');

        // 1. Unassigned user (primaryMembership === null) gets 403 on Campus Hub, Testing Room, and Referrals
        $this->actingAs($unassignedUser)->get('/campus-hub')->assertForbidden();
        $this->actingAs($unassignedUser)->postJson('/testing-room/request', [
            'campus_id' => $this->alijis->id,
            'purpose' => 'Bypass attempt',
            'request_date' => $slotDate,
            'start_time' => '09:00',
            'end_time' => '10:00',
        ])->assertForbidden();
        $this->actingAs($unassignedUser)->postJson('/referrals/submit', [
            'campus_id' => $this->alijis->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Bypass Client',
            'suggested_priority' => ReferralPriority::Routine->value,
            'concern_summary' => 'Should be rejected.',
        ])->assertForbidden();

        // 2. reviewTestingRoom() only transitions from pending (422 otherwise) and returns 409 on approval overlap
        $approvedReq = RoomRequest::create([
            'campus_id' => $this->alijis->id,
            'room_id' => $this->testingRoom->id,
            'requested_by_user_id' => $member->id,
            'purpose' => 'Approved Slot',
            'request_date' => $slotDate,
            'start_time' => '10:00',
            'end_time' => '11:00',
            'status' => 'approved',
        ]);

        $this->actingAs($adviser)
            ->patchJson("/testing-room/{$approvedReq->id}/review", ['status' => 'rejected'])
            ->assertStatus(422);

        $overlappingPending = RoomRequest::create([
            'campus_id' => $this->alijis->id,
            'room_id' => $this->testingRoom->id,
            'requested_by_user_id' => $member->id,
            'purpose' => 'Competing Pending Slot',
            'request_date' => $slotDate,
            'start_time' => '10:30',
            'end_time' => '11:30',
            'status' => 'pending',
        ]);

        $this->actingAs($adviser)
            ->patchJson("/testing-room/{$overlappingPending->id}/review", ['status' => 'approved'])
            ->assertStatus(409);

        // 3. confirmReferral() -> ongoing returns 409 when linked RoomRequest overlaps an already-approved booking
        $referral = GuidanceReferral::create([
            'campus_id' => $this->alijis->id,
            'submitted_by_user_id' => $member->id,
            'referral_type' => 'peer_referral',
            'student_identifier' => 'Student Conflict',
            'suggested_priority' => ReferralPriority::Urgent,
            'triage_response_target' => ReferralPriority::Urgent->responseTarget(),
            'encrypted_concern_summary' => 'Encrypted concern.',
            'status' => 'submitted',
            'feedback_status' => 'pending',
            'room_request_id' => $overlappingPending->id,
        ]);

        $this->actingAs($counselor)
            ->patchJson("/referrals/{$referral->id}/confirm", [
                'feedback_status' => 'ongoing',
            ])
            ->assertStatus(409);

        // 4. ProfileController::showPhoto() requires verified viewer, approved/alumni target, and local disk only
        Storage::disk('local')->put('profile-photos/member.png', 'png-local-bytes');
        Storage::disk('public')->put('profile-photos/public-only.png', 'png-public-bytes');

        $member->forceFill(['profile_photo_path' => 'profile-photos/member.png'])->save();
        $pendingUser->forceFill(['profile_photo_path' => 'profile-photos/member.png'])->save();
        $adviser->forceFill(['profile_photo_path' => 'profile-photos/public-only.png'])->save();

        // Pending (unverified) viewer blocked (403)
        $this->actingAs($pendingUser)->get("/profile-photos/{$member->id}")->assertForbidden();

        // Verified viewer cannot view a Pending applicant's photo (403)
        $this->actingAs($member)->get("/profile-photos/{$pendingUser->id}")->assertForbidden();

        // Verified viewer CAN view approved same-campus member's local-disk photo (200)
        $this->actingAs($counselor)->get("/profile-photos/{$member->id}")->assertOk();

        // File existing ONLY on public disk returns 404 (public fallback removed)
        $this->actingAs($member)->get("/profile-photos/{$adviser->id}")->assertNotFound();
    }

    public function test_election_finalization_blocks_late_votes_and_unopposed_final_seat_auto_archives_gc_with_acting_adviser(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$otherUser] = $this->createCampusUser(CpfRole::President, 'Mercado, Sofia R.');
        [$voter] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');
        [$fourthYearCandidate] = $this->createCampusUser(CpfRole::Member, 'Senior, Alex R.', MembershipStatus::Approved, '4');

        $channel = OfficerGcChannel::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'channel_key' => 'campus.alijis.officers-gc.2026-2027',
            'is_active' => true,
        ]);

        OfficerGcMessage::create([
            'channel_id' => $channel->id,
            'sender_user_id' => $otherUser->id,
            'sender_role' => 'president',
            'message_body' => 'Final term discussion.',
        ]);

        // 1. Unopposed final seat (fourth_year_rep) with different created_by_user_id still auto-archives under acting $adviser
        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'AY 2026-2027 Unopposed Final Seat Election',
            'status' => 'open',
            'current_position' => 'fourth_year_rep',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $otherUser->id,
        ]);

        $cand = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $fourthYearCandidate->id,
            'position' => 'fourth_year_rep',
            'nomination_status' => 'accepted',
        ]);

        $this->actingAs($adviser)
            ->postJson("/elections/{$election->id}/close-nominations")
            ->assertOk()
            ->assertJsonPath('status', 'elected_unopposed')
            ->assertJsonPath('election.status', 'closed')
            ->assertJsonPath('election.current_phase', 'completed');

        $archive = OfficerGcArchive::query()->where('campus_id', $this->alijis->id)->firstOrFail();
        $this->assertSame($adviser->id, $archive->archived_by_user_id);
        $this->assertFalse($channel->fresh()->is_active);
        $this->assertDatabaseHas('officer_gc_channels', [
            'campus_id' => $this->alijis->id,
            'is_active' => true,
        ]);

        if (is_file((string) $archive->hdd_storage_path)) {
            @unlink((string) $archive->hdd_storage_path);
        }

        // 2. Late vote after election finalized/closed is rejected with 422
        $this->actingAs($voter)
            ->postJson("/elections/{$election->id}/vote", [
                'candidate_id' => $cand->id,
                'position' => 'fourth_year_rep',
            ])
            ->assertStatus(422);
    }
}
