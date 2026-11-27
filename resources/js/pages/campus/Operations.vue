<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    Copy,
    DoorOpen,
    HeartHandshake,
    Lock,
    MailPlus,
    MessageSquare,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CounselorAvailabilityCalendar from '@/components/campus/CounselorAvailabilityCalendar.vue';
import type { AnonymousCalendarBooking, CounselorScheduleItem } from '@/components/campus/CounselorAvailabilityCalendar.vue';
import OfficersMessengerChat from '@/components/chat/OfficersMessengerChat.vue';
import type { MessengerOfficerMessage } from '@/components/chat/OfficersMessengerChat.vue';
import { apiRequest } from '@/lib/csrfFetch';
import type { Auth } from '@/types';

interface RoomRequestItem {
    id: number;
    purpose: string;
    requester_name: string;
    counselor_name?: string | null;
    request_date: string | null;
    start_time: string;
    end_time: string;
    status: 'pending' | 'approved' | 'rejected';
    review_notes?: string | null;
    is_mine: boolean;
}

interface SafePeerReferralItem {
    id: number;
    referral_type: 'self_counseling' | 'peer_referral';
    student_identifier: string;
    student_course_section?: string | null;
    feedback_status: 'pending' | 'ongoing' | 'completed';
    safe_scheduling_note?: string | null;
    counselor_id?: number | null;
    counselor_name?: string | null;
    preferred_date?: string | null;
    preferred_start_time?: string | null;
    preferred_end_time?: string | null;
    created_at?: string | null;
}

interface CounselorInboxItem {
    id: number;
    referral_type: 'self_counseling' | 'peer_referral';
    student_identifier: string;
    student_course_section?: string | null;
    suggested_priority: string;
    counselor_confirmed_priority?: string | null;
    triage_response_target: string;
    concern_summary?: string;
    confidential_clinical_notes?: string | null;
    status: string;
    feedback_status: 'pending' | 'ongoing' | 'completed';
    safe_scheduling_note?: string | null;
    counselor_name?: string | null;
    preferred_date?: string | null;
    preferred_start_time?: string | null;
    preferred_end_time?: string | null;
    submitter_name?: string;
    created_at?: string | null;
}

interface CounselorInvitationItem {
    id: number;
    email: string;
    counselor_name: string | null;
    status: 'pending' | 'accepted' | 'expired';
    expires_at: string;
    accepted_at: string | null;
}

const props = withDefaults(
    defineProps<{
        campusId: number;
        campusName: string;
        campuses: Array<{ id: number; code: string; name: string }>;
        isOfficerOrAdviser: boolean;
        isClubAdviser: boolean;
        canCoordinateGuidance?: boolean;
        canReviewTestingRoom?: boolean;
        counselorInvitations?: CounselorInvitationItem[];
        testingRoom: {
            id: number;
            room_code: string;
            room_name: string;
            capacity: number;
        };
        counselors: CounselorScheduleItem[];
        calendarBookings: AnonymousCalendarBooking[];
        roomRequests: RoomRequestItem[];
        myReferrals: SafePeerReferralItem[];
        counselorInbox: CounselorInboxItem[];
        counselorGcMessages: MessengerOfficerMessage[];
    counselorPeers?: Array<{ user_id: number; name: string; role: string; role_label: string }>;
    officersGcPaused?: boolean;
        priorityTiers: Array<{ value: string; label: string; responseTarget: string }>;
        officerChannel: {
            id: number;
            academic_year: string;
            channel_key: string;
            is_active: boolean;
        } | null;
        officerMessages: MessengerOfficerMessage[];
        gcArchives: Array<{
            id: number;
            academic_year: string;
            zip_file_name: string;
            sha256_checksum: string;
            total_messages: number;
            archived_at: string;
        }>;
    }>(),
    {
        canCoordinateGuidance: false,
        canReviewTestingRoom: false,
        counselorInvitations: () => [],
    },
);

const page = usePage<{ auth: Auth }>();
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Campus Hub',
                href: '/campus-hub',
            },
        ],
    },
});

const activeTab = ref<'room' | 'counselors_gc' | 'referrals' | 'gc' | 'invite_counselors'>('room');
const statusBanner = ref<string | null>(null);
const errorBanner = ref<string | null>(null);

// Testing Room + Counselor Request State
const roomPurpose = ref('');
const roomCounselorId = ref<number | ''>('');
const roomDate = ref('');
const roomStart = ref('09:00');
const roomEnd = ref('10:00');
const submittingRoom = ref(false);
const modalBookingError = ref<string | null>(null);

async function submitModalBooking(payload: {
    date: string;
    startTime: string;
    endTime: string;
    counselorId: number;
    purpose: string;
}) {
    submittingRoom.value = true;
    statusBanner.value = null;
    errorBanner.value = null;
    modalBookingError.value = null;

    const { ok, data } = await apiRequest('/testing-room/request', 'POST', {
        campus_id: props.campusId,
        counselor_id: payload.counselorId,
        purpose: payload.purpose,
        request_date: payload.date,
        start_time: payload.startTime,
        end_time: payload.endTime,
    });

    submittingRoom.value = false;

    if (!ok) {
        const slotError = data.errors?.start_time?.[0];
        modalBookingError.value = slotError ?? data.message ?? 'Unable to schedule counseling session.';
        return;
    }

    statusBanner.value = 'Counseling reservation submitted! Slot is now marked Booked on the calendar.';
    router.reload({ only: ['roomRequests', 'calendarBookings'] });
}

async function submitRoomRequest() {
    submittingRoom.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest('/testing-room/request', 'POST', {
        campus_id: props.campusId,
        counselor_id: roomCounselorId.value || null,
        purpose: roomPurpose.value,
        request_date: roomDate.value,
        start_time: roomStart.value,
        end_time: roomEnd.value,
    });

    submittingRoom.value = false;

    if (!ok) {
        const slotError = data.errors?.start_time?.[0];
        errorBanner.value = slotError ?? data.message ?? 'Unable to reserve Testing Room.';
        return;
    }

    roomPurpose.value = '';
    roomCounselorId.value = '';
    statusBanner.value = 'Testing Room reservation submitted for confirmation.';
    router.reload({ only: ['roomRequests', 'calendarBookings'] });
}

async function reviewRoom(id: number, status: 'approved' | 'rejected') {
    const { ok, data } = await apiRequest(`/testing-room/${id}/review`, 'PATCH', { status });
    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to review room request.';
        return;
    }
    statusBanner.value = `Testing Room slot marked ${status}.`;
    router.reload({ only: ['roomRequests', 'calendarBookings'] });
}

// Guidance Referral State (Plain Student Identifier + Optional Free-Slot Scheduling)
const refType = ref<'self_counseling' | 'peer_referral'>('peer_referral');
const refStudent = ref('');
const refCourse = ref('');
const refPriority = ref('routine');
const refSummary = ref('');
const refSuggestSlot = ref(false);
const refCounselorId = ref<number | ''>('');
const refPreferredDate = ref('');
const refPreferredStart = ref('09:00');
const refPreferredEnd = ref('10:00');
const submittingRef = ref(false);

async function submitReferral() {
    submittingRef.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const payload: Record<string, unknown> = {
        campus_id: props.campusId,
        referral_type: refType.value,
        student_identifier: refStudent.value,
        student_course_section: refCourse.value || null,
        suggested_priority: refPriority.value,
        concern_summary: refSummary.value,
    };

    if (refSuggestSlot.value && refPreferredDate.value) {
        payload.counselor_id = refCounselorId.value || null;
        payload.preferred_date = refPreferredDate.value;
        payload.preferred_start_time = refPreferredStart.value;
        payload.preferred_end_time = refPreferredEnd.value;
    }

    const { ok, data } = await apiRequest('/referrals/submit', 'POST', payload);

    submittingRef.value = false;

    if (!ok) {
        const slotErr = data.errors?.preferred_start_time?.[0];
        errorBanner.value = slotErr ?? data.message ?? 'Unable to submit encrypted guidance referral.';
        return;
    }

    refStudent.value = '';
    refCourse.value = '';
    refSummary.value = '';
    refSuggestSlot.value = false;
    refCounselorId.value = '';
    refPreferredDate.value = '';
    statusBanner.value = 'Encrypted referral submitted safely to your campus Guidance Counselor.';
    router.reload({ only: ['myReferrals', 'counselorInbox', 'calendarBookings', 'roomRequests'] });
}

// Counselor / Adviser Referral Feedback & Clinical Triage State
const confirmPriorityMap = ref<Record<number, string>>({});
const feedbackStatusMap = ref<Record<number, 'pending' | 'ongoing' | 'completed'>>({});
const safeSchedulingNoteMap = ref<Record<number, string>>({});
const clinicalNotesMap = ref<Record<number, string>>({});

async function saveReferralFeedback(refItem: CounselorInboxItem) {
    const priority = confirmPriorityMap.value[refItem.id] ?? refItem.counselor_confirmed_priority ?? refItem.suggested_priority;
    const feedbackStatus = feedbackStatusMap.value[refItem.id] ?? refItem.feedback_status ?? 'ongoing';
    const safeNote = safeSchedulingNoteMap.value[refItem.id] ?? refItem.safe_scheduling_note ?? '';
    const clinicalNote = clinicalNotesMap.value[refItem.id] ?? refItem.confidential_clinical_notes ?? '';

    const { ok, data } = await apiRequest(`/referrals/${refItem.id}/confirm`, 'PATCH', {
        counselor_confirmed_priority: priority,
        feedback_status: feedbackStatus,
        safe_scheduling_note: safeNote || null,
        confidential_clinical_notes: clinicalNote || null,
    });

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to update referral status.';
        return;
    }

    statusBanner.value = `Referral updated to ${feedbackStatus.toUpperCase()} (only safe status & scheduling note shared with peer).`;
    router.reload({ only: ['counselorInbox', 'myReferrals', 'roomRequests'] });
}

// Counselor Email + 24h OTP Invite State (Club Adviser Only)
const inviteEmail = ref('');
const inviteName = ref('');
const invitingCounselor = ref(false);
const latestIssuedInvite = ref<{
    email: string;
    invite_url: string;
    otp_code: string;
    expires_at: string;
} | null>(null);
const inviteCopied = ref(false);

async function sendCounselorInvite() {
    if (invitingCounselor.value) return;
    invitingCounselor.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{
        invitation?: { email: string; expires_at: string };
        invite_url?: string;
        otp_code?: string;
        message?: string;
    }>('/counselor-invitations', 'POST', {
        email: inviteEmail.value,
        counselor_name: inviteName.value || null,
    });

    invitingCounselor.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to send Guidance Counselor invitation.';
        return;
    }

    if (data.invitation && data.invite_url && data.otp_code) {
        latestIssuedInvite.value = {
            email: data.invitation.email,
            invite_url: data.invite_url,
            otp_code: data.otp_code,
            expires_at: data.invitation.expires_at,
        };
    }

    inviteEmail.value = '';
    inviteName.value = '';
    statusBanner.value = 'Guidance Counselor 24-hour signed invite link & 6-digit OTP issued!';
    router.reload({ only: ['counselorInvitations'] });
}

async function copyInviteBundle() {
    if (!latestIssuedInvite.value || typeof navigator === 'undefined' || !navigator.clipboard) return;
    await navigator.clipboard.writeText(
        `CPF Guidance Counselor Invite (${props.campusName})\nLink: ${latestIssuedInvite.value.invite_url}\n6-Digit OTP: ${latestIssuedInvite.value.otp_code}`,
    );
    inviteCopied.value = true;
    setTimeout(() => {
        inviteCopied.value = false;
    }, 2000);
}

async function archiveAndResetGc() {
    if (!props.officerChannel) return;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest(`/officers-gc/${props.officerChannel.id}/archive`, 'POST');
    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to archive Officers GC.';
        return;
    }

    statusBanner.value = 'Officers GC archived into ZIP with SHA-256 checksum and reopened for next term.';
    router.reload({ only: ['officerChannel', 'officerMessages', 'gcArchives'] });
}
</script>

<template>
    <Head title="Campus Operations Hub" />

    <div class="flex min-h-full flex-1 flex-col gap-6 bg-[#FBF6EE] p-4 text-slate-900 md:p-6">
        <div class="bento flex flex-wrap items-center justify-between gap-4 p-5">
            <div>
                <span class="rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                    {{ campusName }}
                </span>
                <h1 class="font-editorial mt-2 text-2xl font-bold text-slate-900">
                    Campus Support &amp; Operations Hub
                </h1>
                <p class="mt-0.5 text-xs text-slate-600">
                    Testing Room scheduling, confidential Guidance Referrals, and private coordination.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    @click="activeTab = 'room'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'room' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <DoorOpen class="size-4" />
                    Testing Room (1-Room)
                </button>
                <button
                    type="button"
                    @click="activeTab = 'referrals'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'referrals' ? 'bg-[#1A8F26] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <HeartHandshake class="size-4" />
                    Guidance Referrals
                </button>
                <button
                    v-if="canCoordinateGuidance"
                    type="button"
                    @click="activeTab = 'counselors_gc'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'counselors_gc' ? 'bg-[#1D7F9C] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <MessageSquare class="size-4" />
                    Counselors GC
                </button>
                <button
                    v-if="isOfficerOrAdviser"
                    type="button"
                    @click="activeTab = 'gc'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'gc' ? 'bg-[#FF8D06] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <MessageSquare class="size-4" />
                    Officers Private GC
                </button>
                <button
                    v-if="isClubAdviser"
                    type="button"
                    @click="activeTab = 'invite_counselors'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'invite_counselors' ? 'bg-slate-900 text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <MailPlus class="size-4" />
                    Invite Counselor (OTP)
                </button>
            </div>
        </div>

        <div v-if="statusBanner" class="rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-3 text-xs font-semibold text-[#1A8F26]">
            {{ statusBanner }}
        </div>
        <div v-if="errorBanner" class="rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700">
            {{ errorBanner }}
        </div>

        <!-- TAB 1: TESTING ROOM + MON-FRI COUNSELOR AVAILABILITY & MONTH CALENDAR -->
        <div v-if="activeTab === 'room'" class="flex flex-col gap-5">
            <div class="grid gap-5 lg:grid-cols-2">
                <section class="bento p-6">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-bold text-slate-900">
                            Reserve {{ testingRoom.room_name }}
                        </h2>
                        <span class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[11px] font-bold text-[#0384C5]">
                            Capacity: {{ testingRoom.capacity }} • 1 Room Only
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-600">
                        Each campus allows strictly one Testing Room. Select a vacant slot below or pick your date and time here — overlapping time slots are prevented.
                    </p>

                    <form class="mt-4 grid gap-3 text-xs" @submit.prevent="submitRoomRequest">
                        <div>
                            <label class="mb-1 block font-bold">Preferred Guidance Counselor</label>
                            <select
                                v-model="roomCounselorId"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            >
                                <option value="">Any Available Campus Counselor / Room Only</option>
                                <option v-for="c in counselors" :key="c.id" :value="c.id">
                                    {{ c.name }} ({{ c.title }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Session Purpose *</label>
                            <input
                                v-model="roomPurpose"
                                required
                                placeholder="e.g. One-on-one Peer Listening Shift"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                            />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block font-bold">Date *</label>
                                <input v-model="roomDate" type="date" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                            </div>
                            <div>
                                <label class="mb-1 block font-bold">Start Time *</label>
                                <input v-model="roomStart" type="time" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                            </div>
                            <div>
                                <label class="mb-1 block font-bold">End Time *</label>
                                <input v-model="roomEnd" type="time" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="submittingRoom" class="bento-btn">
                                <CalendarClock class="size-3.5" />
                                Request Time Slot
                            </button>
                        </div>
                    </form>
                </section>

                <section class="bento p-6">
                    <h2 class="text-base font-bold text-slate-900">Testing Room Schedule &amp; Requests</h2>
                    <div v-if="roomRequests.length === 0" class="mt-4 text-xs text-slate-500">
                        No Testing Room reservations scheduled yet.
                    </div>
                    <div v-else class="mt-4 flex flex-col gap-2.5">
                        <div
                            v-for="req in roomRequests"
                            :key="req.id"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-black/10 bg-white p-3.5 text-xs"
                        >
                            <div>
                                <div class="font-bold text-slate-900">{{ req.purpose }}</div>
                                <div class="text-slate-600">
                                    {{ req.request_date }} • {{ req.start_time }}–{{ req.end_time }} • {{ req.requester_name }}
                                    <template v-if="req.counselor_name"> • {{ req.counselor_name }}</template>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase"
                                    :class="
                                        req.status === 'approved'
                                            ? 'bg-[#1A8F26]/15 text-[#1A8F26]'
                                            : req.status === 'rejected'
                                              ? 'bg-red-100 text-red-700'
                                              : 'bg-amber-500/15 text-amber-800'
                                    "
                                >
                                    {{ req.status }}
                                </span>
                                <template v-if="(isOfficerOrAdviser || canReviewTestingRoom) && req.status === 'pending'">
                                    <button type="button" @click="reviewRoom(req.id, 'approved')" class="rounded-lg bg-[#1A8F26] px-2.5 py-1 text-[11px] font-semibold text-white">
                                        Approve
                                    </button>
                                    <button type="button" @click="reviewRoom(req.id, 'rejected')" class="rounded-lg border border-red-300 px-2.5 py-1 text-[11px] font-semibold text-red-700">
                                        Reject
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <CounselorAvailabilityCalendar
                :counselors="counselors"
                :calendar-bookings="calendarBookings"
                :campus-name="campusName"
                :submitting-booking="submittingRoom"
                :booking-error="modalBookingError"
                @book-slot="submitModalBooking"
            />
        </div>

        <!-- TAB 2: PRIVACY-SAFE GUIDANCE REFERRALS & FREE-SLOT SCHEDULING -->
        <div v-else-if="activeTab === 'referrals'" class="flex flex-col gap-5">
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900">
                <div class="flex items-center gap-2 font-bold">
                    <AlertTriangle class="size-4 text-amber-700" />
                    Immediate Safety &amp; Client Privacy Notice
                </div>
                <p class="mt-1">
                    Referred students do not need a CPF account — enter a plain name or student identifier. Confidential clinical notes remain strictly locked to licensed Guidance Counselors; submitting peers see only safe progress status (<strong>Pending</strong>, <strong>Ongoing</strong>, or <strong>Completed</strong>) and non-clinical scheduling details.
                </p>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="bento p-6">
                    <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                        <Lock class="size-4 text-[#1A8F26]" />
                        Submit Encrypted Guidance Referral
                    </div>
                    <form class="mt-4 grid gap-3 text-xs" @submit.prevent="submitReferral">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block font-bold">Referral Type *</label>
                                <select v-model="refType" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2">
                                    <option value="peer_referral">Peer Referral (Plain Student Name/ID)</option>
                                    <option value="self_counseling">Self Counseling Request</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-bold">Suggested Priority (5-Tier) *</label>
                                <select v-model="refPriority" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2">
                                    <option v-for="tier in priorityTiers" :key="tier.value" :value="tier.value">
                                        {{ tier.label }} ({{ tier.responseTarget }})
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block font-bold">Client Name or Plain Identifier * (No Account Needed)</label>
                                <input v-model="refStudent" required placeholder="e.g. Dela Cruz, Juan P. / Student #2026-041" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                            </div>
                            <div>
                                <label class="mb-1 block font-bold">Course &amp; Section</label>
                                <input v-model="refCourse" placeholder="e.g. BSIT 3A" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block font-bold">Encrypted Concern Summary *</label>
                            <textarea
                                v-model="refSummary"
                                rows="3"
                                required
                                placeholder="Encrypted with AES-256 and visible only to your campus Guidance Counselor / Adviser..."
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                            />
                        </div>

                        <!-- Optional Free-Slot Scheduling -->
                        <div class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-3.5">
                            <label class="flex cursor-pointer items-center gap-2 font-bold text-slate-800">
                                <input v-model="refSuggestSlot" type="checkbox" class="rounded border-black/20" />
                                Suggest a Free Counselor / Testing Room Meeting Time
                            </label>
                            <p class="mt-0.5 text-[11px] text-slate-600">
                                Checked against live Counselor &amp; Testing Room bookings — overlapping time slots are automatically rejected.
                            </p>

                            <div v-if="refSuggestSlot" class="mt-3 grid gap-3">
                                <div>
                                    <label class="mb-1 block font-bold">Preferred Guidance Counselor</label>
                                    <select v-model="refCounselorId" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2">
                                        <option value="">Any Available Campus Counselor</option>
                                        <option v-for="c in counselors" :key="c.id" :value="c.id">
                                            {{ c.name }} ({{ c.title }})
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label class="mb-1 block font-bold">Date *</label>
                                        <input v-model="refPreferredDate" type="date" :required="refSuggestSlot" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block font-bold">Start *</label>
                                        <input v-model="refPreferredStart" type="time" :required="refSuggestSlot" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block font-bold">End *</label>
                                        <input v-model="refPreferredEnd" type="time" :required="refSuggestSlot" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" :disabled="submittingRef" class="bento-btn">
                                Submit Confidential Referral
                            </button>
                        </div>
                    </form>
                </section>

                <section class="bento p-6">
                    <h2 class="text-base font-bold text-slate-900">My Submitted Referrals (Privacy-Safe Status)</h2>
                    <p class="mt-0.5 text-[11px] text-slate-500">
                        Displays only safe follow-up status and scheduled slot — clinical notes are never shown here.
                    </p>
                    <div v-if="myReferrals.length === 0" class="mt-4 text-xs text-slate-500">
                        You have not submitted any guidance referrals yet.
                    </div>
                    <div v-else class="mt-4 flex flex-col gap-2.5">
                        <div
                            v-for="r in myReferrals"
                            :key="r.id"
                            class="rounded-2xl border border-black/10 bg-white p-3.5 text-xs"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-bold text-slate-900">{{ r.student_identifier }}</span>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase"
                                    :class="
                                        r.feedback_status === 'completed'
                                            ? 'bg-[#1A8F26]/15 text-[#1A8F26]'
                                            : r.feedback_status === 'ongoing'
                                              ? 'bg-[#0384C5]/15 text-[#0384C5]'
                                              : 'bg-amber-500/15 text-amber-800'
                                    "
                                >
                                    {{ r.feedback_status }}
                                </span>
                            </div>
                            <div v-if="r.preferred_date" class="mt-1.5 text-slate-700">
                                Scheduled Slot: <strong>{{ r.preferred_date }} • {{ r.preferred_start_time }}–{{ r.preferred_end_time }}</strong>
                                <span v-if="r.counselor_name"> ({{ r.counselor_name }})</span>
                            </div>
                            <div
                                v-if="r.safe_scheduling_note"
                                class="mt-1.5 rounded-xl border border-black/10 bg-[#FBF6EE] px-3 py-2 text-slate-700"
                            >
                                <strong>Scheduling Note:</strong> {{ r.safe_scheduling_note }}
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Counselor / Adviser Triage Inbox -->
            <section v-if="canCoordinateGuidance" class="bento p-6">
                <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <ShieldCheck class="size-5 text-[#0384C5]" />
                    Guidance Counselor &amp; Adviser Referral Triage Inbox
                </div>

                <div v-if="counselorInbox.length === 0" class="mt-3 text-xs text-slate-500">
                    No referrals awaiting confirmation in your campus.
                </div>

                <div v-else class="mt-4 grid gap-4 md:grid-cols-2">
                    <div
                        v-for="item in counselorInbox"
                        :key="`inbox-${item.id}`"
                        class="flex flex-col justify-between gap-3 rounded-2xl border border-black/10 bg-white p-4 text-xs"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-bold text-slate-900">{{ item.student_identifier }}</span>
                                <span class="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-[11px] font-bold text-amber-800 uppercase">
                                    {{ item.feedback_status }}
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-500">
                                Submitted by {{ item.submitter_name }} • Suggested: {{ item.suggested_priority }}
                                <span v-if="item.preferred_date">
                                    • Slot: {{ item.preferred_date }} ({{ item.preferred_start_time }}–{{ item.preferred_end_time }})
                                </span>
                            </div>
                            <p class="mt-2 rounded-xl bg-[#FBF6EE] p-2.5 text-slate-700">
                                <strong>Encrypted Intake Summary:</strong> {{ item.concern_summary }}
                            </p>
                        </div>

                        <div class="grid gap-2.5 border-t border-black/10 pt-3">
                            <div class="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-700">Peer-Visible Status</label>
                                    <select
                                        :value="feedbackStatusMap[item.id] ?? item.feedback_status"
                                        @change="feedbackStatusMap[item.id] = ($event.target as HTMLSelectElement).value as 'pending' | 'ongoing' | 'completed'"
                                        class="w-full rounded-xl border border-black/15 bg-[#FBF6EE] px-2.5 py-1.5 text-xs font-semibold"
                                    >
                                        <option value="pending">Pending</option>
                                        <option value="ongoing">Ongoing</option>
                                        <option value="completed">Completed</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-[11px] font-bold text-slate-700">Confirmed Priority</label>
                                    <select
                                        :value="confirmPriorityMap[item.id] ?? item.counselor_confirmed_priority ?? item.suggested_priority"
                                        @change="confirmPriorityMap[item.id] = ($event.target as HTMLSelectElement).value"
                                        class="w-full rounded-xl border border-black/15 bg-[#FBF6EE] px-2.5 py-1.5 text-xs"
                                    >
                                        <option v-for="tier in priorityTiers" :key="tier.value" :value="tier.value">
                                            {{ tier.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-700">
                                    Safe Scheduling Note (Visible to Referring Peer — Non-Clinical)
                                </label>
                                <input
                                    :value="safeSchedulingNoteMap[item.id] ?? item.safe_scheduling_note ?? ''"
                                    @input="safeSchedulingNoteMap[item.id] = ($event.target as HTMLInputElement).value"
                                    placeholder="e.g. Testing Room slot confirmed for Wednesday 9:00 AM."
                                    class="w-full rounded-xl border border-black/15 bg-white px-2.5 py-1.5 text-xs"
                                />
                            </div>

                            <div>
                                <label class="mb-1 block text-[11px] font-bold text-slate-700">
                                    Confidential Clinical Notes (Encrypted • Counselors Only — Never Shown to Peer)
                                </label>
                                <textarea
                                    rows="2"
                                    :value="clinicalNotesMap[item.id] ?? item.confidential_clinical_notes ?? ''"
                                    @input="clinicalNotesMap[item.id] = ($event.target as HTMLTextAreaElement).value"
                                    placeholder="Private counselor session observations..."
                                    class="w-full rounded-xl border border-black/15 bg-white px-2.5 py-1.5 text-xs"
                                />
                            </div>

                            <div class="flex justify-end">
                                <button type="button" @click="saveReferralFeedback(item)" class="bento-btn py-1.5">
                                    Save Status &amp; Notes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- TAB 3: COUNSELORS PRIVATE GC (ADVISER + GUIDANCE COUNSELORS) -->
        <div v-else-if="activeTab === 'counselors_gc' && canCoordinateGuidance" class="flex flex-col gap-5">
            <OfficersMessengerChat
                :channel="officerChannel"
                :initial-messages="officerMessages"
                :counselor-messages="counselorGcMessages"
                :counselor-peers="counselorPeers ?? []"
                :can-access-counselor-gc="canCoordinateGuidance"
                :hide-officers-gc="!isOfficerOrAdviser"
                :officers-gc-paused="officersGcPaused ?? false"
                default-thread="counselors"
                :current-user-id="currentUserId"
                :campus-name="campusName"
                :can-archive="canCoordinateGuidance"
            />
        </div>

        <!-- TAB 4: OFFICERS PRIVATE GC (STUDENT OFFICERS + ADVISER ONLY) -->
        <div v-else-if="activeTab === 'gc' && isOfficerOrAdviser" class="flex flex-col gap-5">
            <OfficersMessengerChat
                :channel="officerChannel"
                :initial-messages="officerMessages"
                :counselor-messages="counselorGcMessages"
                :can-access-counselor-gc="canCoordinateGuidance"
                default-thread="officers"
                :current-user-id="currentUserId"
                :campus-name="campusName"
                :can-archive="isClubAdviser"
                @archive="archiveAndResetGc"
            />

            <section class="bento p-6">
                <h2 class="text-base font-bold text-slate-900">Officer GC ZIP Archives</h2>
                <div v-if="gcArchives.length === 0" class="mt-3 text-xs text-slate-500">
                    No annual GC archives recorded yet.
                </div>
                <div v-else class="mt-3 flex flex-col gap-2.5">
                    <div
                        v-for="arc in gcArchives"
                        :key="arc.id"
                        class="rounded-2xl border border-black/10 bg-white p-3 text-xs"
                    >
                        <div class="font-bold text-slate-900">{{ arc.zip_file_name }}</div>
                        <div class="text-slate-600">AY {{ arc.academic_year }} • {{ arc.total_messages }} messages</div>
                        <div class="mt-1 truncate font-mono text-[10px] text-slate-500">
                            SHA-256: {{ arc.sha256_checksum }}
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- TAB 5: CLUB ADVISER COUNSELOR EMAIL + 24H OTP INVITE -->
        <div v-else-if="activeTab === 'invite_counselors' && isClubAdviser" class="grid gap-5 lg:grid-cols-2">
            <section class="bento p-6 text-xs">
                <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <MailPlus class="size-5 text-[#0384C5]" />
                    Invite Campus Guidance Counselor (Signed Link + 24h OTP)
                </div>
                <p class="mt-1 text-slate-600">
                    Generates a 24-hour signed registration link and 6-digit OTP code that registers the recipient directly with the coordination-only <strong>Guidance Counselor</strong> role (Counselors GC, Testing Room, and Guidance Referrals).
                </p>

                <form class="mt-4 grid gap-3" @submit.prevent="sendCounselorInvite">
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Counselor Email Address *</label>
                        <input
                            v-model="inviteEmail"
                            type="email"
                            required
                            placeholder="counselor@chmsu.edu.ph"
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Counselor Name (Optional)</label>
                        <input
                            v-model="inviteName"
                            placeholder="e.g. Clarissa R. Delos Santos, RGC"
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="invitingCounselor" class="bento-btn">
                            {{ invitingCounselor ? 'Generating Invite...' : 'Send Signed Invite Link + 6-Digit OTP' }}
                        </button>
                    </div>
                </form>

                <div
                    v-if="latestIssuedInvite"
                    class="mt-4 rounded-2xl border border-[#0384C5]/35 bg-[#FFF9EF] p-4"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-bold text-[#0384C5]">24h Invite Issued for {{ latestIssuedInvite.email }}</span>
                        <button
                            type="button"
                            @click="copyInviteBundle"
                            class="inline-flex items-center gap-1 rounded-lg border border-black/15 bg-white px-2.5 py-1 text-[11px] font-semibold"
                        >
                            <Copy class="size-3" />
                            {{ inviteCopied ? 'Copied ✓' : 'Copy Link + OTP' }}
                        </button>
                    </div>
                    <div class="mt-2 font-mono text-sm font-extrabold tracking-widest text-slate-900">
                        OTP: {{ latestIssuedInvite.otp_code }}
                    </div>
                    <div class="mt-1 break-all font-mono text-[11px] text-slate-600">
                        {{ latestIssuedInvite.invite_url }}
                    </div>
                </div>
            </section>

            <section class="bento p-6 text-xs">
                <h2 class="text-base font-bold text-slate-900">Recent Counselor Invitations</h2>
                <div v-if="counselorInvitations.length === 0" class="mt-3 text-slate-500">
                    No Guidance Counselor invitations issued yet.
                </div>
                <div v-else class="mt-3 flex flex-col gap-2.5">
                    <div
                        v-for="inv in counselorInvitations"
                        :key="inv.id"
                        class="flex items-center justify-between gap-2 rounded-2xl border border-black/10 bg-white p-3"
                    >
                        <div>
                            <div class="font-bold text-slate-900">{{ inv.counselor_name || inv.email }}</div>
                            <div class="text-[11px] text-slate-500">{{ inv.email }} • Expires {{ inv.expires_at }}</div>
                        </div>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase"
                            :class="
                                inv.status === 'accepted'
                                    ? 'bg-[#1A8F26]/15 text-[#1A8F26]'
                                    : inv.status === 'pending'
                                      ? 'bg-[#0384C5]/15 text-[#0384C5]'
                                      : 'bg-slate-200 text-slate-600'
                            "
                        >
                            {{ inv.status }}
                        </span>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
