<?php

namespace App\Enums;

enum ReferralPriority: string
{
    case Emergency = 'emergency';
    case VeryUrgent = 'very_urgent';
    case Urgent = 'urgent';
    case SemiUrgent = 'semi_urgent';
    case Routine = 'routine';

    public function responseTarget(): string
    {
        return match ($this) {
            self::Emergency => 'Immediate response',
            self::VeryUrgent => 'Within 4 hours',
            self::Urgent => 'Within 24 hours',
            self::SemiUrgent => 'Within 72 hours',
            self::Routine => 'Within 4 weeks / scheduled intake',
        };
    }
}
