<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Award,
    CheckCircle2,
    Gavel,
    Lock,
    RotateCcw,
    Search,
    ShieldCheck,
    SkipForward,
    Sparkles,
    UserCheck,
    UserPlus,
    Users,
    Vote,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { apiRequest } from '@/lib/csrfFetch';

export interface SenatePositionMeta {
    key: string;
    label: string;
    optional: boolean;
    required_year: string | null;
    seat_order: number;
}

export interface SenateCandidate {
    id: number;
    user_id: number;
    name: string;
    course_year_section: string;
    year_level: string | null;
    avatar_url?: string | null;
    position: string;
    platform?: string | null;
    nominated_by_user_id?: number | null;
    nomination_status: 'pending' | 'accepted' | 'declined';
    is_tie_contender: boolean;
    votes: number;
}

export interface SenateElectionResult {
    status: 'elected' | 'skipped';
    winner_user_id: number | null;
    winner_candidate_id?: number | null;
    winner_name: string | null;
    course_year_section: string | null;
    votes: number;
    won_by: 'vote' | 'unopposed' | 'tiebreak_runoff' | 'adviser_tiebreak' | 'draw_lots' | 'skipped';
}

export interface SenateElectionItem {
    id: number;
    title: string;
    academic_year: string;
    status: 'draft' | 'open' | 'closed';
    current_position: string | null;
    current_phase: 'nomination' | 'voting' | 'tie_breaker' | 'completed';
    round_number: number;
    position_results: Record<string, SenateElectionResult>;
    opted_out_user_ids: number[];
    opens_at?: string | null;
    closes_at?: string | null;
    candidates: SenateCandidate[];
    my_votes: Record<string, number>;
}

export interface SenateMemberSeat {
    user_id: number;
    full_name_last_first_mi: string;
    course: string | null;
    year_level: string | null;
    year_digit: string | null;
    section: string | null;
    course_year_section: string;
    role: string;
    role_label?: string;
    avatar_url?: string | null;
}

const props = defineProps<{
    election: SenateElectionItem;
    campusMembers: SenateMemberSeat[];
    positionOrder: SenatePositionMeta[];
    adviserDisplay: {
        name: string;
        title: string;
        avatar_url?: string | null;
    };
    myUserId: number;
    isClubAdviser: boolean;
    isVerifiedMember: boolean;
}>();

const avatarByUserId = computed<Record<number, string | null>>(() => {
    const map: Record<number, string | null> = {};
    for (const m of props.campusMembers) {
        map[m.user_id] = m.avatar_url ?? null;
    }
    return map;
});

function getSeatInitials(name: string): string {
    const clean = name.replace(/,/g, ' ').trim();
    const parts = clean.split(/\s+/).filter(Boolean);
    if (parts.length === 0) return 'CP';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return `${parts[0][0] ?? ''}${parts[1][0] ?? ''}`.toUpperCase();
}

const busy = ref(false);
const notice = ref<string | null>(null);
const errorText = ref<string | null>(null);
const tieBreakWinnerId = ref<number | ''>('');

const chamberSearch = ref('');
const chamberFilter = ref<'all' | 'candidates' | 'eligible'>('all');

const activePositionMeta = computed(
    () => props.positionOrder.find((p) => p.key === props.election.current_position) ?? null,
);

// Exclude Club Adviser from the curved member benches since Adviser presides at the Center Podium
const assemblyMemberSeats = computed(() =>
    props.campusMembers.filter((m) => m.role !== 'adviser'),
);

const filteredSeats = computed(() => {
    const q = chamberSearch.value.trim().toLowerCase();

    return assemblyMemberSeats.value.filter((seat) => {
        if (chamberFilter.value === 'candidates') {
            const cand = candidateByUserIdForActive.value[seat.user_id];
            if (!cand || (cand.nomination_status !== 'accepted' && cand.nomination_status !== 'pending')) {
                return false;
            }
        } else if (chamberFilter.value === 'eligible') {
            if (!canNominateSeat(seat)) {
                return false;
            }
        }

        if (!q) return true;
        return (
            seat.full_name_last_first_mi.toLowerCase().includes(q) ||
            seat.course_year_section.toLowerCase().includes(q) ||
            seat.role.toLowerCase().includes(q)
        );
    });
});

type FloorLayoutMode = 'empty' | 'intimate' | 'chapter' | 'assembly' | 'grand';

const floorLayoutMode = computed<FloorLayoutMode>(() => {
    const n = filteredSeats.value.length;
    if (n === 0) return 'empty';
    if (n <= 10) return 'intimate';
    if (n <= 30) return 'chapter';
    if (n <= 60) return 'assembly';
    return 'grand';
});

const floorLayoutLabel = computed(() => {
    switch (floorLayoutMode.value) {
        case 'empty':
            return 'Awaiting Members';
        case 'intimate':
            return 'Compact Circle (1–10)';
        case 'chapter':
            return 'Chapter Assembly (11–30)';
        case 'assembly':
            return 'General Floor (31–60)';
        case 'grand':
            return 'Grand Assembly (61–120+)';
    }
});

interface DynamicSeatRow {
    rowNumber: number;
    maxWidthPercent: number;
    seats: Array<{
        seatNumber: number;
        seat: SenateMemberSeat;
    }>;
}

const dynamicRowsReversed = computed<DynamicSeatRow[]>(() => {
    const seats = filteredSeats.value;
    const n = seats.length;
    if (n === 0) return [];

    let rowCount = 1;
    if (n <= 5) rowCount = 1;
    else if (n <= 10) rowCount = 2;
    else if (n <= 20) rowCount = 3;
    else if (n <= 32) rowCount = 4;
    else if (n <= 48) rowCount = 5;
    else rowCount = Math.max(6, Math.ceil(n / 10.5));

    const weights: number[] = [];
    for (let r = 0; r < rowCount; r += 1) {
        const t = rowCount === 1 ? 0.5 : r / (rowCount - 1);
        weights.push(0.72 + t * 0.56);
    }

    const totalWeight = weights.reduce((sum, w) => sum + w, 0);
    const rawCounts = weights.map((w) => (n * w) / totalWeight);
    const counts = rawCounts.map((val) => Math.floor(val));
    const remainder = n - counts.reduce((sum, c) => sum + c, 0);

    const remainders = rawCounts
        .map((val, idx) => ({ idx, frac: val - counts[idx] }))
        .sort((a, b) => b.frac - a.frac);

    for (let i = 0; i < remainder; i += 1) {
        counts[remainders[i % remainders.length].idx] += 1;
    }

    // Taper from narrower inner row (closest to Adviser) to wider back arc
    counts.sort((a, b) => a - b);

    const rows: DynamicSeatRow[] = [];
    let cursor = 0;

    for (let r = 0; r < counts.length; r += 1) {
        const count = counts[r];
        if (count <= 0) continue;

        const progress = counts.length === 1 ? 1 : r / (counts.length - 1);
        const maxWidthPercent = Math.round(74 + progress * 26);

        const rowSeats = seats.slice(cursor, cursor + count).map((seat, idx) => ({
            seatNumber: cursor + idx + 1,
            seat,
        }));

        rows.push({
            rowNumber: r + 1,
            maxWidthPercent,
            seats: rowSeats,
        });
        cursor += count;
    }

    return rows.reverse();
});

const seatCardLayoutClass = computed(() => {
    switch (floorLayoutMode.value) {
        case 'intimate':
            return 'w-48 p-3 text-xs';
        case 'chapter':
            return 'w-40 p-2.5 text-[11px]';
        case 'assembly':
            return 'w-36 p-2 text-[11px]';
        default:
            return 'w-[132px] p-2 text-[10px]';
    }
});

const seatAvatarSizeClass = computed(() => {
    switch (floorLayoutMode.value) {
        case 'intimate':
            return 'size-12 text-xs';
        case 'chapter':
            return 'size-10 text-[11px]';
        case 'assembly':
            return 'size-9 text-[10px]';
        default:
            return 'size-8 text-[10px]';
    }
});

const seatArcLiftMaxPx = computed(() => {
    switch (floorLayoutMode.value) {
        case 'intimate':
            return 22;
        case 'chapter':
            return 16;
        case 'assembly':
            return 12;
        default:
            return 8;
    }
});

function getArcLiftStyle(index: number, total: number, maxLiftPx = 28): Record<string, string> {
    if (total <= 1) return { transform: `translateY(-${maxLiftPx}px)` };
    const normalized = (index / (total - 1)) * 2 - 1; // -1 (left) .. 0 (center) .. +1 (right)
    const archFactor = 1 - normalized * normalized; // parabolic arch peaking in center
    const lift = Math.round(archFactor * maxLiftPx);
    return {
        transform: `translateY(-${lift}px)`,
    };
}

const electedPositionByUserId = computed<Record<number, string>>(() => {
    const map: Record<number, string> = {};
    const results = props.election.position_results ?? {};
    for (const pos of props.positionOrder) {
        const res = results[pos.key];
        const winnerId = Number(res?.winner_user_id ?? 0);
        if (res && res.status === 'elected' && winnerId > 0) {
            map[winnerId] = pos.label;
        }
    }
    return map;
});

const isMyUserAlreadyElected = computed(() => isAlreadyElected(props.myUserId));

const activePositionCandidates = computed(() => {
    if (!props.election.current_position) return [];
    return props.election.candidates.filter((c) => c.position === props.election.current_position);
});

const candidateByUserIdForActive = computed<Record<number, SenateCandidate>>(() => {
    const map: Record<number, SenateCandidate> = {};
    for (const c of activePositionCandidates.value) {
        map[c.user_id] = c;
    }
    return map;
});

const acceptedForActive = computed(() =>
    activePositionCandidates.value.filter((c) => c.nomination_status === 'accepted'),
);

const pendingMyNomination = computed(() =>
    activePositionCandidates.value.find(
        (c) => c.user_id === props.myUserId && c.nomination_status === 'pending',
    ) ?? null,
);

const isMyUserOptedOut = computed(() => props.election.opted_out_user_ids.includes(props.myUserId));

const tiedContenders = computed(() => {
    if (props.election.current_phase !== 'tie_breaker') return [];
    return acceptedForActive.value.filter((c) => c.is_tie_contender);
});

function isYearBlocked(seat: SenateMemberSeat): boolean {
    const reqYear = activePositionMeta.value?.required_year;
    if (!reqYear) return false;
    return seat.year_digit !== reqYear;
}

function isOptedOut(userId: number): boolean {
    return props.election.opted_out_user_ids.includes(userId);
}

function isAlreadyElected(userId: number): boolean {
    return Boolean(electedPositionByUserId.value[userId]);
}

function canNominateSeat(seat: SenateMemberSeat): boolean {
    if (!props.isVerifiedMember) return false;
    if (props.election.status !== 'open' || props.election.current_phase !== 'nomination') return false;
    if (isOptedOut(seat.user_id) || isAlreadyElected(seat.user_id) || isYearBlocked(seat)) return false;

    const existing = candidateByUserIdForActive.value[seat.user_id];
    if (existing && (existing.nomination_status === 'accepted' || existing.nomination_status === 'pending')) {
        return false;
    }
    return true;
}

function isActiveVotingContender(cand: SenateCandidate | undefined): boolean {
    if (!cand || cand.nomination_status !== 'accepted') return false;
    if (props.election.status !== 'open') return false;
    if (props.election.current_phase === 'voting') return true;
    if (props.election.current_phase === 'tie_breaker') return cand.is_tie_contender;
    return false;
}

async function runAction(
    url: string,
    method: 'POST' | 'PATCH',
    body: Record<string, unknown>,
    successMsg: string,
) {
    if (busy.value) return;
    busy.value = true;
    notice.value = null;
    errorText.value = null;

    const { ok, data } = await apiRequest<{ status?: string }>(url, method, body);
    busy.value = false;

    if (!ok) {
        errorText.value = data.message ?? 'Action could not be completed.';
        return;
    }

    notice.value = successMsg;
    router.reload({ only: ['elections'] });
}

function nominateMember(seat: SenateMemberSeat) {
    const isSelf = seat.user_id === props.myUserId;
    void runAction(
        `/elections/${props.election.id}/nominate`,
        'POST',
        { nominee_user_id: seat.user_id },
        isSelf
            ? `You self-nominated for ${activePositionMeta.value?.label}.`
            : `Nomination sent to ${seat.full_name_last_first_mi} — awaiting acceptance.`,
    );
}

function respondNomination(candidateId: number, decision: 'accepted' | 'declined') {
    void runAction(
        `/elections/${props.election.id}/candidates/${candidateId}/respond`,
        'PATCH',
        { decision },
        decision === 'accepted'
            ? `You accepted the nomination for ${activePositionMeta.value?.label}!`
            : 'You declined nominations for this election. You can re-enable eligibility anytime.',
    );
}

function optInAgain() {
    void runAction(
        `/elections/${props.election.id}/opt-in`,
        'POST',
        {},
        'Eligibility restored — you can now be nominated or self-nominate again.',
    );
}

function closeNominations() {
    void runAction(
        `/elections/${props.election.id}/close-nominations`,
        'POST',
        {},
        'Nominations closed! Advancing to Voting / Selection.',
    );
}

function skipOptionalPosition() {
    void runAction(
        `/elections/${props.election.id}/skip-position`,
        'POST',
        {},
        `Skipped optional ${activePositionMeta.value?.label} and moved to the next position.`,
    );
}

function castVoteFor(cand: SenateCandidate) {
    if (!props.election.current_position) return;
    void runAction(
        `/elections/${props.election.id}/vote`,
        'POST',
        { candidate_id: cand.id, position: props.election.current_position },
        `Vote cast for ${cand.name}.`,
    );
}

async function finalizePosition(tieResolution?: 'runoff' | 'adviser_tiebreak' | 'draw_lots') {
    const body: Record<string, unknown> = {};
    if (tieResolution) {
        body.tie_resolution = tieResolution;
    }
    if (tieResolution === 'adviser_tiebreak') {
        if (!tieBreakWinnerId.value) {
            errorText.value = 'Please choose a tied candidate before casting the Adviser tie-breaker.';
            return;
        }
        body.winner_candidate_id = tieBreakWinnerId.value;
    }

    await runAction(
        `/elections/${props.election.id}/finalize-position`,
        'POST',
        body,
        'Position tally processed!',
    );

    if (!errorText.value) {
        tieBreakWinnerId.value = '';
    }
}

async function toggleOpenStatus() {
    const nextStatus = props.election.status === 'open' ? 'closed' : 'open';
    await runAction(
        `/elections/${props.election.id}/status`,
        'PATCH',
        { status: nextStatus },
        `Election marked ${nextStatus}.`,
    );
}

const phaseLabel = computed(() => {
    if (props.election.current_phase === 'completed') return 'All Positions Completed';
    if (props.election.current_phase === 'nomination') return 'Open Nominations';
    if (props.election.current_phase === 'tie_breaker') return `Tie-Breaker Runoff (Round #${props.election.round_number})`;
    return `Voting Open (Round #${props.election.round_number})`;
});

function formatWonBy(wonBy: SenateElectionResult['won_by']): string {
    switch (wonBy) {
        case 'unopposed':
            return 'Unopposed';
        case 'tiebreak_runoff':
            return 'Runoff Win';
        case 'adviser_tiebreak':
            return 'Adviser Tie-Break';
        case 'draw_lots':
            return 'Drawn Lots';
        case 'skipped':
            return 'Skipped (Optional)';
        default:
            return 'Majority Vote';
    }
}
</script>

<template>
    <section class="bento p-6">
        <!-- Chamber Header -->
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-black/10 pb-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                        {{ election.academic_year }} • General Assembly Election Floor
                    </span>
                    <span
                        class="rounded-full px-3 py-1 text-xs font-bold uppercase"
                        :class="
                            election.current_phase === 'tie_breaker'
                                ? 'bg-[#FF8D06] text-white'
                                : election.current_phase === 'completed'
                                  ? 'bg-[#1A8F26] text-white'
                                  : election.status === 'open'
                                    ? 'bg-[#1A8F26]/15 text-[#1A8F26]'
                                    : 'bg-slate-200 text-slate-700'
                        "
                    >
                        {{ election.status }} • {{ phaseLabel }}
                    </span>
                </div>
                <h2 class="font-editorial mt-2 text-xl font-bold text-slate-900">
                    {{ election.title }}
                </h2>
                <p v-if="activePositionMeta" class="mt-0.5 text-xs text-slate-600">
                    Currently Electing Seat #{{ activePositionMeta.seat_order }} of {{ positionOrder.length }}:
                    <strong class="text-[#0384C5]">{{ activePositionMeta.label }}</strong>
                    <span v-if="activePositionMeta.required_year" class="ml-1 font-semibold text-amber-800">
                        (Restricted Strictly to Year {{ activePositionMeta.required_year }} Members)
                    </span>
                    <span v-if="activePositionMeta.optional" class="ml-1 font-semibold text-slate-500">
                        • Optional if unavailable
                    </span>
                </p>
            </div>

            <div v-if="isClubAdviser && election.current_phase !== 'completed'" class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    :disabled="busy"
                    @click="toggleOpenStatus"
                    class="rounded-xl border border-black/15 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 hover:border-[#0384C5]"
                >
                    {{ election.status === 'open' ? 'Pause / Close Session' : 'Activate / Open Session' }}
                </button>
            </div>
        </div>

        <!-- Feedback Banners -->
        <div
            v-if="notice"
            class="mt-4 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-2.5 text-xs font-semibold text-[#1A8F26]"
        >
            {{ notice }}
        </div>
        <div
            v-if="errorText"
            class="mt-4 rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-xs font-semibold text-red-700"
        >
            {{ errorText }}
        </div>

        <!-- Opted-Out Self-Reversal Bar -->
        <div
            v-if="isMyUserOptedOut && !isMyUserAlreadyElected && isVerifiedMember && election.status === 'open' && election.current_phase !== 'completed'"
            class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-400/50 bg-amber-50 p-4 text-xs text-amber-950"
        >
            <div>
                <strong>You declined nominations for this election.</strong>
                Members cannot nominate you for any remaining seat unless you change your mind.
            </div>
            <button
                type="button"
                :disabled="busy"
                @click="optInAgain"
                class="inline-flex items-center gap-1.5 rounded-xl bg-[#0384C5] px-3.5 py-2 font-semibold text-white hover:bg-[#026da3]"
            >
                <RotateCcw class="size-3.5" />
                Change Mind — Allow Nominations Again
            </button>
        </div>

        <!-- Already-Elected Lock Banner -->
        <div
            v-if="isMyUserAlreadyElected && election.status === 'open' && election.current_phase !== 'completed'"
            class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#1A8F26]/35 bg-[#1A8F26]/10 p-4 text-xs text-slate-900"
        >
            <div>
                You have been elected as <strong>{{ electedPositionByUserId[myUserId] }}</strong>.
                Self-nomination and further nominations are locked for your seat, while you can still nominate eligible peers and vote for remaining positions.
            </div>
        </div>

        <!-- Pending Personal Nomination Action Alert -->
        <div
            v-if="pendingMyNomination && election.status === 'open' && election.current_phase === 'nomination'"
            class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#0384C5]/40 bg-[#0384C5]/10 p-4 text-xs text-slate-900"
        >
            <div>
                <span class="font-bold text-[#0384C5]">You were nominated for {{ activePositionMeta?.label }}!</span>
                Please accept to enter the ballot, or decline to opt out of nominations.
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    :disabled="busy"
                    @click="respondNomination(pendingMyNomination.id, 'accepted')"
                    class="inline-flex items-center gap-1 rounded-xl bg-[#1A8F26] px-3.5 py-1.5 font-semibold text-white"
                >
                    <CheckCircle2 class="size-3.5" />
                    Accept Nomination
                </button>
                <button
                    type="button"
                    :disabled="busy"
                    @click="respondNomination(pendingMyNomination.id, 'declined')"
                    class="inline-flex items-center gap-1 rounded-xl border border-red-300 bg-white px-3.5 py-1.5 font-semibold text-red-700"
                >
                    <XCircle class="size-3.5" />
                    Decline All Nominations
                </button>
            </div>
        </div>

        <!-- 2-COLUMN GENERAL ASSEMBLY FLOOR + RIGHT OFFICIAL BILLBOARD -->
        <div class="mt-5 grid gap-6 xl:grid-cols-[1fr_340px]">
            <!-- LEFT: DYNAMIC ADAPTIVE GENERAL ASSEMBLY FLOOR (1 TO 120+ MEMBERS, EXCLUDING ADVISER) -->
            <div class="flex min-w-0 flex-col justify-between gap-6 rounded-3xl border border-black/10 bg-[#FBF6EE] p-5 md:p-6">
                <!-- Member Count, Dynamic Scale Mode, Search & Filter Controls -->
                <div class="flex flex-wrap items-center justify-between gap-2.5 border-b border-black/10 pb-3.5 text-xs">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 font-bold text-[#0384C5]">
                            <Users class="size-3.5" />
                            {{ assemblyMemberSeats.length }} Seated Member(s) (Excludes Adviser)
                        </span>
                        <span class="rounded-full bg-white px-2.5 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-black/10">
                            {{ floorLayoutLabel }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-2 left-2.5 size-3.5 text-slate-400" />
                            <input
                                v-model="chamberSearch"
                                type="text"
                                placeholder="Find name, course, year..."
                                class="w-44 rounded-xl border border-black/15 bg-white py-1.5 pr-2.5 pl-8 text-xs text-slate-900 focus:border-[#0384C5] focus:outline-none"
                            />
                        </div>

                        <div class="flex rounded-xl border border-black/15 bg-white p-0.5 text-[11px] font-semibold">
                            <button
                                type="button"
                                @click="chamberFilter = 'all'"
                                class="rounded-lg px-2.5 py-1 transition"
                                :class="chamberFilter === 'all' ? 'bg-[#0384C5] text-white' : 'text-slate-600'"
                            >
                                All
                            </button>
                            <button
                                type="button"
                                @click="chamberFilter = 'candidates'"
                                class="rounded-lg px-2.5 py-1 transition"
                                :class="chamberFilter === 'candidates' ? 'bg-[#1A8F26] text-white' : 'text-slate-600'"
                            >
                                Candidates
                            </button>
                            <button
                                type="button"
                                @click="chamberFilter = 'eligible'"
                                class="rounded-lg px-2.5 py-1 transition"
                                :class="chamberFilter === 'eligible' ? 'bg-[#FF8D06] text-white' : 'text-slate-600'"
                            >
                                Eligible
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Dynamic Curved Amphitheater Rows (Only Actual Members; Zero Empty Placeholders, Zero Horizontal Scrollbars) -->
                <div
                    class="flex flex-col items-center gap-3 pt-4"
                    :class="floorLayoutMode === 'grand' ? 'max-h-[680px] overflow-y-auto pr-1' : ''"
                >
                    <div
                        v-if="dynamicRowsReversed.length === 0"
                        class="w-full rounded-2xl border border-black/10 bg-white/80 p-6 text-center text-xs text-slate-500"
                    >
                        {{
                            assemblyMemberSeats.length === 0
                                ? 'No verified campus members seated yet (excluding Club Adviser). Approve members in Membership & GC Checklist to populate the floor.'
                                : 'No member seats match your search or filter.'
                        }}
                    </div>

                    <div
                        v-for="row in dynamicRowsReversed"
                        :key="`dyn-row-${row.rowNumber}`"
                        :style="{ maxWidth: `${row.maxWidthPercent}%` }"
                        class="flex w-full flex-wrap items-end justify-center pt-3"
                        :class="
                            floorLayoutMode === 'intimate'
                                ? 'gap-2.5'
                                : floorLayoutMode === 'chapter'
                                  ? 'gap-2'
                                  : 'gap-1.5'
                        "
                    >
                        <div
                            v-for="(item, sIdx) in row.seats"
                            :key="`seat-${item.seat.user_id}`"
                            :style="getArcLiftStyle(sIdx, row.seats.length, seatArcLiftMaxPx)"
                            :title="`${item.seat.full_name_last_first_mi} • ${item.seat.course_year_section}`"
                            class="flex flex-col justify-between rounded-2xl border shadow-2xs transition"
                            :class="[
                                seatCardLayoutClass,
                                candidateByUserIdForActive[item.seat.user_id]?.nomination_status === 'accepted'
                                    ? 'border-[#1A8F26] bg-white ring-1 ring-[#1A8F26]/30'
                                    : candidateByUserIdForActive[item.seat.user_id]?.nomination_status === 'pending'
                                      ? 'border-amber-400 bg-amber-50/70'
                                      : isOptedOut(item.seat.user_id) || isYearBlocked(item.seat) || isAlreadyElected(item.seat.user_id)
                                        ? 'border-black/10 bg-slate-100/80 opacity-65'
                                        : 'border-black/15 bg-white',
                            ]"
                        >
                            <div class="text-center">
                                <div class="mb-2 flex justify-center">
                                    <img
                                        v-if="item.seat.avatar_url"
                                        :src="item.seat.avatar_url"
                                        :alt="item.seat.full_name_last_first_mi"
                                        :class="[
                                            seatAvatarSizeClass,
                                            candidateByUserIdForActive[item.seat.user_id]?.nomination_status === 'accepted'
                                                ? 'border-[#1A8F26]'
                                                : 'border-[#0384C5]/35',
                                        ]"
                                        class="rounded-full border-2 bg-white object-cover shadow-2xs"
                                    />
                                    <div
                                        v-else
                                        :class="seatAvatarSizeClass"
                                        class="flex items-center justify-center rounded-full border border-[#0384C5]/30 bg-[#0384C5]/10 font-bold text-[#0384C5]"
                                    >
                                        {{ getSeatInitials(item.seat.full_name_last_first_mi) }}
                                    </div>
                                </div>
                                <div class="truncate leading-snug font-bold text-slate-900">
                                    {{ item.seat.full_name_last_first_mi }}
                                </div>
                                <div class="mt-0.5 truncate text-[10px] font-medium text-[#0384C5]">
                                    {{ item.seat.course_year_section }}
                                </div>
                                <div class="mt-0.5 truncate text-[10px] font-semibold text-slate-600">
                                    {{ item.seat.role_label ?? item.seat.role }}
                                </div>

                                <div class="mt-1.5 flex flex-wrap justify-center gap-1">
                                    <span
                                        v-if="isAlreadyElected(item.seat.user_id)"
                                        class="rounded-full bg-amber-500/20 px-1.5 py-0.5 text-[9px] font-bold text-amber-900"
                                    >
                                        Elected: {{ electedPositionByUserId[item.seat.user_id] }}
                                    </span>
                                    <span
                                        v-else-if="isOptedOut(item.seat.user_id)"
                                        class="inline-flex items-center gap-0.5 rounded-full bg-red-100 px-1.5 py-0.5 text-[9px] font-bold text-red-700"
                                    >
                                        <Lock class="size-2.5" /> Declined
                                    </span>
                                    <span
                                        v-else-if="isYearBlocked(item.seat)"
                                        class="rounded-full bg-slate-200 px-1.5 py-0.5 text-[9px] font-bold text-slate-600"
                                    >
                                        Yr {{ activePositionMeta?.required_year }} Only
                                    </span>
                                    <span
                                        v-else-if="candidateByUserIdForActive[item.seat.user_id]?.nomination_status === 'pending'"
                                        class="rounded-full bg-amber-500/15 px-1.5 py-0.5 text-[9px] font-bold text-amber-800"
                                    >
                                        Pending Accept
                                    </span>
                                    <span
                                        v-else-if="candidateByUserIdForActive[item.seat.user_id]?.nomination_status === 'accepted'"
                                        class="rounded-full bg-[#1A8F26]/15 px-1.5 py-0.5 text-[9px] font-bold text-[#1A8F26]"
                                    >
                                        {{ candidateByUserIdForActive[item.seat.user_id].votes }} vote(s)
                                    </span>
                                </div>
                            </div>

                            <div class="mt-2 flex flex-wrap gap-1">
                                <button
                                    v-if="canNominateSeat(item.seat)"
                                    type="button"
                                    :disabled="busy"
                                    @click="nominateMember(item.seat)"
                                    class="inline-flex w-full items-center justify-center gap-1 rounded-lg border border-[#0384C5]/35 bg-[#0384C5]/10 px-1.5 py-1 text-[10px] font-bold text-[#0384C5] hover:bg-[#0384C5] hover:text-white"
                                >
                                    <UserPlus class="size-3 shrink-0" />
                                    <span class="truncate">{{ item.seat.user_id === myUserId ? 'Self-Nominate' : 'Nominate' }}</span>
                                </button>

                                <template
                                    v-else-if="
                                        item.seat.user_id === myUserId &&
                                        candidateByUserIdForActive[item.seat.user_id]?.nomination_status === 'pending' &&
                                        election.current_phase === 'nomination'
                                    "
                                >
                                    <button
                                        type="button"
                                        :disabled="busy"
                                        @click="respondNomination(candidateByUserIdForActive[item.seat.user_id].id, 'accepted')"
                                        class="flex-1 rounded-lg bg-[#1A8F26] px-1.5 py-1 text-[10px] font-bold text-white"
                                    >
                                        Accept
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="busy"
                                        @click="respondNomination(candidateByUserIdForActive[item.seat.user_id].id, 'declined')"
                                        class="flex-1 rounded-lg border border-red-300 bg-white px-1.5 py-1 text-[10px] font-bold text-red-700"
                                    >
                                        Decline
                                    </button>
                                </template>

                                <template v-else-if="isActiveVotingContender(candidateByUserIdForActive[item.seat.user_id]) && isVerifiedMember && !isClubAdviser">
                                    <span
                                        v-if="election.my_votes[election.current_position ?? ''] === candidateByUserIdForActive[item.seat.user_id].id"
                                        class="inline-flex w-full items-center justify-center gap-1 rounded-lg bg-[#1A8F26]/15 px-1.5 py-1 text-[10px] font-bold text-[#1A8F26]"
                                    >
                                        <CheckCircle2 class="size-3 shrink-0" /> Your Vote ✓
                                    </span>
                                    <button
                                        v-else-if="!election.my_votes[election.current_position ?? '']"
                                        type="button"
                                        :disabled="busy"
                                        @click="castVoteFor(candidateByUserIdForActive[item.seat.user_id])"
                                        class="inline-flex w-full items-center justify-center gap-1 rounded-lg bg-[#FF8D06] px-1.5 py-1 text-[10px] font-bold text-white hover:bg-[#e57d00]"
                                    >
                                        <Vote class="size-3 shrink-0" /> Cast Vote
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CENTER PRESIDING PODIUM: CLUB ADVISER DAIS -->
                <div class="mx-auto w-full max-w-xl rounded-t-[48px] rounded-b-2xl border-2 border-[#0384C5]/45 bg-white p-5 text-center shadow-sm">
                    <div class="mb-2 flex justify-center">
                        <img
                            v-if="adviserDisplay.avatar_url"
                            :src="adviserDisplay.avatar_url"
                            :alt="adviserDisplay.name"
                            class="size-14 rounded-full border-2 border-[#0384C5]/40 bg-white object-cover shadow-xs"
                        />
                        <div
                            v-else
                            class="flex size-14 items-center justify-center rounded-full border-2 border-[#0384C5]/35 bg-[#0384C5]/10 text-base font-bold text-[#0384C5]"
                        >
                            {{ getSeatInitials(adviserDisplay.name) }}
                        </div>
                    </div>
                    <div class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 text-[11px] font-bold text-[#0384C5]">
                        <ShieldCheck class="size-3.5" />
                        Presiding Dais • Club Adviser
                    </div>
                    <h3 class="font-editorial mt-2 text-lg font-bold text-slate-900">
                        {{ adviserDisplay.name }}
                    </h3>
                    <p class="text-xs text-slate-600">{{ adviserDisplay.title }}</p>

                    <!-- Parliamentary Gavel Controls on Podium -->
                    <div
                        v-if="election.status === 'open' && activePositionMeta"
                        class="mt-4 flex flex-wrap items-center justify-center gap-2 border-t border-black/10 pt-3.5 text-xs"
                    >
                        <!-- Nomination Phase Controls -->
                        <template v-if="election.current_phase === 'nomination'">
                            <button
                                v-if="acceptedForActive.length >= 2 && isClubAdviser"
                                type="button"
                                :disabled="busy"
                                @click="closeNominations"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-[#0384C5] px-4 py-2 font-semibold text-white hover:bg-[#026da3]"
                            >
                                <Gavel class="size-3.5" />
                                Close Nominations &amp; Open Voting ({{ acceptedForActive.length }} Candidates)
                            </button>

                            <button
                                v-else-if="acceptedForActive.length === 1 && isClubAdviser"
                                type="button"
                                :disabled="busy"
                                @click="closeNominations"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-[#1A8F26] px-4 py-2 font-semibold text-white"
                            >
                                <UserCheck class="size-3.5" />
                                Close Unopposed &amp; Elect Candidate
                            </button>

                            <span
                                v-else
                                class="rounded-xl bg-[#FBF6EE] px-3 py-1.5 text-[11px] font-medium text-slate-600"
                            >
                                {{
                                    acceptedForActive.length === 0
                                        ? 'Nominate candidates on the Assembly seats above (Club Adviser closes nominations when ready).'
                                        : acceptedForActive.length === 1
                                          ? '1 accepted candidate — waiting for additional nominees or Club Adviser to close unopposed.'
                                          : `${acceptedForActive.length} accepted candidates — waiting for Club Adviser to close nominations and open voting.`
                                }}
                            </span>

                            <button
                                v-if="isClubAdviser && activePositionMeta.optional"
                                type="button"
                                :disabled="busy"
                                @click="skipOptionalPosition"
                                class="inline-flex items-center gap-1 rounded-xl border border-amber-400 bg-amber-50 px-3 py-2 font-semibold text-amber-900 hover:bg-amber-100"
                            >
                                <SkipForward class="size-3.5" />
                                Skip Optional {{ activePositionMeta.label }}
                            </button>
                        </template>

                        <!-- Voting / Tie-Breaker Controls (Club Adviser Finalizes) -->
                        <template v-else-if="election.current_phase === 'voting' || election.current_phase === 'tie_breaker'">
                            <div
                                v-if="election.current_phase === 'tie_breaker' && isClubAdviser"
                                class="flex w-full flex-col items-center gap-2.5"
                            >
                                <div class="text-[11px] font-bold text-[#FF8D06]">
                                    Tie-Breaker Active (Round #{{ election.round_number }}): {{ tiedContenders.length }} contenders tied
                                </div>
                                <div class="flex flex-wrap items-center justify-center gap-2">
                                    <button
                                        type="button"
                                        :disabled="busy"
                                        @click="finalizePosition('runoff')"
                                        class="bento-btn py-1.5"
                                    >
                                        Tally / Start Next Runoff Round
                                    </button>
                                    <div class="flex items-center gap-1.5">
                                        <select
                                            v-model="tieBreakWinnerId"
                                            class="rounded-xl border border-black/15 bg-[#FBF6EE] px-2.5 py-1.5 text-xs"
                                        >
                                            <option value="" disabled>Select Tied Candidate...</option>
                                            <option v-for="tc in tiedContenders" :key="`tb-${tc.id}`" :value="tc.id">
                                                {{ tc.name }}
                                            </option>
                                        </select>
                                        <button
                                            type="button"
                                            :disabled="busy || !tieBreakWinnerId"
                                            @click="finalizePosition('adviser_tiebreak')"
                                            class="rounded-xl bg-[#0384C5] px-3 py-1.5 font-semibold text-white disabled:opacity-50"
                                        >
                                            Adviser Tie-Break
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        :disabled="busy"
                                        @click="finalizePosition('draw_lots')"
                                        class="inline-flex items-center gap-1 rounded-xl border border-black/15 bg-[#FBF6EE] px-3 py-1.5 font-semibold text-slate-800 hover:border-[#FF8D06]"
                                    >
                                        <Sparkles class="size-3.5 text-[#FF8D06]" />
                                        Draw Lots
                                    </button>
                                </div>
                            </div>

                            <button
                                v-else-if="isClubAdviser"
                                type="button"
                                :disabled="busy"
                                @click="finalizePosition()"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-[#1A8F26] px-4 py-2 font-semibold text-white hover:bg-[#15741f]"
                            >
                                <Gavel class="size-3.5" />
                                Finalize &amp; Tally {{ activePositionMeta.label }} Votes
                            </button>
                            <span v-else class="text-[11px] text-slate-500">
                                Voting in progress — cast your ballot directly on a candidate's seat above.
                            </span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- RIGHT SIDEBAR: OFFICIAL CPF OFFICERS BILLBOARD (IN ORDER: PRESIDENT -> 4TH YEAR REP) -->
            <aside class="flex flex-col rounded-3xl border border-black/10 bg-white p-5 shadow-2xs">
                <div class="flex items-center gap-2 border-b border-black/10 pb-3">
                    <Award class="size-5 text-[#FF8D06]" />
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Official CPF Election Billboard</h3>
                        <p class="text-[11px] text-slate-500">Strict Constitution Order (President &rarr; 4th Yr Rep)</p>
                    </div>
                </div>

                <ol class="mt-3 flex-1 space-y-2">
                    <li
                        v-for="pos in positionOrder"
                        :key="`board-${pos.key}`"
                        class="rounded-xl border p-3 text-xs transition"
                        :class="
                            election.position_results[pos.key]?.status === 'elected'
                                ? 'border-[#1A8F26]/35 bg-[#1A8F26]/5'
                                : election.position_results[pos.key]?.status === 'skipped'
                                  ? 'border-black/10 bg-slate-50 opacity-70'
                                  : election.current_position === pos.key && election.current_phase !== 'completed'
                                    ? 'border-[#0384C5] bg-[#0384C5]/10 ring-1 ring-[#0384C5]/30'
                                    : 'border-black/10 bg-[#FBF6EE]/60'
                        "
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-slate-900">
                                {{ pos.seat_order }}. {{ pos.label }}
                            </span>
                            <span
                                v-if="election.position_results[pos.key]?.status === 'elected'"
                                class="rounded-full bg-[#1A8F26] px-2 py-0.5 text-[10px] font-bold text-white"
                            >
                                {{ formatWonBy(election.position_results[pos.key].won_by) }}
                            </span>
                            <span
                                v-else-if="election.position_results[pos.key]?.status === 'skipped'"
                                class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold text-slate-600"
                            >
                                Skipped
                            </span>
                            <span
                                v-else-if="election.current_position === pos.key && election.current_phase !== 'completed'"
                                class="rounded-full bg-[#0384C5] px-2 py-0.5 text-[10px] font-bold text-white"
                            >
                                Active
                            </span>
                            <span v-else class="text-[10px] font-medium text-slate-400">
                                {{ pos.optional ? 'Optional' : 'Queued' }}
                            </span>
                        </div>

                        <div
                            v-if="election.position_results[pos.key]?.status === 'elected'"
                            class="mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px]"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <img
                                    v-if="avatarByUserId[election.position_results[pos.key].winner_user_id ?? 0]"
                                    :src="avatarByUserId[election.position_results[pos.key].winner_user_id ?? 0]!"
                                    :alt="election.position_results[pos.key].winner_name ?? 'Winner'"
                                    class="size-7 shrink-0 rounded-full border border-[#1A8F26]/40 bg-white object-cover"
                                />
                                <div
                                    v-else
                                    class="flex size-7 shrink-0 items-center justify-center rounded-full border border-[#1A8F26]/30 bg-[#1A8F26]/10 text-[10px] font-bold text-[#1A8F26]"
                                >
                                    {{ getSeatInitials(election.position_results[pos.key].winner_name ?? 'CPF') }}
                                </div>
                                <div class="min-w-0 truncate">
                                    <span class="font-bold text-slate-900">{{ election.position_results[pos.key].winner_name }}</span>
                                    <span class="ml-1 text-slate-600">({{ election.position_results[pos.key].course_year_section }})</span>
                                </div>
                            </div>
                            <span class="shrink-0 font-semibold text-[#1A8F26]">
                                {{ election.position_results[pos.key].votes }} vote(s)
                            </span>
                        </div>
                    </li>
                </ol>
            </aside>
        </div>
    </section>
</template>
