export type CpfRoleValue =
    | 'adviser'
    | 'guidance_counselor'
    | 'president'
    | 'vice_president'
    | 'secretary'
    | 'treasurer'
    | 'auditor'
    | 'business_manager'
    | 'pio'
    | 'sergeant_at_arms'
    | 'member';

export type CampusSummary = {
    id: number;
    code: string;
    name: string;
    slug: string;
    current_academic_year?: string | null;
    adviser_name?: string | null;
    gc_group_name?: string | null;
};

export type CampusMembershipRecord = {
    id: number;
    user_id: number;
    campus_id: number;
    role: CpfRoleValue;
    status: 'pending' | 'approved' | 'rejected' | 'alumni';
    enrollment_source: 'online_form' | 'officer_added' | 'google_forms_api';
    recorded_email: string;
    first_name?: string;
    middle_name?: string | null;
    last_name?: string;
    full_name_last_first_mi: string;
    course?: string | null;
    year_level?: string | null;
    section?: string | null;
    course_year_section: string;
    sex: 'Male' | 'Female';
    gender_identity: 'Man' | 'Woman' | 'Non-binary' | 'Prefer not to say' | 'Others' | null;
    gender_identity_other?: string | null;
    birthdate: string;
    active_email: string;
    active_fb_account: string;
    facebook_profile_url?: string | null;
    cellphone_number: string;
    added_or_approved_by?: number | null;
    approved_at?: string | null;
    is_in_messenger_gc: boolean;
    gc_confirmed_by?: number | null;
    gc_confirmed_at?: string | null;
    has_paid_dues?: boolean;
    dues_confirmed_by?: number | null;
    dues_confirmed_at?: string | null;
    alumni_since?: string | null;
    alumni_reason?: string | null;
    remarks?: string | null;
    campus?: CampusSummary;
    approver?: { id: number; name: string } | null;
    gc_confirmer?: { id: number; name: string } | null;
    dues_confirmer?: { id: number; name: string } | null;
};

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    email_verified_at: string | null;
    must_change_password?: boolean;
    temporary_password_issued_at?: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type AuthPermissions = {
    viewRoster: boolean;
    addOrApproveMembers: boolean;
    issuePasskeys?: boolean;
    confirmMessengerGc: boolean;
    validatePayments?: boolean;
    auditFinances?: boolean;
    proposeEvents?: boolean;
    isOfficerOrAdviser?: boolean;
    isClubAdviser: boolean;
    canCoordinateGuidance?: boolean;
    canPublishAnnouncements?: boolean;
    accessAlumniArchive?: boolean;
};

export type SystemBootstrapState = {
    needsAdviserSetup: boolean;
    mandatoryTourEnabled?: boolean;
    exemptFromMandatoryTour?: boolean;
    activeCampus: CampusSummary | null;
    campuses: Array<{
        id: number;
        code: string;
        name: string;
        current_academic_year: string | null;
        is_active: boolean;
    }>;
};

export type PendingAdviserHandoverState = {
    incoming: {
        id: number;
        campus_name: string;
        from_name: string;
        handover_notes: string | null;
        initiated_at: string | null;
        expires_at: string | null;
    } | null;
    outgoing: {
        id: number;
        campus_name: string;
        to_name: string;
        to_email: string;
        to_course_year_section: string;
        confirmation_code: string;
        handover_notes: string | null;
        initiated_at: string | null;
        expires_at: string | null;
    } | null;
    availableGuidanceCounselors?: Array<{
        membership_id: number;
        user_id: number;
        full_name: string;
        email: string;
    }>;
};

export type PasskeyIssuedNotice = {
    member_name: string;
    email: string;
    passkey_code: string;
    emailed?: boolean;
    email_status?: 'sent' | 'logged' | 'already_sent' | 'failed';
    email_error?: string | null;
};

export type MemberInfoChangeDiff = Record<
    string,
    {
        old: string | null;
        new: string | null;
    }
>;

export type MemberInfoChangeRequestRecord = {
    id: number;
    campus_membership_id: number;
    campus_id: number;
    user_id: number;
    requested_changes: MemberInfoChangeDiff;
    reason: string;
    status: 'pending' | 'approved' | 'declined';
    reviewed_by: number | null;
    reviewed_at: string | null;
    adviser_notes: string | null;
    created_at: string;
    reviewer?: { id: number; name: string } | null;
    membership?: {
        id: number;
        full_name_last_first_mi: string;
        course_year_section: string;
        role: CpfRoleValue;
        active_email: string;
    } | null;
};

export type Auth = {
    user: User | null;
    membership?: CampusMembershipRecord | null;
    role?: CpfRoleValue | null;
    formerRole?: CpfRoleValue | null;
    isAlumni?: boolean;
    roleLabel?: string;
    roleDuties?: string | null;
    mustChangePassword?: boolean;
    can?: AuthPermissions;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type HeaderNotificationItem = {
    id: number;
    campus_event_id: number | null;
    type: string;
    title: string;
    message: string;
    read_at: string | null;
    is_read: boolean;
    created_at: string | null;
    created_at_iso: string | null;
};

export type HeaderNotificationsState = {
    items: HeaderNotificationItem[];
    unread_count: number;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
