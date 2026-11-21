<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight, Clock, Lock, ShieldCheck, UserCheck, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

export interface CounselorVacantSlot {
    start: string;
    end: string;
    label: string;
    days?: number[];
}

export interface CounselorScheduleItem {
    id: number;
    name: string;
    title: string;
    specialty: string | null;
    weekly_vacant_slots: CounselorVacantSlot[];
}

export interface AnonymousCalendarBooking {
    date: string;
    start_time: string;
    end_time: string;
    counselor_id: number | null;
    status: 'booked';
}

const props = withDefaults(
    defineProps<{
        counselors: CounselorScheduleItem[];
        calendarBookings: AnonymousCalendarBooking[];
        campusName: string;
        submittingBooking?: boolean;
        bookingError?: string | null;
    }>(),
    {
        submittingBooking: false,
        bookingError: null,
    },
);

const emit = defineEmits<{
    (
        e: 'book-slot',
        payload: {
            date: string;
            startTime: string;
            endTime: string;
            counselorId: number;
            purpose: string;
        },
    ): void;
}>();

function toYmd(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function getMondayOfDateWeek(baseDate: Date): Date {
    const d = new Date(baseDate.getFullYear(), baseDate.getMonth(), baseDate.getDate());
    const dow = d.getDay(); // 0=Sun, 6=Sat
    if (dow === 0) {
        d.setDate(d.getDate() - 6);
    } else {
        d.setDate(d.getDate() - (dow - 1));
    }
    return d;
}

const now = new Date();
const currentYear = now.getFullYear();
const currentMonth = now.getMonth();
const todayYmd = toYmd(now);

const firstDayOfCurrentMonth = new Date(currentYear, currentMonth, 1);
const lastDayOfCurrentMonth = new Date(currentYear, currentMonth + 1, 0);
const minMonthMonday = getMondayOfDateWeek(firstDayOfCurrentMonth);
const maxMonthMonday = getMondayOfDateWeek(lastDayOfCurrentMonth);

function getInitialWeekMonday(): Date {
    const dow = now.getDay();
    let candidate = new Date(currentYear, currentMonth, now.getDate());
    if (dow === 6) {
        candidate.setDate(candidate.getDate() + 2);
    } else if (dow === 0) {
        candidate.setDate(candidate.getDate() + 1);
    }
    if (candidate.getMonth() !== currentMonth || candidate.getFullYear() !== currentYear) {
        candidate = new Date(currentYear, currentMonth, now.getDate());
    }
    return getMondayOfDateWeek(candidate);
}

const weekAnchorMonday = ref<Date>(getInitialWeekMonday());
const selectedCounselorFilter = ref<number | 'all'>('all');

const canGoPrevWeek = computed(() => weekAnchorMonday.value.getTime() > minMonthMonday.getTime());
const canGoNextWeek = computed(() => weekAnchorMonday.value.getTime() < maxMonthMonday.getTime());

function shiftWeek(offsetWeeks: number) {
    const candidate = new Date(weekAnchorMonday.value);
    candidate.setDate(candidate.getDate() + offsetWeeks * 7);
    if (candidate.getTime() < minMonthMonday.getTime() || candidate.getTime() > maxMonthMonday.getTime()) {
        return;
    }
    weekAnchorMonday.value = candidate;
}

function resetToCurrentWeek() {
    weekAnchorMonday.value = getInitialWeekMonday();
}

const filteredCounselors = computed(() => {
    if (selectedCounselorFilter.value === 'all') {
        return props.counselors;
    }
    return props.counselors.filter((c) => c.id === selectedCounselorFilter.value);
});

const weekDays = computed(() => {
    const names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    const list: Array<{
        ymd: string;
        shortDay: string;
        dayNumber: number;
        label: string;
        dow: number;
        inCurrentMonth: boolean;
        isPast: boolean;
    }> = [];

    for (let i = 0; i < 5; i++) {
        const d = new Date(weekAnchorMonday.value);
        d.setDate(d.getDate() + i);
        const ymd = toYmd(d);
        const inCurrentMonth = d.getFullYear() === currentYear && d.getMonth() === currentMonth;
        list.push({
            ymd,
            shortDay: names[i],
            dayNumber: d.getDate(),
            label: d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
            dow: i + 1,
            inCurrentMonth,
            isPast: ymd < todayYmd,
        });
    }
    return list;
});

const weekRangeLabel = computed(() => {
    const inMonthDays = weekDays.value.filter((d) => d.inCurrentMonth);
    const span = inMonthDays.length > 0 ? inMonthDays : weekDays.value;
    if (span.length === 0) return '';
    return `${span[0].label} – ${span[span.length - 1].label}`;
});

const monthTitle = computed(() => {
    return firstDayOfCurrentMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
});

function slotAppliesToWeekday(slot: CounselorVacantSlot, dow: number): boolean {
    if (!slot.days || slot.days.length === 0) {
        return dow >= 1 && dow <= 5;
    }
    return slot.days.includes(dow);
}

function isSlotBooked(ymd: string, slot: CounselorVacantSlot, counselorId: number): boolean {
    return props.calendarBookings.some((b) => {
        if (b.date !== ymd) return false;
        if (b.counselor_id !== null && b.counselor_id !== counselorId) return false;
        return b.start_time < slot.end && b.end_time > slot.start;
    });
}

function hasBookingsOnDate(ymd: string): boolean {
    return props.calendarBookings.some((b) => {
        if (b.date !== ymd) return false;
        if (selectedCounselorFilter.value === 'all') return true;
        return b.counselor_id === null || b.counselor_id === selectedCounselorFilter.value;
    });
}

interface MonthCell {
    key: string;
    ymd: string | null;
    dayNumber: number | null;
    isWeekday: boolean;
    isToday: boolean;
    isPast: boolean;
    isBooked: boolean;
}

const monthGridCells = computed<MonthCell[]>(() => {
    const daysInMonth = lastDayOfCurrentMonth.getDate();
    const startOffset = firstDayOfCurrentMonth.getDay();

    const cells: MonthCell[] = [];
    for (let i = 0; i < startOffset; i++) {
        cells.push({
            key: `pad-${i}`,
            ymd: null,
            dayNumber: null,
            isWeekday: false,
            isToday: false,
            isPast: false,
            isBooked: false,
        });
    }

    for (let day = 1; day <= daysInMonth; day++) {
        const d = new Date(currentYear, currentMonth, day);
        const dow = d.getDay();
        const ymd = toYmd(d);
        const isWeekday = dow >= 1 && dow <= 5;
        cells.push({
            key: ymd,
            ymd,
            dayNumber: day,
            isWeekday,
            isToday: ymd === todayYmd,
            isPast: ymd < todayYmd,
            isBooked: isWeekday && hasBookingsOnDate(ymd),
        });
    }

    return cells;
});

// Tap-to-Schedule Counseling Modal State
const modalOpen = ref(false);
const modalDate = ref<string>('');
const modalCounselorId = ref<number>(props.counselors[0]?.id ?? 0);
const modalSlotStart = ref<string>('');
const modalSlotEnd = ref<string>('');
const modalPurpose = ref<string>('');
const localModalError = ref<string | null>(null);

watch(
    () => props.counselors,
    (list) => {
        if (list.length > 0 && !list.some((c) => c.id === modalCounselorId.value)) {
            modalCounselorId.value = list[0].id;
        }
    },
);

watch(
    () => props.submittingBooking,
    (isSubmitting, wasSubmitting) => {
        if (wasSubmitting && !isSubmitting && !props.bookingError && modalOpen.value) {
            modalOpen.value = false;
            modalPurpose.value = '';
            modalSlotStart.value = '';
            modalSlotEnd.value = '';
            localModalError.value = null;
        }
    },
);

const modalCounselor = computed(
    () => props.counselors.find((c) => c.id === modalCounselorId.value) ?? props.counselors[0] ?? null,
);

const modalDateFormatted = computed(() => {
    if (!modalDate.value) return '';
    const [y, m, d] = modalDate.value.split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    });
});

function openDayModal(ymd: string, preferredCounselorId?: number, preferredSlot?: CounselorVacantSlot) {
    if (ymd < todayYmd) return;

    const chosenCounselor =
        props.counselors.find((c) => c.id === preferredCounselorId) ??
        (selectedCounselorFilter.value !== 'all'
            ? props.counselors.find((c) => c.id === selectedCounselorFilter.value)
            : props.counselors[0]) ??
        props.counselors[0];

    modalDate.value = ymd;
    modalCounselorId.value = chosenCounselor?.id ?? 0;
    modalPurpose.value = '';
    localModalError.value = null;

    if (preferredSlot) {
        modalSlotStart.value = preferredSlot.start;
        modalSlotEnd.value = preferredSlot.end;
    } else if (chosenCounselor) {
        const firstVacant = chosenCounselor.weekly_vacant_slots.find((s) => !isSlotBooked(ymd, s, chosenCounselor.id));
        modalSlotStart.value = firstVacant?.start ?? '';
        modalSlotEnd.value = firstVacant?.end ?? '';
    } else {
        modalSlotStart.value = '';
        modalSlotEnd.value = '';
    }

    modalOpen.value = true;
}

function selectModalCounselor(counselorId: number) {
    modalCounselorId.value = counselorId;
    const counselor = props.counselors.find((c) => c.id === counselorId);
    if (!counselor || !modalDate.value) return;

    const firstVacant = counselor.weekly_vacant_slots.find((s) => !isSlotBooked(modalDate.value, s, counselor.id));
    modalSlotStart.value = firstVacant?.start ?? '';
    modalSlotEnd.value = firstVacant?.end ?? '';
}

function submitModalBooking() {
    localModalError.value = null;
    if (!modalDate.value || !modalCounselorId.value || !modalSlotStart.value || !modalSlotEnd.value) {
        localModalError.value = 'Please choose a counselor and a vacant time slot.';
        return;
    }
    if (!modalPurpose.value.trim()) {
        localModalError.value = 'Please enter a short session purpose.';
        return;
    }

    emit('book-slot', {
        date: modalDate.value,
        startTime: modalSlotStart.value,
        endTime: modalSlotEnd.value,
        counselorId: modalCounselorId.value,
        purpose: modalPurpose.value.trim(),
    });
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <!-- MIDDLE CARD: CURRENT MONTH MONDAY–FRIDAY COUNSELOR AVAILABILITY -->
        <section class="bento p-6">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-black/10 pb-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Clock class="size-5 text-[#0384C5]" />
                        <h2 class="text-base font-bold text-slate-900">
                            Counselor Availability ({{ monthTitle }} • Mon–Fri Vacant Schedule)
                        </h2>
                        <span class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-[11px] font-bold text-[#1A8F26]">
                            <ShieldCheck class="size-3" />
                            Confidential • Client Details Hidden
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-slate-600">
                        Limited to <strong>{{ monthTitle }}</strong>. Tap any green <strong>Vacant</strong> time slot (or tap a vacant day on the calendar below) for <strong>{{ campusName }}</strong> to schedule counseling. Booked slots display <strong>Booked</strong> with zero student details.
                    </p>
                </div>

                <div class="flex items-center gap-1.5">
                    <button
                        type="button"
                        :disabled="!canGoPrevWeek"
                        @click="shiftWeek(-1)"
                        aria-label="Previous week in month"
                        class="rounded-xl border border-black/15 bg-white p-1.5 text-slate-700 hover:border-[#0384C5] disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <button
                        type="button"
                        @click="resetToCurrentWeek"
                        class="rounded-xl border border-black/15 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 hover:border-[#0384C5]"
                    >
                        {{ weekRangeLabel }}
                    </button>
                    <button
                        type="button"
                        :disabled="!canGoNextWeek"
                        @click="shiftWeek(1)"
                        aria-label="Next week in month"
                        class="rounded-xl border border-black/15 bg-white p-1.5 text-slate-700 hover:border-[#0384C5] disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>
            </div>

            <!-- 3 Sample Counselors Filter Pills -->
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="selectedCounselorFilter = 'all'"
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="
                        selectedCounselorFilter === 'all'
                            ? 'bg-[#0384C5] text-white'
                            : 'border border-black/15 bg-white text-slate-700 hover:border-[#0384C5]'
                    "
                >
                    <UserCheck class="size-3.5" />
                    All Counselors ({{ counselors.length }})
                </button>

                <button
                    v-for="counselor in counselors"
                    :key="`filter-${counselor.id}`"
                    type="button"
                    @click="selectedCounselorFilter = counselor.id"
                    class="rounded-full px-3 py-1.5 text-xs font-semibold transition"
                    :class="
                        selectedCounselorFilter === counselor.id
                            ? 'bg-[#0384C5] text-white'
                            : 'border border-black/15 bg-white text-slate-700 hover:border-[#0384C5]'
                    "
                >
                    {{ counselor.name }}
                </button>
            </div>

            <!-- Counselor Roster Header Cards + Monday-Friday 5-Column Grid -->
            <div class="mt-4 space-y-4">
                <div
                    v-for="counselor in filteredCounselors"
                    :key="`counselor-${counselor.id}`"
                    class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-black/10 pb-2.5">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">{{ counselor.name }}</h3>
                            <p class="text-[11px] text-slate-600">
                                {{ counselor.title }}
                                <template v-if="counselor.specialty"> • {{ counselor.specialty }}</template>
                            </p>
                        </div>
                        <span class="rounded-full border border-[#0384C5]/25 bg-white px-2.5 py-0.5 text-[10px] font-bold text-[#0384C5]">
                            Mon–Fri Office Hours
                        </span>
                    </div>

                    <div class="mt-3 grid gap-2.5 sm:grid-cols-5">
                        <div
                            v-for="day in weekDays"
                            :key="`${counselor.id}-${day.ymd}`"
                            class="flex flex-col gap-1.5 rounded-xl border border-black/10 p-2.5"
                            :class="!day.inCurrentMonth || day.isPast ? 'bg-slate-100/70 opacity-60' : 'bg-white'"
                        >
                            <div class="flex items-center justify-between border-b border-black/5 pb-1 text-[11px] font-bold text-slate-800">
                                <span>{{ day.shortDay }}</span>
                                <span class="text-slate-500">{{ day.label }}</span>
                            </div>

                            <div v-if="!day.inCurrentMonth" class="py-3 text-center text-[10px] font-semibold text-slate-400">
                                Outside {{ monthTitle }}
                            </div>
                            <div v-else-if="day.isPast" class="py-3 text-center text-[10px] font-semibold text-slate-400">
                                Past Date
                            </div>
                            <template v-else>
                                <template v-for="slot in counselor.weekly_vacant_slots" :key="`${counselor.id}-${day.ymd}-${slot.start}`">
                                    <template v-if="slotAppliesToWeekday(slot, day.dow)">
                                        <div
                                            v-if="isSlotBooked(day.ymd, slot, counselor.id)"
                                            class="flex items-center justify-between gap-1 rounded-lg border border-amber-300/60 bg-amber-50 px-2 py-1.5 text-[11px] font-semibold text-amber-900"
                                            title="Booked — client identity is kept strictly confidential"
                                        >
                                            <span class="truncate">{{ slot.start }}–{{ slot.end }}</span>
                                            <span class="inline-flex shrink-0 items-center gap-0.5 font-bold">
                                                <Lock class="size-2.5" />
                                                Booked
                                            </span>
                                        </div>
                                        <button
                                            v-else
                                            type="button"
                                            @click="openDayModal(day.ymd, counselor.id, slot)"
                                            class="flex items-center justify-between gap-1 rounded-lg border border-[#1A8F26]/30 bg-[#1A8F26]/8 px-2 py-1.5 text-left text-[11px] font-semibold text-[#1A8F26] transition hover:bg-[#1A8F26] hover:text-white"
                                        >
                                            <span class="truncate">{{ slot.start }}–{{ slot.end }}</span>
                                            <span class="shrink-0 font-bold">Vacant</span>
                                        </button>
                                    </template>
                                </template>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- BOTTOM CARD: CURRENT-MONTH-ONLY FULL MONTH COUNSELOR CALENDAR -->
        <section class="bento p-6">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-black/10 pb-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2 text-base font-bold text-slate-900">
                        <CalendarDays class="size-5 text-[#FF8D06]" />
                        <span>Full Month Counseling Calendar ({{ monthTitle }})</span>
                        <span class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[11px] font-bold text-[#0384C5]">
                            Current Month Only
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-600">
                        Tap any upcoming <strong>Vacant</strong> weekday to choose a counselor &amp; time in the scheduling modal. Booked days strictly display <strong>Booked</strong> (zero client info).
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-black/10 bg-[#FBF6EE] px-3 py-1.5 text-[11px] font-semibold">
                    <span class="inline-flex items-center gap-1 text-[#1A8F26]">
                        <span class="size-2 rounded-full bg-[#1A8F26]" />
                        Vacant (Tap to Schedule)
                    </span>
                    <span class="text-slate-300">•</span>
                    <span class="inline-flex items-center gap-1 text-amber-800">
                        <span class="size-2 rounded-full bg-[#FF8D06]" />
                        Booked
                    </span>
                    <span class="text-slate-300">•</span>
                    <span class="text-slate-500">Sat/Sun Closed</span>
                </div>
            </div>

            <!-- 7-Column Calendar Grid -->
            <div class="mt-4 grid grid-cols-7 gap-1.5 text-center text-[11px] font-bold text-slate-500">
                <div class="py-1">Sun</div>
                <div class="py-1 text-slate-800">Mon</div>
                <div class="py-1 text-slate-800">Tue</div>
                <div class="py-1 text-slate-800">Wed</div>
                <div class="py-1 text-slate-800">Thu</div>
                <div class="py-1 text-slate-800">Fri</div>
                <div class="py-1">Sat</div>
            </div>

            <div class="mt-1 grid grid-cols-7 gap-1.5">
                <template v-for="cell in monthGridCells" :key="cell.key">
                    <div
                        v-if="cell.ymd === null"
                        class="min-h-[70px] rounded-xl bg-transparent"
                    />
                    <button
                        v-else
                        type="button"
                        :disabled="!cell.isWeekday || cell.isPast"
                        @click="cell.isWeekday && !cell.isPast && openDayModal(cell.ymd)"
                        class="flex min-h-[70px] flex-col justify-between rounded-xl border p-2 text-left transition"
                        :class="[
                            !cell.isWeekday || cell.isPast
                                ? 'cursor-default border-black/5 bg-slate-100/70 text-slate-400'
                                : cell.isBooked
                                  ? 'border-amber-400/60 bg-amber-50/90 hover:border-[#FF8D06]'
                                  : 'border-black/10 bg-white hover:border-[#1A8F26] hover:bg-[#1A8F26]/5',
                        ]"
                    >
                        <div class="flex items-center justify-between gap-1">
                            <span
                                class="text-xs font-bold"
                                :class="cell.isToday ? 'rounded-full bg-[#0384C5] px-1.5 py-0.5 text-[10px] text-white' : ''"
                            >
                                {{ cell.dayNumber }}
                            </span>
                        </div>

                        <div v-if="!cell.isWeekday" class="text-[10px] font-medium text-slate-400">
                            Closed
                        </div>
                        <div v-else-if="cell.isPast" class="text-[10px] font-medium text-slate-400">
                            Past
                        </div>
                        <div
                            v-else-if="cell.isBooked"
                            class="inline-flex w-fit items-center gap-1 rounded-full bg-[#FF8D06] px-2 py-0.5 text-[10px] font-bold text-white"
                        >
                            <Lock class="size-2.5" />
                            Booked
                        </div>
                        <div
                            v-else
                            class="inline-flex w-fit rounded-full bg-[#1A8F26]/12 px-2 py-0.5 text-[10px] font-bold text-[#1A8F26]"
                        >
                            Vacant
                        </div>
                    </button>
                </template>
            </div>
        </section>

        <!-- SCHEDULE COUNSELING SESSION MODAL (TRIGGERED BY TAPPING ANY VACANT DAY/SLOT) -->
        <div
            v-if="modalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4 backdrop-blur-md"
            @click.self="modalOpen = false"
        >
            <div class="bento w-full max-w-lg p-6">
                <div class="flex items-start justify-between gap-3 border-b border-black/10 pb-3">
                    <div>
                        <span class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[10px] font-bold text-[#0384C5]">
                            Confidential Counseling Booking
                        </span>
                        <h3 class="font-editorial mt-1.5 text-lg font-bold text-slate-900">
                            {{ modalDateFormatted }}
                        </h3>
                    </div>
                    <button
                        type="button"
                        @click="modalOpen = false"
                        class="rounded-lg border border-black/10 bg-white p-1.5 text-slate-600 hover:bg-slate-50"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <form class="mt-4 space-y-4 text-xs" @submit.prevent="submitModalBooking">
                    <!-- Step 1: Choose Counselor -->
                    <div>
                        <label class="mb-1.5 block font-bold text-slate-800">1. Choose Guidance Counselor *</label>
                        <div class="grid gap-2">
                            <button
                                v-for="c in counselors"
                                :key="`modal-c-${c.id}`"
                                type="button"
                                @click="selectModalCounselor(c.id)"
                                class="flex items-center justify-between rounded-xl border px-3 py-2 text-left transition"
                                :class="
                                    modalCounselorId === c.id
                                        ? 'border-[#0384C5] bg-[#0384C5]/10 text-slate-900'
                                        : 'border-black/10 bg-white text-slate-700 hover:border-[#0384C5]/50'
                                "
                            >
                                <div>
                                    <div class="font-bold">{{ c.name }}</div>
                                    <div class="text-[11px] text-slate-500">{{ c.title }}</div>
                                </div>
                                <span
                                    v-if="modalCounselorId === c.id"
                                    class="rounded-full bg-[#0384C5] px-2 py-0.5 text-[10px] font-bold text-white"
                                >
                                    Selected
                                </span>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Choose Vacant Time Slot -->
                    <div v-if="modalCounselor">
                        <label class="mb-1.5 block font-bold text-slate-800">2. Choose Vacant Time Slot *</label>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <template v-for="slot in modalCounselor.weekly_vacant_slots" :key="`modal-slot-${slot.start}`">
                                <div
                                    v-if="isSlotBooked(modalDate, slot, modalCounselor.id)"
                                    class="flex items-center justify-center gap-1 rounded-xl border border-amber-300 bg-amber-50 px-2.5 py-2 font-bold text-amber-900"
                                >
                                    <Lock class="size-3" />
                                    <span>Booked</span>
                                </div>
                                <button
                                    v-else
                                    type="button"
                                    @click="
                                        modalSlotStart = slot.start;
                                        modalSlotEnd = slot.end;
                                    "
                                    class="rounded-xl border px-2.5 py-2 text-center font-semibold transition"
                                    :class="
                                        modalSlotStart === slot.start && modalSlotEnd === slot.end
                                            ? 'border-[#1A8F26] bg-[#1A8F26] text-white'
                                            : 'border-[#1A8F26]/30 bg-[#1A8F26]/8 text-[#1A8F26] hover:border-[#1A8F26]'
                                    "
                                >
                                    {{ slot.start }}–{{ slot.end }}
                                </button>
                            </template>
                        </div>
                    </div>

                    <!-- Step 3: Session Purpose -->
                    <div>
                        <label for="modal_counseling_purpose" class="mb-1 block font-bold text-slate-800">
                            3. Session Purpose *
                        </label>
                        <input
                            id="modal_counseling_purpose"
                            v-model="modalPurpose"
                            required
                            placeholder="e.g. Individual Counseling Session or Academic Check-In"
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900 focus:border-[#0384C5] focus:outline-none"
                        />
                        <p class="mt-1 text-[11px] text-slate-500">
                            Your name and session purpose stay confidential — other students only see <strong>Booked</strong> on the calendar.
                        </p>
                    </div>

                    <div
                        v-if="localModalError || bookingError"
                        class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 font-semibold text-red-700"
                    >
                        {{ localModalError ?? bookingError }}
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-black/10 pt-3">
                        <button
                            type="button"
                            @click="modalOpen = false"
                            class="rounded-xl border border-black/15 bg-white px-4 py-2 font-semibold text-slate-700"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="submittingBooking || !modalSlotStart || !modalPurpose.trim()"
                            class="bento-btn disabled:opacity-50"
                        >
                            {{ submittingBooking ? 'Scheduling...' : 'Confirm Counseling Booking' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
