<?php

namespace App\Http\Controllers;

use App\Enums\ReferralPriority;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusRoom;
use App\Models\CounselorGcMessage;
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
        $campuses = Campus::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $campusId = $membership?->campus_id ?? $campuses->first()?->id ?? 1;
        $isOfficerOrAdviser = $user->verifiedRole()?->isOfficerOrAdviser() ?? false;
        $isClubAdviser = $user->isClubAdviser();

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

        $roomRequests = RoomRequest::query()
            ->with(['requester:id,name', 'counselor:id,name'])
            ->where('campus_id', $campusId)
            ->when(! $isOfficerOrAdviser, function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->where('requested_by_user_id', $user->id)
                        ->orWhere('status', 'approved');
                });
            })
            ->orderByDesc('request_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (RoomRequest $req) => [
                'id' => $req->id,
                'purpose' => ($isOfficerOrAdviser || $req->requested_by_user_id === $user->id)
                    ? $req->purpose
                    : 'Booked',
                'requester_name' => ($isOfficerOrAdviser || $req->requested_by_user_id === $user->id)
                    ? ($req->requester?->name ?? 'CPF Member')
                    : 'Confidential Booking',
                'counselor_name' => $req->counselor?->name,
                'request_date' => $req->request_date?->format('Y-m-d'),
                'start_time' => substr((string) $req->start_time, 0, 5),
                'end_time' => substr((string) $req->end_time, 0, 5),
                'status' => $req->status,
                'review_notes' => $req->review_notes,
                'is_mine' => $req->requested_by_user_id === $user->id,
            ])
            ->values();

        $myReferrals = GuidanceReferral::query()
            ->where('submitted_by_user_id', $user->id)
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
                'status' => $ref->status,
                'created_at' => $ref->created_at?->toDateTimeString(),
            ])
            ->values();

        $counselorInbox = collect();
        $counselorGcMessages = collect();

        if ($isClubAdviser && $membership !== null) {
            $counselorInbox = GuidanceReferral::query()
                ->with('submitter:id,name')
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
                    'status' => $ref->status,
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
            'testingRoom' => $testingRoom,
            'counselors' => $counselors,
            'calendarBookings' => $calendarBookings,
            'roomRequests' => $roomRequests,
            'myReferrals' => $myReferrals,
            'counselorInbox' => $counselorInbox,
            'counselorGcMessages' => $counselorGcMessages,
            'priorityTiers' => $priorityTiers,
            'officerChannel' => $officerChannel,
            'officerMessages' => $officerMessages,
            'gcArchives' => $gcArchives,
        ]);
    }
}
