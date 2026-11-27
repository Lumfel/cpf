<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Award,
    BadgeCheck,
    Bell,
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    Copy,
    ExternalLink,
    KeyRound,
    Lock,
    Megaphone,
    MessageCircle,
    Pin,
    QrCode,
    RefreshCw,
    Search,
    ShieldAlert,
    Sparkles,
    Trash2,
    UserPlus,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EventCalendarGrid from '@/components/events/EventCalendarGrid.vue';
import type { CampusEventSummary } from '@/components/events/EventCalendarGrid.vue';
import AdviserHandoverPanel from '@/components/membership/AdviserHandoverPanel.vue';
import { useInitials } from '@/composables/useInitials';
import { apiRequest } from '@/lib/csrfFetch';
import { dashboard } from '@/routes';
import { index as membershipIndex } from '@/routes/membership';
import type { Auth } from '@/types';

interface DuesRosterMember {
    id: number;
    full_name_last_first_mi: string;
    course: string | null;
    year_level: string | null;
    section: string | null;
    course_year_section: string;
    role: string;
    facebook_profile_url: string | null;
    has_paid_dues: boolean;
    dues_confirmed_at: string | null;
    dues_confirmer_name: string | null;
}

interface PaymentSubmissionQueueItem {
    id: number;
    user_id: number;
    member_name: string;
    course_year_section: string;
    channel: string;
    purpose: string;
    amount: number;
    reference_number: string;
    has_receipt: boolean;
    receipt_url: string | null;
    status: 'pending' | 'validated' | 'rejected';
    treasurer_notes: string | null;
    created_at: string | null;
}

interface OfficerGcMessage {
    id: number;
    sender_user_id: number;
    sender_role: string;
    message_body: string;
    created_at: string | null;
    sender?: {
        id: number;
        name: string;
    } | null;
}

interface EventNotificationItem {
    id: number;
    campus_event_id: number;
    type: string;
    title: string;
    message: string;
    is_unread: boolean;
    created_at: string | null;
}

interface AnnouncementTemplateItem {
    key: string;
    label: string;
    default_title: string;
    default_body: string;
    default_schedule_label: string;
    default_location: string;
}

interface CampusAnnouncementItem {
    id: number;
    template_key: string;
    template_label: string;
    title: string;
    body: string;
    schedule_label: string | null;
    location: string | null;
    is_pinned: boolean;
    publisher_name: string;
    publisher_role: string;
    publisher_role_label: string;
    published_at: string | null;
    published_diff: string | null;
}

const props = withDefaults(
    defineProps<{
        isAlumni?: boolean;
        isGuidanceCounselor?: boolean;
        canPublishAnnouncements?: boolean;
        announcementTemplates?: AnnouncementTemplateItem[];
        campusAnnouncements?: CampusAnnouncementItem[];
        myTenureSummary?: {
            first_joined_at: string;
            served_until: string;
            total_duration_label: string;
            academic_years: string[];
            is_fully_verified: boolean;
            events_attended_count: number;
            awards_count: number;
        } | null;
        qrUnlocked: boolean;
        memberIdCode: string | null;
        qrSvg: string | null;
        qrPayload?: string | null;
        dailyQuote: {
            id: number;
            quote_text: string;
            author_name: string;
            mood_tag: string;
        };
        canConfirmDues: boolean;
        canAuditFinances: boolean;
        isOfficerOrAdviser: boolean;
        duesRoster: DuesRosterMember[];
        paymentValidationQueue?: PaymentSubmissionQueueItem[];
        campusEvents?: CampusEventSummary[];
        eventNotifications?: EventNotificationItem[];
        unreadEventNotificationsCount?: number;
        officerGcChannel: {
            id: number;
        } | null;
        officerGcMessages: OfficerGcMessage[];
        myDuesStatus: {
            has_paid_dues: boolean;
            dues_confirmed_at: string | null;
            dues_confirmer_name: string | null;
        } | null;
        myPasskey: {
            enrollment_code: string | null;
            is_locked: boolean;
            expires_at: string | null;
        } | null;
    }>(),
    {
        isAlumni: false,
        isGuidanceCounselor: false,
        canPublishAnnouncements: false,
        announcementTemplates: () => [],
        campusAnnouncements: () => [],
        myTenureSummary: null,
        qrPayload: null,
        paymentValidationQueue: () => [],
        campusEvents: () => [],
        eventNotifications: () => [],
        unreadEventNotificationsCount: 0,
    },
);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'CPF Peer Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const page = usePage<{ auth: Auth; flash?: { status?: string } }>();
const auth = computed(() => page.props.auth);
const flashStatus = computed(() => page.props.flash?.status);
const { getInitials } = useInitials();

const duesSearch = ref('');
const duesFilter = ref<'all' | 'unpaid' | 'paid'>('all');

// Attendance QR Copy & Dashboard Event Calendar State
const qrPayloadCopied = ref(false);
const selectedDashboardEvent = ref<CampusEventSummary | null>(props.campusEvents[0] ?? null);

async function copySignedQrPayload() {
    if (!props.qrPayload || typeof navigator === 'undefined' || !navigator.clipboard) return;
    await navigator.clipboard.writeText(props.qrPayload);
    qrPayloadCopied.value = true;
    setTimeout(() => {
        qrPayloadCopied.value = false;
    }, 2000);
}

async function markDashboardNotificationRead(id: number) {
    const { ok } = await apiRequest(`/events-activities/notifications/${id}/read`, 'PATCH', {});
    if (ok) {
        router.reload({ only: ['eventNotifications', 'unreadEventNotificationsCount'] });
    }
}

async function markAllDashboardNotificationsRead() {
    const { ok } = await apiRequest('/events-activities/notifications/read-all', 'POST', {});
    if (ok) {
        router.reload({ only: ['eventNotifications', 'unreadEventNotificationsCount'] });
    }
}

// Real-time Personal Locked Passkey Code management
const livePasskey = ref(props.myPasskey);
const regeneratingMyPasskey = ref(false);
const myPasskeyNotice = ref<string | null>(null);
const myPasskeyCopied = ref(false);

async function generateMyPasskey(forceRegenerate: boolean) {
    regeneratingMyPasskey.value = true;
    myPasskeyNotice.value = null;
    myPasskeyCopied.value = false;

    const { ok, data } = await apiRequest<{
        enrollment_code: string;
        is_locked: boolean;
        regenerated: boolean;
        expires_at: string | null;
    }>('/my-passkey/regenerate', 'POST', { regenerate: forceRegenerate });

    regeneratingMyPasskey.value = false;

    if (!ok) {
        myPasskeyNotice.value = data.message ?? 'Unable to generate passkey code.';
        return;
    }

    livePasskey.value = {
        enrollment_code: data.enrollment_code,
        is_locked: data.is_locked,
        expires_at: data.expires_at,
    };
    myPasskeyNotice.value = forceRegenerate
        ? 'New passkey generated & locked in real time — your previous passkey is now revoked.'
        : 'Passkey generated & locked.';
}

async function copyMyPasskey() {
    if (!livePasskey.value?.enrollment_code || typeof navigator === 'undefined' || !navigator.clipboard) return;
    await navigator.clipboard.writeText(livePasskey.value.enrollment_code);
    myPasskeyCopied.value = true;
    setTimeout(() => {
        myPasskeyCopied.value = false;
    }, 2000);
}

const paidDuesCount = computed(
    () => props.duesRoster.filter((member) => member.has_paid_dues).length,
);
const pendingPaymentsCount = computed(
    () => props.paymentValidationQueue.filter((item) => item.status === 'pending').length,
);
const validatingPaymentId = ref<number | null>(null);
const paymentQueueNotice = ref<string | null>(null);

async function reviewPaymentSubmission(item: PaymentSubmissionQueueItem, status: 'validated' | 'rejected') {
    if (!props.canConfirmDues || validatingPaymentId.value !== null) return;
    validatingPaymentId.value = item.id;
    paymentQueueNotice.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/payments/${item.id}/validate`,
        'PATCH',
        { status },
    );

    validatingPaymentId.value = null;

    if (!ok) {
        paymentQueueNotice.value = data.message ?? 'Unable to update payment submission.';
        return;
    }

    paymentQueueNotice.value =
        status === 'validated'
            ? `Validated receipt #${item.reference_number} for ${item.member_name} and updated dues checklist.`
            : `Marked receipt #${item.reference_number} for ${item.member_name} as rejected.`;
    router.reload({ only: ['duesRoster', 'paymentValidationQueue'] });
}

// PIO + Secretary (+ Adviser) Templated Announcements Composer State
const showAnnouncementComposer = ref(false);
const selectedTemplateKey = ref(props.announcementTemplates[0]?.key ?? 'general_assembly');
const annTitle = ref(props.announcementTemplates[0]?.default_title ?? '');
const annBody = ref(props.announcementTemplates[0]?.default_body ?? '');
const annSchedule = ref(props.announcementTemplates[0]?.default_schedule_label ?? '');
const annLocation = ref(props.announcementTemplates[0]?.default_location ?? '');
const annPinned = ref(false);
const publishingAnnouncement = ref(false);
const announcementNotice = ref<string | null>(null);

function applyAnnouncementTemplate(tpl: AnnouncementTemplateItem) {
    selectedTemplateKey.value = tpl.key;
    annTitle.value = tpl.default_title;
    annBody.value = tpl.default_body;
    annSchedule.value = tpl.default_schedule_label;
    annLocation.value = tpl.default_location;
}

async function publishCampusAnnouncement() {
    if (publishingAnnouncement.value) return;
    publishingAnnouncement.value = true;
    announcementNotice.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>('/announcements', 'POST', {
        template_key: selectedTemplateKey.value,
        title: annTitle.value,
        body: annBody.value,
        schedule_label: annSchedule.value || null,
        location: annLocation.value || null,
        is_pinned: annPinned.value,
    });

    publishingAnnouncement.value = false;

    if (!ok) {
        announcementNotice.value = data.message ?? 'Unable to publish announcement.';
        return;
    }

    showAnnouncementComposer.value = false;
    annPinned.value = false;
    announcementNotice.value = 'Official campus announcement published!';
    router.reload({ only: ['campusAnnouncements'] });
}

async function removeCampusAnnouncement(id: number) {
    const { ok } = await apiRequest(`/announcements/${id}`, 'DELETE');
    if (ok) {
        router.reload({ only: ['campusAnnouncements'] });
    }
}

const filteredDuesRoster = computed(() => {
    return props.duesRoster.filter((member) => {
        const matchesStatus =
            duesFilter.value === 'all' ||
            (duesFilter.value === 'paid' && member.has_paid_dues) ||
            (duesFilter.value === 'unpaid' && !member.has_paid_dues);

        if (!matchesStatus) return false;

        if (!duesSearch.value.trim()) return true;
        const q = duesSearch.value.toLowerCase();
        return (
            member.full_name_last_first_mi.toLowerCase().includes(q) ||
            (member.course ?? '').toLowerCase().includes(q) ||
            member.course_year_section.toLowerCase().includes(q)
        );
    });
});

function toggleDuesStatus(member: DuesRosterMember) {
    if (!props.canConfirmDues) return;

    router.patch(
        `/membership/${member.id}/toggle-dues`,
        {},
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <Head title="CPF Peer Dashboard" />

    <div class="flex h-full w-full max-w-none flex-1 flex-col gap-3.5 overflow-x-auto bg-background p-3 text-foreground md:p-4">
        <!-- Welcome & Quick Navigation Header -->
        <div class="bento p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                        Circle of Peer Facilitators (CPF) • Multi-Campus Core
                    </span>
                    <h1 class="font-editorial mt-1 text-xl font-bold tracking-tight text-foreground md:text-2xl">
                        Welcome, {{ auth.user?.name ?? 'Peer Facilitator' }}
                    </h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Active Role:
                        <strong class="text-foreground">{{ auth.roleLabel ?? 'Ordinary Account' }}</strong>
                        <span v-if="auth.membership?.campus">
                            • {{ auth.membership.campus.name }}
                        </span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <template v-if="isAlumni || auth.isAlumni">
                        <Link
                            href="/alumni-archive"
                            class="inline-flex items-center gap-1.5 rounded-lg border-[1.5px] border-[#0384C5] bg-[#0384C5] px-3.5 py-2 text-sm font-semibold text-white transition-colors duration-200 hover:bg-[#026da3]"
                        >
                            <Archive class="size-4" />
                            Former Members &amp; Archive
                        </Link>

                        <Link
                            href="/my-credentials"
                            class="inline-flex items-center gap-1.5 rounded-lg border-[1.5px] border-[#FF8D06] bg-card px-3.5 py-2 text-sm font-semibold text-[#FF8D06] transition-colors duration-200 hover:bg-[#FF8D06] hover:text-white"
                        >
                            <Award class="size-4" />
                            My Achievements &amp; Awards
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            href="/events-activities"
                            class="inline-flex items-center gap-1.5 rounded-lg border-[1.5px] border-[#1A8F26] bg-card px-3.5 py-2 text-sm font-semibold text-[#1A8F26] transition-colors duration-200 hover:bg-[#1A8F26] hover:text-white"
                        >
                            <CalendarDays class="size-4" />
                            Events &amp; Activities
                        </Link>

                        <Link
                            href="/my-credentials"
                            class="inline-flex items-center gap-1.5 rounded-lg border-[1.5px] border-[#0384C5] bg-card px-3.5 py-2 text-sm font-semibold text-[#0384C5] transition-colors duration-200 hover:bg-[#0384C5] hover:text-white"
                        >
                            <Award class="size-4" />
                            My Achievements &amp; Awards
                        </Link>

                        <Link
                            :href="membershipIndex().url"
                            class="inline-flex items-center gap-1.5 rounded-lg border-[1.5px] border-[#FF8D06] bg-[#FF8D06] px-3.5 py-2 text-sm font-semibold text-white transition-colors duration-200 hover:bg-background hover:text-[#FF8D06]"
                        >
                            <Users class="size-4" />
                            Membership &amp; GC Checklist
                        </Link>
                    </template>
                </div>
            </div>
        </div>

        <div
            v-if="flashStatus"
            class="rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3.5 py-2.5 text-sm font-semibold text-[#1A8F26]"
        >
            {{ flashStatus }}
        </div>

        <AdviserHandoverPanel
            :is-club-adviser="Boolean(auth.can?.isClubAdviser)"
            :show-initiate-form="false"
        />

        <!-- QR-First Attendance Digital ID + Verified Profile + Daily Calming Quote -->
        <div class="grid gap-3.5 lg:grid-cols-3">
            <!-- Alumni Archive & Verified Club Tenure Card (When Alumni) OR Attendance QR Card -->
            <div v-if="isAlumni || auth.isAlumni" class="bento flex flex-col justify-between p-4">
                <div>
                    <div class="flex items-center justify-between gap-2 text-xs font-bold">
                        <span class="inline-flex items-center gap-1.5 text-primary">
                            <Archive class="size-4" />
                            Read-Only Legacy Archive
                        </span>
                        <span
                            v-if="myTenureSummary?.is_fully_verified"
                            class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/15 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]"
                        >
                            <BadgeCheck class="size-3.5" />
                            Verified Tenure
                        </span>
                    </div>

                    <h2 class="font-editorial mt-2 text-base font-bold text-foreground">
                        {{ auth.roleLabel ?? 'Former Member' }}
                    </h2>
                    <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                        Your account is preserved with <strong class="text-foreground">zero active system control</strong>. Browse your verified club tenure, awards, unlocked time capsules, archived Officers GCs, and fellow former members anytime.
                    </p>

                    <div v-if="myTenureSummary" class="mt-3 space-y-1.5 rounded-xl border border-border bg-background/75 p-3.5 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Verified Time at CPF:</span>
                            <strong class="text-primary">{{ myTenureSummary.total_duration_label }}</strong>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Service Window:</span>
                            <span class="font-semibold text-foreground">{{ myTenureSummary.first_joined_at }} &rarr; {{ myTenureSummary.served_until }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground">Events &amp; Awards:</span>
                            <span class="font-semibold text-foreground">{{ myTenureSummary.events_attended_count }} Events • {{ myTenureSummary.awards_count }} Awards</span>
                        </div>
                    </div>
                </div>

                <Link
                    href="/alumni-archive"
                    class="bento-btn mt-3.5 w-full justify-center"
                >
                    <Archive class="size-4" />
                    Open Former Members &amp; Legacy Archive
                </Link>
            </div>

            <!-- Guidance Counselor Coordination Card -->
            <div v-else-if="isGuidanceCounselor" class="bento mx-auto flex w-full flex-col justify-between p-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary">
                        <BadgeCheck class="size-4" />
                        Guidance Counselor • Coordination Only
                    </span>
                    <h2 class="font-editorial mt-2 text-base font-bold text-foreground">
                        {{ auth.membership?.full_name_last_first_mi ?? auth.user?.name }}
                    </h2>
                    <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                        Access Counselors Private GC, Testing Room schedules, and confidential Peer Referrals from the Campus Hub. Student club elections, dues, and officer channels remain separated.
                    </p>
                </div>
                <Link href="/campus-hub" class="bento-btn mt-3.5 w-full justify-center">
                    Open Campus Guidance Hub
                </Link>
            </div>

            <!-- Attendance QR Card (Only generated when temporary password has been changed) -->
            <div v-else class="bento mx-auto flex w-full flex-col items-center justify-center p-4 text-center">
                <div class="flex w-full items-center justify-between text-sm font-bold">
                    <span class="inline-flex items-center gap-1.5 text-primary">
                        <QrCode class="size-4" />
                        Attendance QR ID
                    </span>
                    <span class="font-mono text-xs text-muted-foreground">
                        {{ qrUnlocked && memberIdCode ? memberIdCode : 'LOCKED' }}
                    </span>
                </div>

                <div v-if="qrUnlocked && qrSvg" class="my-3 flex w-full flex-col items-center justify-center gap-2.5">
                    <div
                        class="cpf-qr-surface mx-auto flex aspect-square w-[min(68vw,224px)] items-center justify-center rounded-xl border border-border bg-white p-3.5 shadow-sm sm:w-56"
                    >
                        <img
                            :src="'data:image/svg+xml;utf8,' + encodeURIComponent(qrSvg)"
                            alt="Official Peer QR Code"
                            class="block h-full w-full"
                        />
                    </div>
                    <button
                        v-if="qrPayload"
                        type="button"
                        @click="copySignedQrPayload"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary hover:bg-primary hover:text-primary-foreground"
                    >
                        <Copy class="size-3.5" />
                        {{ qrPayloadCopied ? 'Signed QR Copied ✓' : 'Copy Signed QR Payload' }}
                    </button>
                </div>
                <div
                    v-else
                    class="my-3 mx-auto flex w-full flex-col items-center justify-center gap-2 rounded-xl border border-amber-400/40 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    <Lock class="size-6 text-amber-700" />
                    <div class="font-bold">Personal Password Required</div>
                    <p class="text-xs leading-relaxed text-amber-800">
                        Sign in with your Passkey Code and create your personal password to unlock your Attendance QR Code.
                    </p>
                    <Link
                        href="/force-password-change"
                        class="mt-1 inline-flex items-center rounded-lg bg-[#FF8D06] px-3 py-1.5 text-sm font-semibold text-white"
                    >
                        Set Personal Password
                    </Link>
                </div>

                <div class="w-full space-y-1.5 text-sm">
                    <div class="mb-1 flex flex-col items-center gap-1">
                        <div class="size-11 overflow-hidden rounded-full border border-primary/30 bg-card shadow-2xs">
                            <img
                                v-if="auth.user?.avatar"
                                :src="auth.user.avatar"
                                :alt="auth.membership?.full_name_last_first_mi ?? auth.user?.name ?? 'Profile'"
                                class="size-full object-cover"
                            />
                            <div
                                v-else
                                class="flex size-full items-center justify-center bg-primary/10 text-xs font-bold text-primary"
                            >
                                {{ getInitials(auth.membership?.full_name_last_first_mi ?? auth.user?.name ?? 'CPF') }}
                            </div>
                        </div>
                        <Link
                            v-if="auth.role"
                            href="/settings/profile"
                            class="text-xs font-semibold text-primary underline hover:opacity-80"
                        >
                            Update Photo
                        </Link>
                    </div>
                    <div class="font-bold text-foreground">
                        {{ auth.membership?.full_name_last_first_mi ?? auth.user?.name ?? 'Pending Member' }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        <template v-if="auth.membership?.course">
                            {{ auth.membership.course }} • Year {{ auth.membership.year_level || '—' }} • Sec {{ auth.membership.section || '—' }}
                        </template>
                        <template v-else>
                            {{ auth.membership?.course_year_section ?? 'Campus Profile Pending' }}
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center justify-center gap-2 pt-0.5">
                        <!-- Messenger GC Status Pill -->
                        <div
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold"
                            :class="
                                auth.membership?.is_in_messenger_gc
                                    ? 'bg-[#1A8F26]/12 text-[#1A8F26]'
                                    : 'bg-amber-500/15 text-amber-800'
                            "
                        >
                            <MessageCircle class="size-3.5" />
                            {{
                                auth.membership?.is_in_messenger_gc
                                    ? 'In Messenger GC ✓'
                                    : 'GC Check Pending'
                            }}
                        </div>

                        <!-- Membership Dues Checklist Pill -->
                        <div
                            v-if="myDuesStatus"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold"
                            :class="
                                myDuesStatus.has_paid_dues
                                    ? 'bg-[#1A8F26]/12 text-[#1A8F26]'
                                    : 'bg-secondary text-secondary-foreground'
                            "
                        >
                            <Wallet class="size-3.5" />
                            {{
                                myDuesStatus.has_paid_dues
                                    ? 'Dues Confirmed ✓'
                                    : 'Dues Pending Check'
                            }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Verified Profile + Daily Calming Quote -->
            <div class="flex flex-col gap-3.5 lg:col-span-2">
                <!-- Daily Calming Quote -->
                <div class="bento small-card-fade p-4">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary">
                            <Sparkles class="size-3.5" />
                            Daily Calming Note • {{ dailyQuote.mood_tag }}
                        </span>
                    </div>
                    <blockquote class="font-editorial mt-2 text-base leading-relaxed text-foreground italic md:text-lg">
                        “{{ dailyQuote.quote_text }}”
                    </blockquote>
                    <p class="mt-1.5 text-xs font-semibold text-muted-foreground">
                        — {{ dailyQuote.author_name }}
                    </p>
                </div>

                <!-- 10-Role CPF Constitution Governance Summary Cards (FR-02 & FR-03) -->
                <div class="grid gap-3.5 md:grid-cols-3">
                    <div class="bento p-3.5">
                        <div class="flex items-center gap-2 text-base font-bold text-primary">
                            <UserPlus class="size-4 shrink-0" />
                            <span>Approvals &amp; Collections</span>
                        </div>
                        <p class="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                            <strong class="text-foreground">Adviser, President &amp; VP</strong> approve members; alongside the <strong class="text-foreground">Treasurer</strong> they confirm dues while the <strong class="text-foreground">Auditor</strong> holds read-only financial audit oversight.
                        </p>
                        <div class="mt-2 text-xs font-semibold" :class="auth.can?.addOrApproveMembers || canConfirmDues ? 'text-[#1A8F26]' : 'text-muted-foreground'">
                            {{ auth.can?.addOrApproveMembers ? '✓ Approval & Signer Authority' : canConfirmDues ? '✓ Dues Confirmation Authority' : canAuditFinances ? '✓ Financial Audit Oversight' : 'Applicant / Officer view' }}
                        </div>
                    </div>

                    <div class="bento p-3.5">
                        <div class="flex items-center gap-2 text-base font-bold text-[#1A8F26]">
                            <ClipboardCheck class="size-4 shrink-0" />
                            <span>GC &amp; Passkey Issuance</span>
                        </div>
                        <p class="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                            <strong class="text-foreground">Adviser, President, VP, Secretary &amp; PIO</strong> confirm the A–Z Messenger GC checklist and issue locked 12-box Passkey Codes.
                        </p>
                        <div class="mt-2 text-xs font-semibold" :class="auth.can?.issuePasskeys ? 'text-[#1A8F26]' : 'text-muted-foreground'">
                            {{ auth.can?.issuePasskeys ? '✓ Authorized for your role' : 'Confirmed by Campus Officers' }}
                        </div>
                    </div>

                    <div class="bento p-3.5">
                        <div class="flex items-center gap-2 text-base font-bold text-[#FF8D06]">
                            <ShieldAlert class="size-4 shrink-0" />
                            <span>Constitution Council</span>
                        </div>
                        <p class="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                            Zero Admin role. Includes <strong class="text-foreground">Business Manager</strong> &amp; <strong class="text-foreground">Sergeant-at-Arms</strong> in Officers GC; <strong class="text-foreground">Club Adviser</strong> assigns roles &amp; archives logs.
                        </p>
                        <div class="mt-2 flex items-center gap-1 text-xs font-semibold" :class="auth.can?.isClubAdviser ? 'text-[#1A8F26]' : isOfficerOrAdviser ? 'text-primary' : 'text-muted-foreground'">
                            <CheckCircle2 v-if="auth.can?.isClubAdviser || isOfficerOrAdviser" class="size-3.5" />
                            {{ auth.can?.isClubAdviser ? 'Club Adviser Authority Active' : isOfficerOrAdviser ? 'Constitution Officer Access Active' : 'Managed by Club Adviser' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Personal Locked Passkey Code (Real-time view & regenerate) -->
        <section v-if="auth.membership" class="bento p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2 text-base font-bold text-foreground">
                        <KeyRound class="size-4 text-primary" />
                        <span>My Passkey Login Code</span>
                        <span
                            v-if="livePasskey?.is_locked"
                            class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]"
                        >
                            <Lock class="size-3" />
                            Locked
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Once generated, your 12-character passkey stays locked so it cannot be mistaken. Click <strong class="text-foreground">Generate New Passkey</strong> anytime to replace it in real time.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="livePasskey?.enrollment_code"
                        type="button"
                        @click="copyMyPasskey"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-background px-3 py-1.5 text-sm font-semibold text-foreground hover:border-primary"
                    >
                        <Copy class="size-3.5" />
                        {{ myPasskeyCopied ? 'Copied ✓' : 'Copy Code' }}
                    </button>
                    <button
                        type="button"
                        :disabled="regeneratingMyPasskey"
                        @click="generateMyPasskey(Boolean(livePasskey?.enrollment_code))"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-primary/40 bg-primary/10 px-3.5 py-1.5 text-sm font-semibold text-primary transition hover:bg-primary hover:text-primary-foreground disabled:opacity-60"
                    >
                        <RefreshCw class="size-3.5" :class="{ 'animate-spin': regeneratingMyPasskey }" />
                        {{
                            regeneratingMyPasskey
                                ? 'Generating...'
                                : livePasskey?.enrollment_code
                                  ? 'Generate New Passkey'
                                  : 'Generate & Lock Passkey'
                        }}
                    </button>
                </div>
            </div>

            <div v-if="livePasskey?.enrollment_code" class="mt-3 rounded-xl border border-primary/25 bg-background/80 p-3.5">
                <div class="flex flex-wrap items-center justify-center gap-1.5">
                    <template v-for="(chunk, cIdx) in livePasskey.enrollment_code.split('-')" :key="`my-pk-${cIdx}`">
                        <span v-if="cIdx > 0" class="px-1 font-mono text-base font-bold text-muted-foreground">-</span>
                        <span
                            v-for="(ch, i) in chunk.split('')"
                            :key="`my-pk-${cIdx}-${i}`"
                            class="inline-flex h-9 w-8 items-center justify-center rounded-lg border border-primary/30 bg-card font-mono text-base font-extrabold text-primary shadow-2xs"
                        >
                            {{ ch }}
                        </span>
                    </template>
                </div>
                <p v-if="livePasskey.expires_at" class="mt-2 text-center text-xs text-muted-foreground">
                    Valid until {{ livePasskey.expires_at }} • Enter in the <strong class="text-foreground">Passkey Code</strong> tab on the Login page.
                </p>
            </div>
            <div v-else class="mt-3 rounded-xl border border-border bg-background/75 p-3.5 text-sm text-muted-foreground">
                No passkey code locked yet. Click <strong class="text-foreground">Generate &amp; Lock Passkey</strong> above (or ask a campus officer to issue one).
            </div>

            <div
                v-if="myPasskeyNotice"
                class="mt-2.5 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3 py-2 text-sm font-semibold text-[#1A8F26]"
            >
                {{ myPasskeyNotice }}
            </div>
        </section>

        <!-- Campus Membership Dues & Collections Checklist (Adviser, President, VP, Treasurer + Auditor Read-Only) -->
        <section v-if="canConfirmDues || canAuditFinances" class="bento p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                <div>
                    <div class="flex items-center gap-2 text-base font-bold text-foreground">
                        <Wallet class="size-4 text-[#1A8F26]" />
                        Campus Membership Dues &amp; Collections Checklist
                        <span
                            v-if="!canConfirmDues && canAuditFinances"
                            class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary"
                        >
                            Auditor Read-Only Audit View
                        </span>
                    </div>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Confirmed Paid: <strong class="text-foreground">{{ paidDuesCount }} / {{ duesRoster.length }}</strong> members
                        • Signers: Adviser, President, VP &amp; Treasurer
                        <span v-if="auth.membership?.campus">• {{ auth.membership.campus.name }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-muted-foreground" />
                        <input
                            v-model="duesSearch"
                            type="text"
                            placeholder="Search member or course..."
                            class="rounded-lg border border-border bg-background py-1.5 pr-3 pl-8 text-sm text-foreground focus:border-primary focus:outline-none"
                        />
                    </div>
                    <select
                        v-model="duesFilter"
                        class="rounded-lg border border-border bg-background px-3 py-1.5 text-sm font-medium text-foreground focus:border-primary focus:outline-none"
                    >
                        <option value="all">All Members ({{ duesRoster.length }})</option>
                        <option value="unpaid">Unpaid ({{ duesRoster.length - paidDuesCount }})</option>
                        <option value="paid">Paid ({{ paidDuesCount }})</option>
                    </select>
                </div>
            </div>

            <div v-if="filteredDuesRoster.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                No campus members match the current filter.
            </div>

            <div v-else class="mt-3 flex flex-col gap-2">
                <div
                    v-for="(member, idx) in filteredDuesRoster"
                    :key="`dues-${member.id}`"
                    class="flex flex-wrap items-center justify-between gap-2.5 rounded-xl border px-3 py-2.5 text-sm transition-all"
                    :class="
                        member.has_paid_dues
                            ? 'border-[#1A8F26]/30 bg-[#1A8F26]/5'
                            : 'border-border bg-card'
                    "
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex size-9 shrink-0 flex-col items-center justify-center rounded-lg text-xs font-bold"
                            :class="
                                member.has_paid_dues
                                    ? 'bg-[#1A8F26] text-white'
                                    : 'bg-secondary text-secondary-foreground'
                            "
                        >
                            <span class="leading-none">{{ member.full_name_last_first_mi.charAt(0).toUpperCase() }}</span>
                            <span class="text-xs leading-none opacity-80">#{{ idx + 1 }}</span>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold text-foreground">{{ member.full_name_last_first_mi }}</span>
                                <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-sm font-semibold text-primary">
                                    <template v-if="member.course">
                                        {{ member.course }} • Yr {{ member.year_level || '—' }} • Sec {{ member.section || '—' }}
                                    </template>
                                    <template v-else>
                                        {{ member.course_year_section }}
                                    </template>
                                </span>
                                <span class="rounded-full bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground">
                                    {{ member.role }}
                                </span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                <a
                                    v-if="member.facebook_profile_url"
                                    :href="member.facebook_profile_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 font-semibold text-primary underline hover:opacity-80"
                                >
                                    Open Facebook Profile
                                    <ExternalLink class="size-3" />
                                </a>
                                <span v-if="member.has_paid_dues && member.dues_confirmed_at">
                                    Confirmed {{ member.dues_confirmed_at }}
                                    <template v-if="member.dues_confirmer_name">
                                        by {{ member.dues_confirmer_name.split(',')[0] }}
                                    </template>
                                </span>
                            </div>
                        </div>
                    </div>

                    <button
                        v-if="canConfirmDues"
                        type="button"
                        @click="toggleDuesStatus(member)"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition-all"
                        :class="
                            member.has_paid_dues
                                ? 'border border-[#1A8F26] bg-[#1A8F26] text-white'
                                : 'border-[1.5px] border-[#FF8D06] bg-[#FF8D06] text-white hover:bg-background hover:text-[#FF8D06]'
                        "
                    >
                        <CheckCircle2 class="size-4" />
                        <span>{{ member.has_paid_dues ? 'Dues Paid ✓' : 'Confirm Dues Paid' }}</span>
                    </button>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
                        :class="member.has_paid_dues ? 'bg-[#1A8F26]/12 text-[#1A8F26]' : 'bg-amber-500/15 text-amber-800'"
                    >
                        <CheckCircle2 class="size-3.5" />
                        {{ member.has_paid_dues ? 'Audit Verified: Paid ✓' : 'Audit Pending: Unpaid' }}
                    </span>
                </div>
            </div>

            <!-- Submitted Payment Receipts Queue (Treasurer, Adviser, Auditor) -->
            <div v-if="paymentValidationQueue.length > 0" class="mt-4 border-t border-border pt-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 class="text-base font-bold text-foreground">
                            Submitted Payment Receipts Queue
                            <span
                                v-if="pendingPaymentsCount > 0"
                                class="ml-1.5 rounded-full bg-[#FF8D06]/15 px-2 py-0.5 text-xs font-bold text-[#FF8D06]"
                            >
                                {{ pendingPaymentsCount }} pending
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Review private payment receipts before confirming chapter dues. Validated receipts automatically update the dues roster and audit log.
                        </p>
                    </div>
                </div>

                <div
                    v-if="paymentQueueNotice"
                    class="mt-2.5 rounded-lg border border-primary/30 bg-primary/10 px-3 py-2 text-sm font-semibold text-primary"
                >
                    {{ paymentQueueNotice }}
                </div>

                <div class="mt-2.5 flex flex-col gap-2">
                    <div
                        v-for="sub in paymentValidationQueue"
                        :key="`pay-sub-${sub.id}`"
                        class="flex flex-wrap items-center justify-between gap-2.5 rounded-xl border px-3 py-2.5 text-sm"
                        :class="
                            sub.status === 'validated'
                                ? 'border-[#1A8F26]/30 bg-[#1A8F26]/5'
                                : sub.status === 'rejected'
                                  ? 'border-red-300 bg-red-50/50'
                                  : 'border-amber-300/70 bg-background/80'
                        "
                    >
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold text-foreground">{{ sub.member_name }}</span>
                                <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                                    {{ sub.channel }} • ₱{{ sub.amount.toFixed(2) }}
                                </span>
                                <span class="rounded-full bg-card px-2 py-0.5 font-mono text-xs text-muted-foreground">
                                    Ref: {{ sub.reference_number }}
                                </span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                <span>{{ sub.purpose }}</span>
                                <span v-if="sub.created_at">• {{ sub.created_at }}</span>
                                <a
                                    v-if="sub.has_receipt && sub.receipt_url"
                                    :href="sub.receipt_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 font-semibold text-primary underline hover:opacity-80"
                                >
                                    View Private Receipt
                                    <ExternalLink class="size-3" />
                                </a>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <template v-if="canConfirmDues && sub.status === 'pending'">
                                <button
                                    type="button"
                                    :disabled="validatingPaymentId === sub.id"
                                    @click="reviewPaymentSubmission(sub, 'validated')"
                                    class="rounded-lg bg-[#1A8F26] px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-[#15751f] disabled:opacity-60"
                                >
                                    Validate &amp; Mark Paid
                                </button>
                                <button
                                    type="button"
                                    :disabled="validatingPaymentId === sub.id"
                                    @click="reviewPaymentSubmission(sub, 'rejected')"
                                    class="rounded-lg border border-red-300 bg-card px-3 py-1.5 text-sm font-semibold text-red-700 transition hover:bg-red-50 disabled:opacity-60"
                                >
                                    Reject
                                </button>
                            </template>
                            <span
                                v-else
                                class="rounded-full px-2.5 py-0.5 text-xs font-bold uppercase"
                                :class="
                                    sub.status === 'validated'
                                        ? 'bg-[#1A8F26]/15 text-[#1A8F26]'
                                        : sub.status === 'rejected'
                                          ? 'bg-red-100 text-red-700'
                                          : 'bg-amber-100 text-amber-800'
                                "
                            >
                                {{ sub.status }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Member Dues Notice (For Non-Financial Officers & Members) -->
        <section v-else-if="myDuesStatus" class="bento p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 text-base font-bold text-foreground">
                        <Wallet class="size-4 text-primary" />
                        Campus Membership Dues Status
                    </div>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Your chapter Treasurer, Adviser, President, or Vice President confirms membership dues directly on the campus checklist. No QR or receipt upload is required.
                    </p>
                </div>
                <span
                    class="rounded-full px-3 py-1 text-xs font-bold"
                    :class="myDuesStatus.has_paid_dues ? 'bg-[#1A8F26]/12 text-[#1A8F26]' : 'bg-amber-500/15 text-amber-800'"
                >
                    {{ myDuesStatus.has_paid_dues ? 'Confirmed Paid ✓' : 'Pending Treasurer Confirmation' }}
                </span>
            </div>
        </section>

        <!-- Campus Announcements Section (PIO, Secretary & Adviser Templated Publishing) -->
        <section v-if="!isAlumni" class="bento p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                <div>
                    <div class="flex items-center gap-2 text-base font-bold text-foreground">
                        <Megaphone class="size-4 text-primary" />
                        Official Campus Announcements
                    </div>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        Chapter notices published by your Public Information Officer (PIO), Secretary, and Club Adviser.
                    </p>
                </div>
                <button
                    v-if="canPublishAnnouncements"
                    type="button"
                    @click="showAnnouncementComposer = !showAnnouncementComposer"
                    class="bento-btn"
                >
                    <Megaphone class="size-3.5" />
                    {{ showAnnouncementComposer ? 'Close Composer' : '+ Publish Templated Announcement' }}
                </button>
            </div>

            <div
                v-if="announcementNotice"
                class="mt-2.5 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3.5 py-2 text-sm font-semibold text-[#1A8F26]"
            >
                {{ announcementNotice }}
            </div>

            <form
                v-if="canPublishAnnouncements && showAnnouncementComposer"
                class="mt-3 grid gap-3 rounded-xl border border-primary/25 bg-background/80 p-3.5 text-sm"
                @submit.prevent="publishCampusAnnouncement"
            >
                <div>
                    <span class="mb-1.5 block font-bold text-foreground">1. Choose Reusable Template:</span>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="tpl in announcementTemplates"
                            :key="tpl.key"
                            type="button"
                            @click="applyAnnouncementTemplate(tpl)"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                            :class="
                                selectedTemplateKey === tpl.key
                                    ? 'bg-primary text-primary-foreground'
                                    : 'border border-border bg-card text-foreground hover:border-primary'
                            "
                        >
                            {{ tpl.label }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="sm:col-span-1">
                        <label class="mb-1 block font-bold text-foreground">Headline *</label>
                        <input v-model="annTitle" required class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground" />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-foreground">Date / Time Label</label>
                        <input v-model="annSchedule" placeholder="e.g. Friday • 3:00 PM" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground" />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-foreground">Venue / Channel</label>
                        <input v-model="annLocation" placeholder="e.g. Guidance Hall" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground" />
                    </div>
                </div>

                <div>
                    <label class="mb-1 block font-bold text-foreground">Announcement Details *</label>
                    <textarea v-model="annBody" rows="3" required class="w-full rounded-lg border border-border bg-card px-3 py-2 text-sm text-foreground" />
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="inline-flex cursor-pointer items-center gap-2 font-semibold text-foreground">
                        <input v-model="annPinned" type="checkbox" class="rounded border-border" />
                        Pin to top of campus feed
                    </label>
                    <button type="submit" :disabled="publishingAnnouncement" class="bento-btn">
                        {{ publishingAnnouncement ? 'Publishing...' : 'Publish to Campus Members' }}
                    </button>
                </div>
            </form>

            <div v-if="campusAnnouncements.length === 0" class="mt-3 text-sm text-muted-foreground">
                No campus announcements posted yet.
            </div>
            <div v-else class="mt-3 grid gap-3 md:grid-cols-2">
                <article
                    v-for="ann in campusAnnouncements"
                    :key="ann.id"
                    class="flex flex-col justify-between gap-2.5 rounded-xl border p-3.5 text-sm"
                    :class="ann.is_pinned ? 'border-primary/40 bg-primary/5' : 'border-border bg-card'"
                >
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-1.5">
                                <span class="rounded-full bg-primary/12 px-2.5 py-0.5 text-xs font-bold text-primary">
                                    {{ ann.template_label }}
                                </span>
                                <span
                                    v-if="ann.is_pinned"
                                    class="inline-flex items-center gap-1 rounded-full bg-[#FF8D06]/15 px-2 py-0.5 text-xs font-bold text-[#FF8D06]"
                                >
                                    <Pin class="size-3" />
                                    Pinned
                                </span>
                            </div>
                            <button
                                v-if="canPublishAnnouncements"
                                type="button"
                                @click="removeCampusAnnouncement(ann.id)"
                                class="rounded-lg p-1 text-muted-foreground hover:bg-red-50 hover:text-red-600"
                                title="Remove announcement"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                        <h3 class="mt-2 text-base font-bold text-foreground">{{ ann.title }}</h3>
                        <p class="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">{{ ann.body }}</p>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2 text-xs text-muted-foreground">
                        <div>
                            <span v-if="ann.schedule_label" class="font-semibold text-foreground">{{ ann.schedule_label }}</span>
                            <span v-if="ann.location"> • {{ ann.location }}</span>
                        </div>
                        <div>
                            {{ ann.publisher_name }} ({{ ann.publisher_role_label }})
                            <span v-if="ann.published_diff"> • {{ ann.published_diff }}</span>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <!-- Campus Events Calendar & Notifications (Visible Across Student Members & Officers) -->
        <section v-if="!isGuidanceCounselor" class="grid gap-3.5 lg:grid-cols-[1.35fr_1fr]">
            <div class="bento p-4">
                <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="flex items-center gap-2 text-base font-bold text-foreground">
                            <CalendarDays class="size-4 text-primary" />
                            Campus Events &amp; Activities Calendar
                        </div>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            Click any scheduled activity to preview its time &amp; status, or open Events &amp; Activities for full details, QR attendance, and Time Capsule reflections.
                        </p>
                    </div>
                    <Link
                        href="/events-activities"
                        class="rounded-lg border border-primary/35 bg-primary/10 px-3 py-1.5 text-sm font-bold text-primary hover:bg-primary hover:text-primary-foreground"
                    >
                        Open Events Space &rarr;
                    </Link>
                </div>

                <EventCalendarGrid
                    :events="campusEvents"
                    :selected-event-id="selectedDashboardEvent?.id ?? null"
                    :compact="true"
                    @select-event="selectedDashboardEvent = $event"
                />

                <div
                    v-if="selectedDashboardEvent"
                    class="mt-2.5 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border bg-background/80 px-3.5 py-2.5 text-sm"
                >
                    <div>
                        <span class="font-bold text-foreground">{{ selectedDashboardEvent.title }}</span>
                        <span class="ml-2 text-muted-foreground">
                            {{ selectedDashboardEvent.event_date }} • {{ selectedDashboardEvent.start_time }}–{{ selectedDashboardEvent.end_time }}
                        </span>
                        <span v-if="selectedDashboardEvent.location" class="ml-2 text-muted-foreground">
                            • {{ selectedDashboardEvent.location }}
                        </span>
                    </div>
                    <span class="rounded-full bg-card px-2.5 py-0.5 text-xs font-bold uppercase text-primary">
                        {{ selectedDashboardEvent.effective_status.replace('_', ' ') }}
                    </span>
                </div>
            </div>

            <div class="bento flex flex-col justify-between p-4">
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2.5">
                        <div class="flex items-center gap-2 text-base font-bold text-foreground">
                            <Bell class="size-4 text-[#FF8D06]" />
                            Event Notifications
                            <span
                                v-if="unreadEventNotificationsCount > 0"
                                class="rounded-full bg-[#FF8D06] px-2.5 py-0.5 text-xs font-bold text-white"
                            >
                                {{ unreadEventNotificationsCount }} New
                            </span>
                        </div>
                        <button
                            v-if="unreadEventNotificationsCount > 0"
                            type="button"
                            @click="markAllDashboardNotificationsRead"
                            class="rounded-lg border border-primary/35 bg-primary/15 px-3 py-1 text-xs font-semibold text-primary transition hover:bg-primary hover:text-primary-foreground"
                        >
                            Mark All Read
                        </button>
                    </div>

                    <div v-if="eventNotifications.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                        No campus event notifications yet.
                    </div>
                    <div v-else class="mt-2.5 flex max-h-80 flex-col gap-2 overflow-y-auto pr-1">
                        <div
                            v-for="notif in eventNotifications"
                            :key="`dash-notif-${notif.id}`"
                            class="flex items-start justify-between gap-2.5 rounded-xl border p-3.5 text-sm transition"
                            :class="notif.is_unread ? 'border-primary/45 bg-primary/10' : 'border-border bg-card/85'"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="font-bold text-foreground">{{ notif.title }}</div>
                                <p class="mt-0.5 text-sm leading-snug text-muted-foreground">{{ notif.message }}</p>
                                <div class="mt-1 text-xs text-muted-foreground/80">{{ notif.created_at }}</div>
                            </div>
                            <button
                                v-if="notif.is_unread"
                                type="button"
                                @click="markDashboardNotificationRead(notif.id)"
                                class="shrink-0 rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-semibold text-foreground transition hover:border-primary/50 hover:text-primary"
                            >
                                Read
                            </button>
                        </div>
                    </div>
                </div>

                <Link
                    href="/events-activities"
                    class="mt-3 inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:opacity-90"
                >
                    View Attendance Checklist &amp; Reflections
                </Link>
            </div>
        </section>
    </div>
</template>
