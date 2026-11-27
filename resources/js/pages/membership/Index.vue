<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    ArrowRightLeft,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Copy,
    ExternalLink,
    FilePenLine,
    GraduationCap,
    KeyRound,
    Lock,
    MessageCircle,
    PlusCircle,
    QrCode,
    RefreshCw,
    Search,
    ShieldCheck,
    UserCheck,
    UserPlus,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import ProfilePhotoUploader from '@/components/ProfilePhotoUploader.vue';
import AdviserHandoverPanel from '@/components/membership/AdviserHandoverPanel.vue';
import OfficerAddMemberForm from '@/components/membership/OfficerAddMemberForm.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { apiRequest } from '@/lib/csrfFetch';
import {
    apply,
    approve,
    index as membershipIndex,
    toggleGc,
} from '@/routes/membership';
import type {
    Auth,
    CampusMembershipRecord,
    CampusSummary,
    CpfRoleValue,
    MemberInfoChangeRequestRecord,
    PasskeyIssuedNotice,
} from '@/types';

const props = defineProps<{
    campuses: CampusSummary[];
    activeCampus: CampusSummary | null;
    myMembership: CampusMembershipRecord | null;
    permissions: {
        canViewRoster: boolean;
        canApproveMembers: boolean;
        canIssuePasskeys: boolean;
        canConfirmMessengerGc: boolean;
        canConfirmDues?: boolean;
        isClubAdviser: boolean;
        verifiedRole: CpfRoleValue | null;
        verifiedRoleLabel: string;
    };
    pendingApplications: CampusMembershipRecord[];
    approvedMembersAZ: CampusMembershipRecord[];
    alumniCount?: number;
    myChangeRequests: MemberInfoChangeRequestRecord[];
    pendingChangeRequests: MemberInfoChangeRequestRecord[];
    availableRoles: Array<{
        value: CpfRoleValue;
        label: string;
        canApproveMembers?: boolean;
        canIssuePasskeys?: boolean;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'CPF Membership & GC Checklist',
                href: membershipIndex(),
            },
        ],
    },
});

const page = usePage<{
    auth: Auth;
    flash?: {
        status?: string;
        passkey_issued_notice?: PasskeyIssuedNotice | null;
    };
}>();
const currentUser = computed(() => page.props.auth.user);
const flashStatus = computed(() => page.props.flash?.status);
const passkeyIssuedNotice = computed(() => page.props.flash?.passkey_issued_notice);

const isApprovedMember = computed(() => props.myMembership?.status === 'approved');
const isPendingApplicant = computed(() => props.myMembership?.status === 'pending');

// Active tab: 'checklist' (A-Z Roster), 'profile' (Official Member Profile & Change Request), 'apply' (New Application / Pending Status), 'add' (Officer Direct Add), 'handover' (Club Adviser Handover)
const activeTab = ref<'checklist' | 'profile' | 'apply' | 'add' | 'handover'>(
    props.permissions.canViewRoster
        ? 'checklist'
        : props.myMembership?.status === 'approved'
          ? 'profile'
          : 'apply',
);

// Client-side search & filter (utilizing user device RAM rather than server queries)
const searchQuery = ref('');
const gcFilter = ref<'all' | 'unconfirmed' | 'confirmed'>('all');

const filteredMembersAZ = computed(() => {
    return props.approvedMembersAZ.filter((member) => {
        const matchesGc =
            gcFilter.value === 'all' ||
            (gcFilter.value === 'confirmed' && member.is_in_messenger_gc) ||
            (gcFilter.value === 'unconfirmed' && !member.is_in_messenger_gc);

        if (!matchesGc) return false;

        if (!searchQuery.value.trim()) return true;
        const q = searchQuery.value.toLowerCase();
        return (
            member.full_name_last_first_mi.toLowerCase().includes(q) ||
            member.course_year_section.toLowerCase().includes(q) ||
            (member.course ?? '').toLowerCase().includes(q) ||
            (member.facebook_profile_url ?? '').toLowerCase().includes(q) ||
            member.active_fb_account.toLowerCase().includes(q) ||
            member.active_email.toLowerCase().includes(q)
        );
    });
});

const gcConfirmedCount = computed(
    () => props.approvedMembersAZ.filter((m) => m.is_in_messenger_gc).length,
);
const duesConfirmedCount = computed(
    () => props.approvedMembersAZ.filter((m) => m.has_paid_dues).length,
);

// 1. Online Membership Form (Separate Course, Year, Section + Required Facebook Link)
const recordAccountEmail = ref(true);
const fallbackApplicantEmail = computed(
    () => currentUser.value?.email ?? 'applicant@cpf.chmsu.edu.ph',
);
const facebookUrlPlaceholder = 'https://www.facebook.com/your.username';
const onlineForm = useForm({
    campus_id: props.myMembership?.campus_id ?? props.activeCampus?.id ?? props.campuses[0]?.id ?? 1,
    recorded_email: props.myMembership?.recorded_email ?? fallbackApplicantEmail.value,
    first_name: props.myMembership?.first_name ?? '',
    middle_name: props.myMembership?.middle_name ?? '',
    last_name: props.myMembership?.last_name ?? '',
    course: props.myMembership?.course ?? '',
    year_level: props.myMembership?.year_level ?? '',
    section: props.myMembership?.section ?? '',
    sex: (props.myMembership?.sex ?? 'Female') as 'Male' | 'Female',
    gender_identity: (props.myMembership?.gender_identity ?? 'Prefer not to say') as
        | 'Man'
        | 'Woman'
        | 'Non-binary'
        | 'Prefer not to say'
        | 'Others',
    gender_identity_other: props.myMembership?.gender_identity_other ?? '',
    birthdate: props.myMembership?.birthdate ?? '',
    active_email: props.myMembership?.active_email ?? fallbackApplicantEmail.value,
    facebook_profile_url: props.myMembership?.facebook_profile_url ?? '',
    active_fb_account: props.myMembership?.active_fb_account ?? '',
    cellphone_number: props.myMembership?.cellphone_number ?? '',
});

function submitOnlineForm() {
    if (recordAccountEmail.value) {
        onlineForm.recorded_email = fallbackApplicantEmail.value;
    }
    onlineForm.post(apply.url(), {
        preserveScroll: true,
    });
}

// 2. Officer / Adviser Actions (Approve Pending Application, Toggle GC Checklist, Assign Role, Issue Passkey)
const passkeyModalOpen = ref(false);
const issuingPasskey = ref(false);
const regeneratingPasskey = ref(false);
const passkeyError = ref<string | null>(null);
const passkeyCopied = ref(false);
const passkeyStatusNote = ref<string | null>(null);
const issuedPasskey = ref<{
    membershipId: number;
    memberName: string;
    enrollment_code: string;
    is_locked: boolean;
    require_2fa: boolean;
    expires_at: string;
} | null>(null);

async function fetchMemberPasskey(membershipId: number, memberName: string, regenerate: boolean) {
    if (!props.permissions.canIssuePasskeys) return;

    passkeyModalOpen.value = true;
    passkeyError.value = null;
    passkeyCopied.value = false;
    passkeyStatusNote.value = null;

    if (regenerate) {
        regeneratingPasskey.value = true;
    } else {
        issuingPasskey.value = true;
        issuedPasskey.value = null;
    }

    const { ok, data } = await apiRequest<{
        enrollment_code: string;
        is_locked?: boolean;
        regenerated?: boolean;
        require_2fa?: boolean;
        expires_at: string;
    }>(`/passkeys/${membershipId}/issue`, 'POST', {
        require_2fa: true,
        regenerate,
    });

    issuingPasskey.value = false;
    regeneratingPasskey.value = false;

    if (!ok) {
        passkeyError.value = data.message ?? 'Unable to issue passkey code.';
        return;
    }

    issuedPasskey.value = {
        membershipId,
        memberName,
        enrollment_code: data.enrollment_code,
        is_locked: Boolean(data.is_locked ?? true),
        require_2fa: Boolean(data.require_2fa ?? true),
        expires_at: data.expires_at,
    };

    if (data.regenerated) {
        passkeyStatusNote.value = 'New passkey generated & locked in real time — previous code has been revoked.';
    }
}

function issueMemberPasskey(member: CampusMembershipRecord) {
    void fetchMemberPasskey(member.id, member.full_name_last_first_mi, false);
}

function regenerateMemberPasskey() {
    if (!issuedPasskey.value || regeneratingPasskey.value) return;
    void fetchMemberPasskey(issuedPasskey.value.membershipId, issuedPasskey.value.memberName, true);
}

async function copyIssuedPasskey() {
    if (!issuedPasskey.value || typeof navigator === 'undefined' || !navigator.clipboard) return;
    await navigator.clipboard.writeText(issuedPasskey.value.enrollment_code);
    passkeyCopied.value = true;
    setTimeout(() => {
        passkeyCopied.value = false;
    }, 2000);
}

// 2b. Minimal Member Attendance QR Card Modal (Name, Course, Year & Section ONLY)
interface MemberQrCard {
    full_name: string;
    course?: string | null;
    year_level?: string | null;
    section?: string | null;
    course_year_section: string;
    qr_svg: string;
}

const qrModalOpen = ref(false);
const loadingMemberQr = ref(false);
const memberQrError = ref<string | null>(null);
const memberQrCard = ref<MemberQrCard | null>(null);

async function openMemberQr(member: CampusMembershipRecord) {
    qrModalOpen.value = true;
    loadingMemberQr.value = true;
    memberQrError.value = null;
    memberQrCard.value = null;

    const { ok, data } = await apiRequest<MemberQrCard>(
        `/membership/${member.id}/qr`,
        'GET',
    );

    loadingMemberQr.value = false;

    if (!ok) {
        memberQrError.value = data.message ?? 'Unable to load member QR code.';
        return;
    }

    memberQrCard.value = data;
}

function approveMember(member: CampusMembershipRecord) {
    router.patch(
        approve.url(member.id),
        {},
        { preserveScroll: true },
    );
}

function toggleGcChecklist(member: CampusMembershipRecord) {
    if (!props.permissions.canConfirmMessengerGc) return;
    router.patch(
        toggleGc.url(member.id),
        {},
        { preserveScroll: true },
    );
}

function toggleDuesChecklist(member: CampusMembershipRecord) {
    if (!props.permissions.canConfirmDues) return;
    router.patch(
        `/membership/${member.id}/toggle-dues`,
        {},
        { preserveScroll: true },
    );
}

// 2c. Club Adviser Transition to Former / Alumni Archive
const alumniModalOpen = ref(false);
const alumniTarget = ref<CampusMembershipRecord | null>(null);
const alumniReason = ref<'graduated' | 'term_completed' | 'stepped_down' | 'transferred'>('graduated');
const alumniRemarks = ref('');
const transitioningAlumni = ref(false);
const alumniModalError = ref<string | null>(null);

function openAlumniModal(member: CampusMembershipRecord) {
    alumniTarget.value = member;
    alumniReason.value = member.role === 'member' ? 'graduated' : 'term_completed';
    alumniRemarks.value = '';
    alumniModalError.value = null;
    alumniModalOpen.value = true;
}

function submitAlumniTransition() {
    if (!alumniTarget.value || transitioningAlumni.value) return;
    transitioningAlumni.value = true;
    alumniModalError.value = null;

    router.post(
        `/membership/${alumniTarget.value.id}/transition-alumni`,
        {
            reason: alumniReason.value,
            remarks: alumniRemarks.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                alumniModalOpen.value = false;
                alumniTarget.value = null;
            },
            onError: (errors) => {
                alumniModalError.value = Object.values(errors)[0] ?? 'Unable to transition member to Former status.';
            },
            onFinish: () => {
                transitioningAlumni.value = false;
            },
        },
    );
}

function roleBadgeClasses(role: CpfRoleValue): string {
    switch (role) {
        case 'adviser':
            return 'bg-[#0384C5] text-white';
        case 'president':
            return 'bg-[#FF8D06] text-white';
        case 'vice_president':
            return 'bg-sky-700 text-white';
        case 'secretary':
            return 'bg-[#1A8F26] text-white';
        case 'treasurer':
            return 'bg-slate-800 text-white';
        case 'auditor':
            return 'bg-teal-700 text-white';
        case 'business_manager':
            return 'bg-amber-700 text-white';
        case 'pio':
            return 'bg-indigo-600 text-white';
        case 'sergeant_at_arms':
            return 'bg-rose-700 text-white';
        default:
            return 'bg-secondary text-secondary-foreground border border-border';
    }
}

// 3. Official Member Information Change Requests (Approved Members submit; Club Adviser ONLY approves/declines)
const hasPendingChangeRequest = computed(() =>
    props.myChangeRequests.some((req) => req.status === 'pending'),
);
const showChangeRequestForm = ref(false);
const changeRequestForm = useForm({
    first_name: props.myMembership?.first_name ?? '',
    middle_name: props.myMembership?.middle_name ?? '',
    last_name: props.myMembership?.last_name ?? '',
    course: props.myMembership?.course ?? '',
    year_level: props.myMembership?.year_level ?? '',
    section: props.myMembership?.section ?? '',
    sex: (props.myMembership?.sex ?? 'Female') as 'Male' | 'Female',
    gender_identity: (props.myMembership?.gender_identity ?? 'Prefer not to say') as
        | 'Man'
        | 'Woman'
        | 'Non-binary'
        | 'Prefer not to say'
        | 'Others',
    gender_identity_other: props.myMembership?.gender_identity_other ?? '',
    birthdate: props.myMembership?.birthdate ?? '',
    active_email: props.myMembership?.active_email ?? '',
    facebook_profile_url: props.myMembership?.facebook_profile_url ?? '',
    active_fb_account: props.myMembership?.active_fb_account ?? '',
    cellphone_number: props.myMembership?.cellphone_number ?? '',
    reason: '',
});

function submitChangeRequest() {
    changeRequestForm.post('/membership/change-requests', {
        preserveScroll: true,
        onSuccess: () => {
            showChangeRequestForm.value = false;
            changeRequestForm.reason = '';
        },
    });
}

const adviserNotesByRequest = ref<Record<number, string>>({});

function reviewChangeRequest(req: MemberInfoChangeRequestRecord, decision: 'approved' | 'declined') {
    if (!props.permissions.isClubAdviser) return;
    router.patch(
        `/membership/change-requests/${req.id}/review`,
        {
            decision,
            adviser_notes: adviserNotesByRequest.value[req.id] ?? '',
        },
        { preserveScroll: true },
    );
}

const fieldLabels: Record<string, string> = {
    first_name: 'First Name',
    middle_name: 'Middle Name / M.I.',
    last_name: 'Last Name',
    course: 'Course / Program',
    year_level: 'Year Level',
    section: 'Section',
    sex: 'Sex',
    gender_identity: 'Gender Identity',
    gender_identity_other: 'Gender Identity (Other)',
    birthdate: 'Birthdate',
    active_email: 'Active Email',
    facebook_profile_url: 'Facebook Profile Link',
    active_fb_account: 'FB Account Name / Note',
    cellphone_number: 'Cellphone Number',
};

function formatFieldLabel(key: string): string {
    return fieldLabels[key] ?? key.replace(/_/g, ' ');
}
</script>

<template>
    <Head title="CPF Membership & A–Z GC Checklist" />

    <div class="flex min-h-full w-full max-w-none flex-1 flex-col gap-3.5 bg-background p-3 text-foreground md:p-4">
        <!-- Top Role & Campus Authority Banner -->
        <div class="bento p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary">
                            <ShieldCheck class="size-3.5" />
                            {{ permissions.verifiedRoleLabel }}
                        </span>
                        <span
                            v-if="activeCampus"
                            class="rounded-full border border-border bg-secondary px-2.5 py-0.5 text-xs font-semibold text-secondary-foreground"
                        >
                            {{ activeCampus.name }}
                        </span>
                    </div>
                    <h1 class="mt-1 text-xl font-bold tracking-tight text-foreground md:text-2xl">
                        CPF Membership &amp; Messenger GC Accountability
                    </h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        <strong class="text-foreground">Role Governance (Zero Admin Role):</strong> Club Adviser is in charge (and exclusively approves member information change requests) •
                        <strong class="text-foreground">Adviser, President &amp; Vice President</strong> approve/add members •
                        <strong class="text-foreground">Adviser, President, Vice President, Secretary &amp; PIO</strong> confirm the A–Z Messenger GC checklist &amp; issue Passkey codes.
                    </p>
                </div>

                <!-- Navigation Tabs -->
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="permissions.canViewRoster"
                        type="button"
                        @click="activeTab = 'checklist'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-[border-color,background-color,color] duration-200"
                        :class="
                            activeTab === 'checklist'
                                ? 'bg-[#0384C5] text-white shadow-sm'
                                : 'border border-border bg-background text-foreground hover:border-primary'
                        "
                    >
                        <ClipboardCheck class="size-4" />
                        A–Z Roster &amp; GC Checklist ({{ approvedMembersAZ.length }})
                        <span
                            v-if="permissions.isClubAdviser && pendingChangeRequests.length > 0"
                            class="ml-1 rounded-full bg-[#FF8D06] px-2 py-0.5 text-xs font-bold text-white"
                        >
                            {{ pendingChangeRequests.length }}
                        </span>
                    </button>

                    <button
                        v-if="permissions.canApproveMembers"
                        type="button"
                        @click="activeTab = 'add'"
                        class="inline-flex items-center gap-1.5 rounded-lg border-[1.5px] border-[#FF8D06] px-3 py-1.5 text-sm font-semibold transition-colors duration-200"
                        :class="
                            activeTab === 'add'
                                ? 'bg-background text-[#FF8D06]'
                                : 'bg-[#FF8D06] text-white hover:bg-background hover:text-[#FF8D06]'
                        "
                    >
                        <UserPlus class="size-4" />
                        + Add Member (Adviser / Pres / VP)
                    </button>

                    <button
                        v-if="isApprovedMember"
                        type="button"
                        @click="activeTab = 'profile'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-[border-color,background-color,color] duration-200"
                        :class="
                            activeTab === 'profile'
                                ? 'bg-[#1A8F26] text-white shadow-sm'
                                : 'border border-border bg-background text-foreground hover:border-[#1A8F26]'
                        "
                    >
                        <FilePenLine class="size-4" />
                        My Official Profile &amp; Change Request
                    </button>

                    <button
                        v-else
                        type="button"
                        @click="activeTab = 'apply'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-[border-color,background-color,color] duration-200"
                        :class="
                            activeTab === 'apply'
                                ? 'bg-[#1A8F26] text-white shadow-sm'
                                : 'border border-border bg-background text-foreground hover:border-[#1A8F26]'
                        "
                    >
                        <PlusCircle class="size-4" />
                        {{ isPendingApplicant ? 'Application Status (Pending)' : 'Online Membership Form' }}
                    </button>

                    <button
                        v-if="permissions.isClubAdviser"
                        type="button"
                        @click="activeTab = 'handover'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-[border-color,background-color,color] duration-200"
                        :class="
                            activeTab === 'handover'
                                ? 'bg-foreground text-background shadow-sm'
                                : 'border border-border bg-card text-foreground hover:border-primary'
                        "
                    >
                        <ArrowRightLeft class="size-4" />
                        Adviser Handover
                    </button>
                </div>
            </div>

            <!-- Status Flash Alert -->
            <div
                v-if="flashStatus"
                class="mt-3 flex items-center gap-2 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3.5 py-2 text-sm font-semibold text-[#1A8F26]"
            >
                <CheckCircle2 class="size-4 shrink-0" />
                <span>{{ flashStatus }}</span>
            </div>

            <!-- Issued Locked Passkey Alert -->
            <div
                v-if="passkeyIssuedNotice"
                class="mt-3 rounded-xl border border-primary/35 bg-primary/10 px-3.5 py-3 text-sm text-foreground"
            >
                <div class="flex flex-wrap items-center justify-between gap-2 font-bold text-primary">
                    <div class="flex items-center gap-2">
                        <KeyRound class="size-4 shrink-0" />
                        <span>Locked Passkey Code Issued for {{ passkeyIssuedNotice.member_name }}</span>
                    </div>
                    <span
                        v-if="passkeyIssuedNotice.email_status === 'sent' || (!passkeyIssuedNotice.email_status && passkeyIssuedNotice.emailed)"
                        class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]"
                    >
                        <CheckCircle2 class="size-3" />
                        One-Time Email Delivered ✓
                    </span>
                    <span
                        v-else-if="passkeyIssuedNotice.email_status === 'already_sent'"
                        class="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-0.5 text-xs font-bold text-muted-foreground"
                    >
                        One-Time Email Already Sent Previously
                    </span>
                    <span
                        v-else-if="passkeyIssuedNotice.email_status === 'logged'"
                        class="inline-flex items-center gap-1 rounded-full bg-amber-500/15 px-2.5 py-0.5 text-xs font-bold text-amber-800"
                    >
                        Logged Locally (MAIL_MAILER=log)
                    </span>
                    <span
                        v-else-if="passkeyIssuedNotice.email_status === 'failed' || passkeyIssuedNotice.email_error"
                        class="inline-flex items-center gap-1 rounded-full bg-red-500/15 px-2.5 py-0.5 text-xs font-bold text-red-700"
                    >
                        Email Delivery Failed — Share Code Manually
                    </span>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <span>Email: <strong>{{ passkeyIssuedNotice.email }}</strong></span>
                    <span class="flex flex-wrap items-center gap-1">
                        Passkey Code:
                        <template v-for="(chunk, cIdx) in passkeyIssuedNotice.passkey_code.split('-')" :key="`notice-${cIdx}`">
                            <span v-if="cIdx > 0" class="font-mono font-bold text-muted-foreground">-</span>
                            <code class="rounded bg-card px-2 py-0.5 font-mono font-bold text-primary">{{ chunk }}</code>
                        </template>
                    </span>
                </div>
                <p
                    v-if="passkeyIssuedNotice.email_error"
                    class="mt-2 rounded-lg border border-amber-400/40 bg-card/80 px-3 py-2 text-xs font-medium text-amber-900"
                >
                    <strong>Mail Notice:</strong> {{ passkeyIssuedNotice.email_error }}
                </p>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Share this locked passkey code with the member. They sign in via <strong class="text-foreground">Login &rarr; Passkey Code</strong> (12 boxes) and will immediately create their own personal password to unlock their Attendance QR Code.
                </p>
            </div>
        </div>

        <AdviserHandoverPanel
            v-if="activeTab !== 'handover'"
            :is-club-adviser="permissions.isClubAdviser"
            :show-initiate-form="false"
        />

        <!-- =================================================================== -->
        <!-- TAB 1: OFFICER & ADVISER A-Z ROSTER, PENDING APPROVALS & GC CHECKLIST -->
        <!-- =================================================================== -->
        <div v-if="activeTab === 'checklist' && permissions.canViewRoster" class="flex flex-col gap-3.5">
            <!-- Club Adviser Exclusive: Member Information Change Requests -->
            <div
                v-if="permissions.isClubAdviser && pendingChangeRequests.length > 0"
                class="bento border-primary/40 p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <FilePenLine class="size-4 text-primary" />
                        <h2 class="text-base font-bold text-foreground">
                            Member Information Change Requests ({{ pendingChangeRequests.length }})
                        </h2>
                    </div>
                    <span class="rounded-full bg-primary/10 px-3 py-0.5 text-xs font-bold text-primary">
                        Club Adviser Exclusive Approval Authority
                    </span>
                </div>

                <div class="mt-3 grid gap-3">
                    <div
                        v-for="req in pendingChangeRequests"
                        :key="`change-req-${req.id}`"
                        class="rounded-xl border border-border bg-background/80 p-3.5 text-sm"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <h3 class="text-sm font-bold text-foreground">
                                    {{ req.membership?.full_name_last_first_mi ?? 'CPF Member' }}
                                </h3>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ req.membership?.course_year_section }} • {{ req.membership?.active_email }}
                                </p>
                            </div>
                            <span class="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                                Pending Adviser Approval
                            </span>
                        </div>

                        <p class="mt-2 rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground">
                            <strong>Reason:</strong> {{ req.reason }}
                        </p>

                        <div class="mt-2.5 space-y-1.5">
                            <div
                                v-for="(diff, fieldKey) in req.requested_changes"
                                :key="`diff-${req.id}-${fieldKey}`"
                                class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm"
                            >
                                <span class="font-bold text-foreground">{{ formatFieldLabel(String(fieldKey)) }}:</span>
                                <span class="rounded bg-muted px-2 py-0.5 text-muted-foreground line-through">
                                    {{ diff.old ?? '—' }}
                                </span>
                                <span class="font-bold text-primary">&rarr;</span>
                                <span class="rounded bg-[#1A8F26]/12 px-2 py-0.5 font-semibold text-[#1A8F26]">
                                    {{ diff.new ?? '—' }}
                                </span>
                            </div>
                        </div>

                        <div class="mt-2.5 flex flex-wrap items-center gap-2">
                            <input
                                v-model="adviserNotesByRequest[req.id]"
                                type="text"
                                placeholder="Optional Adviser note (e.g. Verified with registrar / ID)..."
                                class="min-w-[220px] flex-1 rounded-lg border border-border bg-card px-3 py-1.5 text-sm text-foreground focus:border-primary focus:outline-none"
                            />
                            <button
                                type="button"
                                @click="reviewChangeRequest(req, 'approved')"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-[#1A8F26] px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-[#15741f]"
                            >
                                <CheckCircle2 class="size-3.5" />
                                Approve Changes
                            </button>
                            <button
                                type="button"
                                @click="reviewChangeRequest(req, 'declined')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-card px-3 py-1.5 text-sm font-semibold text-red-700 transition hover:bg-red-50"
                            >
                                <X class="size-3.5" />
                                Decline
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Online & Google Form Submissions -->
            <div
                v-if="pendingApplications.length > 0"
                class="bento border-[#FF8D06]/40 p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <Clock class="size-4 text-[#FF8D06]" />
                        <h2 class="text-base font-bold text-foreground">
                            Pending Membership Applications ({{ pendingApplications.length }})
                        </h2>
                    </div>
                    <span class="text-sm text-muted-foreground">
                        {{
                            permissions.canApproveMembers
                                ? 'As Adviser, President, or Vice President, you can verify and approve these applicants.'
                                : 'View-only for Secretary & Treasurer. Adviser, President, or Vice President can approve.'
                        }}
                    </span>
                </div>

                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    <div
                        v-for="app in pendingApplications"
                        :key="app.id"
                        class="flex flex-col justify-between rounded-xl border border-border bg-background/80 p-3.5"
                    >
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-sm font-bold text-foreground">
                                    {{ app.full_name_last_first_mi }}
                                </h3>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span
                                        v-if="app.enrollment_source === 'google_forms_api'"
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-bold text-primary"
                                    >
                                        Google Forms API
                                    </span>
                                    <span class="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-xs font-semibold text-amber-800">
                                        Pending Review
                                    </span>
                                </div>
                            </div>
                            <p class="mt-1 text-sm font-medium text-primary">
                                <template v-if="app.course">
                                    Course: {{ app.course }} • Year: {{ app.year_level || '—' }} • Section: {{ app.section || '—' }}
                                </template>
                                <template v-else>
                                    {{ app.course_year_section }}
                                </template>
                                • {{ app.sex }}
                                <span v-if="app.gender_identity">({{ app.gender_identity }})</span>
                            </p>
                            <dl class="mt-2 space-y-1 text-sm text-muted-foreground">
                                <div><strong class="text-foreground">Birthdate:</strong> {{ app.birthdate }}</div>
                                <div><strong class="text-foreground">Active Email:</strong> {{ app.active_email }}</div>
                                <div v-if="app.facebook_profile_url">
                                    <strong class="text-foreground">Facebook Profile:</strong>
                                    <a
                                        :href="app.facebook_profile_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="ml-1 inline-flex items-center gap-1 font-semibold text-primary underline hover:opacity-80"
                                    >
                                        Open Facebook Profile
                                        <ExternalLink class="size-3" />
                                    </a>
                                </div>
                                <div v-if="app.active_fb_account && app.active_fb_account !== app.facebook_profile_url">
                                    <strong class="text-foreground">FB Name / Photo Note:</strong> {{ app.active_fb_account }}
                                </div>
                                <div><strong class="text-foreground">Cellphone:</strong> {{ app.cellphone_number }}</div>
                            </dl>
                        </div>

                        <div v-if="permissions.canApproveMembers" class="mt-3 flex justify-end">
                            <button
                                type="button"
                                @click="approveMember(app)"
                                class="inline-flex items-center gap-1.5 rounded-lg border-[1.5px] border-[#FF8D06] bg-[#FF8D06] px-3 py-1.5 text-sm font-semibold text-white transition-colors duration-200 hover:bg-background hover:text-[#FF8D06]"
                            >
                                <UserCheck class="size-3.5" />
                                Confirm &amp; Issue Passkey Code
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upward-Flowing A-Z Card-by-Card Messenger GC & Dues Checklist -->
            <div class="bento p-4">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <MessageCircle class="size-4 text-primary" />
                            <h2 class="text-base font-bold text-foreground">
                                Upward A–Z Member, GC &amp; Dues Checklist
                            </h2>
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Target GC: <strong class="text-foreground">{{ activeCampus?.gc_group_name ?? 'Campus Official Peer GC' }}</strong> •
                            Accounted in GC: <strong class="text-foreground">{{ gcConfirmedCount }} / {{ approvedMembersAZ.length }}</strong> •
                            Dues Settled: <strong class="text-foreground">{{ duesConfirmedCount }} / {{ approvedMembersAZ.length }}</strong>
                        </p>
                    </div>

                    <!-- Client-side search & filter -->
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-muted-foreground" />
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search A–Z name, course, FB..."
                                class="rounded-lg border border-border bg-background py-1.5 pr-3 pl-8 text-sm text-foreground focus:border-primary focus:outline-none"
                            />
                        </div>

                        <select
                            v-model="gcFilter"
                            class="rounded-lg border border-border bg-background px-3 py-1.5 text-sm font-medium text-foreground focus:border-primary focus:outline-none"
                        >
                            <option value="all">All Verified ({{ approvedMembersAZ.length }})</option>
                            <option value="unconfirmed">Needs GC Check ({{ approvedMembersAZ.length - gcConfirmedCount }})</option>
                            <option value="confirmed">In Messenger GC ({{ gcConfirmedCount }})</option>
                        </select>

                        <Link
                            href="/alumni-archive"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-primary/35 bg-primary/10 px-3 py-1.5 text-sm font-bold text-primary hover:bg-primary hover:text-primary-foreground"
                        >
                            <Archive class="size-3.5" />
                            Former Members ({{ alumniCount ?? 0 }})
                        </Link>
                    </div>
                </div>

                <!-- Card-by-Card Upward Flow Stream (Sorted Strictly A-Z by Last Name, First Name, M.I.) -->
                <div class="mt-3 flex flex-col gap-2">
                    <div
                        v-for="(member, idx) in filteredMembersAZ"
                        :key="member.id"
                        class="group flex flex-col gap-2.5 rounded-xl border px-3 py-2.5 transition-[border-color,background-color,box-shadow] duration-200 hover:shadow-sm"
                        :class="
                            member.is_in_messenger_gc
                                ? 'border-[#1A8F26]/35 bg-background/85'
                                : 'border-amber-500/40 bg-card'
                        "
                    >
                        <div class="flex items-stretch gap-3">
                            <!-- Clickable Alphabetical Index Badge -> Minimal Member QR Card -->
                            <button
                                type="button"
                                @click="openMemberQr(member)"
                                :title="`View ${member.full_name_last_first_mi} Attendance QR Code`"
                                class="flex w-14 shrink-0 cursor-pointer flex-col items-center justify-center self-stretch rounded-lg py-1.5 font-bold shadow-2xs transition hover:scale-[1.02] hover:ring-2 hover:ring-primary/45 focus:ring-2 focus:ring-primary focus:outline-none sm:w-16"
                                :class="
                                    member.is_in_messenger_gc
                                        ? 'bg-[#1A8F26] text-white'
                                        : 'bg-secondary text-secondary-foreground'
                                "
                            >
                                <span class="text-base font-extrabold leading-none">{{ member.full_name_last_first_mi.charAt(0).toUpperCase() }}</span>
                                <span class="mt-1 text-xs leading-none opacity-85">#{{ idx + 1 }}</span>
                            </button>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-bold text-foreground">
                                        {{ member.full_name_last_first_mi }}
                                    </h3>
                                    <span
                                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                        :class="roleBadgeClasses(member.role)"
                                    >
                                        {{ member.role.toUpperCase() }}
                                    </span>
                                    <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-sm font-semibold text-primary">
                                        <template v-if="member.course">
                                            {{ member.course }} • Yr {{ member.year_level || '—' }} • Sec {{ member.section || '—' }}
                                        </template>
                                        <template v-else>
                                            {{ member.course_year_section }}
                                        </template>
                                    </span>
                                </div>

                                <div class="mt-1.5 grid grid-cols-1 gap-x-6 gap-y-0.5 text-sm text-muted-foreground md:grid-cols-2">
                                    <div class="min-w-0 truncate">
                                        <strong class="text-foreground">Facebook Link:</strong>
                                        <a
                                            v-if="member.facebook_profile_url"
                                            :href="member.facebook_profile_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="ml-1 inline-flex items-center gap-1 font-semibold text-primary underline hover:opacity-80"
                                        >
                                            View Facebook Profile
                                            <ExternalLink class="size-3" />
                                        </a>
                                        <span v-else class="ml-1">{{ member.active_fb_account }}</span>
                                    </div>
                                    <div
                                        v-if="member.active_fb_account && member.active_fb_account !== member.facebook_profile_url"
                                        class="min-w-0 truncate"
                                    >
                                        <strong class="text-foreground">FB Profile Note:</strong> {{ member.active_fb_account }}
                                    </div>
                                    <div class="min-w-0 truncate">
                                        <strong class="text-foreground">Email / Mobile:</strong> {{ member.active_email }} • {{ member.cellphone_number }}
                                    </div>
                                    <div class="min-w-0 truncate">
                                        <strong class="text-foreground">Sex / Identity:</strong> {{ member.sex }}
                                        <span v-if="member.gender_identity">({{ member.gender_identity }})</span>
                                        • <strong class="text-foreground">DOB:</strong> {{ member.birthdate }}
                                    </div>
                                    <div v-if="member.approver" class="min-w-0 truncate">
                                        <strong class="text-foreground">Verified by:</strong> {{ member.approver.name }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Static Bottom Action Toolbar: Governance Status Pill + GC Confirmation + Dues + Passkey + Former Transition -->
                        <div class="flex flex-wrap items-center gap-2 border-t border-border pt-2">
                            <!-- Read-only governance provenance pill (manual role overrides disabled) -->
                            <div v-if="permissions.isClubAdviser && member.role !== 'member'" class="flex items-center">
                                <span
                                    v-if="member.role === 'adviser'"
                                    class="rounded-lg border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
                                >
                                    Club Adviser • Use Handover Tab
                                </span>
                                <span
                                    v-else-if="member.role === 'guidance_counselor'"
                                    class="rounded-lg border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
                                >
                                    Assigned via Counselor Invite
                                </span>
                                <span
                                    v-else
                                    class="rounded-lg border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary"
                                >
                                    Elected via Assembly
                                </span>
                            </div>

                            <!-- Messenger GC Confirmation Button (Adviser, President, VP, or Secretary) -->
                            <button
                                type="button"
                                :disabled="!permissions.canConfirmMessengerGc"
                                @click="toggleGcChecklist(member)"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-[border-color,background-color,color] duration-200"
                                :class="[
                                    member.is_in_messenger_gc
                                        ? 'border border-[#1A8F26] bg-[#1A8F26] text-white'
                                        : 'border-[1.5px] border-[#FF8D06] bg-[#FF8D06] text-white hover:bg-background hover:text-[#FF8D06]',
                                    !permissions.canConfirmMessengerGc && 'cursor-not-allowed opacity-70',
                                ]"
                            >
                                <CheckCircle2 class="size-4" />
                                <span v-if="member.is_in_messenger_gc">
                                    In Messenger GC ✓
                                    <span v-if="member.gc_confirmer" class="font-normal opacity-90">
                                        (by {{ member.gc_confirmer.name.split(',')[0] }})
                                    </span>
                                </span>
                                <span v-else>
                                    Confirm Present in GC
                                </span>
                            </button>

                            <!-- Membership Dues Confirmation Button (Treasurer or Adviser) -->
                            <button
                                v-if="permissions.canConfirmDues"
                                type="button"
                                @click="toggleDuesChecklist(member)"
                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-[border-color,background-color,color] duration-200"
                                :class="
                                    member.has_paid_dues
                                        ? 'border border-[#1A8F26] bg-[#1A8F26]/10 text-[#1A8F26] hover:bg-[#1A8F26]/20'
                                        : 'border border-border bg-background text-foreground hover:border-primary hover:text-primary'
                                "
                            >
                                <Wallet class="size-4" />
                                <span>{{ member.has_paid_dues ? 'Dues Paid ✓' : 'Confirm Dues Paid' }}</span>
                            </button>
                            <span
                                v-else
                                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-semibold"
                                :class="member.has_paid_dues ? 'bg-[#1A8F26]/12 text-[#1A8F26]' : 'bg-secondary text-secondary-foreground'"
                            >
                                <Wallet class="size-3.5" />
                                {{ member.has_paid_dues ? 'Dues Paid ✓' : 'Dues Unpaid' }}
                            </span>

                            <!-- Passkey + 2FA Enrollment Code Issuance (Adviser, President, VP, Secretary) -->
                            <button
                                v-if="permissions.canIssuePasskeys"
                                type="button"
                                @click="issueMemberPasskey(member)"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-primary/35 bg-card px-3 py-1.5 text-sm font-semibold text-primary transition hover:bg-primary hover:text-primary-foreground"
                            >
                                <KeyRound class="size-3.5" />
                                Issue Passkey
                            </button>

                            <!-- Transition to Former Member / Officer Archive (Club Adviser only, non-Adviser members) -->
                            <button
                                v-if="permissions.isClubAdviser && member.role !== 'adviser'"
                                type="button"
                                @click="openAlumniModal(member)"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-sm font-semibold text-foreground transition hover:bg-foreground hover:text-background"
                            >
                                <GraduationCap class="size-3.5" />
                                Mark as Former
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 2A: OFFICIAL MEMBER PROFILE & ADVISER-APPROVED CHANGE REQUESTS  -->
        <!-- =================================================================== -->
        <div
            v-if="activeTab === 'profile' && isApprovedMember && myMembership"
            class="flex w-full max-w-none flex-col gap-3.5"
        >
            <div class="bento p-4">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-3">
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]">
                            <CheckCircle2 class="size-3.5" />
                            Official Verified CPF Member
                        </span>
                        <h2 class="mt-1 text-base font-bold text-foreground">
                            {{ myMembership.full_name_last_first_mi }}
                        </h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            You are already an official member — you never need to submit another membership application form.
                            To update any of your personal details below, submit an <strong class="text-foreground">Information Change Request</strong> for your <strong class="text-foreground">Club Adviser</strong> to review and approve.
                        </p>
                    </div>

                    <button
                        v-if="!hasPendingChangeRequest"
                        type="button"
                        @click="showChangeRequestForm = !showChangeRequestForm"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90"
                    >
                        <FilePenLine class="size-3.5" />
                        {{ showChangeRequestForm ? 'Cancel Request Form' : 'Request Information Change' }}
                    </button>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-3 py-1 text-xs font-bold text-amber-800"
                    >
                        <Clock class="size-3.5" />
                        Change Request Pending Adviser Approval
                    </span>
                </div>

                <ProfilePhotoUploader :compact="true" class="mt-4" />

                <!-- Read-Only Current Verified Record -->
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Full Name</dt>
                        <dd class="mt-0.5 font-bold text-foreground">{{ myMembership.full_name_last_first_mi }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Course / Year / Section</dt>
                        <dd class="mt-0.5 font-bold text-foreground">
                            <template v-if="myMembership.course">
                                {{ myMembership.course }} • Year {{ myMembership.year_level || '—' }} • Sec {{ myMembership.section || '—' }}
                            </template>
                            <template v-else>
                                {{ myMembership.course_year_section }}
                            </template>
                        </dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Active Email</dt>
                        <dd class="mt-0.5 font-semibold text-foreground">{{ myMembership.active_email }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Active Cellphone No.</dt>
                        <dd class="mt-0.5 font-semibold text-foreground">{{ myMembership.cellphone_number }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Birthdate &amp; Sex / Gender Identity</dt>
                        <dd class="mt-0.5 font-semibold text-foreground">
                            {{ myMembership.birthdate }} • {{ myMembership.sex }}
                            <span v-if="myMembership.gender_identity">({{ myMembership.gender_identity }})</span>
                        </dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Facebook Profile</dt>
                        <dd class="mt-0.5">
                            <a
                                v-if="myMembership.facebook_profile_url"
                                :href="myMembership.facebook_profile_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 font-semibold text-primary underline hover:opacity-80"
                            >
                                Open Facebook Profile
                                <ExternalLink class="size-3" />
                            </a>
                            <span v-else class="font-semibold text-foreground">{{ myMembership.active_fb_account || '—' }}</span>
                        </dd>
                    </div>
                </dl>

                <!-- Request Information Change Form -->
                <form
                    v-if="showChangeRequestForm && !hasPendingChangeRequest"
                    @submit.prevent="submitChangeRequest"
                    class="mt-4 flex flex-col gap-3.5 border-t border-border pt-4"
                >
                    <div>
                        <h3 class="text-base font-bold text-foreground">
                            Request Profile Information Update (Requires Club Adviser Approval)
                        </h3>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Edit only the fields that need updating and provide a short reason. Your record will stay unchanged until your Club Adviser approves the request.
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="chg_first_name">First Name *</Label>
                            <Input id="chg_first_name" v-model="changeRequestForm.first_name" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.first_name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_middle_name">Middle Name / M.I.</Label>
                            <Input id="chg_middle_name" v-model="changeRequestForm.middle_name" class="bg-background" />
                            <InputError :message="changeRequestForm.errors.middle_name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_last_name">Last Name *</Label>
                            <Input id="chg_last_name" v-model="changeRequestForm.last_name" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.last_name" />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="chg_course">Course / Program *</Label>
                            <Input id="chg_course" v-model="changeRequestForm.course" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.course" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_year">Year Level *</Label>
                            <Input id="chg_year" v-model="changeRequestForm.year_level" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.year_level" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_section">Section *</Label>
                            <Input id="chg_section" v-model="changeRequestForm.section" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.section" />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="chg_email">Active Email *</Label>
                            <Input id="chg_email" v-model="changeRequestForm.active_email" type="email" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.active_email" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_phone">Active Cellphone No. *</Label>
                            <Input id="chg_phone" v-model="changeRequestForm.cellphone_number" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.cellphone_number" />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="grid gap-1.5">
                            <Label for="chg_fb_url">Facebook Profile Link *</Label>
                            <Input id="chg_fb_url" v-model="changeRequestForm.facebook_profile_url" type="url" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.facebook_profile_url" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_fb_note">FB Account Name / Note</Label>
                            <Input id="chg_fb_note" v-model="changeRequestForm.active_fb_account" class="bg-background" />
                            <InputError :message="changeRequestForm.errors.active_fb_account" />
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-1.5">
                            <Label for="chg_birthdate">Birthdate *</Label>
                            <Input id="chg_birthdate" v-model="changeRequestForm.birthdate" type="date" required class="bg-background" />
                            <InputError :message="changeRequestForm.errors.birthdate" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_sex">Sex *</Label>
                            <select
                                id="chg_sex"
                                v-model="changeRequestForm.sex"
                                class="h-9 rounded-lg border border-border bg-background px-3 py-1 text-sm text-foreground"
                            >
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <InputError :message="changeRequestForm.errors.sex" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="chg_gender">Gender Identity</Label>
                            <select
                                id="chg_gender"
                                v-model="changeRequestForm.gender_identity"
                                class="h-9 rounded-lg border border-border bg-background px-3 py-1 text-sm text-foreground"
                            >
                                <option v-for="option in ['Man', 'Woman', 'Non-binary', 'Prefer not to say', 'Others']" :key="option" :value="option">
                                    {{ option }}
                                </option>
                            </select>
                            <InputError :message="changeRequestForm.errors.gender_identity" />
                        </div>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="chg_reason">Reason for Requested Change *</Label>
                        <textarea
                            id="chg_reason"
                            v-model="changeRequestForm.reason"
                            rows="2"
                            required
                            placeholder="e.g. Promoted to 4th Year, moved to Section B, or updated active cellphone number."
                            class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-primary focus:outline-none"
                        />
                        <InputError :message="changeRequestForm.errors.reason" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            @click="showChangeRequestForm = false"
                            class="rounded-lg border border-border bg-card px-3.5 py-2 text-sm font-semibold text-foreground"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="changeRequestForm.processing"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition hover:opacity-90 disabled:opacity-60"
                        >
                            <FilePenLine class="size-3.5" />
                            {{ changeRequestForm.processing ? 'Submitting...' : 'Submit Change Request to Adviser' }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- Member's Change Request History -->
            <div
                v-if="myChangeRequests.length > 0"
                class="bento p-4"
            >
                <h3 class="text-base font-bold text-foreground">
                    My Information Change Requests
                </h3>
                <div class="mt-2.5 flex flex-col gap-2.5">
                    <div
                        v-for="req in myChangeRequests"
                        :key="`my-chg-${req.id}`"
                        class="rounded-xl border border-border bg-background/80 p-3.5 text-sm"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-bold text-foreground">{{ req.reason }}</span>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs font-bold uppercase"
                                :class="
                                    req.status === 'approved'
                                        ? 'bg-[#1A8F26]/12 text-[#1A8F26]'
                                        : req.status === 'declined'
                                          ? 'bg-red-500/15 text-red-700'
                                          : 'bg-amber-500/15 text-amber-800'
                                "
                            >
                                {{ req.status === 'pending' ? 'Pending Adviser Review' : req.status }}
                            </span>
                        </div>
                        <div class="mt-2 space-y-1">
                            <div
                                v-for="(diff, fieldKey) in req.requested_changes"
                                :key="`my-diff-${req.id}-${fieldKey}`"
                                class="flex flex-wrap items-center gap-1.5 text-xs"
                            >
                                <span class="font-semibold text-foreground">{{ formatFieldLabel(String(fieldKey)) }}:</span>
                                <span class="text-muted-foreground line-through">{{ diff.old ?? '—' }}</span>
                                <span class="text-primary">&rarr;</span>
                                <span class="font-semibold text-foreground">{{ diff.new ?? '—' }}</span>
                            </div>
                        </div>
                        <p v-if="req.adviser_notes" class="mt-2 rounded-lg bg-card px-3 py-1.5 text-xs text-foreground">
                            <strong>Adviser Note:</strong> {{ req.adviser_notes }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 2B: OFFICIAL CPF ONLINE MEMBERSHIP FORM (NEW / PENDING ONLY)    -->
        <!-- =================================================================== -->
        <div
            v-if="activeTab === 'apply' && !isApprovedMember"
            class="bento w-full max-w-none p-4"
        >
            <!-- Pending Application Status Card (Prevents Duplicate Form Submissions) -->
            <div v-if="isPendingApplicant && myMembership" class="space-y-3.5">
                <div class="border-b border-border pb-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/15 px-2.5 py-0.5 text-xs font-bold text-amber-800">
                        <Clock class="size-3.5" />
                        Application Submitted • Pending Officer Verification
                    </span>
                    <h2 class="mt-1 text-base font-bold text-foreground">
                        Your CPF Membership Application is Under Review
                    </h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        You do not need to submit another form. Once your campus Adviser, President, or Vice President verifies your application, you will receive your locked 12-character <strong class="text-foreground">Passkey Code</strong> to sign in and set your personal password.
                    </p>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Applicant Name</dt>
                        <dd class="mt-0.5 font-bold text-foreground">{{ myMembership.full_name_last_first_mi }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Course / Year / Section</dt>
                        <dd class="mt-0.5 font-bold text-foreground">{{ myMembership.course_year_section }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Active Email</dt>
                        <dd class="mt-0.5 font-semibold text-foreground">{{ myMembership.active_email }}</dd>
                    </div>
                    <div class="rounded-xl border border-border bg-background/75 p-3.5">
                        <dt class="text-xs font-semibold text-muted-foreground">Cellphone</dt>
                        <dd class="mt-0.5 font-semibold text-foreground">{{ myMembership.cellphone_number }}</dd>
                    </div>
                </dl>
            </div>

            <template v-else>
            <div class="border-b border-border pb-3">
                <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary">
                    Official CPF Online Membership Form
                </span>
                <h2 class="mt-1 text-base font-bold text-foreground">
                    Personal Information
                </h2>
                <p class="mt-0.5 text-sm italic text-muted-foreground">
                    Your response helps us respect and recognize your identity. All information is kept confidential.
                </p>
            </div>

            <form @submit.prevent="submitOnlineForm" class="mt-4 flex flex-col gap-4">
                <!-- Campus Selection -->
                <div class="grid gap-2">
                    <Label for="apply_campus">CHMSU Campus <span class="text-red-600">*</span></Label>
                    <select
                        id="apply_campus"
                        v-model="onlineForm.campus_id"
                        required
                        class="rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground focus:border-primary focus:outline-none"
                    >
                        <option v-for="c in campuses" :key="c.id" :value="c.id">
                            {{ c.name }} (Adviser: {{ c.adviser_name }})
                        </option>
                    </select>
                    <InputError :message="onlineForm.errors.campus_id" />
                </div>

                <!-- Field 1 (Screenshot 3): Email Checkbox -->
                <div class="rounded-xl border border-border bg-background/75 p-3.5">
                    <Label class="text-sm font-bold text-foreground">
                        Email <span class="text-red-600">*</span>
                    </Label>
                    <label class="mt-2 flex cursor-pointer items-center gap-2.5 text-sm text-muted-foreground">
                        <input
                            v-model="recordAccountEmail"
                            type="checkbox"
                            required
                            class="size-4 rounded border-border text-primary"
                        />
                        <span>
                            Record <strong class="text-foreground">{{ fallbackApplicantEmail }}</strong> as the email to be included with my response
                        </span>
                    </label>
                    <InputError :message="onlineForm.errors.recorded_email" />
                </div>

                <!-- Field 2 (FR-01): Atomic Name (First, Middle/M.I., Last) -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="online_first_name">
                            First Name <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="online_first_name"
                            v-model="onlineForm.first_name"
                            type="text"
                            required
                            placeholder="e.g. Paul Felippe"
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.first_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="online_middle_name">Middle Name / M.I.</Label>
                        <Input
                            id="online_middle_name"
                            v-model="onlineForm.middle_name"
                            type="text"
                            placeholder="e.g. G."
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.middle_name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="online_last_name">
                            Last Name <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="online_last_name"
                            v-model="onlineForm.last_name"
                            type="text"
                            required
                            placeholder="e.g. Tibo-oc"
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.last_name" />
                    </div>
                </div>

                <!-- Field 3: Separate Course, Year Level & Section -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="online_course">
                            Course / Program <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="online_course"
                            v-model="onlineForm.course"
                            type="text"
                            required
                            placeholder="e.g. BSIT"
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.course" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="online_year_level">
                            Year Level <span class="text-red-600">*</span>
                        </Label>
                        <select
                            id="online_year_level"
                            v-model="onlineForm.year_level"
                            required
                            class="h-9 rounded-lg border border-border bg-background px-3 py-1 text-sm text-foreground"
                        >
                            <option value="" disabled>Select Year</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                            <option value="5">5th Year</option>
                        </select>
                        <InputError :message="onlineForm.errors.year_level" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="online_section">
                            Section <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="online_section"
                            v-model="onlineForm.section"
                            type="text"
                            required
                            placeholder="e.g. A"
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.section" />
                    </div>
                </div>

                <!-- Field 4 (Screenshot 2): Sex (for legal/medical records) -->
                <div class="rounded-xl border border-border bg-background/75 p-3.5">
                    <Label class="text-sm font-bold text-foreground">
                        Sex: <span class="font-normal italic text-muted-foreground">(for legal/medical records)</span>
                        <span class="text-red-600">*</span>
                    </Label>
                    <div class="mt-2.5 flex flex-wrap gap-4 text-sm">
                        <label class="flex cursor-pointer items-center gap-2">
                            <input v-model="onlineForm.sex" type="radio" value="Male" required />
                            <span>Male</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2">
                            <input v-model="onlineForm.sex" type="radio" value="Female" required />
                            <span>Female</span>
                        </label>
                    </div>
                    <InputError :message="onlineForm.errors.sex" />
                </div>

                <!-- Field 5 (Screenshot 2): Gender Identity (how you identify yourself) -->
                <div class="rounded-xl border border-border bg-background/75 p-3.5">
                    <Label class="text-sm font-bold text-foreground">
                        Gender Identity: <span class="font-normal italic text-muted-foreground">(how you identify yourself)</span>
                    </Label>
                    <div class="mt-2.5 flex flex-wrap gap-4 text-sm">
                        <label
                            v-for="option in ['Man', 'Woman', 'Non-binary', 'Prefer not to say', 'Others']"
                            :key="option"
                            class="flex cursor-pointer items-center gap-2"
                        >
                            <input v-model="onlineForm.gender_identity" type="radio" :value="option" />
                            <span>{{ option }}</span>
                        </label>
                    </div>
                    <div v-if="onlineForm.gender_identity === 'Others'" class="mt-3">
                        <Input
                            v-model="onlineForm.gender_identity_other"
                            type="text"
                            placeholder="Please specify how you identify yourself"
                            class="bg-card"
                        />
                    </div>
                    <InputError :message="onlineForm.errors.gender_identity" />
                </div>

                <!-- Field 6 & 7: Birthdate + Active Email + Phone -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="birthdate">
                            Birthdate <span class="font-normal italic text-muted-foreground">(M/D/Y)</span>
                            <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="birthdate"
                            v-model="onlineForm.birthdate"
                            type="date"
                            required
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.birthdate" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="active_email">
                            Active Email Address <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="active_email"
                            v-model="onlineForm.active_email"
                            type="email"
                            required
                            placeholder="your.active.email@gmail.com"
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.active_email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="cellphone_number">
                            Active Cellphone No. <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="cellphone_number"
                            v-model="onlineForm.cellphone_number"
                            type="text"
                            required
                            placeholder="e.g. 0917-123-4567"
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.cellphone_number" />
                    </div>
                </div>

                <!-- Field 8: Clickable Facebook Profile Link (Required) & Optional Note -->
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="online_fb_url">
                            Facebook Profile Link:
                            <span class="text-red-600">*</span>
                        </Label>
                        <Input
                            id="online_fb_url"
                            v-model="onlineForm.facebook_profile_url"
                            type="url"
                            required
                            :placeholder="facebookUrlPlaceholder"
                            class="bg-background"
                        />
                        <p class="text-xs text-muted-foreground">
                            Shown as a clickable link to the President and Club Adviser when reviewing your application.
                        </p>
                        <InputError :message="onlineForm.errors.facebook_profile_url" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="active_fb_account">
                            Active FB Account Name / Photo Note:
                            <span class="font-normal italic text-muted-foreground">(Optional)</span>
                        </Label>
                        <Input
                            id="active_fb_account"
                            v-model="onlineForm.active_fb_account"
                            type="text"
                            placeholder="e.g. Juan Dela Cruz (Wearing CHMSU green lanyard)"
                            class="bg-background"
                        />
                        <InputError :message="onlineForm.errors.active_fb_account" />
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-1">
                    <button
                        type="submit"
                        :disabled="onlineForm.processing"
                        class="bento-btn"
                    >
                        Submit CPF Membership Form
                    </button>
                </div>
            </form>
            </template>
        </div>

        <!-- =================================================================== -->
        <!-- TAB 3: DIRECT MEMBER ADDITION (ADVISER, PRESIDENT & VICE PRESIDENT) -->
        <!-- =================================================================== -->
        <OfficerAddMemberForm
            v-if="activeTab === 'add' && permissions.canApproveMembers"
            :campuses="campuses"
            :active-campus="activeCampus"
            :available-roles="availableRoles"
            :is-club-adviser="permissions.isClubAdviser"
            :verified-role-label="permissions.verifiedRoleLabel"
            @added="activeTab = 'checklist'"
        />

        <!-- =================================================================== -->
        <!-- TAB 5: CLUB ADVISER ROLE HANDOVER & SUCCESSION (LOCKOUT-SAFE)       -->
        <!-- =================================================================== -->
        <AdviserHandoverPanel
            v-if="activeTab === 'handover' && permissions.isClubAdviser"
            :is-club-adviser="permissions.isClubAdviser"
            :show-initiate-form="true"
        />

        <!-- Passkey + 2FA Enrollment Code Issuance Modal (FR-03) -->
        <div
            v-if="passkeyModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-md"
            @click.self="passkeyModalOpen = false"
        >
            <div class="bento w-full max-w-md p-4">
                <div class="mb-3 flex items-center justify-between border-b border-border pb-2.5">
                    <div class="flex items-center gap-2">
                        <KeyRound class="size-4 text-primary" />
                        <h3 class="font-editorial text-base font-bold text-foreground">
                            Passkey Enrollment Code
                        </h3>
                    </div>
                    <button
                        type="button"
                        @click="passkeyModalOpen = false"
                        class="rounded-lg border border-border bg-card p-1 text-muted-foreground hover:text-foreground"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div v-if="issuingPasskey" class="py-6 text-center text-sm font-semibold text-muted-foreground">
                    Loading locked passkey code...
                </div>

                <div v-else-if="passkeyError" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">
                    {{ passkeyError }}
                </div>

                <div v-else-if="issuedPasskey" class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-muted-foreground">
                            Passkey for <strong class="text-foreground">{{ issuedPasskey.memberName }}</strong>
                        </p>
                        <span class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]">
                            <Lock class="size-3" />
                            Locked
                        </span>
                    </div>

                    <div class="rounded-xl border border-primary/30 bg-background p-3.5 text-center">
                        <div class="text-xs font-semibold text-muted-foreground uppercase">
                            Active Locked Passkey Code
                        </div>
                        <div class="mt-2 flex flex-wrap items-center justify-center gap-1">
                            <template v-for="(chunk, cIdx) in issuedPasskey.enrollment_code.split('-')" :key="`chunk-${cIdx}`">
                                <span v-if="cIdx > 0" class="px-0.5 font-mono text-sm font-bold text-muted-foreground">-</span>
                                <span
                                    v-for="(ch, i) in chunk.split('')"
                                    :key="`ch-${cIdx}-${i}`"
                                    class="inline-flex h-8 w-6 items-center justify-center rounded-md border border-primary/30 bg-card font-mono text-sm font-extrabold text-primary shadow-2xs"
                                >
                                    {{ ch }}
                                </span>
                            </template>
                        </div>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Locked so re-opening cannot accidentally change it. Members enter this code on the Login -> Passkey Code tab.
                        </p>
                    </div>

                    <div
                        v-if="passkeyStatusNote"
                        class="rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3 py-2 text-xs font-semibold text-[#1A8F26]"
                    >
                        {{ passkeyStatusNote }}
                    </div>

                    <div class="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                        <span>2FA Required: <strong class="text-foreground">{{ issuedPasskey.require_2fa ? 'Yes (TOTP)' : 'Optional' }}</strong></span>
                        <span>Expires: <strong class="text-foreground">{{ issuedPasskey.expires_at }}</strong></span>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                    <div v-if="issuedPasskey" class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            @click="copyIssuedPasskey"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-sm font-semibold text-foreground hover:border-primary"
                        >
                            <Copy class="size-3.5" />
                            {{ passkeyCopied ? 'Copied ✓' : 'Copy' }}
                        </button>
                        <button
                            type="button"
                            :disabled="regeneratingPasskey"
                            @click="regenerateMemberPasskey"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-primary/40 bg-primary/10 px-3 py-1.5 text-sm font-semibold text-primary transition hover:bg-primary hover:text-primary-foreground disabled:opacity-60"
                        >
                            <RefreshCw class="size-3.5" :class="{ 'animate-spin': regeneratingPasskey }" />
                            {{ regeneratingPasskey ? 'Generating...' : 'Generate New Passkey' }}
                        </button>
                    </div>
                    <button type="button" class="bento-btn ml-auto" @click="passkeyModalOpen = false">
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- Minimal Member Attendance QR Card Modal (QR, Name, Course, Year & Section ONLY) -->
        <div
            v-if="qrModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4 backdrop-blur-md"
            @click.self="qrModalOpen = false"
        >
            <div class="bento mx-auto flex w-full max-w-sm flex-col items-center justify-center p-4 text-center">
                <div class="mb-3 flex w-full items-center justify-between border-b border-border pb-2.5">
                    <div class="flex items-center gap-2 text-base font-bold text-primary">
                        <QrCode class="size-4" />
                        <span>Member Attendance QR</span>
                    </div>
                    <button
                        type="button"
                        @click="qrModalOpen = false"
                        class="rounded-lg border border-border bg-card p-1 text-muted-foreground hover:text-foreground"
                        aria-label="Close Member QR Modal"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div v-if="loadingMemberQr" class="py-8 text-sm font-semibold text-muted-foreground">
                    Loading Attendance QR Code...
                </div>

                <div
                    v-else-if="memberQrError"
                    class="w-full rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700"
                >
                    {{ memberQrError }}
                </div>

                <div v-else-if="memberQrCard" class="mx-auto flex w-full flex-col items-center justify-center gap-3">
                    <div
                        class="cpf-qr-surface mx-auto flex aspect-square w-[min(72vw,240px)] items-center justify-center rounded-xl border border-border bg-white p-3.5 shadow-sm sm:w-60"
                    >
                        <img
                            :src="'data:image/svg+xml;utf8,' + encodeURIComponent(memberQrCard.qr_svg)"
                            alt="Official Peer QR Code"
                            class="block h-full w-full"
                        />
                    </div>

                    <div class="space-y-0.5">
                        <h3 class="font-editorial text-base font-bold text-foreground">
                            {{ memberQrCard.full_name }}
                        </h3>
                        <p class="text-sm font-semibold text-primary">
                            <template v-if="memberQrCard.course">
                                {{ memberQrCard.course }} • Year {{ memberQrCard.year_level || '—' }} • Section {{ memberQrCard.section || '—' }}
                            </template>
                            <template v-else>
                                {{ memberQrCard.course_year_section }}
                            </template>
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex justify-center">
                    <button type="button" class="bento-btn px-5" @click="qrModalOpen = false">
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- Transition Member / Officer to Former Members & Legacy Archive Modal -->
        <div
            v-if="alumniModalOpen && alumniTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4 backdrop-blur-md"
            @click.self="alumniModalOpen = false"
        >
            <div class="bento w-full max-w-md p-4 text-sm">
                <div class="mb-3 flex items-center justify-between border-b border-border pb-2.5">
                    <div class="flex items-center gap-2 text-base font-bold text-foreground">
                        <GraduationCap class="size-4 text-primary" />
                        <span>Mark as Former Member / Officer</span>
                    </div>
                    <button
                        type="button"
                        @click="alumniModalOpen = false"
                        class="rounded-lg border border-border bg-card p-1 text-muted-foreground hover:text-foreground"
                        aria-label="Close Transition Modal"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <p class="text-muted-foreground">
                    Moving <strong class="text-foreground">{{ alumniTarget.full_name_last_first_mi }}</strong> ({{ alumniTarget.role.toUpperCase() }}) preserves their account with <strong class="text-foreground">verified club tenure</strong>, automatically titled by the highest position they ever held (e.g., <strong class="text-foreground">Former President</strong> or <strong class="text-foreground">Former Member</strong>), with read-only access to Awards, Time Capsules, Archived GCs, and zero active permissions.
                </p>

                <div
                    v-if="alumniModalError"
                    class="mt-3 rounded-xl border border-red-200 bg-red-50 p-2.5 text-sm font-semibold text-red-700"
                >
                    {{ alumniModalError }}
                </div>

                <form class="mt-3.5 space-y-3" @submit.prevent="submitAlumniTransition">
                    <div>
                        <label class="mb-1 block font-bold text-foreground">Transition Reason</label>
                        <select
                            v-model="alumniReason"
                            class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-foreground"
                        >
                            <option value="graduated">Graduated</option>
                            <option value="term_completed">Completed Officer Term</option>
                            <option value="stepped_down">Stepped Down</option>
                            <option value="transferred">Transferred Campus / Program</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block font-bold text-foreground">Archive Note (Optional)</label>
                        <input
                            v-model="alumniRemarks"
                            placeholder="e.g. Served faithfully during AY 2025–2026"
                            class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground"
                        />
                    </div>

                    <div class="flex justify-end gap-2 pt-1">
                        <button
                            type="button"
                            @click="alumniModalOpen = false"
                            class="rounded-lg border border-border bg-card px-3.5 py-2 text-sm font-semibold text-foreground"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="transitioningAlumni"
                            class="bento-btn"
                        >
                            {{ transitioningAlumni ? 'Transitioning...' : 'Confirm Transition' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
