<?php

namespace App\Models;

use App\Enums\CpfRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'campus_membership_id',
    'user_id',
    'role',
    'academic_year',
    'started_at',
    'ended_at',
    'end_reason',
    'recorded_by_user_id',
    'verification_hash',
])]
class ClubServiceTenure extends Model
{
    protected function casts(): array
    {
        return [
            'role' => CpfRole::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CampusMembership::class, 'campus_membership_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function computeVerificationHash(): string
    {
        $roleValue = $this->role instanceof CpfRole ? $this->role->value : (string) $this->role;
        $start = $this->started_at ? $this->started_at->copy()->utc()->toIso8601String() : '';
        $end = $this->ended_at ? $this->ended_at->copy()->utc()->toIso8601String() : 'ACTIVE';

        return hash_hmac(
            'sha256',
            "TENURE|{$this->campus_id}|{$this->user_id}|{$roleValue}|{$this->academic_year}|{$start}|{$end}",
            (string) config('app.key'),
        );
    }

    public function verifyHash(): bool
    {
        return is_string($this->verification_hash)
            && $this->verification_hash !== ''
            && hash_equals($this->computeVerificationHash(), $this->verification_hash);
    }

    public function durationLabel(?CarbonInterface $asOf = null): string
    {
        if (! $this->started_at) {
            return '< 1 day';
        }

        $end = $this->ended_at ?? $asOf ?? now();
        if ($end->lt($this->started_at)) {
            return '< 1 day';
        }

        $diff = $this->started_at->diff($end);
        $parts = [];

        if ($diff->y > 0) {
            $parts[] = $diff->y.' '.($diff->y === 1 ? 'yr' : 'yrs');
        }
        if ($diff->m > 0) {
            $parts[] = $diff->m.' '.($diff->m === 1 ? 'mo' : 'mos');
        }
        if ($diff->y === 0 && $diff->d > 0) {
            $parts[] = $diff->d.' '.($diff->d === 1 ? 'day' : 'days');
        }

        if ($parts === []) {
            return '< 1 day';
        }

        return implode(', ', $parts);
    }
}
