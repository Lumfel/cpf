<?php

namespace App\Models;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    'alumni_since',
    'alumni_reason',
    'alumni_transitioned_by',
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
            'alumni_since' => 'datetime',
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

    public function alumniTransitionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alumni_transitioned_by');
    }

    /**
     * @return HasMany<ClubServiceTenure, $this>
     */
    public function tenures(): HasMany
    {
        return $this->hasMany(ClubServiceTenure::class, 'campus_membership_id')
            ->orderBy('started_at')
            ->orderBy('id');
    }

    public function highestRoleHeld(): CpfRole
    {
        $highest = $this->role instanceof CpfRole
            ? $this->role
            : (CpfRole::tryFrom((string) $this->role) ?? CpfRole::Member);

        if ($this->relationLoaded('tenures')) {
            $tenures = $this->tenures;
        } else {
            try {
                $tenures = $this->tenures()->get(['role']);
            } catch (\Throwable) {
                $tenures = collect();
            }
        }

        foreach ($tenures as $tenure) {
            $tRole = $tenure->role instanceof CpfRole
                ? $tenure->role
                : (CpfRole::tryFrom((string) $tenure->role) ?? CpfRole::Member);

            if ($tRole->rank() > $highest->rank()) {
                $highest = $tRole;
            }
        }

        return $highest;
    }

    public function formerRoleLabel(): string
    {
        return $this->highestRoleHeld()->formerTitle();
    }

    public function scopeAlphabetical(Builder $query): Builder
    {
        return $query->orderBy('last_name', 'asc')->orderBy('first_name', 'asc');
    }
}
