<?php

namespace App\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusEventNotification;
use App\Models\CampusEventReflection;
use App\Models\CampusMembership;
use App\Models\TimeCapsule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EventActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $membership = $user->primaryMembership()->with('campus:id,code,name')->first();
        $campus = $membership?->campus
            ?? Campus::query()->where('is_active', true)->orderBy('id')->first();

        $campusId = $campus?->id;
        $campusCode = $campus?->code ?? 'ALIJIS';
        $campusName = $campus?->name ?? 'CHMSU Alijis Campus';

        $isClubAdviser = $user->isClubAdviser();
        $canProposeEvents = $user->canProposeEvents();
        $isOfficerOrAdviser = $user->verifiedRole()?->isOfficerOrAdviser() ?? false;
        $isVerifiedMember = $user->verifiedRole() !== null;

        $events = collect();
        $campusMembers = collect();

        if ($campusId !== null) {
            $approvedMembers = CampusMembership::query()
                ->where('campus_id', $campusId)
                ->where('status', MembershipStatus::Approved->value)
                ->alphabetical()
                ->get(['user_id', 'full_name_last_first_mi', 'course', 'year_level', 'section', 'course_year_section', 'role']);

            $year = now()->format('Y');
            $campusMembers = $approvedMembers->map(fn (CampusMembership $m): array => [
                'user_id' => $m->user_id,
                'full_name_last_first_mi' => $m->full_name_last_first_mi,
                'course_year_section' => $m->course_year_section,
                'role' => $m->role->label(),
                'member_id_code' => sprintf('CPF-%s-%s-%04d', $campusCode, $year, $m->user_id),
            ])->values();

            $rawEvents = CampusEvent::query()
                ->with([
                    'creator:id,name',
                    'approver:id,name',
                    'canceller:id,name',
                    'attendances.loggedInBy:id,name',
                    'attendances.loggedOutBy:id,name',
                    'reflections:id,campus_event_id,user_id,time_capsule_id,submitted_at',
                ])
                ->where('campus_id', $campusId)
                ->when(! $canProposeEvents, fn ($query) => $query->whereIn('status', ['approved', 'cancelled']))
                ->orderBy('event_date')
                ->orderBy('start_time')
                ->get();

            $events = $rawEvents->map(function (CampusEvent $event) use ($approvedMembers, $campusCode, $user, $year): array {
                $event->syncLifecycleTimestamps();

                $attendanceByUser = $event->attendances->keyBy('user_id');
                $reflectionUserIds = $event->reflections->pluck('user_id')->flip();
                $myReflection = $event->reflections->firstWhere('user_id', $user->id);

                $checklist = $approvedMembers->map(function (CampusMembership $m) use ($attendanceByUser, $reflectionUserIds, $campusCode, $year): array {
                    $att = $attendanceByUser->get($m->user_id);

                    return [
                        'user_id' => $m->user_id,
                        'full_name_last_first_mi' => $m->full_name_last_first_mi,
                        'course_year_section' => $m->course_year_section,
                        'role' => $m->role->label(),
                        'member_id_code' => sprintf('CPF-%s-%s-%04d', $campusCode, $year, $m->user_id),
                        'logged_in_at' => $att?->logged_in_at?->format('M d, Y h:i A'),
                        'logged_in_by' => $att?->loggedInBy?->name,
                        'logged_out_at' => $att?->logged_out_at?->format('M d, Y h:i A'),
                        'logged_out_by' => $att?->loggedOutBy?->name,
                        'has_reflection' => $reflectionUserIds->has($m->user_id),
                    ];
                })->values();

                $loggedInCount = $event->attendances->whereNotNull('logged_in_at')->count();
                $loggedOutCount = $event->attendances->whereNotNull('logged_out_at')->count();

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'description' => $event->description,
                    'location' => $event->location,
                    'event_date' => $event->event_date->format('Y-m-d'),
                    'event_date_label' => $event->event_date->format('M d, Y (D)'),
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'status' => $event->status,
                    'effective_status' => $event->effectiveStatus(),
                    'can_edit_schedule' => $event->canEditSchedule(),
                    'within_one_day_cutoff' => $event->isWithinOneDayCutoff(),
                    'creator_name' => $event->creator?->name ?? 'Officer',
                    'approver_name' => $event->approver?->name,
                    'approved_at' => $event->approved_at?->format('M d, Y h:i A'),
                    'canceller_name' => $event->canceller?->name,
                    'cancelled_at' => $event->cancelled_at?->format('M d, Y h:i A'),
                    'cancellation_reason' => $event->cancellation_reason,
                    'activated_at' => $event->activated_at?->format('M d, Y h:i A'),
                    'completed_at' => $event->completed_at?->format('M d, Y h:i A'),
                    'logged_in_count' => $loggedInCount,
                    'logged_out_count' => $loggedOutCount,
                    'reflection_count' => $event->reflections->count(),
                    'my_reflection_submitted' => $myReflection !== null,
                    'my_reflection_capsule_id' => $myReflection?->time_capsule_id,
                    'my_reflection_submitted_at' => $myReflection?->submitted_at?->format('M d, Y h:i A'),
                    'checklist' => $checklist,
                ];
            })->values();
        }

        $notifications = CampusEventNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CampusEventNotification $n): array => [
                'id' => $n->id,
                'campus_event_id' => $n->campus_event_id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'is_unread' => $n->read_at === null,
                'created_at' => $n->created_at?->diffForHumans(),
            ])
            ->values();

        $unreadNotificationCount = CampusEventNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return Inertia::render('events/Index', [
            'campusId' => $campusId,
            'campusName' => $campusName,
            'campusCode' => $campusCode,
            'canProposeEvents' => $canProposeEvents,
            'isClubAdviser' => $isClubAdviser,
            'isOfficerOrAdviser' => $isOfficerOrAdviser,
            'isVerifiedMember' => $isVerifiedMember,
            'myUserId' => $user->id,
            'todayDate' => now()->toDateString(),
            'minScheduleDate' => now()->addDay()->toDateString(),
            'events' => $events,
            'campusMembers' => $campusMembers,
            'notifications' => $notifications,
            'unreadNotificationCount' => $unreadNotificationCount,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canProposeEvents() && $membership !== null,
            403,
            'Only the Club Adviser, President, or Vice President can schedule campus events.',
        );

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:160'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
        ]);

        abort_if(
            Carbon::parse($validated['event_date'])->startOfDay()->lt(now()->addDay()->startOfDay()),
            422,
            'Events must be scheduled at least 1 day in advance.',
        );

        $isAdviser = $user->isClubAdviser();

        $event = CampusEvent::create([
            'campus_id' => $membership->campus_id,
            'title' => trim($validated['title']),
            'description' => isset($validated['description']) ? trim($validated['description']) : null,
            'location' => isset($validated['location']) ? trim($validated['location']) : null,
            'event_date' => $validated['event_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => $isAdviser ? 'approved' : 'pending_approval',
            'created_by_user_id' => $user->id,
            'approved_by_user_id' => $isAdviser ? $user->id : null,
            'approved_at' => $isAdviser ? now() : null,
        ]);

        $dateLabel = $event->event_date->format('M d, Y');

        if ($isAdviser) {
            CampusEvent::notifyCampusMembers(
                $event,
                'event_approved',
                "New Campus Event Scheduled: {$event->title}",
                "{$event->title} is scheduled on {$dateLabel} ({$event->start_time}–{$event->end_time}). It will automatically activate for attendance QR scanning on its scheduled window.",
            );
        } else {
            CampusEvent::notifyCampusMembers(
                $event,
                'event_proposed',
                "Event Proposal Awaiting Approval: {$event->title}",
                "{$user->name} proposed \"{$event->title}\" for {$dateLabel} ({$event->start_time}–{$event->end_time}). Club Adviser review is needed before activation.",
                advisersOnly: true,
            );
        }

        return response()->json([
            'status' => $event->status,
            'event' => $event,
        ], 201);
    }

    public function approve(Request $request, CampusEvent $event): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $event->campus_id,
            403,
            'Only the campus Club Adviser can approve events.',
        );

        abort_unless(
            $event->status === 'pending_approval',
            422,
            'Only pending event proposals can be approved.',
        );

        abort_if(
            $event->isWithinOneDayCutoff(),
            422,
            'Events can only be approved at least 1 day before the scheduled date. Please reschedule first.',
        );

        $event->update([
            'status' => 'approved',
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
        ]);

        CampusEvent::notifyCampusMembers(
            $event,
            'event_approved',
            "Event Approved: {$event->title}",
            "{$event->title} has been approved for {$event->event_date->format('M d, Y')} ({$event->start_time}–{$event->end_time}).",
        );

        return response()->json([
            'status' => 'approved',
            'event' => $event->fresh(),
        ]);
    }

    public function updateSchedule(Request $request, CampusEvent $event): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $event->campus_id,
            403,
            'Only the campus Club Adviser can change an event schedule.',
        );

        abort_if(
            in_array($event->effectiveStatus(), ['cancelled', 'active', 'completed'], true),
            422,
            'Cancelled, active, or completed events can no longer be rescheduled.',
        );

        abort_if(
            $event->status === 'approved' && $event->isWithinOneDayCutoff(),
            422,
            'Schedule changes are locked within 1 day of the event.',
        );

        $validated = $request->validate([
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'string', 'max:160'],
        ]);

        $startTime = $validated['start_time'] ?? $event->start_time;
        $endTime = $validated['end_time'] ?? $event->end_time;

        abort_if(
            $endTime <= $startTime,
            422,
            'End time must be after start time.',
        );

        abort_if(
            Carbon::parse($validated['event_date'])->startOfDay()->lt(now()->addDay()->startOfDay()),
            422,
            'New event date must be at least 1 day in advance.',
        );

        $event->update([
            'event_date' => $validated['event_date'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'title' => array_key_exists('title', $validated) && filled($validated['title']) ? trim((string) $validated['title']) : $event->title,
            'description' => array_key_exists('description', $validated) ? ($validated['description'] !== null ? trim((string) $validated['description']) : null) : $event->description,
            'location' => array_key_exists('location', $validated) ? ($validated['location'] !== null ? trim((string) $validated['location']) : null) : $event->location,
        ]);

        CampusEvent::notifyCampusMembers(
            $event,
            'event_rescheduled',
            "Event Rescheduled: {$event->title}",
            "{$event->title} was moved to {$event->event_date->format('M d, Y')} ({$event->start_time}–{$event->end_time}) by the Club Adviser.",
            advisersOnly: $event->status === 'pending_approval',
        );

        return response()->json([
            'status' => 'rescheduled',
            'event' => $event->fresh(),
        ]);
    }

    public function cancel(Request $request, CampusEvent $event): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isClubAdviser() && $membership?->campus_id === $event->campus_id,
            403,
            'Only the campus Club Adviser can cancel events.',
        );

        $effective = $event->effectiveStatus();
        abort_if(
            in_array($effective, ['cancelled', 'completed'], true),
            422,
            'Completed or already cancelled events cannot be cancelled.',
        );

        $validated = $request->validate([
            'cancellation_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = isset($validated['cancellation_reason']) && filled($validated['cancellation_reason'])
            ? trim((string) $validated['cancellation_reason'])
            : null;

        $event->update([
            'status' => 'cancelled',
            'cancelled_by_user_id' => $user->id,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        CampusEvent::notifyCampusMembers(
            $event,
            'event_cancelled',
            "Event Cancelled: {$event->title}",
            "{$event->title} scheduled on {$event->event_date->format('M d, Y')} has been cancelled.".($reason ? " Reason: {$reason}" : ''),
        );

        return response()->json([
            'status' => 'cancelled',
            'event' => $event->fresh(),
        ]);
    }

    public function scanQr(Request $request, CampusEvent $event): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;
        $role = $user?->verifiedRole();

        abort_unless(
            $role && $role->isOfficerOrAdviser() && $membership?->campus_id === $event->campus_id,
            403,
            'Only verified campus officers and the Club Adviser can use the attendance QR scanner.',
        );

        $event->syncLifecycleTimestamps();

        abort_unless(
            $event->effectiveStatus() === 'active',
            422,
            'QR attendance scanner is only available while this event is active.',
        );

        $validated = $request->validate([
            'qr_payload' => ['required', 'string', 'max:255'],
            'scan_type' => ['required', Rule::in(['login', 'logout'])],
        ]);

        $targetMembership = $this->resolveScannedMember($event, trim($validated['qr_payload']));
        $scanType = $validated['scan_type'];

        $attendance = CampusEventAttendance::query()->firstOrNew([
            'campus_event_id' => $event->id,
            'user_id' => $targetMembership->user_id,
        ]);

        if ($scanType === 'login') {
            if ($attendance->logged_in_at !== null) {
                return response()->json([
                    'status' => 'already_logged_in',
                    'member_name' => $targetMembership->full_name_last_first_mi,
                    'course_year_section' => $targetMembership->course_year_section,
                    'attendance' => $attendance,
                ], 200);
            }

            $attendance->logged_in_at = now();
            $attendance->logged_in_by_user_id = $user->id;
            $attendance->save();

            return response()->json([
                'status' => 'logged_in',
                'member_name' => $targetMembership->full_name_last_first_mi,
                'course_year_section' => $targetMembership->course_year_section,
                'attendance' => $attendance->fresh(),
            ], 201);
        }

        if (! $attendance->exists || $attendance->logged_in_at === null) {
            abort(422, 'Member must complete Log-In scan at the start of the activity before Log-Out.');
        }

        if ($attendance->logged_out_at !== null) {
            return response()->json([
                'status' => 'already_logged_out',
                'member_name' => $targetMembership->full_name_last_first_mi,
                'course_year_section' => $targetMembership->course_year_section,
                'attendance' => $attendance,
            ], 200);
        }

        $attendance->logged_out_at = now();
        $attendance->logged_out_by_user_id = $user->id;
        $attendance->save();

        return response()->json([
            'status' => 'logged_out',
            'member_name' => $targetMembership->full_name_last_first_mi,
            'course_year_section' => $targetMembership->course_year_section,
            'attendance' => $attendance->fresh(),
        ], 200);
    }

    public function storeReflection(Request $request, CampusEvent $event): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->verifiedRole() !== null && $membership?->campus_id === $event->campus_id,
            403,
            'Only verified members of this campus can submit an event reflection.',
        );

        $event->syncLifecycleTimestamps();

        abort_unless(
            $event->effectiveStatus() === 'completed',
            422,
            'Reflections open after the event has ended.',
        );

        $alreadySubmitted = CampusEventReflection::query()
            ->where('campus_event_id', $event->id)
            ->where('user_id', $user->id)
            ->exists();

        abort_if(
            $alreadySubmitted,
            422,
            'You have already added your reflection for this event to your Time Capsule.',
        );

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'reflection_message' => ['required', 'string', 'min:10', 'max:4000'],
            'unlock_at' => ['required', 'date', 'after:now'],
        ]);

        $capsuleTitle = filled($validated['title'] ?? null)
            ? trim((string) $validated['title'])
            : Str::limit("Reflection: {$event->title}", 160, '');

        $reflection = DB::transaction(function () use ($event, $user, $capsuleTitle, $validated): CampusEventReflection {
            $capsule = TimeCapsule::create([
                'campus_id' => $event->campus_id,
                'user_id' => $user->id,
                'title' => $capsuleTitle,
                'encrypted_message' => trim($validated['reflection_message']),
                'unlock_at' => $validated['unlock_at'],
                'is_opened' => false,
            ]);

            return CampusEventReflection::create([
                'campus_event_id' => $event->id,
                'user_id' => $user->id,
                'time_capsule_id' => $capsule->id,
                'submitted_at' => now(),
            ]);
        });

        return response()->json([
            'status' => 'sealed',
            'reflection_id' => $reflection->id,
            'time_capsule_id' => $reflection->time_capsule_id,
            'unlock_at' => $validated['unlock_at'],
        ], 201);
    }

    public function markNotificationRead(Request $request, CampusEventNotification $notification): JsonResponse
    {
        abort_unless(
            $notification->user_id === $request->user()?->id,
            403,
            'You can only mark your own notifications as read.',
        );

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'status' => 'read',
            'notification_id' => $notification->id,
        ]);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        CampusEventNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'all_read']);
    }

    private function resolveScannedMember(CampusEvent $event, string $payload): CampusMembership
    {
        $event->loadMissing('campus:id,code');
        $campusCode = strtoupper((string) ($event->campus?->code ?? ''));
        $targetUserId = null;

        if (preg_match('/^CPF\|campus:([A-Z0-9_]+)\|user:(\d+)\|role:([a-z_]+)\|sig:([a-f0-9]{16})$/', $payload, $matches)) {
            $scannedCampus = strtoupper($matches[1]);
            $uid = (int) $matches[2];
            $role = $matches[3];
            $sig = $matches[4];

            $raw = "CPF|campus:{$matches[1]}|user:{$uid}|role:{$role}";
            $expected = substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, 16);

            abort_unless(
                hash_equals($expected, $sig),
                422,
                'Invalid or tampered CPF Attendance QR signature.',
            );

            abort_unless(
                $scannedCampus === $campusCode,
                422,
                'This Attendance QR code belongs to a different CHMSU campus.',
            );

            $targetUserId = $uid;
        } elseif (preg_match('/^CPF-([A-Z0-9_]+)-\d{4}-(\d+)$/i', $payload, $matches)) {
            $scannedCampus = strtoupper($matches[1]);

            abort_unless(
                $scannedCampus === $campusCode,
                422,
                'This Member ID code belongs to a different CHMSU campus.',
            );

            $targetUserId = (int) $matches[2];
        } else {
            abort(422, 'Unrecognized or invalid CPF Attendance QR / Member ID code.');
        }

        $targetMembership = CampusMembership::query()
            ->where('campus_id', $event->campus_id)
            ->where('user_id', $targetUserId)
            ->where('status', MembershipStatus::Approved->value)
            ->first();

        abort_unless(
            $targetMembership !== null,
            422,
            'No approved member matching that QR / Member ID exists on this campus.',
        );

        return $targetMembership;
    }
}
