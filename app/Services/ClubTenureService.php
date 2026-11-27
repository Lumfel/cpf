<?php

namespace App\Services;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusMembership;
use App\Models\ClubServiceTenure;
use App\Models\Election;
use App\Models\RoomRequest;
use App\Models\User;
use App\Support\CpfElectionPositions;
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
        'elected_year_rep' => 'Elected Year-Level Representative',
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
        ?string $newAcademicYear = null,
        ?string $electedSeat = null,
    ): ClubServiceTenure {
        return DB::transaction(function () use ($membership, $newRole, $actor, $reason, $newAcademicYear, $electedSeat): ClubServiceTenure {
            $membership->loadMissing('campus:id,current_academic_year');
            $now = now();
            $currentRole = $membership->role instanceof CpfRole
                ? $membership->role
                : (CpfRole::tryFrom((string) $membership->role) ?? CpfRole::Member);

            $academicYear = $membership->campus?->current_academic_year
                ?: sprintf('%d-%d', $now->year, $now->year + 1);
            $nextAcademicYear = filled($newAcademicYear) ? trim((string) $newAcademicYear) : $academicYear;

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
                'elected_seat' => $electedSeat,
                'academic_year' => $nextAcademicYear,
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

    /**
     * Atomically apply elected student officer roles and transition outgoing
     * non-Adviser officers on election completion, preserving verified tenure history.
     *
     * @return array{promoted_user_ids: array<int, int>, demoted_user_ids: array<int, int>, retained_user_ids: array<int, int>}
     */
    public function applyElectionTermTurnover(Election $election, ?User $actor = null): array
    {
        return DB::transaction(function () use ($election, $actor): array {
            $results = $election->position_results ?? [];
            $electedRolesByUserId = [];
            $electedYearRepsByUserId = [];
            $yearRepKeys = ['first_year_rep', 'second_year_rep', 'third_year_rep', 'fourth_year_rep'];

            foreach ($results as $positionKey => $entry) {
                if (! is_array($entry) || ($entry['status'] ?? null) !== 'elected') {
                    continue;
                }

                $winnerUserId = (int) ($entry['winner_user_id'] ?? 0);
                if ($winnerUserId <= 0) {
                    continue;
                }

                if (in_array((string) $positionKey, $yearRepKeys, true)) {
                    $electedYearRepsByUserId[$winnerUserId] = (string) $positionKey;

                    continue;
                }

                $targetRole = CpfRole::tryFrom((string) $positionKey);
                if (
                    $targetRole === null
                    || in_array($targetRole, [CpfRole::Adviser, CpfRole::GuidanceCounselor, CpfRole::Member], true)
                ) {
                    continue;
                }

                $electedRolesByUserId[$winnerUserId] = $targetRole;
            }

            $studentOfficerRoleValues = array_map(
                static fn (CpfRole $role): string => $role->value,
                array_values(array_filter(
                    CpfRole::cases(),
                    static fn (CpfRole $role): bool => ! in_array($role, [CpfRole::Adviser, CpfRole::GuidanceCounselor, CpfRole::Member], true),
                )),
            );

            $outgoingOfficers = CampusMembership::query()
                ->with('campus:id,current_academic_year')
                ->where('campus_id', $election->campus_id)
                ->where('status', MembershipStatus::Approved->value)
                ->whereIn('role', $studentOfficerRoleValues)
                ->lockForUpdate()
                ->get();

            $promotedUserIds = [];
            $demotedUserIds = [];
            $retainedUserIds = [];
            $processedUserIds = [];
            $newTermAcademicYear = trim((string) $election->academic_year) ?: null;

            foreach ($outgoingOfficers as $officerMembership) {
                $uid = (int) $officerMembership->user_id;
                $processedUserIds[$uid] = true;

                if (isset($electedRolesByUserId[$uid])) {
                    $newRole = $electedRolesByUserId[$uid];
                    $this->recordRoleTransition(
                        $officerMembership,
                        $newRole,
                        $actor,
                        'term_completed',
                        $newTermAcademicYear,
                    );

                    if ($officerMembership->role !== $newRole) {
                        $officerMembership->update([
                            'role' => $newRole,
                            'privileges_suspended_at' => null,
                            'suspension_reason' => null,
                            'suspended_by' => null,
                        ]);
                        $promotedUserIds[] = $uid;
                    } else {
                        if ($officerMembership->isPrivilegesSuspended()) {
                            $officerMembership->update([
                                'privileges_suspended_at' => null,
                                'suspension_reason' => null,
                                'suspended_by' => null,
                            ]);
                        }
                        $retainedUserIds[] = $uid;
                    }
                } else {
                    $this->recordRoleTransition(
                        $officerMembership,
                        CpfRole::Member,
                        $actor,
                        'term_completed',
                        $newTermAcademicYear,
                    );
                    $officerMembership->update([
                        'role' => CpfRole::Member,
                        'privileges_suspended_at' => null,
                        'suspension_reason' => null,
                        'suspended_by' => null,
                    ]);
                    $demotedUserIds[] = $uid;
                }
            }

            $newWinnerUserIds = array_values(array_diff(array_keys($electedRolesByUserId), array_keys($processedUserIds)));
            if ($newWinnerUserIds !== []) {
                $winnerMemberships = CampusMembership::query()
                    ->with('campus:id,current_academic_year')
                    ->where('campus_id', $election->campus_id)
                    ->where('status', MembershipStatus::Approved->value)
                    ->whereIn('user_id', $newWinnerUserIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($winnerMemberships as $winnerMembership) {
                    if (in_array($winnerMembership->role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true)) {
                        continue;
                    }

                    $uid = (int) $winnerMembership->user_id;
                    $newRole = $electedRolesByUserId[$uid];

                    $this->recordRoleTransition(
                        $winnerMembership,
                        $newRole,
                        $actor,
                        'role_change',
                        $newTermAcademicYear,
                    );
                    $winnerMembership->update([
                        'role' => $newRole,
                        'privileges_suspended_at' => null,
                        'suspension_reason' => null,
                        'suspended_by' => null,
                    ]);
                    $promotedUserIds[] = $uid;
                }
            }

            $yearRepUserIds = [];
            $repOnlyUserIds = array_values(array_diff(array_keys($electedYearRepsByUserId), array_keys($electedRolesByUserId)));
            if ($repOnlyUserIds !== []) {
                $repMemberships = CampusMembership::query()
                    ->with('campus:id,current_academic_year')
                    ->where('campus_id', $election->campus_id)
                    ->where('status', MembershipStatus::Approved->value)
                    ->whereIn('user_id', $repOnlyUserIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($repMemberships as $repMembership) {
                    if (in_array($repMembership->role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true)) {
                        continue;
                    }

                    $uid = (int) $repMembership->user_id;
                    $seatKey = $electedYearRepsByUserId[$uid];

                    $this->recordRoleTransition(
                        $repMembership,
                        CpfRole::Member,
                        $actor,
                        'elected_year_rep',
                        $newTermAcademicYear,
                        $seatKey,
                    );
                    $yearRepUserIds[] = $uid;
                }
            }

            return [
                'promoted_user_ids' => $promotedUserIds,
                'demoted_user_ids' => $demotedUserIds,
                'retained_user_ids' => $retainedUserIds,
                'year_rep_user_ids' => $yearRepUserIds,
            ];
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

            RoomRequest::query()
                ->where('campus_id', $lockedMembership->campus_id)
                ->where('requested_by_user_id', $lockedMembership->user_id)
                ->where('status', 'pending')
                ->whereDate('request_date', '>=', now()->toDateString())
                ->lockForUpdate()
                ->update([
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $actor?->id,
                    'review_notes' => 'Auto-released upon transition to Former / Alumni status.',
                ]);

            $lockedMembership->user?->tokens()->delete();

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

            $seatMeta = $t->elected_seat ? CpfElectionPositions::find($t->elected_seat) : null;

            return [
                'id' => $t->id,
                'role' => $roleEnum->value,
                'elected_seat' => $t->elected_seat,
                'role_label' => $seatMeta['label'] ?? $roleEnum->label(),
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
