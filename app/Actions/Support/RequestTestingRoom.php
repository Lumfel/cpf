<?php

namespace App\Actions\Support;

use App\Models\CampusRoom;
use App\Models\RoomRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestTestingRoom
{
    public function handle(User $requester, int $campusId, array $validated): RoomRequest
    {
        return DB::transaction(function () use ($requester, $campusId, $validated): RoomRequest {
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
                'counselor_id' => $validated['counselor_id'] ?? null,
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
