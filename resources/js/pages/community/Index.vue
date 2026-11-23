<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, Clock, HeartHandshake, Lock, Moon, Sparkles, Sunrise, Unlock, Vote, Zap } from '@lucide/vue';
import { ref } from 'vue';
import SenateElectionChamber from '@/components/community/SenateElectionChamber.vue';
import type {
    SenateElectionItem,
    SenateMemberSeat,
    SenatePositionMeta,
} from '@/components/community/SenateElectionChamber.vue';
import { apiRequest } from '@/lib/csrfFetch';

type CapsuleLetterStyle = 'classic' | 'letter_in_sunrise' | 'joy_blobs' | 'vice_dusk' | 'twilight_calm';

interface CapsuleItem {
    id: number;
    title: string;
    letter_style?: CapsuleLetterStyle;
    unlock_at: string | null;
    is_opened: boolean;
    ready_to_open: boolean;
    opened_at?: string | null;
    message?: string | null;
}

const props = defineProps<{
    campusId: number;
    campusName: string;
    isClubAdviser: boolean;
    isVerifiedMember: boolean;
    myUserId: number;
    myRole: string;
    adviserDisplay: {
        name: string;
        title: string;
        avatar_url?: string | null;
    };
    positionOrder: SenatePositionMeta[];
    elections: SenateElectionItem[];
    campusMembers: SenateMemberSeat[];
    timeCapsules: CapsuleItem[];
    schoolYearArchives: Array<{
        id: number;
        academic_year: string;
        archive_type: string;
        title: string;
        zip_file_name: string;
        sha256_checksum: string;
        archived_at: string;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Community & Archives',
                href: '/community-hub',
            },
        ],
    },
});

const activeTab = ref<'elections' | 'capsules' | 'archives'>('elections');
const statusBanner = ref<string | null>(null);
const errorBanner = ref<string | null>(null);

// FR-09: Elections State (Adviser activation + sequential General Assembly chamber below)
const electionTitle = ref('');
const electionAy = ref('2026-2027');
const electionStatus = ref<'draft' | 'open'>('open');

async function createElection() {
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest('/elections', 'POST', {
        title: electionTitle.value,
        academic_year: electionAy.value,
        status: electionStatus.value,
    });

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to create election.';
        return;
    }

    electionTitle.value = '';
    statusBanner.value = 'Campus election activated. Sequential nominations start with President.';
    router.reload({ only: ['elections'] });
}

// FR-10: Time Capsules State
const capsuleTitle = ref('');
const capsuleLetterStyle = ref<CapsuleLetterStyle>('classic');
const capsuleUnlockAt = ref('');
const capsuleMessage = ref('');
const savingCapsule = ref(false);

const letterStyleOptions: Array<{
    value: CapsuleLetterStyle;
    label: string;
    caption: string;
    swatch: string;
}> = [
    {
        value: 'classic',
        label: 'Soft Cream Classic',
        caption: 'Warm ivory stationery',
        swatch: 'linear-gradient(135deg, #FBF6EE 0%, #F3EBDD 100%)',
    },
    {
        value: 'letter_in_sunrise',
        label: 'Letter in Sunrise',
        caption: 'Vibrant sunset coral & rosy pink',
        swatch: 'linear-gradient(135deg, #FF9E57 0%, #F46B6E 45%, #E0538C 80%, #9E4E9C 100%)',
    },
    {
        value: 'joy_blobs',
        label: 'In Little Blobs of Joy',
        caption: 'Warm cream with calm color blobs',
        swatch: '#FBF4E4',
    },
    {
        value: 'vice_dusk',
        label: 'Somehow, We Got Here Before Anything Worse',
        caption: 'Vice neon pink, cyan & palm dusk',
        swatch: 'linear-gradient(135deg, #1B0E2E 0%, #5B1E66 36%, #FF4FA3 74%, #FFA84C 100%)',
    },
    {
        value: 'twilight_calm',
        label: 'Twilight Calm',
        caption: 'Quiet indigo dusk note',
        swatch: 'linear-gradient(135deg, #141C24 0%, #1F2D3D 60%, #56B4E3 100%)',
    },
];

async function createCapsule() {
    savingCapsule.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest('/time-capsules', 'POST', {
        campus_id: props.campusId,
        title: capsuleTitle.value,
        letter_style: capsuleLetterStyle.value,
        unlock_at: capsuleUnlockAt.value,
        message: capsuleMessage.value,
    });

    savingCapsule.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to seal Time Capsule.';
        return;
    }

    capsuleTitle.value = '';
    capsuleLetterStyle.value = 'classic';
    capsuleUnlockAt.value = '';
    capsuleMessage.value = '';
    statusBanner.value = 'Time Capsule sealed and encrypted until your chosen unlock date.';
    router.reload({ only: ['timeCapsules'] });
}

async function openCapsule(capsuleId: number) {
    const { ok, data } = await apiRequest(`/time-capsules/${capsuleId}/open`, 'POST');
    if (!ok) {
        errorBanner.value = data.message ?? 'This Time Capsule is still date-locked.';
        return;
    }
    router.reload({ only: ['timeCapsules'] });
}
</script>

<template>
    <Head title="Community, Elections & Archives" />

    <div class="flex min-h-full flex-1 flex-col gap-6 bg-[#FBF6EE] p-4 text-slate-900 md:p-6">
        <div class="bento flex flex-wrap items-center justify-between gap-4 p-5">
            <div>
                <span class="rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                    {{ campusName }}
                </span>
                <h1 class="font-editorial mt-2 text-2xl font-bold text-slate-900">
                    Campus Elections, Time Capsules &amp; Archives
                </h1>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    @click="activeTab = 'elections'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'elections' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <Vote class="size-4" />
                    Campus Elections
                </button>
                <button
                    type="button"
                    @click="activeTab = 'capsules'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'capsules' ? 'bg-[#FF8D06] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <Clock class="size-4" />
                    Time Capsules
                </button>
                <button
                    type="button"
                    @click="activeTab = 'archives'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                    :class="activeTab === 'archives' ? 'bg-[#1A8F26] text-white' : 'border border-black/10 bg-white text-slate-700'"
                >
                    <Archive class="size-4" />
                    School Year Archives
                </button>
            </div>
        </div>

        <div v-if="statusBanner" class="rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-3 text-xs font-semibold text-[#1A8F26]">
            {{ statusBanner }}
        </div>
        <div v-if="errorBanner" class="rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700">
            {{ errorBanner }}
        </div>

        <!-- TAB 1: FR-09 CAMPUS ELECTIONS (GENERAL ASSEMBLY FLOOR + SEQUENTIAL CONSTITUTION ORDER) -->
        <div v-if="activeTab === 'elections'" class="flex flex-col gap-5">
            <section v-if="isClubAdviser" class="bento p-5">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">
                        Activate Campus Election Session (Club Adviser Control)
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-600">
                        Seats run sequentially from President down to 4th Year Representative (with optional representative skipping).
                    </p>
                </div>
                <form class="mt-3 grid gap-2.5 text-xs md:grid-cols-[1.4fr_1fr_1fr_auto]" @submit.prevent="createElection">
                    <input
                        v-model="electionTitle"
                        required
                        placeholder="Election Title (e.g. CPF Officers AY 2026-2027)"
                        class="rounded-xl border border-black/15 bg-white px-3 py-2"
                    />
                    <input
                        v-model="electionAy"
                        required
                        placeholder="Academic Year (e.g. 2026-2027)"
                        class="rounded-xl border border-black/15 bg-white px-3 py-2"
                    />
                    <select v-model="electionStatus" class="rounded-xl border border-black/15 bg-white px-3 py-2">
                        <option value="open">Activate &amp; Open Nominations</option>
                        <option value="draft">Save as Draft</option>
                    </select>
                    <button type="submit" class="bento-btn whitespace-nowrap">Activate Election</button>
                </form>
            </section>

            <div v-if="elections.length === 0" class="bento p-6 text-xs text-slate-500">
                No campus elections have been activated by the Club Adviser yet.
            </div>

            <SenateElectionChamber
                v-for="el in elections"
                :key="el.id"
                :election="el"
                :campus-members="campusMembers"
                :position-order="positionOrder"
                :adviser-display="adviserDisplay"
                :my-user-id="myUserId"
                :is-club-adviser="isClubAdviser"
                :is-verified-member="isVerifiedMember"
            />
        </div>

        <!-- TAB 2: FR-10 ENCRYPTED TIME CAPSULES -->
        <div v-else-if="activeTab === 'capsules'" class="flex flex-col gap-5">
            <!-- Distress Redirect Safeguard (System Limitation #4) -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 p-4 text-xs text-slate-800">
                <div class="flex items-center gap-2">
                    <HeartHandshake class="size-4 text-[#1A8F26]" />
                    <span>
                        <strong>Feeling overwhelmed right now?</strong> Time Capsules stay locked and unread by officers until their unlock date. For immediate support, reach out through Confidential Guidance Referrals.
                    </span>
                </div>
                <Link href="/campus-hub" class="rounded-xl bg-[#1A8F26] px-3 py-1.5 font-semibold text-white">
                    Go to Guidance Referrals
                </Link>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="bento p-6">
                    <h2 class="text-base font-bold text-slate-900">Seal an Encrypted Time Capsule</h2>
                    <form class="mt-4 grid gap-3 text-xs" @submit.prevent="createCapsule">
                        <div>
                            <label class="mb-1 block font-bold">Title *</label>
                            <input v-model="capsuleTitle" required placeholder="e.g. Letter to Future Me" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1.5 block font-bold">Letter Variety / Stationery Style</label>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <button
                                    v-for="opt in letterStyleOptions"
                                    :key="opt.value"
                                    type="button"
                                    @click="capsuleLetterStyle = opt.value"
                                    class="overflow-hidden rounded-xl border text-left transition"
                                    :class="
                                        capsuleLetterStyle === opt.value
                                            ? 'border-[#D94B7C] ring-2 ring-[#D94B7C]/35'
                                            : 'border-black/10 bg-white hover:border-black/25'
                                    "
                                >
                                    <div class="relative h-12 w-full overflow-hidden" :style="{ background: opt.swatch }">
                                        <div
                                            v-if="opt.value === 'letter_in_sunrise'"
                                            class="pointer-events-none absolute -top-2 left-3 size-12 rounded-full bg-[#FFE28A]/70 blur-md"
                                        />
                                        <template v-if="opt.value === 'joy_blobs'">
                                            <span class="pointer-events-none absolute -top-1 left-2 size-8 rounded-full bg-[#E56C66]/45 blur-[2px]" />
                                            <span class="pointer-events-none absolute top-2 left-12 size-8 rounded-full bg-[#4A9ED6]/45 blur-[2px]" />
                                            <span class="pointer-events-none absolute -bottom-1 left-7 size-8 rounded-full bg-[#F2C85C]/55 blur-[2px]" />
                                            <span class="pointer-events-none absolute top-1 right-5 size-7 rounded-full bg-[#68B886]/45 blur-[2px]" />
                                        </template>
                                        <template v-if="opt.value === 'vice_dusk'">
                                            <span class="pointer-events-none absolute -bottom-2 left-2 size-10 rounded-full bg-[#3CE4E8]/45 blur-xs" />
                                            <span class="pointer-events-none absolute -top-2 right-4 size-10 rounded-full bg-[#FFB357]/45 blur-xs" />
                                        </template>
                                    </div>
                                    <div class="p-2">
                                        <div class="text-[11px] font-bold text-slate-900">{{ opt.label }}</div>
                                        <div class="text-[10px] text-slate-500">{{ opt.caption }}</div>
                                    </div>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Unlock Date &amp; Time *</label>
                            <input v-model="capsuleUnlockAt" type="datetime-local" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Private Message (AES-256 Encrypted) *</label>
                            <textarea v-model="capsuleMessage" rows="4" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                        <button type="submit" :disabled="savingCapsule" class="bento-btn w-fit">
                            <Lock class="size-3.5" />
                            Seal Time Capsule
                        </button>
                    </form>
                </section>

                <section class="bento p-6">
                    <h2 class="text-base font-bold text-slate-900">My Time Capsules</h2>
                    <div v-if="timeCapsules.length === 0" class="mt-3 text-xs text-slate-500">
                        No sealed Time Capsules yet.
                    </div>
                    <div v-else class="mt-3 flex flex-col gap-3">
                        <div
                            v-for="cap in timeCapsules"
                            :key="cap.id"
                            class="overflow-hidden rounded-2xl border text-xs transition"
                            :class="
                                cap.letter_style === 'letter_in_sunrise'
                                    ? 'border-[#D94B7C]/45 bg-gradient-to-br from-[#FFF0EA] via-[#FDE0E4] to-[#F7CBE0] text-[#2D1628]'
                                    : cap.letter_style === 'joy_blobs'
                                      ? 'border-[#E2A246]/35 bg-[#FBF4E4] text-[#2B2621]'
                                      : cap.letter_style === 'vice_dusk'
                                        ? 'border-[#FF5CAA]/45 bg-gradient-to-br from-[#160C26] via-[#211138] to-[#152438] text-[#F8F0FF]'
                                        : cap.letter_style === 'twilight_calm'
                                          ? 'border-[#56B4E3]/30 bg-gradient-to-br from-[#141C24] via-[#192430] to-[#1D2B38] text-[#EFE9DF]'
                                          : 'border-black/10 bg-white text-slate-900'
                            "
                        >
                            <div
                                v-if="cap.letter_style === 'letter_in_sunrise'"
                                class="relative h-20 w-full overflow-hidden"
                                style="background: linear-gradient(135deg, #FF9E57 0%, #F46B6E 45%, #E0538C 80%, #9E4E9C 100%)"
                            >
                                <div class="pointer-events-none absolute -top-3 left-6 size-20 rounded-full bg-[#FFE28A]/65 blur-xl" />
                                <div class="pointer-events-none absolute right-6 -bottom-4 size-24 rounded-full bg-[#FF75B5]/45 blur-xl" />
                                <span class="absolute bottom-2.5 left-3 inline-flex items-center gap-1 rounded-full bg-[#FFF1F0]/90 px-2.5 py-0.5 text-[10px] font-bold text-[#B53568] shadow-2xs">
                                    <Sunrise class="size-3" />
                                    Letter in Sunrise
                                </span>
                            </div>

                            <div
                                v-else-if="cap.letter_style === 'joy_blobs'"
                                class="relative h-20 w-full overflow-hidden bg-[#FBF4E4]"
                            >
                                <div class="pointer-events-none absolute -top-4 left-4 size-24 rounded-full bg-[#E56C66]/24 blur-lg" />
                                <div class="pointer-events-none absolute top-1 left-24 size-24 rounded-full bg-[#4A9ED6]/24 blur-lg" />
                                <div class="pointer-events-none absolute -bottom-5 left-14 size-24 rounded-full bg-[#F2C85C]/34 blur-lg" />
                                <div class="pointer-events-none absolute top-0 right-6 size-20 rounded-full bg-[#68B886]/24 blur-lg" />
                                <span class="absolute bottom-2.5 left-3 inline-flex items-center gap-1 rounded-full border border-[#5C4C38]/15 bg-[#FFFCF6]/92 px-2.5 py-0.5 text-[10px] font-bold text-[#C45D44] shadow-2xs">
                                    <Sparkles class="size-3" />
                                    In Little Blobs of Joy
                                </span>
                            </div>

                            <div
                                v-else-if="cap.letter_style === 'vice_dusk'"
                                class="relative h-20 w-full overflow-hidden"
                                style="background: linear-gradient(135deg, #1B0E2E 0%, #5B1E66 36%, #FF4FA3 74%, #FFA84C 100%)"
                            >
                                <div class="pointer-events-none absolute -bottom-4 left-4 size-24 rounded-full bg-[#3CE4E8]/40 blur-xl" />
                                <div class="pointer-events-none absolute -top-3 right-8 size-20 rounded-full bg-[#FFB357]/45 blur-xl" />
                                <span class="absolute bottom-2.5 left-3 inline-flex items-center gap-1 rounded-full border border-[#3CE4E8]/40 bg-[#140B22]/85 px-2.5 py-0.5 text-[10px] font-bold text-[#3CE4E8] shadow-2xs">
                                    <Zap class="size-3 text-[#FF5CAA]" />
                                    Somehow, We Got Here Before Anything Worse
                                </span>
                            </div>

                            <div class="p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-bold">{{ cap.title }}</span>
                                        <span
                                            v-if="cap.letter_style === 'twilight_calm'"
                                            class="inline-flex items-center gap-1 rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold text-[#56B4E3]"
                                        >
                                            <Moon class="size-3" />
                                            Twilight Calm
                                        </span>
                                        <span
                                            v-else-if="cap.letter_style !== 'letter_in_sunrise' && cap.letter_style !== 'joy_blobs' && cap.letter_style !== 'vice_dusk'"
                                            class="inline-flex items-center gap-1 rounded-full bg-[#FBF6EE] px-2 py-0.5 text-[10px] font-semibold text-slate-600"
                                        >
                                            <Sparkles class="size-3" />
                                            Soft Cream Classic
                                        </span>
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                                        :class="
                                            cap.is_opened
                                                ? 'bg-[#1A8F26]/15 text-[#1A8F26]'
                                                : 'bg-amber-500/15 text-amber-800'
                                        "
                                    >
                                        <Unlock v-if="cap.is_opened" class="size-3" />
                                        <Lock v-else class="size-3" />
                                        {{ cap.is_opened ? 'Opened' : cap.ready_to_open ? 'Ready to Open' : 'Date-Locked' }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1"
                                    :class="cap.letter_style === 'twilight_calm' || cap.letter_style === 'vice_dusk' ? 'text-[#C4B0DC]' : 'text-slate-500'"
                                >
                                    Unlock Date: {{ cap.unlock_at }}
                                </div>
                                <p
                                    v-if="cap.is_opened && cap.message"
                                    class="font-editorial mt-2 rounded-xl p-3.5 text-sm leading-relaxed"
                                    :class="
                                        cap.letter_style === 'letter_in_sunrise'
                                            ? 'border border-[#D94B7C]/30 bg-white/85 text-[#2D1628]'
                                            : cap.letter_style === 'joy_blobs'
                                              ? 'border border-[#5C4C38]/15 bg-[#FFFCF6]/95 text-[#2B2621]'
                                              : cap.letter_style === 'vice_dusk'
                                                ? 'border border-[#FF5CAA]/30 bg-[#140B22]/85 text-[#F8F0FF]'
                                                : cap.letter_style === 'twilight_calm'
                                                  ? 'border border-white/10 bg-black/25 text-[#EFE9DF]'
                                                  : 'bg-[#FBF6EE] text-slate-800'
                                    "
                                >
                                    {{ cap.message }}
                                </p>
                                <button
                                    v-else-if="cap.ready_to_open"
                                    type="button"
                                    @click="openCapsule(cap.id)"
                                    class="bento-btn mt-3 py-1.5"
                                >
                                    Open Unlocked Capsule
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- TAB 3: FR-10 SCHOOL YEAR ARCHIVES -->
        <div v-else class="bento p-6">
            <h2 class="text-base font-bold text-slate-900">School Year Archives (HDD ZIP Bundles)</h2>
            <p class="mt-0.5 text-xs text-slate-600">
                End-of-school-year ZIP archive index with SHA-256 checksums for chapter continuity.
            </p>
            <div v-if="schoolYearArchives.length === 0" class="mt-4 text-xs text-slate-500">
                No School Year Archives generated yet.
            </div>
            <div v-else class="mt-4 grid gap-3 md:grid-cols-2">
                <div
                    v-for="arc in schoolYearArchives"
                    :key="arc.id"
                    class="rounded-2xl border border-black/10 bg-white p-4 text-xs"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-bold text-slate-900">{{ arc.title }}</span>
                        <span class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[11px] font-bold text-[#0384C5]">
                            {{ arc.academic_year }}
                        </span>
                    </div>
                    <div class="mt-1 text-slate-600">File: {{ arc.zip_file_name }} ({{ arc.archive_type }})</div>
                    <div class="mt-1 truncate font-mono text-[10px] text-slate-500">
                        SHA-256: {{ arc.sha256_checksum }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
