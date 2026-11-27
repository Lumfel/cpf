<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight, GripVertical } from '@lucide/vue';
import { computed, ref } from 'vue';

export type CampusEventEffectiveStatus =
    | 'pending_approval'
    | 'scheduled'
    | 'active'
    | 'completed'
    | 'cancelled';

export interface CampusEventSummary {
    id: number;
    title: string;
    description?: string | null;
    location?: string | null;
    event_date: string;
    event_date_label?: string;
    start_time: string;
    end_time: string;
    status: 'pending_approval' | 'approved' | 'cancelled';
    effective_status: CampusEventEffectiveStatus;
    reflection_enabled?: boolean;
    can_edit_schedule?: boolean;
    within_one_day_cutoff?: boolean;
    cancellation_reason?: string | null;
}

const props = withDefaults(
    defineProps<{
        events: CampusEventSummary[];
        selectedEventId?: number | null;
        canDragReschedule?: boolean;
        minScheduleDate?: string | null;
        compact?: boolean;
    }>(),
    {
        selectedEventId: null,
        canDragReschedule: false,
        minScheduleDate: null,
        compact: false,
    },
);

const emit = defineEmits<{
    (e: 'select-event', event: CampusEventSummary): void;
    (e: 'drop-reschedule', payload: { eventId: number; newDate: string }): void;
    (e: 'invalid-drop', message: string): void;
}>();

const now = new Date();
const viewYear = ref(now.getFullYear());
const viewMonth = ref(now.getMonth()); // 0-indexed
const draggedEventId = ref<number | null>(null);
const dragOverDate = ref<string | null>(null);

function formatYmd(year: number, month0: number, day: number): string {
    return `${year}-${String(month0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

const todayYmd = formatYmd(now.getFullYear(), now.getMonth(), now.getDate());

const monthLabel = computed(() =>
    new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString('en-US', {
        month: 'long',
        year: 'numeric',
    }),
);

function prevMonth() {
    if (viewMonth.value === 0) {
        viewMonth.value = 11;
        viewYear.value -= 1;
    } else {
        viewMonth.value -= 1;
    }
}

function nextMonth() {
    if (viewMonth.value === 11) {
        viewMonth.value = 0;
        viewYear.value += 1;
    } else {
        viewMonth.value += 1;
    }
}

function goToday() {
    viewYear.value = now.getFullYear();
    viewMonth.value = now.getMonth();
}

const eventsByDate = computed<Record<string, CampusEventSummary[]>>(() => {
    const map: Record<string, CampusEventSummary[]> = {};
    for (const ev of props.events) {
        if (!map[ev.event_date]) map[ev.event_date] = [];
        map[ev.event_date].push(ev);
    }
    return map;
});

interface CalendarCell {
    date: string | null;
    day: number | null;
    isToday: boolean;
    isValidDropTarget: boolean;
    events: CampusEventSummary[];
}

const calendarCells = computed<CalendarCell[]>(() => {
    const firstDay = new Date(viewYear.value, viewMonth.value, 1).getDay();
    const daysInMonth = new Date(viewYear.value, viewMonth.value + 1, 0).getDate();
    const cells: CalendarCell[] = [];

    for (let i = 0; i < firstDay; i += 1) {
        cells.push({
            date: null,
            day: null,
            isToday: false,
            isValidDropTarget: false,
            events: [],
        });
    }

    for (let d = 1; d <= daysInMonth; d += 1) {
        const ymd = formatYmd(viewYear.value, viewMonth.value, d);
        const isValidDrop = !props.minScheduleDate || ymd >= props.minScheduleDate;
        cells.push({
            date: ymd,
            day: d,
            isToday: ymd === todayYmd,
            isValidDropTarget: isValidDrop,
            events: eventsByDate.value[ymd] ?? [],
        });
    }

    while (cells.length % 7 !== 0) {
        cells.push({
            date: null,
            day: null,
            isToday: false,
            isValidDropTarget: false,
            events: [],
        });
    }

    return cells;
});

function eventBadgeClasses(ev: CampusEventSummary): string {
    switch (ev.effective_status) {
        case 'active':
            return 'border-[#1A8F26]/40 bg-[#1A8F26] text-white';
        case 'scheduled':
            return 'border-[#0384C5]/40 bg-[#0384C5] text-white';
        case 'pending_approval':
            return 'border-amber-400 bg-amber-100 text-amber-950';
        case 'completed':
            return 'border-emerald-300 bg-emerald-50 text-emerald-900';
        case 'cancelled':
            return 'border-red-300 bg-red-50 text-red-700 line-through';
    }
}

function handleDragStart(e: DragEvent, ev: CampusEventSummary) {
    if (!props.canDragReschedule || !ev.can_edit_schedule) return;
    draggedEventId.value = ev.id;
    e.dataTransfer?.setData('text/plain', String(ev.id));
    if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
    }
}

function handleDragOver(e: DragEvent, cell: CalendarCell) {
    if (!props.canDragReschedule || !cell.date || draggedEventId.value === null) return;
    e.preventDefault();
    dragOverDate.value = cell.date;
}

function handleDrop(e: DragEvent, cell: CalendarCell) {
    if (!props.canDragReschedule || !cell.date) return;
    e.preventDefault();
    const rawId = draggedEventId.value ?? Number(e.dataTransfer?.getData('text/plain') || 0);
    draggedEventId.value = null;
    dragOverDate.value = null;

    if (!rawId) return;

    if (props.minScheduleDate && cell.date < props.minScheduleDate) {
        emit('invalid-drop', 'Events can only be moved to a date at least 1 day in advance.');
        return;
    }

    const draggedEv = props.events.find((item) => item.id === rawId);
    if (!draggedEv || draggedEv.event_date === cell.date) return;

    emit('drop-reschedule', {
        eventId: rawId,
        newDate: cell.date,
    });
}

function handleDragEnd() {
    draggedEventId.value = null;
    dragOverDate.value = null;
}
</script>

<template>
    <div class="rounded-[14px] border border-border bg-card p-3.5">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2.5">
            <div class="flex items-center gap-2">
                <CalendarDays class="size-4 text-primary" />
                <h3 class="text-base font-bold text-foreground">
                    {{ monthLabel }}
                </h3>
                <span
                    v-if="canDragReschedule"
                    class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary"
                >
                    Adviser Drag &amp; Drop Enabled (1-Day Cutoff Enforced)
                </span>
            </div>

            <div class="flex items-center gap-1.5 text-xs">
                <button
                    type="button"
                    @click="prevMonth"
                    class="rounded-lg border border-border bg-background p-1.5 text-foreground hover:border-primary"
                    aria-label="Previous month"
                >
                    <ChevronLeft class="size-3.5" />
                </button>
                <button
                    type="button"
                    @click="goToday"
                    class="rounded-lg border border-border bg-background px-2.5 py-1 text-xs font-semibold text-foreground hover:border-primary"
                >
                    Today
                </button>
                <button
                    type="button"
                    @click="nextMonth"
                    class="rounded-lg border border-border bg-background p-1.5 text-foreground hover:border-primary"
                    aria-label="Next month"
                >
                    <ChevronRight class="size-3.5" />
                </button>
            </div>
        </div>

        <!-- Legend -->
        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs font-semibold">
            <span class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/15 px-2 py-0.5 text-[#1A8F26]">
                <span class="size-1.5 rounded-full bg-[#1A8F26]" /> Active Now
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-primary/15 px-2 py-0.5 text-primary">
                <span class="size-1.5 rounded-full bg-primary" /> Approved / Scheduled
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-amber-900">
                <span class="size-1.5 rounded-full bg-amber-600" /> Pending Adviser Approval
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800">
                <span class="size-1.5 rounded-full bg-emerald-600" /> Completed
            </span>
            <span class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-red-700">
                <span class="size-1.5 rounded-full bg-red-600" /> Cancelled
            </span>
        </div>

        <!-- Weekday Headers -->
        <div class="mt-2.5 grid grid-cols-7 gap-1 text-center text-xs font-bold tracking-wide text-muted-foreground uppercase">
            <div>Sun</div>
            <div>Mon</div>
            <div>Tue</div>
            <div>Wed</div>
            <div>Thu</div>
            <div>Fri</div>
            <div>Sat</div>
        </div>

        <!-- Day Cells -->
        <div class="mt-1.5 grid grid-cols-7 gap-1">
            <div
                v-for="(cell, idx) in calendarCells"
                :key="cell.date ?? `empty-${idx}`"
                @dragover="handleDragOver($event, cell)"
                @drop="handleDrop($event, cell)"
                class="flex flex-col rounded-lg border p-1.5 transition"
                :class="[
                    compact ? 'min-h-[64px]' : 'min-h-[86px]',
                    !cell.date
                        ? 'border-transparent bg-transparent'
                        : dragOverDate === cell.date
                          ? cell.isValidDropTarget
                              ? 'border-primary bg-primary/10 ring-2 ring-primary/30'
                              : 'border-red-400 bg-red-50'
                          : cell.isToday
                            ? 'border-[#FF8D06] bg-[#FF8D06]/8'
                            : 'border-border bg-background/60',
                ]"
            >
                <div v-if="cell.day !== null" class="flex items-center justify-between text-xs font-bold">
                    <span :class="cell.isToday ? 'text-[#FF8D06]' : 'text-foreground'">
                        {{ cell.day }}
                    </span>
                    <span v-if="cell.isToday" class="text-xs font-bold text-[#FF8D06]">Today</span>
                </div>

                <div v-if="cell.events.length > 0" class="mt-1 flex flex-col gap-1">
                    <button
                        v-for="ev in cell.events"
                        :key="ev.id"
                        type="button"
                        :draggable="canDragReschedule && Boolean(ev.can_edit_schedule)"
                        @dragstart="handleDragStart($event, ev)"
                        @dragend="handleDragEnd"
                        @click="emit('select-event', ev)"
                        class="group flex w-full items-center gap-1 rounded-md border px-1.5 py-1 text-left text-xs font-bold shadow-2xs transition"
                        :class="[
                            eventBadgeClasses(ev),
                            selectedEventId === ev.id ? 'ring-2 ring-ring' : '',
                            canDragReschedule && ev.can_edit_schedule ? 'cursor-grab active:cursor-grabbing' : '',
                        ]"
                        :title="`${ev.title} (${ev.start_time}–${ev.end_time})`"
                    >
                        <GripVertical
                            v-if="canDragReschedule && ev.can_edit_schedule"
                            class="size-3 shrink-0 opacity-75"
                        />
                        <span class="truncate">{{ ev.title }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
