<?php

namespace Tests\Feature;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusEventNotification;
use App\Models\CampusEventReflection;
use App\Models\CampusMembership;
use App\Models\TimeCapsule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampusEventsAndActivitiesTest extends TestCase
{
    use RefreshDatabase;

    private Campus $talisay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay (Main Campus)',
            'slug' => 'talisay',
            'adviser_name' => 'Dr. Elena V. Montemayor, RGC',
            'gc_group_name' => 'CPF Talisay Official Peer GC',
            'is_active' => true,
        ]);
    }

    private function createMember(Campus $campus, CpfRole $role, string $name = 'Member, Sample A.'): User
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
            'active_fb_account' => $name.' (Portrait)',
            'cellphone_number' => '0917-000-0000',
        ]);

        return $user;
    }

    public function test_only_adviser_president_and_vp_can_propose_events_with_one_day_advance_limit(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember($this->talisay, CpfRole::President, 'Mercado, Sofia R.');
        $vp = $this->createMember($this->talisay, CpfRole::VicePresident, 'Bautista, Miguel A.');
        $secretary = $this->createMember($this->talisay, CpfRole::Secretary, 'Alvarez, Hannah G.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $futureDate = now()->addDays(2)->toDateString();

        // Secretary & ordinary member blocked with 403
        $this->actingAs($secretary)
            ->postJson('/events-activities', [
                'title' => 'Unauthorized Secretary Event',
                'event_date' => $futureDate,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertForbidden();

        $this->actingAs($member)
            ->postJson('/events-activities', [
                'title' => 'Unauthorized Member Event',
                'event_date' => $futureDate,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertForbidden();

        // Scheduling for today or yesterday fails the strict 1-day-before limit
        $this->actingAs($president)
            ->postJson('/events-activities', [
                'title' => 'Same Day Event Blocked',
                'event_date' => now()->toDateString(),
                'start_time' => '14:00',
                'end_time' => '16:00',
            ])
            ->assertStatus(422);

        $this->actingAs($vp)
            ->postJson('/events-activities', [
                'title' => 'Past Event Blocked',
                'event_date' => now()->subDay()->toDateString(),
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertStatus(422);

        // President proposes event >= 1 day ahead -> pending_approval + notifies Adviser only
        $this->actingAs($president)
            ->postJson('/events-activities', [
                'title' => 'President Peer Dialogue Day',
                'location' => 'CHMSU Guidance AVR',
                'event_date' => $futureDate,
                'start_time' => '09:00',
                'end_time' => '11:00',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'pending_approval');

        $proposedEvent = CampusEvent::where('title', 'President Peer Dialogue Day')->firstOrFail();
        $this->assertDatabaseHas('campus_event_notifications', [
            'campus_event_id' => $proposedEvent->id,
            'user_id' => $adviser->id,
            'type' => 'event_proposed',
        ]);
        $this->assertDatabaseMissing('campus_event_notifications', [
            'campus_event_id' => $proposedEvent->id,
            'user_id' => $member->id,
        ]);

        // Adviser schedules event directly -> auto-approved + notifies all approved campus members
        $this->actingAs($adviser)
            ->postJson('/events-activities', [
                'title' => 'Adviser Mental Wellness Summit',
                'event_date' => now()->addDays(3)->toDateString(),
                'start_time' => '13:00',
                'end_time' => '16:00',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'approved');

        $adviserEvent = CampusEvent::where('title', 'Adviser Mental Wellness Summit')->firstOrFail();
        $this->assertDatabaseHas('campus_event_notifications', [
            'campus_event_id' => $adviserEvent->id,
            'user_id' => $member->id,
            'type' => 'event_approved',
        ]);
    }

    public function test_adviser_can_approve_drag_reschedule_and_cancel_events_while_non_advisers_are_blocked(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $president = $this->createMember($this->talisay, CpfRole::President, 'Mercado, Sofia R.');
        $vp = $this->createMember($this->talisay, CpfRole::VicePresident, 'Bautista, Miguel A.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $event = CampusEvent::create([
            'campus_id' => $this->talisay->id,
            'title' => 'VP Stress Relief Caravan',
            'event_date' => now()->addDays(4)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'pending_approval',
            'created_by_user_id' => $vp->id,
        ]);

        // Non-advisers (even President & VP) cannot approve, reschedule, or cancel
        foreach ([$president, $vp, $member] as $actor) {
            $this->actingAs($actor)->patchJson("/events-activities/{$event->id}/approve")->assertForbidden();
            $this->actingAs($actor)->patchJson("/events-activities/{$event->id}/schedule", [
                'event_date' => now()->addDays(5)->toDateString(),
            ])->assertForbidden();
            $this->actingAs($actor)->patchJson("/events-activities/{$event->id}/cancel")->assertForbidden();
        }

        // Adviser approves pending event -> notifies campus members
        $this->actingAs($adviser)
            ->patchJson("/events-activities/{$event->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('campus_event_notifications', [
            'campus_event_id' => $event->id,
            'user_id' => $member->id,
            'type' => 'event_approved',
        ]);

        // Adviser drag-reschedules (date-only payload preserves original start/end times)
        $movedDate = now()->addDays(5)->toDateString();
        $this->actingAs($adviser)
            ->patchJson("/events-activities/{$event->id}/schedule", [
                'event_date' => $movedDate,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'rescheduled');

        $event->refresh();
        $this->assertSame($movedDate, $event->event_date->format('Y-m-d'));
        $this->assertSame('09:00', $event->start_time);
        $this->assertSame('11:00', $event->end_time);
        $this->assertDatabaseHas('campus_event_notifications', [
            'campus_event_id' => $event->id,
            'user_id' => $member->id,
            'type' => 'event_rescheduled',
        ]);

        // Dragging onto today fails the 1-day-before rule
        $this->actingAs($adviser)
            ->patchJson("/events-activities/{$event->id}/schedule", [
                'event_date' => now()->toDateString(),
            ])
            ->assertStatus(422);

        // Adviser cancels event -> notifies members
        $this->actingAs($adviser)
            ->patchJson("/events-activities/{$event->id}/cancel", [
                'cancellation_reason' => 'Venue under maintenance',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('campus_event_notifications', [
            'campus_event_id' => $event->id,
            'user_id' => $member->id,
            'type' => 'event_cancelled',
        ]);
    }

    public function test_approved_event_auto_activates_unlocks_officer_qr_login_logout_and_blocks_regular_members(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $saa = $this->createMember($this->talisay, CpfRole::SergeantAtArms, 'Mendoza, Rafael J.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $eventDate = now()->addDays(2)->toDateString();
        $event = CampusEvent::create([
            'campus_id' => $this->talisay->id,
            'title' => 'General Assembly & Attendance Scan',
            'event_date' => $eventDate,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        $memberIdCode = sprintf('CPF-TALISAY-%s-%04d', now()->format('Y'), $member->id);

        // Before start window, QR scanner is locked
        $this->actingAs($saa)
            ->postJson("/events-activities/{$event->id}/scan", [
                'qr_payload' => $memberIdCode,
                'scan_type' => 'login',
            ])
            ->assertStatus(422);

        // Travel into the scheduled event time window -> event auto-activates!
        $this->travelTo(Carbon::parse($eventDate.' 09:30:00'));

        try {
            // Schedule changes are blocked once active / inside 1-day cutoff
            $this->actingAs($adviser)
                ->patchJson("/events-activities/{$event->id}/schedule", [
                    'event_date' => Carbon::parse($eventDate)->addDays(3)->toDateString(),
                ])
                ->assertStatus(422);

            // Regular member cannot operate the QR scanner
            $this->actingAs($member)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => $memberIdCode,
                    'scan_type' => 'login',
                ])
                ->assertForbidden();

            // Officer cannot Log-Out before Log-In
            $this->actingAs($saa)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => $memberIdCode,
                    'scan_type' => 'logout',
                ])
                ->assertStatus(422);

            // Tampered QR HMAC signature is rejected
            $this->actingAs($saa)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => "CPF|campus:TALISAY|user:{$member->id}|role:member|sig:0000000000000000",
                    'scan_type' => 'login',
                ])
                ->assertStatus(422);

            // Valid HMAC-signed QR payload logs member IN at start of activity
            $raw = "CPF|campus:TALISAY|user:{$member->id}|role:member";
            $sig = substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, 16);
            $signedPayload = "{$raw}|sig:{$sig}";

            $this->actingAs($saa)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => $signedPayload,
                    'scan_type' => 'login',
                ])
                ->assertCreated()
                ->assertJsonPath('status', 'logged_in');

            // Member ID code logs member OUT at end of activity
            $idForYear = sprintf('CPF-TALISAY-%s-%04d', now()->format('Y'), $member->id);
            $this->actingAs($saa)
                ->postJson("/events-activities/{$event->id}/scan", [
                    'qr_payload' => $idForYear,
                    'scan_type' => 'logout',
                ])
                ->assertOk()
                ->assertJsonPath('status', 'logged_out');

            $attendance = CampusEventAttendance::where('campus_event_id', $event->id)
                ->where('user_id', $member->id)
                ->firstOrFail();

            $this->assertNotNull($attendance->logged_in_at);
            $this->assertSame($saa->id, $attendance->logged_in_by_user_id);
            $this->assertNotNull($attendance->logged_out_at);
            $this->assertSame($saa->id, $attendance->logged_out_by_user_id);

            $event->refresh();
            $this->assertNotNull($event->activated_at);
            $this->assertSame('active', $event->effectiveStatus());

            // Checklist view on /events-activities displays attendance record
            $this->actingAs($saa)
                ->get('/events-activities')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('events/Index')
                    ->where('events.0.effective_status', 'active')
                    ->where('events.0.logged_in_count', 1)
                    ->where('events.0.logged_out_count', 1));
        } finally {
            $this->travelBack();
        }
    }

    public function test_post_event_reflection_seals_encrypted_time_capsule_and_notifications_mark_read(): void
    {
        $adviser = $this->createMember($this->talisay, CpfRole::Adviser, 'Montemayor, Elena V.');
        $member = $this->createMember($this->talisay, CpfRole::Member, 'Reyes, Katrina M.');

        $eventDate = now()->addDays(2)->toDateString();
        $event = CampusEvent::create([
            'campus_id' => $this->talisay->id,
            'title' => 'Empathy Listening Circle',
            'event_date' => $eventDate,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'approved',
            'created_by_user_id' => $adviser->id,
            'approved_by_user_id' => $adviser->id,
            'approved_at' => now(),
        ]);

        // Travel past end time -> event auto-completes and sends completion notification
        $this->travelTo(Carbon::parse($eventDate.' 12:00:00'));

        try {
            $plaintext = 'Today I learned how to listen with patience and care during our circle.';

            $res = $this->actingAs($member)
                ->postJson("/events-activities/{$event->id}/reflection", [
                    'title' => 'Empathy Circle Takeaways',
                    'reflection_message' => $plaintext,
                    'unlock_at' => now()->addMonths(6)->format('Y-m-d H:i:s'),
                ])
                ->assertCreated()
                ->assertJsonPath('status', 'sealed');

            $capsuleId = $res->json('time_capsule_id');

            $this->assertDatabaseHas('campus_event_reflections', [
                'campus_event_id' => $event->id,
                'user_id' => $member->id,
                'time_capsule_id' => $capsuleId,
            ]);

            // Verify raw time_capsules column stores AES-256 encrypted payload, not plaintext
            $rawCapsule = DB::table('time_capsules')->where('id', $capsuleId)->first();
            $this->assertNotNull($rawCapsule);
            $this->assertNotEquals($plaintext, $rawCapsule->encrypted_message);

            $modelCapsule = TimeCapsule::findOrFail($capsuleId);
            $this->assertSame($plaintext, $modelCapsule->encrypted_message);

            // Duplicate reflection submission for same event rejected
            $this->actingAs($member)
                ->postJson("/events-activities/{$event->id}/reflection", [
                    'title' => 'Second Attempt',
                    'reflection_message' => 'Trying to submit twice for the same activity.',
                    'unlock_at' => now()->addMonths(6)->format('Y-m-d H:i:s'),
                ])
                ->assertStatus(422);

            // Verify Dashboard exposes campus events calendar & unread notifications
            $this->actingAs($member)
                ->get('/dashboard')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Dashboard')
                    ->has('campusEvents', 1)
                    ->where('campusEvents.0.title', 'Empathy Listening Circle')
                    ->where('campusEvents.0.effective_status', 'completed')
                    ->has('eventNotifications', 1)
                    ->where('unreadEventNotificationsCount', 1));

            $notif = CampusEventNotification::where('user_id', $member->id)->firstOrFail();
            $this->actingAs($member)
                ->patchJson("/events-activities/notifications/{$notif->id}/read")
                ->assertOk();

            $this->assertNotNull($notif->fresh()->read_at);

            CampusEventNotification::create([
                'campus_id' => $this->talisay->id,
                'campus_event_id' => $event->id,
                'user_id' => $member->id,
                'type' => 'event_approved',
                'title' => 'Follow-up Notice',
                'message' => 'Another unread notification',
            ]);

            $this->actingAs($member)
                ->postJson('/events-activities/notifications/read-all')
                ->assertOk();

            $this->assertSame(
                0,
                CampusEventNotification::where('user_id', $member->id)->whereNull('read_at')->count(),
            );
        } finally {
            $this->travelBack();
        }
    }
}
