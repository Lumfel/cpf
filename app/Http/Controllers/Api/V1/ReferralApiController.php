<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Support\SubmitGuidanceReferral;
use App\Enums\MembershipStatus;
use App\Enums\ReferralPriority;
use App\Http\Controllers\Controller;
use App\Models\CampusCounselor;
use App\Models\GuidanceReferral;
use App\Models\RoomRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferralApiController extends Controller
{
    /**
     * GET /api/v1/referrals/mine
     * Privacy-safe endpoint: the referring peer sees ONLY safe feedback status
     * (pending / ongoing / completed), safe scheduling note, and suggested/confirmed slot.
     * Clinical notes and encrypted concern summaries are NEVER exposed here.
     */
    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $user->primaryMembership()->with('campus:id,code,name')->first();
        $campusId = $membership?->campus_id;

        $referrals = GuidanceReferral::query()
            ->with([
                'counselor:id,name,title,specialty',
                'roomRequest:id,request_date,start_time,end_time,status,counselor_id',
            ])
            ->where('submitted_by_user_id', $user->id)
            ->latest('id')
            ->get()
            ->map(function (GuidanceReferral $ref): array {
                $safeStatus = in_array($ref->feedback_status, ['pending', 'ongoing', 'completed'], true)
                    ? $ref->feedback_status
                    : 'pending';

                $prefDate = $ref->preferred_date?->format('Y-m-d');
                $prefStart = $ref->preferred_start_time ? substr((string) $ref->preferred_start_time, 0, 5) : null;
                $prefEnd = $ref->preferred_end_time ? substr((string) $ref->preferred_end_time, 0, 5) : null;

                $suggestedSlot = ($prefDate !== null && $prefStart !== null && $prefEnd !== null)
                    ? [
                        'date' => $prefDate,
                        'start_time' => $prefStart,
                        'end_time' => $prefEnd,
                    ]
                    : null;

                $roomReq = $ref->roomRequest;
                $isSlotConfirmed = ($roomReq !== null && $roomReq->status === 'approved')
                    || (in_array($safeStatus, ['ongoing', 'completed'], true) && $suggestedSlot !== null);

                $confirmedSlot = null;
                if ($isSlotConfirmed) {
                    $confirmedSlot = [
                        'date' => $roomReq?->request_date?->format('Y-m-d') ?? $prefDate,
                        'start_time' => $roomReq?->start_time ? substr((string) $roomReq->start_time, 0, 5) : $prefStart,
                        'end_time' => $roomReq?->end_time ? substr((string) $roomReq->end_time, 0, 5) : $prefEnd,
                        'room_status' => $roomReq?->status ?? 'confirmed',
                    ];
                }

                return [
                    'id' => $ref->id,
                    'referral_type' => $ref->referral_type,
                    'student_identifier' => $ref->student_identifier,
                    'student_course_section' => $ref->student_course_section,
                    'status' => $safeStatus,
                    'feedback_status' => $safeStatus,
                    'safe_scheduling_note' => $ref->safe_scheduling_note,
                    'counselor' => $ref->counselor ? [
                        'id' => $ref->counselor->id,
                        'name' => $ref->counselor->name,
                        'title' => $ref->counselor->title,
                        'specialty' => $ref->counselor->specialty,
                    ] : null,
                    'suggested_slot' => $suggestedSlot,
                    'confirmed_slot' => $confirmedSlot,
                    'preferred_date' => $prefDate,
                    'preferred_start_time' => $prefStart,
                    'preferred_end_time' => $prefEnd,
                    'created_at' => $ref->created_at?->toIso8601String(),
                ];
            })
            ->values();

        $counselors = collect();
        $bookedSlots = collect();

        if ($campusId !== null && $membership?->status === MembershipStatus::Approved) {
            $counselors = CampusCounselor::ensureDefaultCounselorsForCampus($campusId)
                ->map(fn (CampusCounselor $c): array => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'title' => $c->title,
                    'specialty' => $c->specialty,
                    'weekly_vacant_slots' => $c->weekly_vacant_slots ?? [],
                ])
                ->values();

            $bookedSlots = RoomRequest::query()
                ->where('campus_id', $campusId)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('request_date', '>=', now()->toDateString())
                ->orderBy('request_date')
                ->orderBy('start_time')
                ->get(['counselor_id', 'request_date', 'start_time', 'end_time'])
                ->map(fn (RoomRequest $req): array => [
                    'counselor_id' => $req->counselor_id,
                    'date' => $req->request_date?->format('Y-m-d'),
                    'start_time' => substr((string) $req->start_time, 0, 5),
                    'end_time' => substr((string) $req->end_time, 0, 5),
                ])
                ->values();
        }

        return response()->json([
            'campus' => $membership?->campus ? [
                'id' => $membership->campus->id,
                'code' => $membership->campus->code,
                'name' => $membership->campus->name,
            ] : null,
            'counselors' => $counselors,
            'booked_slots' => $bookedSlots,
            'referrals' => $referrals,
        ]);
    }

    /**
     * POST /api/v1/referrals
     * Submits a peer or self referral with an optional free-slot suggestion
     * validated against counselor weekly availability and existing bookings.
     */
    public function store(Request $request, SubmitGuidanceReferral $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_if(
            $user->isAlumni(),
            403,
            'Former member and officer accounts have read-only archive access.',
        );

        $membership = $user->primaryMembership;

        abort_unless(
            $user->verifiedRole() !== null
            && $membership !== null
            && $membership->status === MembershipStatus::Approved
            && $membership->campus_id !== null,
            403,
            'An approved campus membership is required to submit a guidance referral.',
        );

        if (! $request->filled('campus_id')) {
            $request->merge(['campus_id' => $membership->campus_id]);
        }

        if (! $request->filled('suggested_priority')) {
            $request->merge(['suggested_priority' => ReferralPriority::Routine->value]);
        }

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'referral_type' => ['required', Rule::in(['self_counseling', 'peer_referral'])],
            'student_identifier' => ['required', 'string', 'max:180'],
            'student_course_section' => ['nullable', 'string', 'max:80'],
            'suggested_priority' => ['required', Rule::enum(ReferralPriority::class)],
            'concern_summary' => ['required', 'string', 'max:4000'],
            'counselor_id' => ['nullable', 'integer', 'exists:campus_counselors,id'],
            'preferred_date' => ['nullable', 'required_with:preferred_start_time,preferred_end_time', 'date', 'after_or_equal:today'],
            'preferred_start_time' => ['nullable', 'required_with:preferred_date,preferred_end_time', 'date_format:H:i'],
            'preferred_end_time' => ['nullable', 'required_with:preferred_date,preferred_start_time', 'date_format:H:i', 'after:preferred_start_time'],
        ]);

        $campusId = (int) $validated['campus_id'];

        abort_unless(
            $campusId === $membership->campus_id,
            403,
            'Referrals must be submitted to your assigned campus chapter.',
        );

        $counselor = null;
        if (! empty($validated['counselor_id'])) {
            $counselor = CampusCounselor::query()
                ->where('id', (int) $validated['counselor_id'])
                ->where('campus_id', $campusId)
                ->where('is_active', true)
                ->first();

            abort_unless(
                $counselor !== null,
                422,
                'Selected counselor does not belong to this campus or is inactive.',
            );
        }

        $preferredDate = $validated['preferred_date'] ?? null;
        $preferredStart = $validated['preferred_start_time'] ?? null;
        $preferredEnd = $validated['preferred_end_time'] ?? null;

        $referral = $action->handle($user, $campusId, $validated, $request->ip());

        $suggestedSlot = ($preferredDate !== null && $preferredStart !== null && $preferredEnd !== null)
            ? [
                'date' => $referral->preferred_date?->format('Y-m-d') ?? $preferredDate,
                'start_time' => $preferredStart,
                'end_time' => $preferredEnd,
            ]
            : null;

        return response()->json([
            'status' => 'submitted',
            'referral_id' => $referral->id,
            'feedback_status' => $referral->feedback_status ?: 'pending',
            'suggested_slot' => $suggestedSlot,
        ], 201);
    }
}
