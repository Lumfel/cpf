<?php

namespace App\Actions\Membership;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusEventNotification;
use App\Models\CampusMembership;
use App\Models\DuesAuditLog;
use App\Models\User;
use App\Services\ClubTenureService;
use Illuminate\Support\Facades\DB;

class ToggleMessengerGcChecklist
{
    public function __construct(
        private readonly ClubTenureService $tenureService,
    ) {}

    public function handle(User $actor, CampusMembership $membership): CampusMembership
    {
        $newState = ! $membership->is_in_messenger_gc;

        $membership->update([
            'is_in_messenger_gc' => $newState,
            'gc_confirmed_by' => $newState ? $actor->id : null,
            'gc_confirmed_at' => $newState ? now() : null,
        ]);

        return $membership->refresh();
    }

    /**
     * Confirm or unconfirm that a verified member has settled membership dues.
     * Records an immutable DuesAuditLog entry and alerts Adviser + Auditor
     * whenever dues are marked paid without a linked payment receipt.
     */
    public function toggleDues(
        User $actor,
        CampusMembership $membership,
        ?string $reason = null,
        ?int $paymentSubmissionId = null,
    ): CampusMembership {
        return DB::transaction(function () use ($actor, $membership, $reason, $paymentSubmissionId): CampusMembership {
            $locked = CampusMembership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $oldState = (bool) $locked->has_paid_dues;
            $newState = ! $oldState;

            $locked->update([
                'has_paid_dues' => $newState,
                'dues_confirmed_by' => $newState ? $actor->id : null,
                'dues_confirmed_at' => $newState ? now() : null,
            ]);

            DuesAuditLog::create([
                'campus_id' => $locked->campus_id,
                'actor_user_id' => $actor->id,
                'target_user_id' => $locked->user_id,
                'campus_membership_id' => $locked->id,
                'payment_submission_id' => $paymentSubmissionId,
                'old_status' => $oldState,
                'new_status' => $newState,
                'reason' => $reason !== null ? trim($reason) : null,
                'created_at' => now(),
            ]);

            // Alert Club Adviser & Auditor when dues are toggled paid without a linked receipt
            if ($newState && $paymentSubmissionId === null) {
                $oversightUserIds = CampusMembership::query()
                    ->where('campus_id', $locked->campus_id)
                    ->where('status', MembershipStatus::Approved->value)
                    ->whereIn('role', [CpfRole::Adviser->value, CpfRole::Auditor->value])
                    ->where('user_id', '!=', $actor->id)
                    ->pluck('user_id');

                if ($oversightUserIds->isNotEmpty()) {
                    $now = now();
                    $rows = $oversightUserIds->map(fn (int $uid) => [
                        'campus_id' => $locked->campus_id,
                        'campus_event_id' => null,
                        'user_id' => $uid,
                        'type' => 'dues_manual_override_alert',
                        'title' => 'Manual Dues Override Recorded',
                        'message' => sprintf(
                            '%s marked %s as Dues Paid without a linked receipt. Reason: %s',
                            $actor->name,
                            $locked->full_name_last_first_mi ?? 'Member',
                            $reason ?: 'None supplied',
                        ),
                        'read_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    CampusEventNotification::query()->insert($rows);
                }
            }

            return $locked->refresh();
        });
    }

    public function updateRole(CampusMembership $membership, CpfRole $newRole, ?User $actor = null): CampusMembership
    {
        if ($membership->role !== $newRole) {
            $this->tenureService->recordRoleTransition($membership, $newRole, $actor, 'role_change');
        }

        $membership->update([
            'role' => $newRole,
        ]);

        return $membership->refresh();
    }
}
