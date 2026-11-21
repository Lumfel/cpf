<?php

namespace App\Actions\Membership;

use App\Enums\CpfRole;
use App\Models\CampusMembership;
use App\Models\User;

class ToggleMessengerGcChecklist
{
    /**
     * Confirm or unconfirm that a verified member is present in the campus
     * Messenger Group Chat (GC). Strictly restricted to Adviser or President.
     */
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
     * Restricted to the Treasurer and Club Adviser.
     */
    public function toggleDues(User $actor, CampusMembership $membership): CampusMembership
    {
        $newState = ! $membership->has_paid_dues;

        $membership->update([
            'has_paid_dues' => $newState,
            'dues_confirmed_by' => $newState ? $actor->id : null,
            'dues_confirmed_at' => $newState ? now() : null,
        ]);

        return $membership->refresh();
    }

    /**
     * Assign or update an officer role (President, Secretary, Treasurer, Member).
     * Strictly restricted to the Club Adviser (highest authority in charge; no Admin role).
     */
    public function updateRole(CampusMembership $membership, CpfRole $newRole): CampusMembership
    {
        $membership->update([
            'role' => $newRole,
        ]);

        return $membership->refresh();
    }
}
