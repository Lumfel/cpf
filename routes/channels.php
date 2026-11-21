<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('campus.{campusId}.officers-gc.{academicYear}', function ($user, int $campusId, string $academicYear): bool {
    $membership = $user->primaryMembership;

    return $membership !== null
        && $membership->campus_id === $campusId
        && ($user->verifiedRole()?->isOfficerOrAdviser() ?? false);
});
