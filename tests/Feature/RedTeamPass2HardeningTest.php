<?php

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Http\Controllers\Api\V1\ElectionApiController;
use App\Http\Controllers\FirstBootSetupController;
use App\Http\Controllers\Prototype\CpfPrototypeController;
use App\Http\Controllers\Prototype\DemoBotController;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusMembership;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\GuidanceReferral;
use App\Models\RoomRequest;
use App\Models\User;
use App\Services\ClubTenureService;
use App\Services\ProfanityFilterService;
use Database\Seeders\BlankSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RedTeamPass2HardeningTest extends TestCase
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
            'initialized_at' => now(),
        ]);

        $this->talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay Campus',
            'slug' => 'talisay',
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Other, Adviser A.',
            'gc_group_name' => 'CPF Talisay Official Peer GC',
            'is_active' => true,
            'initialized_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: CampusMembership}
     */
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
            'password' => Hash::make('SecretPass2026!'),
            'must_change_password' => $mustChangePassword,
            'email_verified_at' => now(),
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
            'last_name' => 'User',
            'full_name_last_first_mi' => $name,
            'course' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Guidance Services' : 'BSIT',
            'year_level' => in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true) ? 'Faculty' : '3',
            'section' => $role === CpfRole::GuidanceCounselor ? 'Guidance Counselor' : 'A',
            'course_year_section' => 'BSIT 3A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.cpf',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-555-0001',
            'approved_at' => $status === MembershipStatus::Approved ? now() : null,
        ]);

        return [$user, $membership];
    }

    private function nextWeekdays(int $count): array
    {
        $days = [];
        $cursor = now()->addDay();
        while (count($days) < $count) {
            if (! $cursor->isWeekend()) {
                $days[] = $cursor->toDateString();
            }
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    public function test_high_05_mobile_nomination_normalization_and_event_checklist_manual_attendance_api(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$officer, $officerMembership] = $this->createCampusUser(CpfRole::SergeantAtArms, 'Mendoza, Rafael J.');
        [$memberA] = $this->createCampusUser(CpfRole::Member, 'Alvarez, Hannah G.');
        [$memberB] = $this->createCampusUser(CpfRole::Member, 'Zamora, Katrina M.');
        [$counselor] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Soriano, Clarissa D.');
        [$otherOfficer] = $this->createCampusUser(CpfRole::President, 'Other, Officer X.', MembershipStatus::Approved, $this->talisay);

        // 1. Mobile nomination response normalizes 'accept' -> 'accepted' and 'decline' -> 'declined'
        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'Mobile Nomination Normalization Test',
            'status' => 'open',
            'current_position' => 'president',
            'current_phase' => 'nomination',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $adviser->id,
        ]);

        $candA = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $memberA->id,
            'position' => 'president',
            'nominated_by_user_id' => $officer->id,
            'nomination_status' => 'pending',
        ]);
        $candB = ElectionCandidate::create([
            'election_id' => $election->id,
            'user_id' => $memberB->id,
            'position' => 'president',
            'nominated_by_user_id' => $officer->id,
            'nomination_status' => 'pending',
        ]);

        $tokenA = $memberA->createToken('MemberA')->plainTextToken;
        $tokenB = $memberB->createToken('MemberB')->plainTextToken;

        $this->withToken($tokenA)
            ->patchJson("/api/v1/elections/{$election->id}/candidates/{$candA->id}/respond", [
                'decision' => 'accept',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'accepted');
        $this->assertSame('accepted', $candA->fresh()->nomination_status);

        app('auth')->forgetGuards();
        $this->withToken($tokenB)
            ->patchJson("/api/v1/elections/{$election->id}/candidates/{$candB->id}/respond", [
                'decision' => 'decline',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'declined');
        $this->assertSame('declined', $candB->fresh()->nomination_status);

        // 2. Mobile checklist & manual-attendance API routes + throttle:60,1
        $manualRoute = Route::getRoutes()->getByName('api.v1.events.manual-attendance');
        $this->assertNotNull($manualRoute);
        $this->assertContains('throttle:60,1', $manualRoute->gatherMiddleware());

        $event = CampusEvent::create([
            'campus_id' => $this->alijis->id,
            'title' => 'Mobile Attendance Assembly',
            'event_date' => now()->toDateString(),
            'start_time' => '00:00',
            'end_time' => '23:59',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        $officerToken = $officer->createToken('OfficerPhone')->plainTextToken;
        $otherToken = $otherOfficer->createToken('OtherOfficerPhone')->plainTextToken;

        // Regular member and cross-campus officer are blocked (403)
        app('auth')->forgetGuards();
        $this->withToken($tokenA)->getJson("/api/v1/events/{$event->id}/checklist")->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($tokenA)->postJson("/api/v1/events/{$event->id}/manual-attendance", [
            'user_id' => $memberA->id,
            'scan_type' => 'login',
        ])->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($otherToken)->getJson("/api/v1/events/{$event->id}/checklist")->assertForbidden();

        // Active officer views checklist (excludes Guidance Counselor) and records manual login + logout
        app('auth')->forgetGuards();
        $checklistRes = $this->withToken($officerToken)
            ->getJson("/api/v1/events/{$event->id}/checklist")
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('event.can_record_attendance', true)
            ->assertJsonPath('summary.total_members', 4);

        $checklistUserIds = array_column($checklistRes->json('checklist'), 'user_id');
        $this->assertNotContains($counselor->id, $checklistUserIds);

        app('auth')->forgetGuards();
        $this->withToken($officerToken)
            ->postJson("/api/v1/events/{$event->id}/manual-attendance", [
                'user_id' => $memberA->id,
                'scan_type' => 'login',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'logged_in');

        app('auth')->forgetGuards();
        $this->withToken($officerToken)
            ->postJson("/api/v1/events/{$event->id}/manual-attendance", [
                'user_id' => $memberA->id,
                'scan_type' => 'logout',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'logged_out');

        // Suspended officer is blocked from both mobile checklist & manual-attendance
        $officerMembership->update([
            'privileges_suspended_at' => now(),
            'suspension_reason' => 'Governance freeze',
            'suspended_by' => $adviser->id,
        ]);

        app('auth')->forgetGuards();
        $this->withToken($officerToken)->getJson("/api/v1/events/{$event->id}/checklist")->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($officerToken)->postJson("/api/v1/events/{$event->id}/manual-attendance", [
            'user_id' => $memberB->id,
            'scan_type' => 'login',
        ])->assertForbidden();
    }

    public function test_item_08_first_boot_latch_secret_channels_prototype_guard_and_attendance_reflection_locks(): void
    {
        // 1. First-Boot initialized_at latch + optional CPF_FIRST_BOOT_SECRET
        Campus::query()->delete();
        $this->seed(BlankSystemSeeder::class);

        // Pending user does NOT close first-boot setup
        $blankCampus = Campus::where('is_active', true)->firstOrFail();
        $this->createCampusUser(CpfRole::Member, 'Pending, Student A.', MembershipStatus::Pending, $blankCampus);
        $this->assertTrue(FirstBootSetupController::needsAdviserSetup());

        // When CPF_FIRST_BOOT_SECRET is configured, missing/wrong secret returns 403
        config(['cpf.first_boot_secret' => 'TopSecretBootKey2026']);
        $payload = [
            'campus_id' => $blankCampus->id,
            'academic_year' => '2026-2027',
            'first_name' => 'Elena',
            'last_name' => 'Montemayor',
            'email' => 'founding.latch@chmsu.edu.ph',
            'password' => 'FoundingPass2026!',
            'password_confirmation' => 'FoundingPass2026!',
            'sex' => 'Female',
        ];

        $this->postJson('/first-boot/setup-adviser', $payload)->assertForbidden();
        $this->postJson('/first-boot/setup-adviser', [...$payload, 'first_boot_secret' => 'WrongSecret'])->assertForbidden();

        $this->post('/first-boot/setup-adviser', [...$payload, 'first_boot_secret' => 'TopSecretBootKey2026'])
            ->assertRedirect('/dashboard');

        $this->assertNotNull($blankCampus->fresh()->initialized_at);
        $this->assertFalse(FirstBootSetupController::needsAdviserSetup());

        // Even if the founding Adviser transitions to Alumni, initialized_at keeps first-boot permanently locked
        $foundingAdviser = User::where('email', 'founding.latch@chmsu.edu.ph')->firstOrFail();
        app(ClubTenureService::class)->transitionToAlumni($foundingAdviser->primaryMembership, $foundingAdviser, 'stepped_down');
        $this->assertFalse(FirstBootSetupController::needsAdviserSetup());

        config(['cpf.first_boot_secret' => '']);
        auth()->logout();

        // 2. routes/channels.php requires ! $user->requiresPasswordChange() on all private channels
        [$tempOfficer] = $this->createCampusUser(CpfRole::President, 'Temp, Officer', MembershipStatus::Approved, $blankCampus, mustChangePassword: true);
        [$tempCounselor] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Temp, Counselor', MembershipStatus::Approved, $blankCampus, mustChangePassword: true);

        $channels = app('Illuminate\Broadcasting\BroadcastManager')->driver()->getChannels();
        $this->assertFalse(($channels['campus.{campusId}.officers-gc.{academicYear}'])($tempOfficer, $blankCampus->id, '2026-2027'));
        $this->assertFalse(($channels['campus.{campusId}.counselors-gc'])($tempCounselor, $blankCampus->id));
        $this->assertFalse(($channels['campus.{campusId}.counselor-dm.{threadKey}'])($tempCounselor, $blankCampus->id, "{$tempCounselor->id}-{$foundingAdviser->id}"));

        // 3. config/cpf.php prototype.enabled defaults to false in non-local/testing
        $this->assertFalse((bool) config('cpf.prototype.enabled'));

        // Prototype controllers enforce isActiveOfficerOrAdviser() and column allowlists (no raw PII)
        [$activeOfficer, $activeOfficerMembership] = $this->createCampusUser(CpfRole::President, 'Active, Officer', MembershipStatus::Approved, $blankCampus);
        [$regularMember] = $this->createCampusUser(CpfRole::Member, 'Plain, Member', MembershipStatus::Approved, $blankCampus);

        config(['cpf.prototype.enabled' => true]);

        $protoController = app(CpfPrototypeController::class);
        $demoController = app(DemoBotController::class);

        $memberReq = Request::create('/prototype', 'GET');
        $memberReq->setUserResolver(fn () => $regularMember);

        try {
            $protoController->index($memberReq);
            $this->fail('Expected 403 for regular member on CpfPrototypeController::index');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $demoController->index($memberReq);
            $this->fail('Expected 403 for regular member on DemoBotController::index');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $officerReq = Request::create('/prototype', 'GET');
        $officerReq->setUserResolver(fn () => $activeOfficer);
        $inertiaResponse = $protoController->index($officerReq);
        $pageData = $inertiaResponse->toResponse($officerReq)->getOriginalContent()->getData()['page']['props'];

        $this->assertNotEmpty($pageData['approvedMembersAZ']);
        $firstRow = $pageData['approvedMembersAZ'][0];
        $this->assertArrayHasKey('full_name_last_first_mi', $firstRow);
        $this->assertArrayNotHasKey('active_email', $firstRow);
        $this->assertArrayNotHasKey('cellphone_number', $firstRow);
        $this->assertArrayNotHasKey('birthdate', $firstRow);
        $this->assertArrayNotHasKey('facebook_profile_url', $firstRow);

        // Suspended officer is blocked from prototype too
        $activeOfficerMembership->update(['privileges_suspended_at' => now()]);
        $activeOfficer->refresh();
        try {
            $protoController->index($officerReq);
            $this->fail('Expected 403 for suspended officer on CpfPrototypeController::index');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        config(['cpf.prototype.enabled' => false]);

        // 4. Transactional idempotency on recordAttendance & duplicate prevention on storeReflection
        $activeOfficerMembership->update(['privileges_suspended_at' => null]);
        $activeOfficer->refresh();
        $eventDate = now()->subDay()->toDateString();
        $completedEvent = CampusEvent::create([
            'campus_id' => $blankCampus->id,
            'title' => 'Completed Reflection Event',
            'event_date' => $eventDate,
            'start_time' => '08:00',
            'end_time' => '10:00',
            'status' => 'approved',
            'reflection_enabled' => true,
            'created_by_user_id' => $activeOfficer->id,
            'approved_by_user_id' => $activeOfficer->id,
            'approved_at' => now()->subDays(2),
        ]);

        CampusEventAttendance::create([
            'campus_event_id' => $completedEvent->id,
            'user_id' => $regularMember->id,
            'logged_in_at' => Carbon::parse($eventDate.' 08:15:00'),
            'logged_in_by_user_id' => $activeOfficer->id,
        ]);

        $this->actingAs($regularMember)
            ->postJson("/events-activities/{$completedEvent->id}/reflection", [
                'title' => 'Grateful reflection',
                'reflection_message' => 'This peer facilitation workshop helped me grow as a listener.',
                'unlock_at' => now()->addMonths(3)->toDateString(),
            ])
            ->assertCreated();

        $this->actingAs($regularMember)
            ->postJson("/events-activities/{$completedEvent->id}/reflection", [
                'title' => 'Duplicate reflection',
                'reflection_message' => 'Trying to submit a second reflection for the same event.',
                'unlock_at' => now()->addMonths(3)->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_medium_01_profanity_filter_evasion_false_positives_and_controller_coverage(): void
    {
        $filter = app(ProfanityFilterService::class);

        // Evasion catches: zero-width, Cyrillic/Greek homoglyphs, doubled letters
        $this->assertTrue($filter->containsProfanity("f\u{200B}uck this"));
        $this->assertTrue($filter->containsProfanity("fu\u{0441}k")); // Cyrillic с -> c
        $this->assertTrue($filter->containsProfanity("\u{0443}\u{0430}w\u{0430}")); // Cyrillic у, а -> y, a
        $this->assertTrue($filter->containsProfanity("g\u{03B1}go")); // Greek α -> a
        $this->assertTrue($filter->containsProfanity('fuuck'));
        $this->assertTrue($filter->containsProfanity('shiit'));
        $this->assertTrue($filter->containsProfanity('yaawa'));
        $this->assertTrue($filter->containsProfanity('gaago'));
        $this->assertTrue($filter->containsProfanity('chinga'));
        $this->assertTrue($filter->containsProfanity('kayat'));

        // Zero false positives on normal Hiligaynon, Tagalog, and English
        $this->assertFalse($filter->containsProfanity('Kinanglan naton magbuligay sa campus.'));
        $this->assertFalse($filter->containsProfanity("Kaya't maghanda tayo para sa General Assembly."));
        $this->assertFalse($filter->containsProfanity('We are coaching a new batch of peer facilitators.'));
        $this->assertFalse($filter->containsProfanity('Please coordinate with Prof. Vergara at the Guidance Office.'));
        $this->assertFalse($filter->containsProfanity('Serving puto and snacks; remember buto at atay biology; Pepe Rizal.'));
        $this->assertFalse($filter->containsProfanity('Review the icon chapter guidelines.'));

        // Controller coverage checks
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$pio] = $this->createCampusUser(CpfRole::Pio, 'Santos, Bea L.');
        [$member] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        $weekday = $this->nextWeekdays(1)[0];

        // AnnouncementController::store
        $this->actingAs($pio)
            ->postJson('/announcements', [
                'template_key' => 'general_assembly',
                'title' => 'Fuuck announcement',
                'body' => 'Normal announcement body text.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        // CpfFeatureController::requestTestingRoom & reviewTestingRoom
        $this->actingAs($member)
            ->postJson('/testing-room/request', [
                'campus_id' => $this->alijis->id,
                'purpose' => 'Study session yaawa',
                'request_date' => $weekday,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('purpose');

        $roomRes = $this->actingAs($member)
            ->postJson('/testing-room/request', [
                'campus_id' => $this->alijis->id,
                'purpose' => 'Clean study session',
                'request_date' => $weekday,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])
            ->assertCreated();

        $this->actingAs($adviser)
            ->patchJson('/testing-room/'.$roomRes->json('room_request.id').'/review', [
                'status' => 'approved',
                'review_notes' => 'Notes with shiit',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('review_notes');

        // CpfV4Controller: storeTimeCapsule, storeElection, storeCandidate, nominateCandidate, storeQuote (author_name/mood_tag)
        $this->actingAs($member)
            ->postJson('/time-capsules', [
                'campus_id' => $this->alijis->id,
                'title' => 'Gaago capsule title',
                'message' => 'Clean reflection letter to my future self.',
                'unlock_at' => now()->addMonths(2)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->actingAs($adviser)
            ->postJson('/elections', [
                'title' => 'Election fuuck title',
                'academic_year' => '2026-2027',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $electionRes = $this->actingAs($adviser)
            ->postJson('/elections', [
                'title' => 'AY 2026-2027 General Election',
                'academic_year' => '2026-2027',
            ])
            ->assertCreated();
        $electionId = (int) $electionRes->json('election.id');

        $this->actingAs($adviser)
            ->postJson("/elections/{$electionId}/candidates", [
                'user_id' => $member->id,
                'position' => 'president',
                'platform' => 'Platform with yaawa',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');

        $this->actingAs($member)
            ->postJson("/elections/{$electionId}/nominate", [
                'nominee_user_id' => $member->id,
                'platform' => "Platform with f\u{200B}uck",
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('platform');

        $this->actingAs($member)
            ->postJson('/quotes', [
                'quote_text' => 'Stay calm and breathe.',
                'author_name' => 'Gaago Author',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('author_name');

        $this->actingAs($member)
            ->postJson('/quotes', [
                'quote_text' => 'Stay calm and breathe.',
                'mood_tag' => 'shiit',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mood_tag');

        // EventActivityController: store, updateSchedule, cancel, storeReflection (title)
        $futureDate = now()->addDays(4)->toDateString();
        $this->actingAs($adviser)
            ->postJson('/events-activities', [
                'title' => 'Event with yaawa',
                'event_date' => $futureDate,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $eventRes = $this->actingAs($adviser)
            ->postJson('/events-activities', [
                'title' => 'Clean Peer Workshop',
                'event_date' => $futureDate,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertCreated();
        $eventId = (int) $eventRes->json('event.id');

        $this->actingAs($adviser)
            ->patchJson("/events-activities/{$eventId}/schedule", [
                'event_date' => now()->addDays(5)->toDateString(),
                'title' => 'Updated fuuck title',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');

        $this->actingAs($adviser)
            ->patchJson("/events-activities/{$eventId}/cancel", [
                'cancellation_reason' => 'Cancelled due to shiit',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancellation_reason');

        $pastEvent = CampusEvent::create([
            'campus_id' => $this->alijis->id,
            'title' => 'Past Event Reflection Title Test',
            'event_date' => now()->subDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'status' => 'approved',
            'reflection_enabled' => true,
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now()->subDays(2),
        ]);
        CampusEventAttendance::create([
            'campus_event_id' => $pastEvent->id,
            'user_id' => $member->id,
            'logged_in_at' => now()->subDay(),
            'logged_in_by_user_id' => $adviser->id,
        ]);

        $this->actingAs($member)
            ->postJson("/events-activities/{$pastEvent->id}/reflection", [
                'title' => 'Gaago reflection title',
                'reflection_message' => 'A completely respectful reflection body text.',
                'unlock_at' => now()->addMonths(2)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_medium_02_ephemeral_live_chat_close_race_and_disk_ttl_sweep(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$member] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');

        $election = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'Live Chat Race Election',
            'status' => 'open',
            'current_position' => 'president',
            'current_phase' => 'voting',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $adviser->id,
        ]);

        // Simulate close race: stale in-memory Election model thinks it is open, DB row was just closed
        $staleElection = Election::findOrFail($election->id);
        Election::whereKey($election->id)->update(['status' => 'closed']);

        $cache = Election::liveChatCache();
        $cacheKey = Election::liveChatCacheKey($election->id);
        $cache->put($cacheKey, [['id' => 'old', 'message_body' => 'Old']], now()->addHour());

        $req = Request::create("/api/v1/elections/{$election->id}/live-chat", 'POST', [
            'message_body' => 'Race condition message',
        ]);
        $req->setUserResolver(fn () => $member);

        try {
            app(ElectionApiController::class)->liveChatStore($req, $staleElection);
            $this->fail('Expected 422 when election closed before acquiring live-chat lock.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertFalse($cache->has($cacheKey));

        // Completed election (even if status remained 'open') is purged by cpf:purge-expired-election-chat
        $completedElection = Election::create([
            'campus_id' => $this->alijis->id,
            'academic_year' => '2026-2027',
            'title' => 'Completed Phase Election',
            'status' => 'open',
            'current_phase' => 'completed',
            'round_number' => 1,
            'position_results' => [],
            'created_by_user_id' => $adviser->id,
        ]);
        $completedKey = Election::liveChatCacheKey($completedElection->id);
        $cache->put($completedKey, [['id' => 'done', 'message_body' => 'Done']], now()->addHour());

        // Expired TTL file on disk is swept, active TTL file is preserved
        $ephemeralDir = storage_path('framework/cache/ephemeral_chat');
        @mkdir($ephemeralDir, 0755, true);
        $expiredFile = $ephemeralDir.DIRECTORY_SEPARATOR.'test_expired_ttl_entry';
        $activeFile = $ephemeralDir.DIRECTORY_SEPARATOR.'test_active_ttl_entry';
        file_put_contents($expiredFile, str_pad((string) (now()->timestamp - 60), 10, '0', STR_PAD_LEFT).'s:3:"old";');
        file_put_contents($activeFile, str_pad((string) (now()->timestamp + 3600), 10, '0', STR_PAD_LEFT).'s:6:"active";');

        try {
            $this->artisan('cpf:purge-expired-election-chat')->assertSuccessful();

            $this->assertFalse($cache->has($completedKey));
            $this->assertFileDoesNotExist($expiredFile);
            $this->assertFileExists($activeFile);
        } finally {
            @unlink($expiredFile);
            @unlink($activeFile);
        }
    }

    public function test_medium_03_testing_room_pending_cap_referral_overlap_scope_reviewed_by_and_alumni_cleanup(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');
        [$counselorUser] = $this->createCampusUser(CpfRole::GuidanceCounselor, 'Soriano, Clarissa D.');
        [$student, $studentMembership] = $this->createCampusUser(CpfRole::Member, 'Reyes, Katrina M.');
        [$otherStudent] = $this->createCampusUser(CpfRole::Member, 'Santos, Carla P.');

        $counselor = CampusCounselor::registerRealCounselor($this->alijis->id, 'Soriano, Clarissa D.');
        [$day1, $day2, $day3, $day4] = $this->nextWeekdays(4);

        // 1. Non-counselor student capped at 2 active future pending bookings across all dates
        $this->actingAs($student)
            ->postJson('/testing-room/request', [
                'campus_id' => $this->alijis->id,
                'purpose' => 'Session 1',
                'request_date' => $day1,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])
            ->assertCreated();

        $this->actingAs($student)
            ->postJson('/testing-room/request', [
                'campus_id' => $this->alijis->id,
                'purpose' => 'Session 2',
                'request_date' => $day2,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])
            ->assertCreated();

        // 3rd future pending booking fails with 422
        $this->actingAs($student)
            ->postJson('/testing-room/request', [
                'campus_id' => $this->alijis->id,
                'purpose' => 'Session 3 over cap',
                'request_date' => $day3,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_time');

        // Counselor is exempt from the 2-hold cap
        foreach ([$day1, $day2, $day3] as $i => $d) {
            $this->actingAs($counselorUser)
                ->postJson('/testing-room/request', [
                    'campus_id' => $this->alijis->id,
                    'purpose' => "Counselor block {$i}",
                    'request_date' => $d,
                    'start_time' => '13:30',
                    'end_time' => '14:30',
                ])
                ->assertCreated();
        }

        // 2. Alumni transition auto-rejects future pending RoomRequests with reviewed_by_user_id
        app(ClubTenureService::class)->transitionToAlumni($studentMembership, $adviser, 'graduated');

        $studentRooms = RoomRequest::where('requested_by_user_id', $student->id)->get();
        $this->assertCount(2, $studentRooms);
        foreach ($studentRooms as $rr) {
            $this->assertSame('rejected', $rr->status);
            $this->assertSame($adviser->id, $rr->reviewed_by_user_id);
        }

        // 3. Referral overlap ignores referrals whose linked RoomRequest was rejected, and confirmReferral sets reviewed_by_user_id
        $refRes = $this->actingAs($otherStudent)
            ->postJson('/referrals/submit', [
                'campus_id' => $this->alijis->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Peer Client A',
                'suggested_priority' => ReferralPriority::Routine->value,
                'concern_summary' => 'Needs consultation.',
                'counselor_id' => $counselor->id,
                'preferred_date' => $day4,
                'preferred_start_time' => '08:30',
                'preferred_end_time' => '09:30',
            ])
            ->assertCreated();

        $referral = GuidanceReferral::findOrFail($refRes->json('referral_id'));
        $linkedRoomId = $referral->room_request_id;
        $this->assertNotNull($linkedRoomId);

        // Direct completion auto-releases linked pending room request with reviewed_by_user_id
        $this->actingAs($counselorUser)
            ->patchJson("/referrals/{$referral->id}/confirm", [
                'feedback_status' => 'completed',
                'safe_scheduling_note' => 'Resolved via quick walk-in.',
            ])
            ->assertOk();

        $releasedRoom = RoomRequest::findOrFail($linkedRoomId);
        $this->assertSame('rejected', $releasedRoom->status);
        $this->assertSame($counselorUser->id, $releasedRoom->reviewed_by_user_id);

        // Also verify: an ongoing/pending referral whose linked room request is rejected does NOT block rebooking the slot
        $ref2Res = $this->actingAs($otherStudent)
            ->postJson('/referrals/submit', [
                'campus_id' => $this->alijis->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Peer Client B',
                'suggested_priority' => ReferralPriority::Routine->value,
                'concern_summary' => 'Follow up slot.',
                'counselor_id' => $counselor->id,
                'preferred_date' => $day4,
                'preferred_start_time' => '10:30',
                'preferred_end_time' => '11:30',
            ])
            ->assertCreated();

        $ref2 = GuidanceReferral::findOrFail($ref2Res->json('referral_id'));
        RoomRequest::whereKey($ref2->room_request_id)->update(['status' => 'rejected']);

        // Another student can now book that exact 10:30-11:30 slot even though ref2 is still pending
        [$thirdStudent] = $this->createCampusUser(CpfRole::Member, 'Dela Cruz, Third S.');
        $this->actingAs($thirdStudent)
            ->postJson('/referrals/submit', [
                'campus_id' => $this->alijis->id,
                'referral_type' => 'peer_referral',
                'student_identifier' => 'Peer Client C',
                'suggested_priority' => ReferralPriority::Routine->value,
                'concern_summary' => 'Re-booking freed slot.',
                'counselor_id' => $counselor->id,
                'preferred_date' => $day4,
                'preferred_start_time' => '10:30',
                'preferred_end_time' => '11:30',
            ])
            ->assertCreated();
    }

    public function test_medium_04_sanctum_token_revocation_on_alumni_transition_and_password_rotation(): void
    {
        [$adviser] = $this->createCampusUser(CpfRole::Adviser, 'Montemayor, Elena V.');

        // 1. Mobile API forceChangePassword keeps current token and revokes other tokens
        [$apiUser] = $this->createCampusUser(CpfRole::Member, 'Mobile, Rotate A.', MembershipStatus::Approved, $this->alijis, mustChangePassword: true);
        $currentToken = $apiUser->createToken('CurrentDevice')->plainTextToken;
        $otherToken = $apiUser->createToken('StolenDevice')->plainTextToken;

        $this->withToken($currentToken)
            ->postJson('/api/v1/auth/password/force-change', [
                'password' => 'RotatedMobile2026!',
                'password_confirmation' => 'RotatedMobile2026!',
            ])
            ->assertOk();

        $this->assertSame(1, $apiUser->fresh()->tokens()->count());
        app('auth')->forgetGuards();
        $this->withToken($currentToken)->getJson('/api/v1/auth/me')->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // 2. Web ForcePasswordChangeController::update revokes all Sanctum tokens
        [$webTempUser] = $this->createCampusUser(CpfRole::Member, 'Web, Rotate B.', MembershipStatus::Approved, $this->alijis, mustChangePassword: true);
        $webTempUser->createToken('Phone1');
        $webTempUser->createToken('Phone2');
        $this->assertSame(2, $webTempUser->tokens()->count());

        $this->actingAs($webTempUser)
            ->post('/force-password-change', [
                'password' => 'RotatedWeb2026!',
                'password_confirmation' => 'RotatedWeb2026!',
            ])
            ->assertRedirect(route('dashboard'));
        $this->assertSame(0, $webTempUser->fresh()->tokens()->count());

        // 3. Settings SecurityController::update revokes all Sanctum tokens
        [$secUser] = $this->createCampusUser(CpfRole::Member, 'Security, Rotate C.');
        $secUser->createToken('OldTablet');
        $this->assertSame(1, $secUser->tokens()->count());

        $this->actingAs($secUser)
            ->put(route('user-password.update'), [
                'current_password' => 'SecretPass2026!',
                'password' => 'UpdatedSecPass2026!',
                'password_confirmation' => 'UpdatedSecPass2026!',
            ])
            ->assertRedirect();
        $this->assertSame(0, $secUser->fresh()->tokens()->count());

        // 4. Fortify ResetUserPassword::reset revokes all Sanctum tokens
        [$resetUser] = $this->createCampusUser(CpfRole::Member, 'Reset, Rotate D.');
        $resetUser->createToken('ForgottenPhone');
        $this->assertSame(1, $resetUser->tokens()->count());

        app(ResetUserPassword::class)->reset($resetUser, [
            'password' => 'ResetPass2026!',
            'password_confirmation' => 'ResetPass2026!',
        ]);
        $this->assertSame(0, $resetUser->fresh()->tokens()->count());

        // 5. ClubTenureService::transitionToAlumni revokes all Sanctum tokens
        [$alumniUser, $alumniMembership] = $this->createCampusUser(CpfRole::Member, 'Alumni, Revoke E.');
        $alumniUser->createToken('ActiveMemberPhone');
        $this->assertSame(1, $alumniUser->tokens()->count());

        app(ClubTenureService::class)->transitionToAlumni($alumniMembership, $adviser, 'graduated');
        $this->assertSame(0, $alumniUser->fresh()->tokens()->count());
    }
}
