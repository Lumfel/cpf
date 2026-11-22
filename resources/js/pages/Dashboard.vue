<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Award,
    Bell,
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    Copy,
    ExternalLink,
    KeyRound,
    Lock,
    MessageCircle,
    QrCode,
    RefreshCw,
    Search,
    ShieldAlert,
    Sparkles,
    UserPlus,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EventCalendarGrid from '@/components/events/EventCalendarGrid.vue';
import type { CampusEventSummary } from '@/components/events/EventCalendarGrid.vue';
import AdviserHandoverPanel from '@/components/membership/AdviserHandoverPanel.vue';
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

const props = withDefaults(
    defineProps<{
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
        qrPayload: null,
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

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto bg-[#FBF6EE] p-4 text-slate-900 md:p-6">
        <!-- Welcome & Quick Navigation Header -->
        <div class="bento p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-semibold text-[#0384C5]">
                        Circle of Peer Facilitators (CPF) • Multi-Campus Core
                    </span>
                    <h1 class="font-editorial mt-2 text-2xl font-bold tracking-tight text-slate-900">
                        Welcome, {{ auth.user?.name ?? 'Peer Facilitator' }}
                    </h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Active Role:
                        <strong class="text-slate-900">{{ auth.roleLabel ?? 'Ordinary Account' }}</strong>
                        <span v-if="auth.membership?.campus">
                            • {{ auth.membership.campus.name }}
                        </span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    <Link
                        href="/events-activities"
                        class="inline-flex items-center gap-2 rounded-xl border-[1.5px] border-[#1A8F26] bg-white px-4 py-2.5 text-sm font-semibold text-[#1A8F26] transition-colors duration-200 hover:bg-[#1A8F26] hover:text-white"
                    >
                        <CalendarDays class="size-4" />
                        Events &amp; Activities
                    </Link>

                    <Link
                        href="/my-credentials"
                        class="inline-flex items-center gap-2 rounded-xl border-[1.5px] border-[#0384C5] bg-white px-4 py-2.5 text-sm font-semibold text-[#0384C5] transition-colors duration-200 hover:bg-[#0384C5] hover:text-white"
                    >
                        <Award class="size-4" />
                        My Achievements &amp; Awards
                    </Link>

                    <Link
                        :href="membershipIndex().url"
                        class="inline-flex items-center gap-2 rounded-xl border-[1.5px] border-[#FF8D06] bg-[#FF8D06] px-4 py-2.5 text-sm font-semibold text-white transition-colors duration-200 hover:bg-[#FBF6EE] hover:text-[#FF8D06]"
                    >
                        <Users class="size-4" />
                        Membership &amp; GC Checklist
                    </Link>
                </div>
            </div>
        </div>

        <div
            v-if="flashStatus"
            class="rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-3 text-xs font-semibold text-[#1A8F26]"
        >
            {{ flashStatus }}
        </div>

        <AdviserHandoverPanel
            :is-club-adviser="Boolean(auth.can?.isClubAdviser)"
            :show-initiate-form="false"
        />

        <!-- QR-First Attendance Digital ID + Verified Profile + Daily Calming Quote -->
        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Attendance QR Card (Only generated when temporary password has been changed) -->
            <div class="bento flex flex-col items-center justify-between p-6 text-center">
                <div class="flex w-full items-center justify-between text-xs font-bold">
                    <span class="inline-flex items-center gap-1.5 text-[#0384C5]">
                        <QrCode class="size-4" />
                        Attendance QR ID
                    </span>
                    <span class="font-mono text-[11px] text-slate-500">
                        {{ qrUnlocked && memberIdCode ? memberIdCode : 'LOCKED' }}
                    </span>
                </div>

                <div v-if="qrUnlocked && qrSvg" class="my-4 flex flex-col items-center gap-2">
                    <div
                        class="cpf-qr-surface rounded-2xl border border-black/10 bg-white p-3 shadow-sm"
                        v-html="qrSvg"
                    />
                    <button
                        v-if="qrPayload"
                        type="button"
                        @click="copySignedQrPayload"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-[#0384C5]/30 bg-[#0384C5]/10 px-3 py-1 text-[11px] font-semibold text-[#0384C5] hover:bg-[#0384C5] hover:text-white"
                    >
                        <Copy class="size-3" />
                        {{ qrPayloadCopied ? 'Signed QR Copied ✓' : 'Copy Signed QR Payload' }}
                    </button>
                </div>
                <div
                    v-else
                    class="my-4 flex w-full flex-col items-center gap-2 rounded-2xl border border-amber-400/40 bg-amber-50 p-5 text-xs text-amber-900"
                >
                    <Lock class="size-7 text-amber-700" />
                    <div class="font-bold">Personal Password Required</div>
                    <p class="leading-relaxed text-amber-800">
                        Sign in with your Passkey Code and create your personal password to unlock your Attendance QR Code.
                    </p>
                    <Link
                        href="/force-password-change"
                        class="mt-1 inline-flex items-center rounded-xl bg-[#FF8D06] px-3 py-1.5 text-xs font-semibold text-white"
                    >
                        Set Personal Password
                    </Link>
                </div>

                <div class="w-full space-y-2 text-xs">
                    <div class="font-bold text-slate-900">
                        {{ auth.membership?.full_name_last_first_mi ?? auth.user?.name ?? 'Pending Member' }}
                    </div>
                    <div class="text-slate-600">
                        <template v-if="auth.membership?.course">
                            {{ auth.membership.course }} • Year {{ auth.membership.year_level || '—' }} • Sec {{ auth.membership.section || '—' }}
                        </template>
                        <template v-else>
                            {{ auth.membership?.course_year_section ?? 'Campus Profile Pending' }}
                        </template>
                    </div>

                    <div class="flex flex-wrap items-center justify-center gap-2">
                        <!-- Messenger GC Status Pill -->
                        <div
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-bold"
                            :class="
                                auth.membership?.is_in_messenger_gc
                                    ? 'bg-[#1A8F26]/10 text-[#1A8F26]'
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
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-bold"
                            :class="
                                myDuesStatus.has_paid_dues
                                    ? 'bg-[#1A8F26]/10 text-[#1A8F26]'
                                    : 'bg-[#F3EBDD] text-slate-700'
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
            <div class="flex flex-col gap-5 lg:col-span-2">
                <!-- Daily Calming Quote -->
                <div class="bento small-card-fade p-6">
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                            <Sparkles class="size-3.5" />
                            Daily Calming Note • {{ dailyQuote.mood_tag }}
                        </span>
                    </div>
                    <blockquote class="font-editorial mt-3 text-lg leading-relaxed text-slate-900 italic">
                        “{{ dailyQuote.quote_text }}”
                    </blockquote>
                    <p class="mt-2 text-xs font-semibold text-slate-500">
                        — {{ dailyQuote.author_name }}
                    </p>
                </div>

                <!-- 10-Role CPF Constitution Governance Summary Cards (FR-02 & FR-03) -->
                <div class="grid gap-4 md:grid-cols-3">
                    <div class="bento p-4">
                        <div class="flex items-center gap-2 text-xs font-bold text-[#0384C5]">
                            <UserPlus class="size-4" />
                            Approvals &amp; Fund Collections
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
                            <strong>Adviser, President &amp; VP</strong> approve members; alongside the <strong>Treasurer</strong> they confirm dues while the <strong>Auditor</strong> holds read-only financial audit oversight.
                        </p>
                        <div class="mt-2 text-[11px] font-semibold" :class="auth.can?.addOrApproveMembers || canConfirmDues ? 'text-[#1A8F26]' : 'text-slate-500'">
                            {{ auth.can?.addOrApproveMembers ? '✓ Approval & Signer Authority' : canConfirmDues ? '✓ Dues Confirmation Authority' : canAuditFinances ? '✓ Financial Audit Oversight' : 'Applicant / Officer view' }}
                        </div>
                    </div>

                    <div class="bento p-4">
                        <div class="flex items-center gap-2 text-xs font-bold text-[#1A8F26]">
                            <ClipboardCheck class="size-4" />
                            GC &amp; Passkey Issuance
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
                            <strong>Adviser, President, VP, Secretary &amp; PIO</strong> confirm the A–Z Messenger GC checklist and issue locked 12-box Passkey Codes.
                        </p>
                        <div class="mt-2 text-[11px] font-semibold" :class="auth.can?.issuePasskeys ? 'text-[#1A8F26]' : 'text-slate-500'">
                            {{ auth.can?.issuePasskeys ? '✓ Authorized for your role' : 'Confirmed by Campus Officers' }}
                        </div>
                    </div>

                    <div class="bento p-4">
                        <div class="flex items-center gap-2 text-xs font-bold text-[#FF8D06]">
                            <ShieldAlert class="size-4" />
                            Constitution Executive Council
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-slate-600">
                            Zero Admin role. Includes <strong>Business Manager</strong> &amp; <strong>Sergeant-at-Arms</strong> in Officers GC; <strong>Club Adviser</strong> assigns roles &amp; archives logs.
                        </p>
                        <div class="mt-2 flex items-center gap-1 text-[11px] font-semibold" :class="auth.can?.isClubAdviser ? 'text-[#1A8F26]' : isOfficerOrAdviser ? 'text-[#0384C5]' : 'text-slate-500'">
                            <CheckCircle2 v-if="auth.can?.isClubAdviser || isOfficerOrAdviser" class="size-3.5" />
                            {{ auth.can?.isClubAdviser ? 'Club Adviser Authority Active' : isOfficerOrAdviser ? 'Constitution Officer Access Active' : 'Managed by Club Adviser' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Personal Locked Passkey Code (Real-time view & regenerate) -->
        <section v-if="auth.membership" class="bento p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2 text-base font-bold text-slate-900">
                        <KeyRound class="size-5 text-[#0384C5]" />
                        <span>My Passkey Login Code</span>
                        <span
                            v-if="livePasskey?.is_locked"
                            class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-[11px] font-bold text-[#1A8F26]"
                        >
                            <Lock class="size-3" />
                            Locked
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-600">
                        Once generated, your 12-character passkey stays locked so it cannot be mistaken. Click <strong>Generate New Passkey</strong> anytime to replace it in real time.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button
                        v-if="livePasskey?.enrollment_code"
                        type="button"
                        @click="copyMyPasskey"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-black/15 bg-[#FBF6EE] px-3 py-2 text-xs font-semibold text-slate-800 hover:border-[#0384C5]"
                    >
                        <Copy class="size-3.5" />
                        {{ myPasskeyCopied ? 'Copied ✓' : 'Copy Code' }}
                    </button>
                    <button
                        type="button"
                        :disabled="regeneratingMyPasskey"
                        @click="generateMyPasskey(Boolean(livePasskey?.enrollment_code))"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-[#0384C5]/40 bg-[#0384C5]/10 px-3.5 py-2 text-xs font-semibold text-[#0384C5] transition hover:bg-[#0384C5] hover:text-white disabled:opacity-60"
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

            <div v-if="livePasskey?.enrollment_code" class="mt-4 rounded-2xl border border-[#0384C5]/25 bg-[#FBF6EE] p-4">
                <div class="flex flex-wrap items-center justify-center gap-1.5">
                    <template v-for="(chunk, cIdx) in livePasskey.enrollment_code.split('-')" :key="`my-pk-${cIdx}`">
                        <span v-if="cIdx > 0" class="px-1 font-mono text-base font-bold text-slate-400">-</span>
                        <span
                            v-for="(ch, i) in chunk.split('')"
                            :key="`my-pk-${cIdx}-${i}`"
                            class="inline-flex h-10 w-8 items-center justify-center rounded-lg border border-[#0384C5]/30 bg-white font-mono text-base font-extrabold text-[#0384C5] shadow-2xs"
                        >
                            {{ ch }}
                        </span>
                    </template>
                </div>
                <p v-if="livePasskey.expires_at" class="mt-2 text-center text-[11px] text-slate-500">
                    Valid until {{ livePasskey.expires_at }} • Enter in the <strong>Passkey Code</strong> tab on the Login page.
                </p>
            </div>
            <div v-else class="mt-4 rounded-2xl border border-black/10 bg-[#FBF6EE] p-4 text-xs text-slate-600">
                No passkey code locked yet. Click <strong>Generate &amp; Lock Passkey</strong> above (or ask a campus officer to issue one).
            </div>

            <div
                v-if="myPasskeyNotice"
                class="mt-3 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3 py-2 text-xs font-semibold text-[#1A8F26]"
            >
                {{ myPasskeyNotice }}
            </div>
        </section>

        <!-- Campus Membership Dues & Collections Checklist (Adviser, President, VP, Treasurer + Auditor Read-Only) -->
        <section v-if="canConfirmDues || canAuditFinances" class="bento p-6">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-black/10 pb-4">
                <div>
                    <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                        <Wallet class="size-5 text-[#1A8F26]" />
                        Campus Membership Dues &amp; Collections Checklist
                        <span
                            v-if="!canConfirmDues && canAuditFinances"
                            class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[11px] font-semibold text-[#0384C5]"
                        >
                            Auditor Read-Only Audit View
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-600">
                        Confirmed Paid: <strong>{{ paidDuesCount }} / {{ duesRoster.length }}</strong> members
                        • Signers: Adviser, President, VP &amp; Treasurer
                        <span v-if="auth.membership?.campus">• {{ auth.membership.campus.name }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-slate-400" />
                        <input
                            v-model="duesSearch"
                            type="text"
                            placeholder="Search member or course..."
                            class="rounded-xl border border-black/15 bg-[#FBF6EE] py-1.5 pr-3 pl-8 text-xs text-slate-800 focus:border-[#0384C5] focus:outline-none"
                        />
                    </div>
                    <select
                        v-model="duesFilter"
                        class="rounded-xl border border-black/15 bg-[#FBF6EE] px-3 py-1.5 text-xs font-medium text-slate-800 focus:border-[#0384C5] focus:outline-none"
                    >
                        <option value="all">All Members ({{ duesRoster.length }})</option>
                        <option value="unpaid">Unpaid ({{ duesRoster.length - paidDuesCount }})</option>
                        <option value="paid">Paid ({{ paidDuesCount }})</option>
                    </select>
                </div>
            </div>

            <div v-if="filteredDuesRoster.length === 0" class="py-8 text-center text-xs text-slate-500">
                No campus members match the current filter.
            </div>

            <div v-else class="mt-4 flex flex-col gap-2.5">
                <div
                    v-for="(member, idx) in filteredDuesRoster"
                    :key="`dues-${member.id}`"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4 text-xs transition-all"
                    :class="
                        member.has_paid_dues
                            ? 'border-[#1A8F26]/30 bg-[#1A8F26]/5'
                            : 'border-black/10 bg-white'
                    "
                >
                    <div class="flex items-start gap-3">
                        <div
                            class="flex size-9 shrink-0 flex-col items-center justify-center rounded-xl font-bold"
                            :class="
                                member.has_paid_dues
                                    ? 'bg-[#1A8F26] text-white'
                                    : 'bg-[#F3EBDD] text-slate-700'
                            "
                        >
                            <span>{{ member.full_name_last_first_mi.charAt(0).toUpperCase() }}</span>
                            <span class="text-[9px] opacity-75">#{{ idx + 1 }}</span>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-bold text-slate-900">{{ member.full_name_last_first_mi }}</span>
                                <span class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[11px] font-semibold text-[#0384C5]">
                                    <template v-if="member.course">
                                        {{ member.course }} • Yr {{ member.year_level || '—' }} • Sec {{ member.section || '—' }}
                                    </template>
                                    <template v-else>
                                        {{ member.course_year_section }}
                                    </template>
                                </span>
                                <span class="rounded-full bg-[#F3EBDD] px-2 py-0.5 text-[11px] font-medium text-slate-700">
                                    {{ member.role }}
                                </span>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-3 text-slate-600">
                                <a
                                    v-if="member.facebook_profile_url"
                                    :href="member.facebook_profile_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 font-semibold text-[#0384C5] underline hover:text-[#FF8D06]"
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
                        class="inline-flex items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-semibold transition-all"
                        :class="
                            member.has_paid_dues
                                ? 'border border-[#1A8F26] bg-[#1A8F26] text-white'
                                : 'border-[1.5px] border-[#FF8D06] bg-[#FF8D06] text-white hover:bg-[#FBF6EE] hover:text-[#FF8D06]'
                        "
                    >
                        <CheckCircle2 class="size-4" />
                        <span>{{ member.has_paid_dues ? 'Dues Paid ✓' : 'Confirm Dues Paid' }}</span>
                    </button>
                    <span
                        v-else
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold"
                        :class="member.has_paid_dues ? 'bg-[#1A8F26]/10 text-[#1A8F26]' : 'bg-amber-500/15 text-amber-800'"
                    >
                        <CheckCircle2 class="size-3.5" />
                        {{ member.has_paid_dues ? 'Audit Verified: Paid ✓' : 'Audit Pending: Unpaid' }}
                    </span>
                </div>
            </div>
        </section>

        <!-- Member Dues Notice (For Non-Financial Officers & Members) -->
        <section v-else-if="myDuesStatus" class="bento p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                        <Wallet class="size-5 text-[#0384C5]" />
                        Campus Membership Dues Status
                    </div>
                    <p class="mt-1 text-xs text-slate-600">
                        Your chapter Treasurer, Adviser, President, or Vice President confirms membership dues directly on the campus checklist. No QR or receipt upload is required.
                    </p>
                </div>
                <span
                    class="rounded-full px-3.5 py-1.5 text-xs font-bold"
                    :class="myDuesStatus.has_paid_dues ? 'bg-[#1A8F26]/10 text-[#1A8F26]' : 'bg-amber-500/15 text-amber-800'"
                >
                    {{ myDuesStatus.has_paid_dues ? 'Confirmed Paid ✓' : 'Pending Treasurer Confirmation' }}
                </span>
            </div>
        </section>

        <!-- Campus Events Calendar & Notifications (Visible Across Members) -->
        <section class="grid gap-5 lg:grid-cols-[1.35fr_1fr]">
            <div class="bento p-5">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                            <CalendarDays class="size-5 text-[#0384C5]" />
                            Campus Events &amp; Activities Calendar
                        </div>
                        <p class="mt-0.5 text-xs text-slate-600">
                            Click any scheduled activity to preview its time &amp; status, or open Events &amp; Activities for full details, QR attendance, and Time Capsule reflections.
                        </p>
                    </div>
                    <Link
                        href="/events-activities"
                        class="rounded-xl border border-[#0384C5]/35 bg-[#0384C5]/10 px-3 py-1.5 text-xs font-bold text-[#0384C5] hover:bg-[#0384C5] hover:text-white"
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
                    class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-black/10 bg-[#FBF6EE] px-4 py-3 text-xs"
                >
                    <div>
                        <span class="font-bold text-slate-900">{{ selectedDashboardEvent.title }}</span>
                        <span class="ml-2 text-slate-600">
                            {{ selectedDashboardEvent.event_date }} • {{ selectedDashboardEvent.start_time }}–{{ selectedDashboardEvent.end_time }}
                        </span>
                        <span v-if="selectedDashboardEvent.location" class="ml-2 text-slate-500">
                            • {{ selectedDashboardEvent.location }}
                        </span>
                    </div>
                    <span class="rounded-full bg-white px-2.5 py-0.5 text-[11px] font-bold uppercase text-[#0384C5]">
                        {{ selectedDashboardEvent.effective_status.replace('_', ' ') }}
                    </span>
                </div>
            </div>

            <div class="bento flex flex-col justify-between p-5">
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-black/10 pb-3">
                        <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                            <Bell class="size-5 text-[#FF8D06]" />
                            Event Notifications
                            <span
                                v-if="unreadEventNotificationsCount > 0"
                                class="rounded-full bg-[#FF8D06] px-2 py-0.5 text-[11px] font-bold text-white"
                            >
                                {{ unreadEventNotificationsCount }} New
                            </span>
                        </div>
                        <button
                            v-if="unreadEventNotificationsCount > 0"
                            type="button"
                            @click="markAllDashboardNotificationsRead"
                            class="rounded-xl border border-[#0384C5]/30 bg-[#0384C5]/10 px-2.5 py-1 text-[11px] font-semibold text-[#0384C5]"
                        >
                            Mark All Read
                        </button>
                    </div>

                    <div v-if="eventNotifications.length === 0" class="py-8 text-center text-xs text-slate-500">
                        No campus event notifications yet.
                    </div>
                    <div v-else class="mt-3 flex max-h-80 flex-col gap-2 overflow-y-auto pr-1">
                        <div
                            v-for="notif in eventNotifications"
                            :key="`dash-notif-${notif.id}`"
                            class="flex items-start justify-between gap-2 rounded-2xl border p-3 text-xs"
                            :class="notif.is_unread ? 'border-[#FF8D06]/45 bg-[#FFF8EC]' : 'border-black/10 bg-white'"
                        >
                            <div>
                                <div class="font-bold text-slate-900">{{ notif.title }}</div>
                                <p class="mt-0.5 text-slate-600">{{ notif.message }}</p>
                                <div class="mt-1 text-[10px] text-slate-400">{{ notif.created_at }}</div>
                            </div>
                            <button
                                v-if="notif.is_unread"
                                type="button"
                                @click="markDashboardNotificationRead(notif.id)"
                                class="shrink-0 rounded-lg border border-black/15 bg-white px-2 py-1 text-[10px] font-semibold text-slate-700"
                            >
                                Read
                            </button>
                        </div>
                    </div>
                </div>

                <Link
                    href="/events-activities"
                    class="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-[#0384C5] px-4 py-2 text-xs font-bold text-white hover:bg-[#026da3]"
                >
                    View Attendance Checklist &amp; Reflections
                </Link>
            </div>
        </section>
    </div>
</template>
