<?php

namespace App\Actions\Membership;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusEventNotification;
use App\Models\CampusMembership;
use App\Models\DuesAuditLog;
use App\Models\PaymentSubmission;
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

            $trimmedReason = $reason !== null ? trim($reason) : null;
            if ($trimmedReason === '') {
                $trimmedReason = null;
            }

            $hasReceiptBackedValidation = false;

            if ($paymentSubmissionId !== null) {
                $submission = PaymentSubmission::query()
                    ->whereKey($paymentSubmissionId)
                    ->where('campus_id', $locked->campus_id)
                    ->where('user_id', $locked->user_id)
                    ->where('status', 'validated')
                    ->lockForUpdate()
                    ->first();

                abort_if(
                    $submission === null,
                    422,
                    'The specified payment submission must be a validated submission belonging to this member on this campus.',
                );

                $hasReceiptBackedValidation = filled($submission->receipt_hdd_path);
            } else {
                abort_if(
                    mb_strlen((string) $trimmedReason) < 5,
                    422,
                    'A manual dues reason of at least 5 characters is required when no validated payment submission is linked.',
                );
            }

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
                'reason' => $trimmedReason,
                'created_at' => now(),
            ]);

            // Alert Club Adviser & Auditor whenever dues are marked paid without a receipt-backed validated submission
            if ($newState && ! $hasReceiptBackedValidation) {
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
                            '%s marked %s as Dues Paid without a receipt-backed validated submission. Reason: %s',
                            $actor->name,
                            $locked->full_name_last_first_mi ?? 'Member',
                            $trimmedReason ?: 'None supplied',
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
