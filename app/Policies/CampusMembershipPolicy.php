<?php

namespace App\Policies;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\CampusMembership;
use App\Models\User;

class CampusMembershipPolicy
{
    public function viewRoster(User $user): bool
    {
        return $user->verifiedRole()?->isOfficerOrAdviser() ?? false;
    }

    /**
     * Adviser, President, and Vice President can approve or add members.
     */
    public function addOrApproveMember(User $user, ?CampusMembership $target = null): bool
    {
        $actorMembership = $user->primaryMembership;

        if (! $actorMembership || $actorMembership->status !== MembershipStatus::Approved) {
            return false;
        }

        if (! $actorMembership->role->canApproveMembers()) {
            return false;
        }

        if ($target !== null) {
            if ($target->campus_id !== $actorMembership->campus_id) {
                return false;
            }

            if ($target->status !== MembershipStatus::Pending) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adviser, President, Vice President, and Secretary can issue Passkeys + 2FA
     * strictly to lower-ranking student members (never Adviser, Guidance Counselor, or equal/higher officers).
     */
    public function issuePasskey(User $user, ?CampusMembership $target = null): bool
    {
        $actorMembership = $user->primaryMembership;

        if (! $actorMembership || $actorMembership->status !== MembershipStatus::Approved) {
            return false;
        }

        if (! $actorMembership->role->canIssuePasskeys()) {
            return false;
        }

        if ($target !== null) {
            if ($target->campus_id !== $actorMembership->campus_id) {
                return false;
            }

            if (in_array($target->role, [CpfRole::Adviser, CpfRole::GuidanceCounselor], true)) {
                return false;
            }

            if ($target->role->rank() >= $actorMembership->role->rank()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adviser, President, and Vice President can confirm Messenger GC presence.
     */
    public function confirmMessengerGc(User $user, CampusMembership $target): bool
    {
        $actorMembership = $user->primaryMembership;

        if (! $actorMembership || $actorMembership->status !== MembershipStatus::Approved) {
            return false;
        }

        if (! $actorMembership->role->canConfirmMessengerGc()) {
            return false;
        }

        return $target->campus_id === $actorMembership->campus_id;
    }

    /**
     * Treasurer and Club Adviser can confirm membership dues checklist items.
     */
    public function confirmDues(User $user, CampusMembership $target): bool
    {
        $actorMembership = $user->primaryMembership;

        if (! $actorMembership || $actorMembership->status !== MembershipStatus::Approved) {
            return false;
        }

        if (! $actorMembership->role->canValidatePayments()) {
            return false;
        }

        return $target->campus_id === $actorMembership->campus_id;
    }

    /**
     * Only the Club Adviser (highest authority; no Admin role) can assign officer roles.
     */
    public function assignRole(User $user, CampusMembership $target, CpfRole $newRole): bool
    {
        $actorMembership = $user->primaryMembership;

        if (! $actorMembership || $actorMembership->status !== MembershipStatus::Approved) {
            return false;
        }

        if ($target->campus_id !== $actorMembership->campus_id) {
            return false;
        }

        if ($target->role === CpfRole::Adviser) {
            return $actorMembership->role->isTopAuthority();
        }

        if ($actorMembership->role->isTopAuthority()) {
            return true;
        }

        if ($newRole === CpfRole::Member) {
            return $actorMembership->role->canApproveMembers()
                && $actorMembership->role->rank() > $target->role->rank();
        }

        return false;
    }
}
