<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('campus.{campusId}.officers-gc.{academicYear}', function ($user, int $campusId, string $academicYear): bool {
    if ($user->requiresPasswordChange()) {
        return false;
    }

    $membership = $user->primaryMembership;

    return $membership !== null
        && $membership->campus_id === $campusId
        && $user->isActiveOfficerOrAdviser();
});

Broadcast::channel('campus.{campusId}.counselors-gc', function ($user, int $campusId): bool {
    if ($user->requiresPasswordChange()) {
        return false;
    }

    $membership = $user->primaryMembership;

    return $membership !== null
        && $membership->campus_id === $campusId
        && $user->canCoordinateGuidance();
});

Broadcast::channel('campus.{campusId}.counselor-dm.{threadKey}', function ($user, int $campusId, string $threadKey): bool {
    if ($user->requiresPasswordChange()) {
        return false;
    }

    $membership = $user->primaryMembership;
    if ($membership === null || $membership->campus_id !== $campusId || ! $user->canCoordinateGuidance()) {
        return false;
    }

    $parts = explode('-', $threadKey);
    if (count($parts) !== 2) {
        return false;
    }

    return in_array((int) $user->id, [(int) $parts[0], (int) $parts[1]], true);
});
