<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

#[Fillable(['campus_id', 'name', 'title', 'specialty', 'weekly_vacant_slots', 'is_active'])]
class CampusCounselor extends Model
{
    public const OPERATING_START = '08:00';

    public const OPERATING_END = '17:00';

    public const MAX_BOOKING_MINUTES = 120;

    protected function casts(): array
    {
        return [
            'weekly_vacant_slots' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    /**
     * Canonical Mon–Fri vacant slots used for real Club Advisers initialized
     * via First-Boot Setup or Adviser Handover.
     *
     * @return array<int, array{start: string, end: string, label: string, days: array<int, int>}>
     */
    public static function defaultAdviserWeeklyVacantSlots(): array
    {
        return [
            ['start' => '08:30', 'end' => '10:00', 'label' => '8:30 AM – 10:00 AM', 'days' => [1, 2, 3, 4, 5]],
            ['start' => '10:30', 'end' => '12:00', 'label' => '10:30 AM – 12:00 PM', 'days' => [1, 2, 3, 4, 5]],
            ['start' => '13:30', 'end' => '15:00', 'label' => '1:30 PM – 3:00 PM', 'days' => [1, 2, 3, 4, 5]],
        ];
    }

    /**
     * Canonical default vacant slots for invited Guidance Counselors.
     *
     * @return array<int, array{start: string, end: string, label: string, days: array<int, int>}>
     */
    public static function defaultInvitedCounselorSlots(): array
    {
        return [
            ['start' => '09:00', 'end' => '11:30', 'label' => '9:00 AM – 11:30 AM', 'days' => [1, 5]],
            ['start' => '13:30', 'end' => '16:00', 'label' => '1:30 PM – 4:00 PM', 'days' => [3]],
        ];
    }

    /**
     * Return active real campus counselors without inserting fictional demo rows.
     *
     * @return Collection<int, CampusCounselor>
     */
    public static function ensureDefaultCounselorsForCampus(int $campusId): Collection
    {
        return self::query()
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * Register a real Club Adviser or Guidance Counselor in campus_counselors.
     *
     * @param  array<int, array{start: string, end: string, label: string, days: array<int, int>}>|null  $slots
     */
    public static function registerRealCounselor(
        int $campusId,
        string $name,
        string $title = 'Registered Guidance Counselor',
        string $specialty = 'Student Wellness & Peer Referral Triage',
        ?array $slots = null,
    ): self {
        return self::query()->firstOrCreate(
            [
                'campus_id' => $campusId,
                'name' => trim($name),
            ],
            [
                'title' => $title,
                'specialty' => $specialty,
                'weekly_vacant_slots' => $slots ?? self::defaultAdviserWeeklyVacantSlots(),
                'is_active' => true,
            ],
        );
    }

    /**
     * Enforce campus Testing Room operating hours (08:00–17:00, Mon–Fri),
     * maximum 120-minute booking duration, and counselor weekly_vacant_slots.
     */
    public static function assertBookingSlotRules(
        string $date,
        string $startTime,
        string $endTime,
        ?self $counselor = null,
        string $errorField = 'start_time',
    ): void {
        $start = substr(trim($startTime), 0, 5);
        $end = substr(trim($endTime), 0, 5);
        $dayIso = Carbon::parse($date)->dayOfWeekIso; // 1 (Mon) .. 7 (Sun)

        if ($dayIso < 1 || $dayIso > 5) {
            throw ValidationException::withMessages([
                $errorField => ['Testing Room bookings are only available Monday through Friday (08:00–17:00).'],
            ]);
        }

        if ($start < self::OPERATING_START || $end > self::OPERATING_END || $end <= $start) {
            throw ValidationException::withMessages([
                $errorField => ['Testing Room bookings must fall within campus operating hours (08:00–17:00).'],
            ]);
        }

        [$startH, $startM] = array_map('intval', explode(':', $start));
        [$endH, $endM] = array_map('intval', explode(':', $end));
        $durationMinutes = (($endH * 60) + $endM) - (($startH * 60) + $startM);

        if ($durationMinutes <= 0 || $durationMinutes > self::MAX_BOOKING_MINUTES) {
            throw ValidationException::withMessages([
                $errorField => ['Testing Room bookings cannot exceed 120 minutes.'],
            ]);
        }

        if ($counselor !== null) {
            self::assertSlotMatchesAvailability($counselor, $date, $start, $end, $errorField);
        }
    }

    public static function assertSlotMatchesAvailability(
        self $counselor,
        string $date,
        string $startTime,
        string $endTime,
        string $errorField = 'preferred_start_time',
    ): void {
        $vacantSlots = $counselor->weekly_vacant_slots ?? [];
        if (! is_array($vacantSlots) || $vacantSlots === []) {
            return;
        }

        $dayIso = Carbon::parse($date)->dayOfWeekIso;
        $start = substr(trim($startTime), 0, 5);
        $end = substr(trim($endTime), 0, 5);

        $dayNameMap = [
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
            'sunday' => 7,
        ];

        foreach ($vacantSlots as $slot) {
            if (! is_array($slot)) {
                continue;
            }

            if (isset($slot['days']) && is_array($slot['days'])) {
                $days = array_map('intval', $slot['days']);
            } elseif (isset($slot['day']) && is_string($slot['day'])) {
                $mapped = $dayNameMap[strtolower(trim($slot['day']))] ?? null;
                $days = $mapped !== null ? [$mapped] : [];
            } else {
                $days = [1, 2, 3, 4, 5];
            }

            $slotStart = isset($slot['start']) ? substr((string) $slot['start'], 0, 5) : null;
            $slotEnd = isset($slot['end']) ? substr((string) $slot['end'], 0, 5) : null;

            if ($slotStart === null || $slotEnd === null) {
                continue;
            }

            if (in_array($dayIso, $days, true) && $start >= $slotStart && $end <= $slotEnd) {
                return;
            }
        }

        throw ValidationException::withMessages([
            $errorField => [
                'The requested slot is outside the selected counselor\'s available consultation schedule.',
            ],
        ]);
    }
}
