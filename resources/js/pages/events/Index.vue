<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bell,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Clock,
    Lock,
    MapPin,
    Pencil,
    PlusCircle,
    Search,
    Sparkles,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EventCalendarGrid from '@/components/events/EventCalendarGrid.vue';
import type { CampusEventSummary } from '@/components/events/EventCalendarGrid.vue';
import EventQrScanner from '@/components/events/EventQrScanner.vue';
import { apiRequest } from '@/lib/csrfFetch';

interface ChecklistRow {
    user_id: number;
    full_name_last_first_mi: string;
    course_year_section: string;
    role: string;
    member_id_code: string;
    logged_in_at: string | null;
    logged_in_by: string | null;
    logged_out_at: string | null;
    logged_out_by: string | null;
    has_reflection: boolean;
}

interface CampusEventFull extends CampusEventSummary {
    creator_name: string;
    approver_name: string | null;
    approved_at: string | null;
    canceller_name: string | null;
    cancelled_at: string | null;
    activated_at: string | null;
    completed_at: string | null;
    logged_in_count: number;
    logged_out_count: number;
    reflection_count: number;
    my_reflection_submitted: boolean;
    my_reflection_capsule_id: number | null;
    my_reflection_submitted_at: string | null;
    checklist: ChecklistRow[];
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

const props = defineProps<{
    campusId: number | null;
    campusName: string;
    campusCode: string;
    canProposeEvents: boolean;
    isClubAdviser: boolean;
    isOfficerOrAdviser: boolean;
    isVerifiedMember: boolean;
    myUserId: number;
    todayDate: string;
    minScheduleDate: string;
    events: CampusEventFull[];
    campusMembers: Array<{
        user_id: number;
        full_name_last_first_mi: string;
        course_year_section: string;
        role: string;
        member_id_code: string;
    }>;
    notifications: EventNotificationItem[];
    unreadNotificationCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Events & Activities',
                href: '/events-activities',
            },
        ],
    },
});

const activeTab = ref<'schedule' | 'attendance' | 'reflections'>('schedule');
const showNotificationsPanel = ref(false);
const statusBanner = ref<string | null>(null);
const errorBanner = ref<string | null>(null);
const busy = ref(false);

// Default selected event: active first, else upcoming scheduled/pending, else latest completed
const defaultEventId = computed(() => {
    const active = props.events.find((e) => e.effective_status === 'active');
    if (active) return active.id;
    const pendingOrScheduled = props.events.find(
        (e) => e.effective_status === 'pending_approval' || e.effective_status === 'scheduled',
    );
    if (pendingOrScheduled) return pendingOrScheduled.id;
    return props.events[0]?.id ?? null;
});

const selectedEventId = ref<number | null>(defaultEventId.value);
const selectedEvent = computed(
    () => props.events.find((e) => e.id === selectedEventId.value) ?? props.events[0] ?? null,
);

// Create Event Form (Adviser, President, Vice President)
const newTitle = ref('');
const newDate = ref(props.minScheduleDate);
const newStartTime = ref('09:00');
const newEndTime = ref('11:00');
const newLocation = ref('');
const newDescription = ref('');

async function createEvent() {
    if (busy.value) return;
    busy.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{ status?: string; message?: string }>(
        '/events-activities',
        'POST',
        {
            title: newTitle.value,
            event_date: newDate.value,
            start_time: newStartTime.value,
            end_time: newEndTime.value,
            location: newLocation.value || null,
            description: newDescription.value || null,
        },
    );

    busy.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to schedule event.';
        return;
    }

    newTitle.value = '';
    newLocation.value = '';
    newDescription.value = '';
    statusBanner.value =
        data.status === 'approved'
            ? 'Campus event scheduled & approved! Members have been notified.'
            : 'Event proposal submitted! Waiting for Club Adviser approval.';
    router.reload();
}

// Adviser Reschedule Form
const editingSchedule = ref(false);
const editTitle = ref('');
const editDate = ref('');
const editStartTime = ref('');
const editEndTime = ref('');
const editLocation = ref('');
const editDescription = ref('');

function openEditSchedule(ev: CampusEventFull) {
    editingSchedule.value = true;
    editTitle.value = ev.title;
    editDate.value = ev.event_date;
    editStartTime.value = ev.start_time;
    editEndTime.value = ev.end_time;
    editLocation.value = ev.location ?? '';
    editDescription.value = ev.description ?? '';
}

watch(selectedEventId, () => {
    editingSchedule.value = false;
    cancelPromptOpen.value = false;
});

async function submitEditSchedule() {
    if (!selectedEvent.value || busy.value) return;
    busy.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/events-activities/${selectedEvent.value.id}/schedule`,
        'PATCH',
        {
            title: editTitle.value,
            event_date: editDate.value,
            start_time: editStartTime.value,
            end_time: editEndTime.value,
            location: editLocation.value || null,
            description: editDescription.value || null,
        },
    );

    busy.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to update schedule.';
        return;
    }

    editingSchedule.value = false;
    statusBanner.value = 'Event schedule updated by Club Adviser and members notified.';
    router.reload();
}

async function handleDropReschedule(payload: { eventId: number; newDate: string }) {
    if (busy.value) return;
    busy.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/events-activities/${payload.eventId}/schedule`,
        'PATCH',
        {
            event_date: payload.newDate,
        },
    );

    busy.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to move event to the dropped date.';
        return;
    }

    selectedEventId.value = payload.eventId;
    statusBanner.value = `Event dragged to ${payload.newDate} cleanly — members notified.`;
    router.reload();
}

function handleInvalidDrop(msg: string) {
    statusBanner.value = null;
    errorBanner.value = msg;
}

async function approveEvent(ev: CampusEventFull) {
    if (busy.value) return;
    busy.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/events-activities/${ev.id}/approve`,
        'PATCH',
        {},
    );

    busy.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to approve event.';
        return;
    }

    statusBanner.value = `"${ev.title}" approved! Members can view it on their Dashboard & receive notifications.`;
    router.reload();
}

const cancelPromptOpen = ref(false);
const cancelReason = ref('');

async function cancelEvent(ev: CampusEventFull) {
    if (busy.value) return;
    busy.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/events-activities/${ev.id}/cancel`,
        'PATCH',
        {
            cancellation_reason: cancelReason.value || null,
        },
    );

    busy.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to cancel event.';
        return;
    }

    cancelPromptOpen.value = false;
    cancelReason.value = '';
    statusBanner.value = `"${ev.title}" has been cancelled and members notified.`;
    router.reload();
}

// Attendance Checklist Search & Filter
const checklistSearch = ref('');
const checklistFilter = ref<'all' | 'complete' | 'in_only' | 'missing'>('all');

const filteredChecklist = computed(() => {
    const list = selectedEvent.value?.checklist ?? [];
    const q = checklistSearch.value.trim().toLowerCase();

    return list.filter((row) => {
        const isComplete = Boolean(row.logged_in_at && row.logged_out_at);
        const isInOnly = Boolean(row.logged_in_at && !row.logged_out_at);
        const isMissing = !row.logged_in_at;

        if (checklistFilter.value === 'complete' && !isComplete) return false;
        if (checklistFilter.value === 'in_only' && !isInOnly) return false;
        if (checklistFilter.value === 'missing' && !isMissing) return false;

        if (!q) return true;
        return (
            row.full_name_last_first_mi.toLowerCase().includes(q) ||
            row.course_year_section.toLowerCase().includes(q) ||
            row.member_id_code.toLowerCase().includes(q)
        );
    });
});

// Post-Event Reflections -> Encrypted Time Capsule
const completedEvents = computed(() =>
    props.events.filter((e) => e.effective_status === 'completed'),
);

const reflectionTitles = ref<Record<number, string>>({});
const reflectionMessages = ref<Record<number, string>>({});
const reflectionUnlockDates = ref<Record<number, string>>({});

async function submitReflection(ev: CampusEventFull) {
    if (busy.value) return;
    busy.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/events-activities/${ev.id}/reflection`,
        'POST',
        {
            title: reflectionTitles.value[ev.id] || `Reflection: ${ev.title}`,
            reflection_message: reflectionMessages.value[ev.id] || '',
            unlock_at: reflectionUnlockDates.value[ev.id] || '',
        },
    );

    busy.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to save reflection into your Time Capsule.';
        return;
    }

    reflectionTitles.value[ev.id] = '';
    reflectionMessages.value[ev.id] = '';
    reflectionUnlockDates.value[ev.id] = '';
    statusBanner.value = `Reflection for "${ev.title}" sealed into your encrypted Time Capsule!`;
    router.reload();
}

// Notifications
async function markNotificationRead(id: number) {
    const { ok } = await apiRequest(`/events-activities/notifications/${id}/read`, 'PATCH', {});
    if (ok) {
        router.reload({ only: ['notifications', 'unreadNotificationCount'] });
    }
}

async function markAllNotificationsRead() {
    const { ok } = await apiRequest('/events-activities/notifications/read-all', 'POST', {});
    if (ok) {
        router.reload({ only: ['notifications', 'unreadNotificationCount'] });
    }
}

function statusPillLabel(ev: CampusEventFull): string {
    switch (ev.effective_status) {
        case 'active':
            return 'Active Now (QR Open)';
        case 'scheduled':
            return 'Approved & Scheduled';
        case 'pending_approval':
            return 'Pending Adviser Approval';
        case 'completed':
            return 'Completed';
        case 'cancelled':
            return 'Cancelled';
    }
}

function statusPillClasses(ev: CampusEventFull): string {
    switch (ev.effective_status) {
        case 'active':
            return 'bg-[#1A8F26] text-white';
        case 'scheduled':
            return 'bg-[#0384C5]/15 text-[#0384C5]';
        case 'pending_approval':
            return 'bg-amber-500/20 text-amber-900';
        case 'completed':
            return 'bg-emerald-100 text-emerald-900';
        case 'cancelled':
            return 'bg-red-100 text-red-700';
    }
}
</script>

<template>
    <Head title="Events & Activities" />

    <div class="flex min-h-full flex-1 flex-col gap-6 bg-[#FBF6EE] p-4 text-slate-900 md:p-6">
        <!-- Header & Space Tabs -->
        <div class="bento flex flex-wrap items-center justify-between gap-4 p-5">
            <div>
                <span class="rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                    {{ campusName }}
                </span>
                <h1 class="font-editorial mt-2 text-2xl font-bold text-slate-900">
                    Events &amp; Activities
                </h1>
                <p class="mt-0.5 text-xs text-slate-600">
                    Event scheduling (1-day advance limit), automatic activation, Officer QR Log-In/Log-Out scanner, Checklist list view &amp; Time Capsule reflections.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="activeTab = 'schedule'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'schedule' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <CalendarDays class="size-4" />
                    Schedule &amp; Calendar
                </button>
                <button
                    type="button"
                    @click="activeTab = 'attendance'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'attendance' ? 'bg-[#1A8F26] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <ClipboardList class="size-4" />
                    QR Scanner &amp; Checklist
                </button>
                <button
                    type="button"
                    @click="activeTab = 'reflections'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'reflections' ? 'bg-[#FF8D06] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <Sparkles class="size-4" />
                    Reflections &rarr; Time Capsule
                </button>
                <button
                    type="button"
                    @click="showNotificationsPanel = !showNotificationsPanel"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-black/15 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:border-[#0384C5]"
                >
                    <Bell class="size-4 text-[#FF8D06]" />
                    Notifications
                    <span
                        v-if="unreadNotificationCount > 0"
                        class="rounded-full bg-[#FF8D06] px-1.5 py-0.5 text-[10px] font-bold text-white"
                    >
                        {{ unreadNotificationCount }}
                    </span>
                </button>
            </div>
        </div>

        <!-- Feedback Banners -->
        <div
            v-if="statusBanner"
            class="rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-3 text-xs font-semibold text-[#1A8F26]"
        >
            {{ statusBanner }}
        </div>
        <div
            v-if="errorBanner"
            class="rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700"
        >
            {{ errorBanner }}
        </div>

        <!-- Collapsible Notifications Panel -->
        <section v-if="showNotificationsPanel" class="bento p-5">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-black/10 pb-3">
                <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                    <Bell class="size-4 text-[#FF8D06]" />
                    Event &amp; Schedule Notifications
                </div>
                <button
                    v-if="unreadNotificationCount > 0"
                    type="button"
                    @click="markAllNotificationsRead"
                    class="rounded-xl border border-[#0384C5]/30 bg-[#0384C5]/10 px-3 py-1 text-xs font-semibold text-[#0384C5]"
                >
                    Mark All Read
                </button>
            </div>

            <div v-if="notifications.length === 0" class="mt-3 text-xs text-slate-500">
                No event notifications yet.
            </div>
            <div v-else class="mt-3 grid gap-2 md:grid-cols-2">
                <div
                    v-for="notif in notifications"
                    :key="notif.id"
                    class="flex items-start justify-between gap-3 rounded-2xl border p-3 text-xs"
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
                        @click="markNotificationRead(notif.id)"
                        class="shrink-0 rounded-lg border border-black/15 bg-white px-2 py-1 text-[10px] font-semibold text-slate-700"
                    >
                        Read
                    </button>
                </div>
            </div>
        </section>

        <!-- TAB 1: SCHEDULE & CALENDAR -->
        <div v-if="activeTab === 'schedule'" class="flex flex-col gap-5">
            <!-- Propose / Schedule Event Form (Adviser, President, VP) -->
            <section v-if="canProposeEvents" class="bento p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="flex items-center gap-1.5 text-sm font-bold text-slate-900">
                            <PlusCircle class="size-4 text-[#0384C5]" />
                            Schedule Campus Event / Activity (Adviser, President &amp; VP)
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-600">
                            Must be scheduled at least <strong>1 day in advance</strong> (earliest: <strong>{{ minScheduleDate }}</strong>).
                            {{ isClubAdviser ? 'Events you create are immediately approved.' : 'President / VP proposals require Club Adviser approval before going live.' }}
                        </p>
                    </div>
                </div>

                <form class="mt-4 grid gap-3 text-xs md:grid-cols-6" @submit.prevent="createEvent">
                    <div class="md:col-span-2">
                        <label class="mb-1 block font-bold">Event Title *</label>
                        <input
                            v-model="newTitle"
                            required
                            placeholder="e.g. Peer Listening Caravan"
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold">Date * (Min 1 day ahead)</label>
                        <input
                            v-model="newDate"
                            type="date"
                            :min="minScheduleDate"
                            required
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold">Start Time *</label>
                        <input
                            v-model="newStartTime"
                            type="time"
                            required
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold">End Time *</label>
                        <input
                            v-model="newEndTime"
                            type="time"
                            required
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold">Venue / Room</label>
                        <input
                            v-model="newLocation"
                            placeholder="e.g. Student Center"
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div class="md:col-span-5">
                        <input
                            v-model="newDescription"
                            placeholder="Optional activity agenda or notes for members..."
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div class="flex items-center">
                        <button type="submit" :disabled="busy" class="bento-btn w-full justify-center">
                            {{ isClubAdviser ? 'Schedule Event' : 'Propose Event' }}
                        </button>
                    </div>
                </form>
            </section>

            <!-- Calendar Grid + Selected Event Controls -->
            <div class="grid gap-5 xl:grid-cols-[1.35fr_1fr]">
                <EventCalendarGrid
                    :events="events"
                    :selected-event-id="selectedEvent?.id ?? null"
                    :can-drag-reschedule="isClubAdviser"
                    :min-schedule-date="minScheduleDate"
                    @select-event="selectedEventId = $event.id"
                    @drop-reschedule="handleDropReschedule"
                    @invalid-drop="handleInvalidDrop"
                />

                <!-- Selected Event Inspector & Adviser Controls -->
                <div class="flex flex-col gap-4">
                    <section v-if="selectedEvent" class="bento p-5">
                        <div class="flex flex-wrap items-start justify-between gap-2 border-b border-black/10 pb-3">
                            <div>
                                <span
                                    class="inline-block rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                                    :class="statusPillClasses(selectedEvent)"
                                >
                                    {{ statusPillLabel(selectedEvent) }}
                                </span>
                                <h2 class="font-editorial mt-2 text-lg font-bold text-slate-900">
                                    {{ selectedEvent.title }}
                                </h2>
                                <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-600">
                                    <span class="inline-flex items-center gap-1 font-semibold text-slate-800">
                                        <Clock class="size-3.5 text-[#0384C5]" />
                                        {{ selectedEvent.event_date_label }} • {{ selectedEvent.start_time }}–{{ selectedEvent.end_time }}
                                    </span>
                                    <span v-if="selectedEvent.location" class="inline-flex items-center gap-1">
                                        <MapPin class="size-3.5 text-[#FF8D06]" />
                                        {{ selectedEvent.location }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <p v-if="selectedEvent.description" class="mt-3 text-xs leading-relaxed text-slate-700">
                            {{ selectedEvent.description }}
                        </p>

                        <div class="mt-3 rounded-xl bg-[#FBF6EE] p-3 text-[11px] text-slate-600">
                            <div>Proposed by: <strong>{{ selectedEvent.creator_name }}</strong></div>
                            <div v-if="selectedEvent.approver_name">
                                Approved by: <strong>{{ selectedEvent.approver_name }}</strong>
                                <span v-if="selectedEvent.approved_at">({{ selectedEvent.approved_at }})</span>
                            </div>
                            <div v-if="selectedEvent.effective_status === 'scheduled'" class="mt-1 font-semibold text-[#0384C5]">
                                Activates automatically on {{ selectedEvent.event_date }} at {{ selectedEvent.start_time }}.
                            </div>
                            <div v-if="selectedEvent.within_one_day_cutoff && selectedEvent.effective_status === 'scheduled'" class="mt-1 inline-flex items-center gap-1 font-semibold text-amber-800">
                                <Lock class="size-3" /> Within 1-day cutoff — schedule changes are locked.
                            </div>
                            <div v-if="selectedEvent.effective_status === 'cancelled' && selectedEvent.cancellation_reason" class="mt-1 font-semibold text-red-700">
                                Cancellation reason: {{ selectedEvent.cancellation_reason }}
                            </div>
                        </div>

                        <!-- Adviser Controls -->
                        <div v-if="isClubAdviser" class="mt-4 flex flex-wrap gap-2">
                            <button
                                v-if="selectedEvent.effective_status === 'pending_approval'"
                                type="button"
                                :disabled="busy"
                                @click="approveEvent(selectedEvent)"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-[#1A8F26] px-3.5 py-2 text-xs font-bold text-white"
                            >
                                <CheckCircle2 class="size-3.5" />
                                Approve Event
                            </button>

                            <button
                                v-if="selectedEvent.can_edit_schedule"
                                type="button"
                                @click="editingSchedule ? (editingSchedule = false) : openEditSchedule(selectedEvent)"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-[#0384C5]/40 bg-[#0384C5]/10 px-3.5 py-2 text-xs font-bold text-[#0384C5]"
                            >
                                <Pencil class="size-3.5" />
                                {{ editingSchedule ? 'Close Schedule Editor' : 'Change Schedule / Details' }}
                            </button>

                            <button
                                v-if="selectedEvent.effective_status !== 'completed' && selectedEvent.effective_status !== 'cancelled'"
                                type="button"
                                @click="cancelPromptOpen = !cancelPromptOpen"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-red-300 bg-white px-3.5 py-2 text-xs font-bold text-red-700"
                            >
                                <XCircle class="size-3.5" />
                                Cancel Event
                            </button>
                        </div>

                        <!-- Adviser Input Schedule Editor -->
                        <form
                            v-if="isClubAdviser && editingSchedule && selectedEvent.can_edit_schedule"
                            class="mt-4 grid gap-2.5 rounded-2xl border border-[#0384C5]/30 bg-[#FBF6EE] p-4 text-xs"
                            @submit.prevent="submitEditSchedule"
                        >
                            <div class="font-bold text-[#0384C5]">Adviser Input Schedule Change</div>
                            <input v-model="editTitle" required placeholder="Event Title" class="rounded-xl border border-black/15 bg-white px-3 py-1.5" />
                            <div class="grid gap-2 sm:grid-cols-3">
                                <input v-model="editDate" type="date" :min="minScheduleDate" required class="rounded-xl border border-black/15 bg-white px-2.5 py-1.5" />
                                <input v-model="editStartTime" type="time" required class="rounded-xl border border-black/15 bg-white px-2.5 py-1.5" />
                                <input v-model="editEndTime" type="time" required class="rounded-xl border border-black/15 bg-white px-2.5 py-1.5" />
                            </div>
                            <input v-model="editLocation" placeholder="Venue / Location" class="rounded-xl border border-black/15 bg-white px-3 py-1.5" />
                            <input v-model="editDescription" placeholder="Description" class="rounded-xl border border-black/15 bg-white px-3 py-1.5" />
                            <button type="submit" :disabled="busy" class="bento-btn w-fit py-1.5">
                                Save New Schedule
                            </button>
                        </form>

                        <!-- Adviser Cancellation Prompt -->
                        <form
                            v-if="isClubAdviser && cancelPromptOpen"
                            class="mt-4 flex flex-col gap-2 rounded-2xl border border-red-200 bg-red-50/80 p-4 text-xs"
                            @submit.prevent="cancelEvent(selectedEvent)"
                        >
                            <label class="font-bold text-red-800">Confirm Cancellation (Members will be notified)</label>
                            <input
                                v-model="cancelReason"
                                placeholder="Optional cancellation reason..."
                                class="rounded-xl border border-red-200 bg-white px-3 py-1.5"
                            />
                            <button type="submit" :disabled="busy" class="w-fit rounded-xl bg-red-600 px-3.5 py-1.5 font-bold text-white">
                                Confirm Cancel Event
                            </button>
                        </form>

                        <!-- Quick Tab Shortcuts -->
                        <div class="mt-4 flex flex-wrap gap-2 border-t border-black/10 pt-3">
                            <button
                                v-if="selectedEvent.effective_status === 'active' || selectedEvent.effective_status === 'completed'"
                                type="button"
                                @click="activeTab = 'attendance'"
                                class="rounded-xl bg-[#1A8F26]/12 px-3 py-1.5 text-xs font-bold text-[#1A8F26]"
                            >
                                {{ selectedEvent.effective_status === 'active' ? 'Open Officer QR Scanner & Checklist →' : 'View Attendance Checklist →' }}
                            </button>
                            <button
                                v-if="selectedEvent.effective_status === 'completed'"
                                type="button"
                                @click="activeTab = 'reflections'"
                                class="rounded-xl bg-[#FF8D06]/15 px-3 py-1.5 text-xs font-bold text-[#FF8D06]"
                            >
                                Write Reflection for Time Capsule →
                            </button>
                        </div>
                    </section>

                    <!-- All Campus Events Quick Selector -->
                    <section class="bento p-5">
                        <h3 class="text-sm font-bold text-slate-900">Campus Events Directory ({{ events.length }})</h3>
                        <div v-if="events.length === 0" class="mt-2 text-xs text-slate-500">
                            No campus events scheduled yet.
                        </div>
                        <div v-else class="mt-3 flex max-h-64 flex-col gap-2 overflow-y-auto pr-1">
                            <button
                                v-for="ev in events"
                                :key="`list-${ev.id}`"
                                type="button"
                                @click="selectedEventId = ev.id"
                                class="flex items-center justify-between gap-2 rounded-xl border p-2.5 text-left text-xs transition"
                                :class="
                                    selectedEvent?.id === ev.id
                                        ? 'border-[#0384C5] bg-[#0384C5]/5'
                                        : 'border-black/10 bg-white hover:border-black/25'
                                "
                            >
                                <div class="min-w-0">
                                    <div class="truncate font-bold text-slate-900">{{ ev.title }}</div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ ev.event_date_label }} • {{ ev.start_time }}–{{ ev.end_time }}
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold"
                                    :class="statusPillClasses(ev)"
                                >
                                    {{ statusPillLabel(ev) }}
                                </span>
                            </button>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <!-- TAB 2: OFFICER QR SCANNER & ATTENDANCE CHECKLIST (LIST VIEW) -->
        <div v-else-if="activeTab === 'attendance'" class="flex flex-col gap-5">
            <div class="bento flex flex-wrap items-center justify-between gap-3 p-4 text-xs">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-bold text-slate-700">Selected Activity:</span>
                    <select
                        v-model="selectedEventId"
                        class="rounded-xl border border-black/15 bg-white px-3 py-2 font-semibold text-slate-900"
                    >
                        <option v-for="ev in events" :key="`opt-${ev.id}`" :value="ev.id">
                            {{ ev.title }} ({{ ev.event_date_label }} • {{ statusPillLabel(ev) }})
                        </option>
                    </select>
                </div>

                <div v-if="selectedEvent" class="flex flex-wrap items-center gap-2 font-bold">
                    <span class="rounded-full bg-[#1A8F26]/12 px-3 py-1 text-[#1A8F26]">
                        Logged In: {{ selectedEvent.logged_in_count }}
                    </span>
                    <span class="rounded-full bg-[#FF8D06]/15 px-3 py-1 text-[#FF8D06]">
                        Logged Out: {{ selectedEvent.logged_out_count }}
                    </span>
                    <span class="rounded-full bg-[#0384C5]/12 px-3 py-1 text-[#0384C5]">
                        Reflections Sealed: {{ selectedEvent.reflection_count }}
                    </span>
                </div>
            </div>

            <template v-if="selectedEvent">
                <EventQrScanner
                    v-if="isOfficerOrAdviser && selectedEvent.effective_status === 'active'"
                    :event-id="selectedEvent.id"
                    :event-title="selectedEvent.title"
                    :campus-members="campusMembers"
                    @scanned="router.reload()"
                />

                <div
                    v-else-if="isOfficerOrAdviser"
                    class="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900"
                >
                    <Lock class="mr-1.5 inline size-4" />
                    <strong>QR Scanner Locked:</strong> The Attendance QR Scanner unlocks automatically only while this event is <strong>Active</strong> ({{ selectedEvent.event_date_label }} • {{ selectedEvent.start_time }}–{{ selectedEvent.end_time }}).
                </div>

                <!-- Separate List-View Attendance Checklist -->
                <section class="bento p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-black/10 pb-3">
                        <div>
                            <h2 class="text-base font-bold text-slate-900">
                                Attendance Checklist (List View) — {{ selectedEvent.title }}
                            </h2>
                            <p class="text-xs text-slate-600">
                                Real-time A–Z attendance roster tracking Log-In (start), Log-Out (end), and reflection completion.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <div class="relative">
                                <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-slate-400" />
                                <input
                                    v-model="checklistSearch"
                                    type="text"
                                    placeholder="Search member or ID..."
                                    class="rounded-xl border border-black/15 bg-[#FBF6EE] py-1.5 pr-3 pl-8"
                                />
                            </div>
                            <select
                                v-model="checklistFilter"
                                class="rounded-xl border border-black/15 bg-[#FBF6EE] px-3 py-1.5 font-medium"
                            >
                                <option value="all">All ({{ selectedEvent.checklist.length }})</option>
                                <option value="complete">Present In &amp; Out</option>
                                <option value="in_only">Logged In Only</option>
                                <option value="missing">Not Scanned Yet</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="filteredChecklist.length === 0" class="py-8 text-center text-xs text-slate-500">
                        No members match this checklist filter.
                    </div>
                    <div v-else class="mt-4 flex flex-col gap-2">
                        <div
                            v-for="(row, idx) in filteredChecklist"
                            :key="`chk-${row.user_id}`"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-3.5 text-xs"
                            :class="
                                row.logged_in_at && row.logged_out_at
                                    ? 'border-[#1A8F26]/30 bg-[#1A8F26]/5'
                                    : row.logged_in_at
                                      ? 'border-amber-300 bg-amber-50/70'
                                      : 'border-black/10 bg-white'
                            "
                        >
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-mono text-[11px] font-bold text-slate-400">#{{ idx + 1 }}</span>
                                    <span class="text-sm font-bold text-slate-900">{{ row.full_name_last_first_mi }}</span>
                                    <span class="rounded-full bg-[#0384C5]/10 px-2 py-0.5 text-[10px] font-semibold text-[#0384C5]">
                                        {{ row.course_year_section }}
                                    </span>
                                    <span class="rounded-full bg-[#FBF6EE] px-2 py-0.5 text-[10px] font-medium text-slate-700">
                                        {{ row.role }}
                                    </span>
                                    <span class="font-mono text-[10px] text-slate-500">{{ row.member_id_code }}</span>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded-full px-2.5 py-1 text-[11px] font-bold"
                                    :class="row.logged_in_at ? 'bg-[#1A8F26]/15 text-[#1A8F26]' : 'bg-slate-100 text-slate-500'"
                                >
                                    {{ row.logged_in_at ? `In: ${row.logged_in_at}` : 'In: Pending' }}
                                </span>
                                <span
                                    class="rounded-full px-2.5 py-1 text-[11px] font-bold"
                                    :class="row.logged_out_at ? 'bg-[#0384C5]/15 text-[#0384C5]' : 'bg-slate-100 text-slate-500'"
                                >
                                    {{ row.logged_out_at ? `Out: ${row.logged_out_at}` : 'Out: Pending' }}
                                </span>
                                <span
                                    v-if="row.has_reflection"
                                    class="rounded-full bg-[#FF8D06]/15 px-2.5 py-1 text-[11px] font-bold text-[#FF8D06]"
                                >
                                    Reflection Sealed ✓
                                </span>
                            </div>
                        </div>
                    </div>
                </section>
            </template>
        </div>

        <!-- TAB 3: POST-EVENT REFLECTIONS -> TIME CAPSULE -->
        <div v-else class="flex flex-col gap-5">
            <div class="bento flex flex-wrap items-center justify-between gap-3 p-5 text-xs">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Post-Event Reflections &rarr; Encrypted Time Capsule</h2>
                    <p class="mt-0.5 text-slate-600">
                        After any campus activity finishes, write your personal reflection here. It is AES-256 encrypted and sealed straight into your date-locked Time Capsule.
                    </p>
                </div>
                <Link href="/community-hub" class="bento-btn">
                    View My Time Capsules
                </Link>
            </div>

            <div v-if="completedEvents.length === 0" class="bento p-6 text-xs text-slate-500">
                No completed events yet — reflections unlock automatically once a scheduled event’s end time has passed.
            </div>

            <section
                v-for="ev in completedEvents"
                :key="`refl-${ev.id}`"
                class="bento p-5"
            >
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-black/10 pb-3">
                    <div>
                        <h3 class="font-editorial text-lg font-bold text-slate-900">{{ ev.title }}</h3>
                        <p class="text-xs text-slate-600">
                            Completed on {{ ev.event_date_label }} • {{ ev.start_time }}–{{ ev.end_time }}
                        </p>
                    </div>
                    <span
                        v-if="ev.my_reflection_submitted"
                        class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/15 px-3 py-1 text-xs font-bold text-[#1A8F26]"
                    >
                        <CheckCircle2 class="size-3.5" />
                        Reflection Sealed in Your Time Capsule ✓
                    </span>
                </div>

                <div v-if="ev.my_reflection_submitted" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-[#FBF6EE] p-4 text-xs text-slate-700">
                    <span>
                        Your reflection was encrypted and stored in Time Capsule #{{ ev.my_reflection_capsule_id }} ({{ ev.my_reflection_submitted_at }}).
                    </span>
                    <Link href="/community-hub" class="font-bold text-[#0384C5] underline">
                        Open Community &amp; Archives &rarr; Time Capsules
                    </Link>
                </div>

                <form
                    v-else-if="isVerifiedMember"
                    class="mt-4 grid gap-3 text-xs"
                    @submit.prevent="submitReflection(ev)"
                >
                    <div class="grid gap-3 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block font-bold">Time Capsule Title</label>
                            <input
                                v-model="reflectionTitles[ev.id]"
                                :placeholder="`Reflection: ${ev.title}`"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Time Capsule Unlock Date &amp; Time *</label>
                            <input
                                v-model="reflectionUnlockDates[ev.id]"
                                type="datetime-local"
                                required
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                            />
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block font-bold">Your Post-Event Reflection * (min 10 characters)</label>
                        <textarea
                            v-model="reflectionMessages[ev.id]"
                            rows="3"
                            minlength="10"
                            required
                            placeholder="What stayed with you from today’s activity, and what do you want to remember when this Time Capsule unlocks?"
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>

                    <button type="submit" :disabled="busy" class="bento-btn w-fit">
                        <Lock class="size-3.5" />
                        Save &amp; Seal Reflection to Time Capsule
                    </button>
                </form>

                <div v-else class="mt-3 text-xs text-slate-500">
                    Verified campus membership is required to submit post-event reflections.
                </div>
            </section>
        </div>
    </div>
</template>
