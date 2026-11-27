<?php

namespace App\Actions\Support;

use App\Enums\ReferralPriority;
use App\Models\CampusCounselor;
use App\Models\CampusRoom;
use App\Models\GuidanceReferral;
use App\Models\ReferralAuditLog;
use App\Models\RoomRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitGuidanceReferral
{
    public function handle(User $submitter, int $campusId, array $validated, ?string $ipAddress = null): GuidanceReferral
    {
        $priority = ReferralPriority::from((string) ($validated['suggested_priority'] ?? 'routine'));
        $counselorId = ! empty($validated['counselor_id']) ? (int) $validated['counselor_id'] : null;
        $preferredDate = $validated['preferred_date'] ?? null;
        $preferredStart = $validated['preferred_start_time'] ?? null;
        $preferredEnd = $validated['preferred_end_time'] ?? null;

        $counselor = null;
        if ($counselorId !== null) {
            $counselor = CampusCounselor::query()
                ->where('id', $counselorId)
                ->where('campus_id', $campusId)
                ->where('is_active', true)
                ->first();

            if ($counselor === null) {
                throw ValidationException::withMessages([
                    'counselor_id' => ['Selected counselor does not belong to this campus or is inactive.'],
                ]);
            }
        }

        if ($preferredDate !== null && $preferredStart !== null && $preferredEnd !== null) {
            CampusCounselor::assertBookingSlotRules(
                (string) $preferredDate,
                (string) $preferredStart,
                (string) $preferredEnd,
                $counselor,
                'preferred_start_time',
            );
        }

        return DB::transaction(function () use (
            $submitter,
            $campusId,
            $validated,
            $priority,
            $ipAddress,
            $counselorId,
            $preferredDate,
            $preferredStart,
            $preferredEnd,
        ): GuidanceReferral {
            $roomRequestId = null;

            if ($preferredDate !== null && $preferredStart !== null && $preferredEnd !== null) {
                $room = CampusRoom::query()->firstOrCreate(
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

                // Serialize concurrent Testing Room & referral slot checks on the same campus room
                CampusRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

                $hasPendingToday = RoomRequest::query()
                    ->where('campus_id', $campusId)
                    ->where('requested_by_user_id', $submitter->id)
                    ->whereDate('request_date', $preferredDate)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->exists();

                if ($hasPendingToday) {
                    throw ValidationException::withMessages([
                        'preferred_start_time' => 'You already have an active pending Testing Room booking for this date.',
                    ]);
                }

                if (! $submitter->canCoordinateGuidance()) {
                    $activeFuturePendingCount = RoomRequest::query()
                        ->where('campus_id', $campusId)
                        ->where('requested_by_user_id', $submitter->id)
                        ->where('status', 'pending')
                        ->whereDate('request_date', '>=', now()->toDateString())
                        ->lockForUpdate()
                        ->count();

                    if ($activeFuturePendingCount >= 2) {
                        throw ValidationException::withMessages([
                            'preferred_start_time' => 'You may hold at most 2 active pending Testing Room reservations across all future dates.',
                        ]);
                    }
                }

                $roomOverlap = RoomRequest::query()
                    ->where('campus_id', $campusId)
                    ->whereDate('request_date', $preferredDate)
                    ->whereIn('status', ['pending', 'approved'])
                    ->where('start_time', '<', $preferredEnd)
                    ->where('end_time', '>', $preferredStart)
                    ->lockForUpdate()
                    ->exists();

                $referralOverlap = GuidanceReferral::query()
                    ->where('campus_id', $campusId)
                    ->whereDate('preferred_date', $preferredDate)
                    ->whereIn('feedback_status', ['pending', 'ongoing'])
                    ->where('preferred_start_time', '<', $preferredEnd)
                    ->where('preferred_end_time', '>', $preferredStart)
                    ->where(function ($query): void {
                        $query->whereNull('room_request_id')
                            ->orWhereHas('roomRequest', fn ($rq) => $rq->where('status', '!=', 'rejected'));
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($roomOverlap || $referralOverlap) {
                    throw ValidationException::withMessages([
                        'preferred_start_time' => 'The counselor calendar or Testing Room slot is already booked during that time.',
                    ]);
                }

                $roomRequest = RoomRequest::create([
                    'campus_id' => $campusId,
                    'room_id' => $room->id,
                    'counselor_id' => $counselorId,
                    'requested_by_user_id' => $submitter->id,
                    'purpose' => 'Guidance Referral Consultation',
                    'request_date' => $preferredDate,
                    'start_time' => $preferredStart,
                    'end_time' => $preferredEnd,
                    'status' => 'pending',
                ]);

                $roomRequestId = $roomRequest->id;
            }

            $referral = GuidanceReferral::create([
                'campus_id' => $campusId,
                'submitted_by_user_id' => $submitter->id,
                'referral_type' => $validated['referral_type'] ?? 'peer_referral',
                'student_identifier' => trim((string) $validated['student_identifier']),
                'student_course_section' => isset($validated['student_course_section']) && filled($validated['student_course_section'])
                    ? trim((string) $validated['student_course_section'])
                    : null,
                'suggested_priority' => $priority,
                'triage_response_target' => $priority->responseTarget(),
                'encrypted_concern_summary' => trim((string) $validated['concern_summary']),
                'status' => 'submitted',
                'feedback_status' => 'pending',
                'counselor_id' => $counselorId,
                'room_request_id' => $roomRequestId,
                'preferred_date' => $preferredDate,
                'preferred_start_time' => $preferredStart,
                'preferred_end_time' => $preferredEnd,
            ]);

            ReferralAuditLog::create([
                'referral_id' => $referral->id,
                'actor_user_id' => $submitter->id,
                'action' => 'submitted',
                'ip_address' => $ipAddress,
            ]);

            return $referral;
        });
    }
}
