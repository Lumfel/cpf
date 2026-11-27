<?php

namespace App\Actions\Support;

use App\Models\CampusCounselor;
use App\Models\CampusRoom;
use App\Models\RoomRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestTestingRoom
{
    public function handle(User $requester, int $campusId, array $validated): RoomRequest
    {
        $counselorId = ! empty($validated['counselor_id']) ? (int) $validated['counselor_id'] : null;
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

        CampusCounselor::assertBookingSlotRules(
            (string) $validated['request_date'],
            (string) $validated['start_time'],
            (string) $validated['end_time'],
            $counselor,
            'start_time',
        );

        return DB::transaction(function () use ($requester, $campusId, $validated, $counselorId): RoomRequest {
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
                ->where('requested_by_user_id', $requester->id)
                ->whereDate('request_date', $validated['request_date'])
                ->where('status', 'pending')
                ->lockForUpdate()
                ->exists();

            if ($hasPendingToday) {
                throw ValidationException::withMessages([
                    'start_time' => 'You already have an active pending Testing Room booking for this date.',
                ]);
            }

            if (! $requester->canCoordinateGuidance()) {
                $activeFuturePendingCount = RoomRequest::query()
                    ->where('campus_id', $campusId)
                    ->where('requested_by_user_id', $requester->id)
                    ->where('status', 'pending')
                    ->whereDate('request_date', '>=', now()->toDateString())
                    ->lockForUpdate()
                    ->count();

                if ($activeFuturePendingCount >= 2) {
                    throw ValidationException::withMessages([
                        'start_time' => 'You may hold at most 2 active pending Testing Room reservations across all future dates.',
                    ]);
                }
            }

            $conflict = RoomRequest::query()
                ->where('room_id', $room->id)
                ->whereDate('request_date', $validated['request_date'])
                ->whereIn('status', ['pending', 'approved'])
                ->where('start_time', '<', $validated['end_time'])
                ->where('end_time', '>', $validated['start_time'])
                ->lockForUpdate()
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'start_time' => 'The Testing Room is already reserved during that time slot.',
                ]);
            }

            return RoomRequest::create([
                'campus_id' => $campusId,
                'room_id' => $room->id,
                'counselor_id' => $counselorId,
                'requested_by_user_id' => $requester->id,
                'purpose' => trim((string) $validated['purpose']),
                'request_date' => $validated['request_date'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'status' => 'pending',
            ]);
        });
    }
}
