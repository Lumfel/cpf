<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Alumni = 'alumni';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Officer / Adviser Review',
            self::Approved => 'Verified CPF Member',
            self::Rejected => 'Returned / Declined',
            self::Alumni => 'Former Member / Officer (Archive Access)',
        };
    }
}
