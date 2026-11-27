<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\CounselorGcMessage;
use App\Models\CounselorInvitation;
use App\Models\GuidanceReferral;
use App\Models\OfficerGcArchive;
use App\Models\OfficerGcChannel;
use App\Models\RoomRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampusOperationsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $membership = $user->primaryMembership()->with('campus:id,code,name')->first();

        abort_unless(
            $user !== null
            && $user->verifiedRole() !== null
            && $membership !== null
            && $membership->status === MembershipStatus::Approved
            && $membership->campus_id !== null,
            403,
            'An approved campus membership is required to access the Campus Hub.',
        );

        if ($request->filled('campus_id')) {
            abort_unless(
                (int) $request->input('campus_id') === $membership->campus_id,
                403,
                'You may only access operations for your assigned campus chapter.',
            );
        }

        $campuses = Campus::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $campusId = $membership->campus_id;
        $isOfficerOrAdviser = $user->isActiveOfficerOrAdviser();
        $isClubAdviser = $user->isClubAdviser();
        $canCoordinateGuidance = $user->canCoordinateGuidance();
        $canReviewTestingRoom = $isOfficerOrAdviser || $canCoordinateGuidance;

        $testingRoom = CampusRoom::query()->firstOrCreate(
            [
                'campus_id' => $campusId,
                'is_single_allowed_room' => true,
            ],
            [
                'room_code' => 'TESTING_ROOM',
                'room_name' => 'Testing Room',
                'capacity' => 6,
            ],
        );

        $counselors = CampusCounselor::ensureDefaultCounselorsForCampus($campusId)
            ->map(fn (CampusCounselor $counselor) => [
                'id' => $counselor->id,
                'name' => $counselor->name,
                'title' => $counselor->title,
                'specialty' => $counselor->specialty,
                'weekly_vacant_slots' => $counselor->weekly_vacant_slots ?? [],
            ])
            ->values();

        $calendarBookings = RoomRequest::query()
            ->where('campus_id', $campusId)
            ->whereIn('status', ['pending', 'approved'])
            ->orderBy('request_date')
            ->orderBy('start_time')
            ->get(['counselor_id', 'request_date', 'start_time', 'end_time'])
            ->map(fn (RoomRequest $req) => [
                'date' => $req->request_date?->format('Y-m-d'),
                'start_time' => substr((string) $req->start_time, 0, 5),
                'end_time' => substr((string) $req->end_time, 0, 5),
                'counselor_id' => $req->counselor_id,
                'status' => 'booked',
            ])
            ->values();

        $referralLinkedRoomRequestIds = GuidanceReferral::query()
            ->where('campus_id', $campusId)
            ->whereNotNull('room_request_id')
            ->pluck('room_request_id')
            ->flip();

        $roomRequests = RoomRequest::query()
            ->with(['requester:id,name', 'counselor:id,name'])
            ->where('campus_id', $campusId)
            ->when(! $canReviewTestingRoom, function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('requested_by_user_id', $user->id)
                        ->orWhere('status', 'approved');
                });
            })
            ->orderByDesc('request_date')
            ->orderBy('start_time')
            ->get()
            ->map(function (RoomRequest $req) use ($user, $canReviewTestingRoom, $canCoordinateGuidance, $referralLinkedRoomRequestIds): array {
                $isMine = $req->requested_by_user_id === $user->id;
                $isCounselingBooking = $req->counselor_id !== null || $referralLinkedRoomRequestIds->has($req->id);
                $canSeeDetails = $isCounselingBooking
                    ? ($canCoordinateGuidance || $isMine)
                    : ($canReviewTestingRoom || $isMine);

                return [
                    'id' => $req->id,
                    'purpose' => $canSeeDetails ? $req->purpose : 'Booked',
                    'requester_name' => $canSeeDetails
                        ? ($req->requester?->name ?? 'CPF Member')
                        : 'Confidential Booking',
                    'counselor_name' => $req->counselor?->name,
                    'request_date' => $req->request_date?->format('Y-m-d'),
                    'start_time' => substr((string) $req->start_time, 0, 5),
                    'end_time' => substr((string) $req->end_time, 0, 5),
                    'status' => $req->status,
                    'review_notes' => $canSeeDetails ? $req->review_notes : null,
                    'is_mine' => $isMine,
                ];
            })
            ->values();

        // Privacy-safe view for the submitting peer: ONLY safe feedback status + scheduled slot + non-clinical scheduling note.
        $myReferrals = GuidanceReferral::query()
            ->with('counselor:id,name')
            ->where('submitted_by_user_id', $user->id)
            ->latest()
            ->get()
            ->map(fn (GuidanceReferral $ref) => [
                'id' => $ref->id,
                'referral_type' => $ref->referral_type,
                'student_identifier' => $ref->student_identifier,
                'student_course_section' => $ref->student_course_section,
                'feedback_status' => $ref->feedback_status ?: 'pending',
                'safe_scheduling_note' => $ref->safe_scheduling_note,
                'counselor_id' => $ref->counselor_id,
                'counselor_name' => $ref->counselor?->name,
                'preferred_date' => $ref->preferred_date?->format('Y-m-d'),
                'preferred_start_time' => $ref->preferred_start_time ? substr((string) $ref->preferred_start_time, 0, 5) : null,
                'preferred_end_time' => $ref->preferred_end_time ? substr((string) $ref->preferred_end_time, 0, 5) : null,
                'created_at' => $ref->created_at?->toDateTimeString(),
            ])
            ->values();

        $counselorInbox = collect();
        $counselorGcMessages = collect();

        if ($canCoordinateGuidance && $membership !== null) {
            $counselorInbox = GuidanceReferral::query()
                ->with(['submitter:id,name', 'counselor:id,name'])
                ->where('campus_id', $campusId)
                ->latest()
                ->get()
                ->map(fn (GuidanceReferral $ref) => [
                    'id' => $ref->id,
                    'referral_type' => $ref->referral_type,
                    'student_identifier' => $ref->student_identifier,
                    'student_course_section' => $ref->student_course_section,
                    'suggested_priority' => $ref->suggested_priority->value,
                    'counselor_confirmed_priority' => $ref->counselor_confirmed_priority?->value,
                    'triage_response_target' => $ref->triage_response_target,
                    'concern_summary' => $ref->encrypted_concern_summary,
                    'confidential_clinical_notes' => $ref->confidential_clinical_notes,
                    'status' => $ref->status,
                    'feedback_status' => $ref->feedback_status ?: 'pending',
                    'safe_scheduling_note' => $ref->safe_scheduling_note,
                    'counselor_name' => $ref->counselor?->name,
                    'preferred_date' => $ref->preferred_date?->format('Y-m-d'),
                    'preferred_start_time' => $ref->preferred_start_time ? substr((string) $ref->preferred_start_time, 0, 5) : null,
                    'preferred_end_time' => $ref->preferred_end_time ? substr((string) $ref->preferred_end_time, 0, 5) : null,
                    'submitter_name' => $ref->submitter?->name ?? 'Peer Member',
                    'created_at' => $ref->created_at?->toDateTimeString(),
                ])
                ->values();

            $counselorGcMessages = CounselorGcMessage::query()
                ->with('sender:id,name')
                ->where('campus_id', $campusId)
                ->orderBy('created_at')
                ->limit(100)
                ->get()
                ->map(fn (CounselorGcMessage $msg) => [
                    'id' => $msg->id,
                    'sender_user_id' => $msg->sender_user_id,
                    'sender_name' => $msg->sender?->name ?? 'Guidance Counselor',
                    'sender' => $msg->sender ? [
                        'id' => $msg->sender->id,
                        'name' => $msg->sender->name,
                    ] : null,
                    'sender_role' => $msg->sender_role,
                    'message_body' => $msg->message_body,
                    'created_at' => (string) $msg->created_at,
                ])
                ->values();
        }

        $counselorInvitations = collect();
        if ($isClubAdviser && $membership !== null) {
            $counselorInvitations = CounselorInvitation::query()
                ->where('campus_id', $campusId)
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (CounselorInvitation $inv) => [
                    'id' => $inv->id,
                    'email' => $inv->email,
                    'counselor_name' => $inv->counselor_name,
                    'status' => $inv->accepted_at !== null
                        ? 'accepted'
                        : ($inv->expires_at->isFuture() ? 'pending' : 'expired'),
                    'expires_at' => $inv->expires_at->toDayDateTimeString(),
                    'accepted_at' => $inv->accepted_at?->toDayDateTimeString(),
                ])
                ->values();
        }

        $counselorPeers = collect();
        if ($canCoordinateGuidance && $campusId !== null) {
            $counselorPeers = CampusMembership::query()
                ->with('user:id,name')
                ->where('campus_id', $campusId)
                ->where('status', MembershipStatus::Approved->value)
                ->whereIn('role', [CpfRole::Adviser->value, CpfRole::GuidanceCounselor->value])
                ->where('user_id', '!=', $user->id)
                ->alphabetical()
                ->get()
                ->map(fn (CampusMembership $cm) => [
                    'user_id' => $cm->user_id,
                    'name' => $cm->user?->name ?? $cm->full_name_last_first_mi,
                    'role' => $cm->role->value,
                    'role_label' => $cm->role->label(),
                ])
                ->values();
        }

        $officerChannel = null;
        $officerMessages = collect();
        $gcArchives = collect();

        if ($isOfficerOrAdviser && $membership !== null) {
            $officerChannel = OfficerGcChannel::query()
                ->where('campus_id', $campusId)
                ->orderByDesc('is_active')
                ->orderByDesc('id')
                ->first();

            if ($officerChannel) {
                $officerMessages = $officerChannel->messages()
                    ->with('sender:id,name')
                    ->orderBy('created_at')
                    ->limit(100)
                    ->get()
                    ->map(fn ($msg) => [
                        'id' => $msg->id,
                        'sender_user_id' => $msg->sender_user_id,
                        'sender_name' => $msg->sender?->name ?? 'Officer',
                        'sender' => $msg->sender ? [
                            'id' => $msg->sender->id,
                            'name' => $msg->sender->name,
                        ] : null,
                        'sender_role' => $msg->sender_role,
                        'message_body' => $msg->message_body,
                        'created_at' => (string) $msg->created_at,
                    ])
                    ->values();
            }

            $gcArchives = OfficerGcArchive::query()
                ->where('campus_id', $campusId)
                ->orderByDesc('archived_at')
                ->get();
        }

        $priorityTiers = collect(ReferralPriority::cases())->map(fn (ReferralPriority $tier) => [
            'value' => $tier->value,
            'label' => match ($tier) {
                ReferralPriority::Emergency => '1 — Emergency',
                ReferralPriority::VeryUrgent => '2 — Very Urgent',
                ReferralPriority::Urgent => '3 — Urgent',
                ReferralPriority::SemiUrgent => '4 — Semi-Urgent',
                ReferralPriority::Routine => '5 — Routine',
            },
            'responseTarget' => $tier->responseTarget(),
        ])->values();

        return Inertia::render('campus/Operations', [
            'campusId' => $campusId,
            'campusName' => $membership?->campus?->name ?? $campuses->first()?->name ?? 'CHMSU Campus',
            'campuses' => $campuses,
            'isOfficerOrAdviser' => $isOfficerOrAdviser,
            'isClubAdviser' => $isClubAdviser,
            'canCoordinateGuidance' => $canCoordinateGuidance,
            'canReviewTestingRoom' => $canReviewTestingRoom,
            'counselorInvitations' => $counselorInvitations,
            'testingRoom' => $testingRoom,
            'counselors' => $counselors,
            'calendarBookings' => $calendarBookings,
            'roomRequests' => $roomRequests,
            'myReferrals' => $myReferrals,
            'counselorInbox' => $counselorInbox,
            'counselorGcMessages' => $counselorGcMessages,
            'counselorPeers' => $counselorPeers,
            'officersGcPaused' => (bool) config('cpf.features.officers_gc_paused', false),
            'priorityTiers' => $priorityTiers,
            'officerChannel' => $officerChannel,
            'officerMessages' => $officerMessages,
            'gcArchives' => $gcArchives,
        ]);
    }
}
