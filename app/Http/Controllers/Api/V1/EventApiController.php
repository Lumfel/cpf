<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CpfRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\EventActivityController;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
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
        $isOfficerOrAdviser = $user->verifiedRole()?->isOfficerOrAdviser() ?? false;
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

    public function scan(
        Request $request,
        CampusEvent $event,
        EventActivityController $eventActivityController,
    ): JsonResponse {
        return $eventActivityController->scanQr($request, $event);
    }
}
