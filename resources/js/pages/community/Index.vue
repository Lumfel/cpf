<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Archive, Clock, HeartHandshake, Lock, Unlock, Vote } from '@lucide/vue';
import { ref } from 'vue';
import SenateElectionChamber from '@/components/community/SenateElectionChamber.vue';
import type {
    SenateElectionItem,
    SenateMemberSeat,
    SenatePositionMeta,
} from '@/components/community/SenateElectionChamber.vue';
import { apiRequest } from '@/lib/csrfFetch';

interface CapsuleItem {
    id: number;
    title: string;
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
const capsuleUnlockAt = ref('');
const capsuleMessage = ref('');
const savingCapsule = ref(false);

async function createCapsule() {
    savingCapsule.value = true;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest('/time-capsules', 'POST', {
        campus_id: props.campusId,
        title: capsuleTitle.value,
        unlock_at: capsuleUnlockAt.value,
        message: capsuleMessage.value,
    });

    savingCapsule.value = false;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to seal Time Capsule.';
        return;
    }

    capsuleTitle.value = '';
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
                            class="rounded-2xl border border-black/10 bg-white p-4 text-xs"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-bold text-slate-900">{{ cap.title }}</span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold" :class="cap.is_opened ? 'bg-[#1A8F26]/15 text-[#1A8F26]' : 'bg-amber-500/15 text-amber-800'">
                                    <Unlock v-if="cap.is_opened" class="size-3" />
                                    <Lock v-else class="size-3" />
                                    {{ cap.is_opened ? 'Opened' : cap.ready_to_open ? 'Ready to Open' : 'Date-Locked' }}
                                </span>
                            </div>
                            <div class="mt-1 text-slate-500">Unlock Date: {{ cap.unlock_at }}</div>
                            <p v-if="cap.is_opened && cap.message" class="mt-2 rounded-xl bg-[#FBF6EE] p-3 text-slate-800">
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
