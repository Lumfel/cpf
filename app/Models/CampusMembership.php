<?php

namespace App\Models;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'campus_id',
    'role',
    'status',
    'enrollment_source',
    'recorded_email',
    'first_name',
    'middle_name',
    'last_name',
    'full_name_last_first_mi',
    'course',
    'year_level',
    'section',
    'course_year_section',
    'sex',
    'gender_identity',
    'gender_identity_other',
    'birthdate',
    'active_email',
    'active_fb_account',
    'facebook_profile_url',
    'cellphone_number',
    'added_or_approved_by',
    'approved_at',
    'is_in_messenger_gc',
    'gc_confirmed_by',
    'gc_confirmed_at',
    'has_paid_dues',
    'dues_confirmed_by',
    'dues_confirmed_at',
    'remarks',
])]
class CampusMembership extends Model
{
    protected function casts(): array
    {
        return [
            'role' => CpfRole::class,
            'status' => MembershipStatus::class,
            'birthdate' => 'date:Y-m-d',
            'approved_at' => 'datetime',
            'is_in_messenger_gc' => 'boolean',
            'gc_confirmed_at' => 'datetime',
            'has_paid_dues' => 'boolean',
            'dues_confirmed_at' => 'datetime',
        ];
    }

    public static function formatLastFirstMi(string $first, ?string $middle, string $last): string
    {
        $mi = trim((string) $middle);
        $miFormatted = $mi !== '' ? ' '.strtoupper(mb_substr($mi, 0, 1)).'.' : '';

        return trim($last).', '.trim($first).$miFormatted;
    }

    public static function formatCourseYearSection(string $course, string $yearLevel, string $section): string
    {
        $parts = array_values(array_filter([
            trim($course),
            trim($yearLevel),
            trim($section),
        ], fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return '';
        }

        if (count($parts) === 3) {
            return $parts[0].' '.$parts[1].$parts[2];
        }

        return implode(' ', $parts);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_or_approved_by');
    }

    public function gcConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gc_confirmed_by');
    }

    public function duesConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dues_confirmed_by');
    }

    public function scopeAlphabetical(Builder $query): Builder
    {
        return $query->orderBy('last_name', 'asc')->orderBy('first_name', 'asc');
    }
}
