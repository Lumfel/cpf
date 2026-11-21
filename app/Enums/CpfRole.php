<?php

namespace App\Enums;

enum CpfRole: string
{
    case Adviser = 'adviser';
    case President = 'president';
    case VicePresident = 'vice_president';
    case Secretary = 'secretary';
    case Treasurer = 'treasurer';
    case Auditor = 'auditor';
    case BusinessManager = 'business_manager';
    case Pio = 'pio';
    case SergeantAtArms = 'sergeant_at_arms';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Adviser => 'Club Adviser (Guidance Counselor)',
            self::President => 'President',
            self::VicePresident => 'Vice President',
            self::Secretary => 'Secretary',
            self::Treasurer => 'Treasurer',
            self::Auditor => 'Auditor',
            self::BusinessManager => 'Business Manager',
            self::Pio => 'Public Information Officer (PIO)',
            self::SergeantAtArms => 'Sergeant-at-Arms',
            self::Member => 'Verified Peer Member',
        };
    }

    public function dutiesSummary(): string
    {
        return match ($this) {
            self::Adviser => 'Guides all officers, approves organizational plans and special meetings, co-signs funds, certifies candidacy, and oversees disciplinary sanctions.',
            self::President => 'Head of the organization; presides over meetings, signs official letters/reports, submits Accomplishment Reports, and coordinates through the Adviser.',
            self::VicePresident => 'Assumes Presidential duties upon absence/vacancy, co-approves contracts, and assists with chapter governance and collections.',
            self::Secretary => 'Maintains meeting minutes, records, official letters, A–Z roster accountability, and issues Passkey codes under Presidential direction.',
            self::Treasurer => 'Custodian of chapter funds and receipts; confirms dues collections, prepares monthly/liquidation/financial reports, and co-signs bank funds.',
            self::Auditor => 'Audits incoming and outgoing funds, certifies financial reports by the Treasurer, and alerts executive officers to any discrepancy.',
            self::BusinessManager => 'Manages organizational business transactions, t-shirt/project logistics, and income-generating initiatives assigned by the President.',
            self::Pio => 'Disseminates official announcements, notifies officers and members of meetings/assemblies, and helps track Messenger GC outreach.',
            self::SergeantAtArms => 'Maintains peace, calm, and order during meetings, assemblies, and campus peer facilitator activities.',
            self::Member => 'Participates in campus peer facilitation, meetings, elections, wellness capsules, and community milestones.',
        };
    }

    public function canApproveMembers(): bool
    {
        return in_array($this, [self::Adviser, self::President, self::VicePresident], true);
    }

    public function canIssuePasskeys(): bool
    {
        return in_array($this, [self::Adviser, self::President, self::VicePresident, self::Secretary], true);
    }

    /**
     * Adviser, President, Vice President, Secretary, and PIO can confirm Messenger GC checklist.
     */
    public function canConfirmMessengerGc(): bool
    {
        return in_array($this, [self::Adviser, self::President, self::VicePresident, self::Secretary, self::Pio], true);
    }

    /**
     * Fund collections & dues confirmation: Treasurer, Adviser, President, and Vice President.
     */
    public function canValidatePayments(): bool
    {
        return in_array($this, [self::Treasurer, self::Adviser, self::President, self::VicePresident], true);
    }

    /**
     * Read-only / audit financial oversight (includes Auditor alongside collection signers).
     */
    public function canAuditFinances(): bool
    {
        return in_array($this, [self::Auditor, self::Treasurer, self::Adviser, self::President, self::VicePresident], true);
    }

    public function isTopAuthority(): bool
    {
        return $this === self::Adviser;
    }

    public function isOfficerOrAdviser(): bool
    {
        return $this !== self::Member;
    }
}
