<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['campus_id', 'name', 'title', 'specialty', 'weekly_vacant_slots', 'is_active'])]
class CampusCounselor extends Model
{
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
     * Ensure 3 default Mon-Fri counselors exist for the given campus.
     *
     * @return Collection<int, CampusCounselor>
     */
    public static function ensureDefaultCounselorsForCampus(int $campusId): Collection
    {
        $existing = self::query()
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($existing->count() >= 3) {
            return $existing;
        }

        $defaults = [
            [
                'name' => 'Dr. Elena V. Montemayor, RGC',
                'title' => 'Lead Guidance Counselor & CPF Adviser',
                'specialty' => 'Crisis Triage & Personal Wellness',
                'weekly_vacant_slots' => [
                    ['start' => '08:30', 'end' => '10:00', 'label' => '8:30 AM – 10:00 AM', 'days' => [1, 2, 3, 4, 5]],
                    ['start' => '10:30', 'end' => '12:00', 'label' => '10:30 AM – 12:00 PM', 'days' => [1, 2, 3, 4, 5]],
                    ['start' => '13:30', 'end' => '15:00', 'label' => '1:30 PM – 3:00 PM', 'days' => [1, 2, 3, 4, 5]],
                ],
            ],
            [
                'name' => 'Ms. Clarissa D. Soriano, RGC',
                'title' => 'Academic & Career Counselor',
                'specialty' => 'Academic Stress & Career Guidance',
                'weekly_vacant_slots' => [
                    ['start' => '09:00', 'end' => '10:30', 'label' => '9:00 AM – 10:30 AM', 'days' => [1, 2, 3, 4, 5]],
                    ['start' => '11:00', 'end' => '12:00', 'label' => '11:00 AM – 12:00 PM', 'days' => [1, 2, 3, 4, 5]],
                    ['start' => '14:00', 'end' => '16:00', 'label' => '2:00 PM – 4:00 PM', 'days' => [1, 2, 3, 4, 5]],
                ],
            ],
            [
                'name' => 'Mr. Nathaniel P. Mendoza, RPm',
                'title' => 'Student Wellness & Peer Support Counselor',
                'specialty' => 'Peer Facilitation & Emotional Support',
                'weekly_vacant_slots' => [
                    ['start' => '08:00', 'end' => '09:30', 'label' => '8:00 AM – 9:30 AM', 'days' => [1, 2, 3, 4, 5]],
                    ['start' => '10:00', 'end' => '11:30', 'label' => '10:00 AM – 11:30 AM', 'days' => [1, 2, 3, 4, 5]],
                    ['start' => '15:00', 'end' => '16:30', 'label' => '3:00 PM – 4:30 PM', 'days' => [1, 2, 3, 4, 5]],
                ],
            ],
        ];

        foreach ($defaults as $data) {
            self::query()->firstOrCreate(
                [
                    'campus_id' => $campusId,
                    'name' => $data['name'],
                ],
                [
                    'title' => $data['title'],
                    'specialty' => $data['specialty'],
                    'weekly_vacant_slots' => $data['weekly_vacant_slots'],
                    'is_active' => true,
                ],
            );
        }

        return self::query()
            ->where('campus_id', $campusId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }
}
