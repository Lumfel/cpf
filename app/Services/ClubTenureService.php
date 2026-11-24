<?php

namespace App\Services;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusMembership;
use App\Models\ClubServiceTenure;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ClubTenureService
{
    public const REASON_LABELS = [
        'adviser_handover' => 'Completed Adviser Term (Handed Over)',
        'graduated' => 'Graduated',
        'term_completed' => 'Completed Officer Term',
        'stepped_down' => 'Stepped Down',
        'transferred' => 'Transferred Campus / Program',
        'role_change' => 'Role Transition',
    ];

    public function openTenure(
        CampusMembership $membership,
        ?User $actor = null,
        ?CarbonInterface $startedAt = null,
    ): ClubServiceTenure {
        return DB::transaction(function () use ($membership, $actor, $startedAt): ClubServiceTenure {
            $membership->loadMissing('campus:id,current_academic_year');
            $role = $membership->role instanceof CpfRole
                ? $membership->role
                : (CpfRole::tryFrom((string) $membership->role) ?? CpfRole::Member);

            $academicYear = $membership->campus?->current_academic_year
                ?: sprintf('%d-%d', now()->year, now()->year + 1);

            $openTenures = ClubServiceTenure::query()
                ->where('campus_membership_id', $membership->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->get();

            $matchingOpen = $openTenures->first(fn (ClubServiceTenure $t) => $t->role === $role);
            $now = $startedAt ?? now();

            foreach ($openTenures as $open) {
                if ($matchingOpen && $open->id === $matchingOpen->id) {
                    if (! $open->verifyHash()) {
                        $open->forceFill([
                            'verification_hash' => $open->computeVerificationHash(),
                        ])->save();
                    }

                    continue;
                }

                $this->closeTenureRecord($open, $now, 'role_change', $actor);
            }

            if ($matchingOpen) {
                return $matchingOpen->refresh();
            }

            $start = $startedAt ?? $membership->approved_at ?? $membership->created_at ?? now();

            $tenure = ClubServiceTenure::create([
                'campus_id' => $membership->campus_id,
                'campus_membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'role' => $role,
                'academic_year' => $academicYear,
                'started_at' => $start,
                'ended_at' => null,
                'end_reason' => null,
                'recorded_by_user_id' => $actor?->id,
            ]);

            $tenure->forceFill([
                'verification_hash' => $tenure->computeVerificationHash(),
            ])->save();

            return $tenure->refresh();
        });
    }

    public function recordRoleTransition(
        CampusMembership $membership,
        CpfRole $newRole,
        ?User $actor = null,
        string $reason = 'role_change',
    ): ClubServiceTenure {
        return DB::transaction(function () use ($membership, $newRole, $actor, $reason): ClubServiceTenure {
            $membership->loadMissing('campus:id,current_academic_year');
            $now = now();
            $currentRole = $membership->role instanceof CpfRole
                ? $membership->role
                : (CpfRole::tryFrom((string) $membership->role) ?? CpfRole::Member);

            $academicYear = $membership->campus?->current_academic_year
                ?: sprintf('%d-%d', $now->year, $now->year + 1);

            $existingCount = ClubServiceTenure::query()
                ->where('campus_membership_id', $membership->id)
                ->lockForUpdate()
                ->count();

            if ($existingCount === 0) {
                $initialStart = $membership->approved_at ?? $membership->created_at ?? $now;
                $backfill = ClubServiceTenure::create([
                    'campus_id' => $membership->campus_id,
                    'campus_membership_id' => $membership->id,
                    'user_id' => $membership->user_id,
                    'role' => $currentRole,
                    'academic_year' => $academicYear,
                    'started_at' => $initialStart,
                    'ended_at' => $now,
                    'end_reason' => $reason,
                    'recorded_by_user_id' => $actor?->id ?? $membership->added_or_approved_by,
                ]);
                $backfill->forceFill([
                    'verification_hash' => $backfill->computeVerificationHash(),
                ])->save();
            } else {
                $openTenures = ClubServiceTenure::query()
                    ->where('campus_membership_id', $membership->id)
                    ->whereNull('ended_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($openTenures as $open) {
                    $this->closeTenureRecord($open, $now, $reason, $actor);
                }
            }

            $next = ClubServiceTenure::create([
                'campus_id' => $membership->campus_id,
                'campus_membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'role' => $newRole,
                'academic_year' => $academicYear,
                'started_at' => $now,
                'ended_at' => null,
                'end_reason' => null,
                'recorded_by_user_id' => $actor?->id,
            ]);

            $next->forceFill([
                'verification_hash' => $next->computeVerificationHash(),
            ])->save();

            return $next->refresh();
        });
    }

    public function transitionToAlumni(
        CampusMembership $membership,
        ?User $actor = null,
        string $reason = 'graduated',
        ?CarbonInterface $endedAt = null,
    ): CampusMembership {
        return DB::transaction(function () use ($membership, $actor, $reason, $endedAt): CampusMembership {
            $lockedMembership = CampusMembership::query()
                ->with('campus:id,current_academic_year')
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $end = $endedAt ?? now();
            $role = $lockedMembership->role instanceof CpfRole
                ? $lockedMembership->role
                : (CpfRole::tryFrom((string) $lockedMembership->role) ?? CpfRole::Member);

            $academicYear = $lockedMembership->campus?->current_academic_year
                ?: sprintf('%d-%d', $end->year, $end->year + 1);

            $existingTenures = ClubServiceTenure::query()
                ->where('campus_membership_id', $lockedMembership->id)
                ->lockForUpdate()
                ->get();

            if ($existingTenures->isEmpty()) {
                $start = $lockedMembership->approved_at ?? $lockedMembership->created_at ?? $end;
                if ($end->lt($start)) {
                    $start = $end->copy();
                }

                $backfill = ClubServiceTenure::create([
                    'campus_id' => $lockedMembership->campus_id,
                    'campus_membership_id' => $lockedMembership->id,
                    'user_id' => $lockedMembership->user_id,
                    'role' => $role,
                    'academic_year' => $academicYear,
                    'started_at' => $start,
                    'ended_at' => $end,
                    'end_reason' => $reason,
                    'recorded_by_user_id' => $actor?->id ?? $lockedMembership->added_or_approved_by,
                ]);
                $backfill->forceFill([
                    'verification_hash' => $backfill->computeVerificationHash(),
                ])->save();
            } else {
                $openTenures = $existingTenures->whereNull('ended_at');

                if ($openTenures->isEmpty()) {
                    $latest = $existingTenures->sortByDesc('started_at')->first();
                    if ($latest && $latest->ended_at === null) {
                        $this->closeTenureRecord($latest, $end, $reason, $actor);
                    }
                } else {
                    foreach ($openTenures as $open) {
                        $this->closeTenureRecord($open, $end, $reason, $actor);
                    }
                }
            }

            $lockedMembership->update([
                'status' => MembershipStatus::Alumni,
                'alumni_since' => $end,
                'alumni_reason' => $reason,
                'alumni_transitioned_by' => $actor?->id,
            ]);

            return $lockedMembership->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTenureSummary(
        CampusMembership $membership,
        int $eventsAttendedCount = 0,
        int $awardsCount = 0,
        int $achievementsCount = 0,
        int $certificatesCount = 0,
    ): array {
        if ($membership->relationLoaded('tenures')) {
            $tenures = $membership->tenures->sortBy('started_at')->values();
        } else {
            try {
                $tenures = $membership->tenures()->orderBy('started_at')->orderBy('id')->get();
            } catch (\Throwable) {
                $tenures = collect();
            }
        }

        $asOf = $membership->status === MembershipStatus::Alumni
            ? ($membership->alumni_since ?? now())
            : now();

        if ($tenures->isEmpty()) {
            $start = $membership->approved_at ?? $membership->created_at ?? $asOf;
            $end = $membership->status === MembershipStatus::Alumni ? $asOf : null;
            $roleEnum = $membership->role instanceof CpfRole
                ? $membership->role
                : (CpfRole::tryFrom((string) $membership->role) ?? CpfRole::Member);

            $academicYear = $membership->campus?->current_academic_year
                ?: sprintf('%d-%d', $start->year, $start->year + 1);

            $effectiveEnd = $end ?? $asOf;
            $totalDays = max(1, (int) $start->copy()->startOfDay()->diffInDays($effectiveEnd->copy()->startOfDay()));

            return [
                'first_joined_at' => $start->format('M d, Y'),
                'first_joined_iso' => $start->toIso8601String(),
                'served_until' => $end ? $end->format('M d, Y') : 'Present',
                'served_until_iso' => $end?->toIso8601String(),
                'total_days' => $totalDays,
                'total_duration_label' => $this->formatDateSpan($start, $effectiveEnd),
                'academic_years' => [$academicYear],
                'roles' => [[
                    'id' => null,
                    'role' => $roleEnum->value,
                    'role_label' => $roleEnum->label(),
                    'academic_year' => $academicYear,
                    'started_at' => $start->format('M d, Y'),
                    'ended_at' => $end ? $end->format('M d, Y') : 'Present',
                    'duration_label' => $this->formatDateSpan($start, $effectiveEnd),
                    'end_reason' => $membership->alumni_reason,
                    'end_reason_label' => self::reasonLabel($membership->alumni_reason),
                    'verified' => false,
                    'verification_code' => null,
                ]],
                'is_fully_verified' => false,
                'events_attended_count' => $eventsAttendedCount,
                'awards_count' => $awardsCount,
                'achievements_count' => $achievementsCount,
                'certificates_count' => $certificatesCount,
            ];
        }

        $firstStart = $tenures->first()->started_at ?? $membership->approved_at ?? $membership->created_at ?? $asOf;
        $hasActiveSegment = $tenures->contains(fn (ClubServiceTenure $t) => $t->ended_at === null);
        $lastEnded = $hasActiveSegment
            ? null
            : ($tenures->filter(fn (ClubServiceTenure $t) => $t->ended_at !== null)->max('ended_at') ?? $membership->alumni_since);

        $effectiveOverallEnd = $lastEnded ?? $asOf;
        $totalDays = 0;

        $roleSegments = $tenures->map(function (ClubServiceTenure $t) use ($asOf, &$totalDays): array {
            $roleEnum = $t->role instanceof CpfRole
                ? $t->role
                : (CpfRole::tryFrom((string) $t->role) ?? CpfRole::Member);

            $segmentStart = $t->started_at ?? $asOf;
            $segmentEnd = $t->ended_at ?? $asOf;
            $segmentDays = max(0, (int) $segmentStart->copy()->startOfDay()->diffInDays($segmentEnd->copy()->startOfDay()));
            $totalDays += $segmentDays;

            return [
                'id' => $t->id,
                'role' => $roleEnum->value,
                'role_label' => $roleEnum->label(),
                'academic_year' => $t->academic_year,
                'started_at' => $segmentStart->format('M d, Y'),
                'ended_at' => $t->ended_at ? $t->ended_at->format('M d, Y') : 'Present',
                'duration_label' => $t->durationLabel($asOf),
                'end_reason' => $t->end_reason,
                'end_reason_label' => self::reasonLabel($t->end_reason),
                'verified' => $t->verifyHash(),
                'verification_code' => $t->verification_hash ? strtoupper(substr($t->verification_hash, 0, 12)) : null,
            ];
        })->values()->all();

        $totalDays = max(1, $totalDays);
        $isFullyVerified = collect($roleSegments)->every(fn (array $seg) => $seg['verified'] === true);

        return [
            'first_joined_at' => $firstStart->format('M d, Y'),
            'first_joined_iso' => $firstStart->toIso8601String(),
            'served_until' => $lastEnded ? $lastEnded->format('M d, Y') : 'Present',
            'served_until_iso' => $lastEnded?->toIso8601String(),
            'total_days' => $totalDays,
            'total_duration_label' => $this->formatDateSpan($firstStart, $effectiveOverallEnd),
            'academic_years' => $tenures->pluck('academic_year')->filter()->unique()->values()->all(),
            'roles' => $roleSegments,
            'is_fully_verified' => $isFullyVerified,
            'events_attended_count' => $eventsAttendedCount,
            'awards_count' => $awardsCount,
            'achievements_count' => $achievementsCount,
            'certificates_count' => $certificatesCount,
        ];
    }

    public static function reasonLabel(?string $reason): ?string
    {
        if (! $reason) {
            return null;
        }

        return self::REASON_LABELS[$reason] ?? ucwords(str_replace('_', ' ', $reason));
    }

    private function closeTenureRecord(
        ClubServiceTenure $tenure,
        CarbonInterface $endedAt,
        string $reason,
        ?User $actor,
    ): void {
        $effectiveEnd = $tenure->started_at && $endedAt->lt($tenure->started_at)
            ? $tenure->started_at->copy()
            : $endedAt;

        $tenure->ended_at = $effectiveEnd;
        $tenure->end_reason = $reason;
        $tenure->recorded_by_user_id = $actor?->id ?? $tenure->recorded_by_user_id;
        $tenure->verification_hash = $tenure->computeVerificationHash();
        $tenure->save();
    }

    private function formatDateSpan(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($end->lt($start)) {
            return '< 1 day';
        }

        $diff = $start->diff($end);
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

        return $parts === [] ? '< 1 day' : implode(', ', $parts);
    }
}
