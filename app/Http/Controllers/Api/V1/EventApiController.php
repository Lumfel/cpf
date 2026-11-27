<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventActivityController;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if(
            $user?->verifiedRole() === CpfRole::GuidanceCounselor,
            403,
            'Guidance Counselors have coordination-only access and are blocked from Club Events.',
        );
        $membership = $user->primaryMembership()->with('campus:id,code,name')->first();
        $isOfficerOrAdviser = $user->isActiveOfficerOrAdviser();
        $canProposeEvents = $user->canProposeEvents();

        if (! $membership || ! $membership->campus) {
            return response()->json([
                'campus' => null,
                'can_scan_events' => false,
                'can_scan' => false,
                'events' => [],
            ]);
        }

        $campus = $membership->campus;

        $events = CampusEvent::query()
            ->with('attendances:id,campus_event_id,user_id,logged_in_at,logged_out_at')
            ->where('campus_id', $campus->id)
            ->when(! $canProposeEvents, fn ($query) => $query->whereIn('status', ['approved', 'cancelled']))
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get()
            ->map(function (CampusEvent $event) use ($user, $isOfficerOrAdviser): array {
                $event->syncLifecycleTimestamps();
                $effectiveStatus = $event->effectiveStatus();

                /** @var CampusEventAttendance|null $myAttendance */
                $myAttendance = $event->attendances->firstWhere('user_id', $user->id);

                $attendanceStatus = match (true) {
                    $myAttendance?->logged_out_at !== null => 'completed',
                    $myAttendance?->logged_in_at !== null => 'logged_in',
                    default => 'not_checked_in',
                };

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
                    'effective_status' => $effectiveStatus,
                    'reflection_enabled' => (bool) $event->reflection_enabled,
                    'cancellation_reason' => $event->cancellation_reason,
                    'logged_in_count' => $event->attendances->whereNotNull('logged_in_at')->count(),
                    'logged_out_count' => $event->attendances->whereNotNull('logged_out_at')->count(),
                    'can_scan_events' => $isOfficerOrAdviser && $effectiveStatus === 'active',
                    'can_scan' => $isOfficerOrAdviser && $effectiveStatus === 'active',
                    'my_attendance' => [
                        'status' => $attendanceStatus,
                        'logged_in_at' => $myAttendance?->logged_in_at?->toIso8601String(),
                        'logged_out_at' => $myAttendance?->logged_out_at?->toIso8601String(),
                    ],
                ];
            })
            ->values();

        return response()->json([
            'campus' => [
                'id' => $campus->id,
                'code' => $campus->code,
                'name' => $campus->name,
            ],
            'can_scan_events' => $isOfficerOrAdviser,
            'can_scan' => $isOfficerOrAdviser,
            'events' => $events,
        ]);
    }

    public function checklist(Request $request, CampusEvent $event): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isActiveOfficerOrAdviser() && $membership?->campus_id === $event->campus_id,
            403,
            'Only active verified campus officers and the Club Adviser can view the event attendance checklist.',
        );

        $event->syncLifecycleTimestamps();
        $effectiveStatus = $event->effectiveStatus();

        $attendances = $event->attendances()
            ->with(['loggedInBy:id,name', 'loggedOutBy:id,name'])
            ->get()
            ->keyBy('user_id');

        $approvedMembers = CampusMembership::query()
            ->where('campus_id', $event->campus_id)
            ->where('status', MembershipStatus::Approved->value)
            ->where('role', '!=', CpfRole::GuidanceCounselor->value)
            ->alphabetical()
            ->get(['user_id', 'full_name_last_first_mi', 'course_year_section', 'role']);

        $checklist = $approvedMembers->map(function (CampusMembership $member) use ($attendances): array {
            /** @var CampusEventAttendance|null $att */
            $att = $attendances->get($member->user_id);

            $status = match (true) {
                $att?->logged_out_at !== null => 'completed',
                $att?->logged_in_at !== null => 'logged_in',
                default => 'not_checked_in',
            };

            return [
                'user_id' => $member->user_id,
                'full_name_last_first_mi' => $member->full_name_last_first_mi,
                'course_year_section' => $member->course_year_section,
                'role' => $member->role->label(),
                'status' => $status,
                'logged_in_at' => $att?->logged_in_at?->toIso8601String(),
                'logged_in_by' => $att?->loggedInBy?->name,
                'logged_out_at' => $att?->logged_out_at?->toIso8601String(),
                'logged_out_by' => $att?->loggedOutBy?->name,
            ];
        })->values();

        return response()->json([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'status' => $event->status,
                'effective_status' => $effectiveStatus,
                'can_record_attendance' => $effectiveStatus === 'active',
            ],
            'summary' => [
                'total_members' => $checklist->count(),
                'logged_in_count' => $attendances->whereNotNull('logged_in_at')->count(),
                'logged_out_count' => $attendances->whereNotNull('logged_out_at')->count(),
            ],
            'checklist' => $checklist,
        ]);
    }

    public function scan(
        Request $request,
        CampusEvent $event,
        EventActivityController $eventActivityController,
    ): JsonResponse {
        return $eventActivityController->scanQr($request, $event);
    }

    public function manualAttendance(
        Request $request,
        CampusEvent $event,
        EventActivityController $eventActivityController,
    ): JsonResponse {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->isActiveOfficerOrAdviser() && $membership?->campus_id === $event->campus_id,
            403,
            'Only active verified campus officers and the Club Adviser can record manual attendance.',
        );

        return $eventActivityController->manualAttendance($request, $event);
    }
}
