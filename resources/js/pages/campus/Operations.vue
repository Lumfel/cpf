<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    DoorOpen,
    HeartHandshake,
    Lock,
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

interface ReferralItem {
    id: number;
    referral_type: 'self_counseling' | 'peer_referral';
    student_identifier: string;
    student_course_section?: string | null;
    suggested_priority: string;
    counselor_confirmed_priority?: string | null;
    triage_response_target: string;
    concern_summary?: string;
    status: string;
    submitter_name?: string;
    created_at?: string | null;
}

const props = defineProps<{
    campusId: number;
    campusName: string;
    campuses: Array<{ id: number; code: string; name: string }>;
    isOfficerOrAdviser: boolean;
    isClubAdviser: boolean;
    testingRoom: {
        id: number;
        room_code: string;
        room_name: string;
        capacity: number;
    };
    counselors: CounselorScheduleItem[];
    calendarBookings: AnonymousCalendarBooking[];
    roomRequests: RoomRequestItem[];
    myReferrals: ReferralItem[];
    counselorInbox: ReferralItem[];
    counselorGcMessages: MessengerOfficerMessage[];
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
}>();

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

const activeTab = ref<'room' | 'counselors_gc' | 'referrals' | 'gc'>('room');
const statusBanner = ref<string | null>(null);
const errorBanner = ref<string | null>(null);

// FR-05: Testing Room + Counselor Request State
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
    statusBanner.value = 'Testing Room reservation submitted for officer confirmation.';
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

// FR-06: Guidance Referral State
const refType = ref<'self_counseling' | 'peer_referral'>('self_counseling');
const refStudent = ref('');
const refCourse = ref('');
const refPriority = ref('routine');
const refSummary = ref('');
const submittingRef = ref(false);

async function submitReferral() {
    submittingRef.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest('/referrals/submit', 'POST', {
        campus_id: props.campusId,
        referral_type: refType.value,
        student_identifier: refStudent.value,
        student_course_section: refCourse.value || null,
        suggested_priority: refPriority.value,
        concern_summary: refSummary.value,
    });

    submittingRef.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to submit encrypted guidance referral.';
        return;
    }

    refStudent.value = '';
    refCourse.value = '';
    refSummary.value = '';
    statusBanner.value = 'Encrypted referral submitted safely to your Guidance Counselor.';
    router.reload({ only: ['myReferrals', 'counselorInbox'] });
}

const confirmPriorityMap = ref<Record<number, string>>({});
const confirmStatusMap = ref<Record<number, string>>({});

async function confirmReferralPriority(refItem: ReferralItem) {
    const priority = confirmPriorityMap.value[refItem.id] ?? refItem.suggested_priority;
    const status = confirmStatusMap.value[refItem.id] ?? 'in_review';

    const { ok, data } = await apiRequest(`/referrals/${refItem.id}/confirm`, 'PATCH', {
        counselor_confirmed_priority: priority,
        status,
    });

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to confirm referral priority.';
        return;
    }

    statusBanner.value = 'Counselor priority confirmation logged.';
    router.reload({ only: ['counselorInbox', 'myReferrals'] });
}

// FR-07: Officers Private GC State
const gcDraft = ref('');
const sendingGc = ref(false);

async function sendGcMessage() {
    if (!props.officerChannel || !gcDraft.value.trim()) return;

    sendingGc.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest(`/officers-gc/${props.officerChannel.id}/messages`, 'POST', {
        message_body: gcDraft.value,
    });

    sendingGc.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to send officer message.';
        return;
    }

    gcDraft.value = '';
    router.reload({ only: ['officerMessages'] });
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

    statusBanner.value = 'Officers GC archived into ZIP with SHA-256 checksum and reset.';
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
                    Testing Room scheduling, confidential Guidance Referrals, and private Officer coordination.
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
                    @click="activeTab = 'counselors_gc'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'counselors_gc' ? 'bg-[#1D7F9C] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <MessageSquare class="size-4" />
                    Counselors GC
                </button>
            </div>
        </div>

        <div v-if="statusBanner" class="rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-3 text-xs font-semibold text-[#1A8F26]">
            {{ statusBanner }}
        </div>
        <div v-if="errorBanner" class="rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700">
            {{ errorBanner }}
        </div>

        <!-- TAB 1: FR-05 TESTING ROOM + MON-FRI COUNSELOR AVAILABILITY & MONTH CALENDAR -->
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
                                <template v-if="isOfficerOrAdviser && req.status === 'pending'">
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

            <!-- MIDDLE CARD: MON-FRI COUNSELOR AVAILABILITY & BOTTOM CARD: FULL MONTH CALENDAR -->
            <CounselorAvailabilityCalendar
                :counselors="counselors"
                :calendar-bookings="calendarBookings"
                :campus-name="campusName"
                :submitting-booking="submittingRoom"
                :booking-error="modalBookingError"
                @book-slot="submitModalBooking"
            />
        </div>

        <!-- TAB 2: FR-06 ENCRYPTED GUIDANCE REFERRALS -->
        <div v-else-if="activeTab === 'referrals'" class="flex flex-col gap-5">
            <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900">
                <div class="flex items-center gap-2 font-bold">
                    <AlertTriangle class="size-4 text-amber-700" />
                    Immediate Safety Notice
                </div>
                <p class="mt-1">
                    Priority levels help our Guidance Counselors organize intake only. A licensed counselor always reviews and confirms clinical urgency. If someone is in immediate physical danger, contact CHMSU campus security or emergency hotlines right away.
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
                                    <option value="self_counseling">Self Counseling Request</option>
                                    <option value="peer_referral">Peer Referral</option>
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
                                <label class="mb-1 block font-bold">Student Name or Identifier *</label>
                                <input v-model="refStudent" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
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
                                rows="4"
                                required
                                placeholder="Encrypted with AES-256 and visible only to your Guidance Counselor / Adviser..."
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                            />
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" :disabled="submittingRef" class="bento-btn">
                                Submit Confidential Referral
                            </button>
                        </div>
                    </form>
                </section>

                <section class="bento p-6">
                    <h2 class="text-base font-bold text-slate-900">My Submitted Referrals</h2>
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
                                <span class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[11px] font-bold text-[#0384C5] uppercase">
                                    {{ r.status }}
                                </span>
                            </div>
                            <div class="mt-1 text-slate-600">
                                Suggested: <strong>{{ r.suggested_priority }}</strong>
                                <span v-if="r.counselor_confirmed_priority">
                                    • Confirmed: <strong class="text-[#1A8F26]">{{ r.counselor_confirmed_priority }}</strong>
                                </span>
                                • Target: {{ r.triage_response_target }}
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Counselor / Adviser Triage Inbox (Human Confirmation Required) -->
            <section v-if="isClubAdviser" class="bento p-6">
                <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                    <ShieldCheck class="size-5 text-[#0384C5]" />
                    Counselor / Adviser Triage Inbox (Human Priority Confirmation)
                </div>

                <div v-if="counselorInbox.length === 0" class="mt-3 text-xs text-slate-500">
                    No referrals awaiting confirmation in your campus.
                </div>

                <div v-else class="mt-4 grid gap-3 md:grid-cols-2">
                    <div
                        v-for="item in counselorInbox"
                        :key="`inbox-${item.id}`"
                        class="flex flex-col justify-between gap-3 rounded-2xl border border-black/10 bg-white p-4 text-xs"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-bold text-slate-900">{{ item.student_identifier }}</span>
                                <span class="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-[11px] font-bold text-amber-800 uppercase">
                                    {{ item.status }}
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-500">
                                Submitted by {{ item.submitter_name }} • Suggested: {{ item.suggested_priority }}
                            </div>
                            <p class="mt-2 rounded-xl bg-[#FBF6EE] p-2.5 text-slate-700">
                                {{ item.concern_summary }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <select
                                :value="confirmPriorityMap[item.id] ?? item.counselor_confirmed_priority ?? item.suggested_priority"
                                @change="confirmPriorityMap[item.id] = ($event.target as HTMLSelectElement).value"
                                class="rounded-xl border border-black/15 bg-[#FBF6EE] px-2.5 py-1.5 text-xs"
                            >
                                <option v-for="tier in priorityTiers" :key="tier.value" :value="tier.value">
                                    {{ tier.label }}
                                </option>
                            </select>
                            <select
                                :value="confirmStatusMap[item.id] ?? 'in_review'"
                                @change="confirmStatusMap[item.id] = ($event.target as HTMLSelectElement).value"
                                class="rounded-xl border border-black/15 bg-[#FBF6EE] px-2.5 py-1.5 text-xs"
                            >
                                <option value="in_review">In Review</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="resolved">Resolved</option>
                            </select>
                            <button type="button" @click="confirmReferralPriority(item)" class="bento-btn py-1.5">
                                Confirm Triage
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- TAB 3: FR-07 OFFICERS PRIVATE GC & COUNSELORS GC (MESSENGER-STYLE UI) -->
        <div v-else-if="activeTab === 'counselors_gc' && isClubAdviser" class="flex flex-col gap-5">
            <OfficersMessengerChat
                :channel="officerChannel"
                :initial-messages="officerMessages"
                :counselor-messages="counselorGcMessages"
                :can-access-counselor-gc="isClubAdviser"
                default-thread="counselors"
                :current-user-id="currentUserId"
                :campus-name="campusName"
                :can-archive="false"
            />
        </div>

        <div v-else-if="activeTab === 'gc' && isOfficerOrAdviser" class="flex flex-col gap-5">
            <OfficersMessengerChat
                :channel="officerChannel"
                :initial-messages="officerMessages"
                :counselor-messages="counselorGcMessages"
                :can-access-counselor-gc="isClubAdviser"
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
    </div>
</template>
