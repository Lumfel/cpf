<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Archive,
    Award,
    BadgeCheck,
    CalendarCheck2,
    CheckCircle2,
    Clock,
    FileBadge2,
    Lock,
    MessageSquareText,
    Moon,
    Search,
    ShieldCheck,
    Sparkles,
    Sunrise,
    Unlock,
    Users,
    X,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { apiRequest } from '@/lib/csrfFetch';

type CapsuleLetterStyle = 'classic' | 'letter_in_sunrise' | 'joy_blobs' | 'vice_dusk' | 'twilight_calm';

interface TenureSegment {
    id: number | null;
    role: string;
    role_label: string;
    academic_year: string;
    started_at: string;
    ended_at: string;
    duration_label: string;
    end_reason: string | null;
    end_reason_label: string | null;
    verified: boolean;
    verification_code: string | null;
}

interface TenureSummary {
    first_joined_at: string;
    first_joined_iso: string | null;
    served_until: string;
    served_until_iso: string | null;
    total_days: number;
    total_duration_label: string;
    academic_years: string[];
    roles: TenureSegment[];
    is_fully_verified: boolean;
    events_attended_count: number;
    awards_count: number;
    achievements_count: number;
    certificates_count: number;
}

interface AlumniDirectoryEntry {
    id: number;
    user_id: number;
    full_name_last_first_mi: string;
    course_year_section: string;
    avatar_url?: string | null;
    former_role: string;
    former_role_label: string;
    category: 'adviser' | 'officer' | 'member';
    alumni_since: string | null;
    alumni_reason: string | null;
    alumni_reason_label: string | null;
    transitioned_by_name: string | null;
    remarks: string | null;
    tenure: TenureSummary;
}

interface GcArchiveEntry {
    id: number;
    academic_year: string;
    zip_file_name: string;
    sha256_checksum: string;
    total_messages: number;
    archived_by_name: string;
    archived_at: string | null;
    has_readable_zip: boolean;
}

interface TranscriptMessage {
    id: number | null;
    sender_name: string;
    sender_role: string;
    message_body: string;
    created_at: string | null;
}

const props = defineProps<{
    campusName: string;
    isAlumni: boolean;
    roleLabel: string;
    myTenure: TenureSummary;
    myAwards: Array<{
        id: number;
        award_code: string;
        title: string;
        award_category: string;
        academic_year: string;
        awarded_on: string | null;
        citation: string | null;
        issuer_name: string;
        credential_hash: string;
    }>;
    myAchievements: Array<{
        id: number;
        achievement_code: string;
        title: string;
        category: string;
        achieved_on: string | null;
        description: string | null;
        credential_hash: string;
    }>;
    myCertificates: Array<{
        id: number;
        certificate_code: string;
        title: string;
        event_name: string;
        issued_on: string | null;
        credential_hash: string;
    }>;
    campusAwards: Array<{
        id: number;
        award_code: string;
        recipient_name: string;
        title: string;
        award_category: string;
        academic_year: string;
        awarded_on: string | null;
        citation: string | null;
        issuer_name: string;
        credential_hash: string;
    }>;
    campusAchievements: Array<{
        id: number;
        achievement_code: string;
        recipient_name: string;
        title: string;
        category: string;
        achieved_on: string | null;
        description: string | null;
    }>;
    campusCertificates: Array<{
        id: number;
        certificate_code: string;
        recipient_name: string;
        title: string;
        event_name: string;
        issued_on: string | null;
    }>;
    timeCapsules: Array<{
        id: number;
        title: string;
        letter_style: CapsuleLetterStyle;
        unlock_at: string | null;
        is_opened: boolean;
        ready_to_open: boolean;
        opened_at: string | null;
        message: string | null;
    }>;
    myAttendedEvents: Array<{
        id: number;
        event_id: number;
        title: string;
        location: string | null;
        event_date: string | null;
        time_window: string | null;
        logged_in_at: string | null;
        logged_out_at: string | null;
    }>;
    gcArchives: GcArchiveEntry[];
    schoolYearArchives: Array<{
        id: number;
        academic_year: string;
        archive_type: string;
        title: string;
        zip_file_name: string;
        sha256_checksum: string;
        archived_at: string | null;
    }>;
    inactiveGcChannels: Array<{
        id: number;
        academic_year: string;
        reset_at: string | null;
        messages: TranscriptMessage[];
    }>;
    alumniDirectory: AlumniDirectoryEntry[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Former Members & Legacy Archive',
                href: '/alumni-archive',
            },
        ],
    },
});

const activeTab = ref<'awards' | 'times' | 'gcs' | 'directory'>('awards');
const showMyTimeline = ref(false);
const noticeBanner = ref<string | null>(null);
const errorBanner = ref<string | null>(null);

// Tab 1: Search honors
const awardsSearch = ref('');
const filteredCampusAwards = computed(() => {
    const q = awardsSearch.value.trim().toLowerCase();
    if (!q) return props.campusAwards;
    return props.campusAwards.filter(
        (a) =>
            a.title.toLowerCase().includes(q) ||
            a.recipient_name.toLowerCase().includes(q) ||
            a.award_category.toLowerCase().includes(q) ||
            a.academic_year.toLowerCase().includes(q),
    );
});

// Tab 2: Time Capsule opening (read/unlock only)
const openingCapsuleId = ref<number | null>(null);
async function openCapsule(id: number) {
    if (openingCapsuleId.value !== null) return;
    openingCapsuleId.value = id;
    noticeBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(`/time-capsules/${id}/open`, 'POST');
    openingCapsuleId.value = null;

    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to open this Time Capsule yet.';
        return;
    }

    noticeBanner.value = 'Time Capsule unlocked for reading.';
    router.reload({ only: ['timeCapsules'] });
}

// Tab 3: Archived GC Transcript Modal
const transcriptModalOpen = ref(false);
const loadingTranscript = ref(false);
const transcriptError = ref<string | null>(null);
const activeArchiveMeta = ref<{ academic_year: string; zip_file_name: string; sha256_checksum: string; total_messages: number } | null>(null);
const transcriptMessages = ref<TranscriptMessage[]>([]);
const transcriptNotice = ref<string | null>(null);

async function viewGcTranscript(archive: GcArchiveEntry) {
    transcriptModalOpen.value = true;
    loadingTranscript.value = true;
    transcriptError.value = null;
    transcriptNotice.value = null;
    transcriptMessages.value = [];
    activeArchiveMeta.value = {
        academic_year: archive.academic_year,
        zip_file_name: archive.zip_file_name,
        sha256_checksum: archive.sha256_checksum,
        total_messages: archive.total_messages,
    };

    const { ok, data } = await apiRequest<{
        available?: boolean;
        messages?: TranscriptMessage[];
        notice?: string;
        message?: string;
    }>(`/alumni-archive/gc/${archive.id}/transcript`, 'GET');

    loadingTranscript.value = false;

    if (!ok) {
        transcriptError.value = data.message ?? 'Unable to load archived GC transcript.';
        return;
    }

    transcriptMessages.value = data.messages ?? [];
    if (data.available === false) {
        transcriptNotice.value = data.notice ?? 'Archive ZIP bundle is stored offline.';
    }
}

// Tab 4: Dynamic Alumni Directory Filters
const directoryCategory = ref<'all' | 'adviser' | 'officer' | 'member'>('all');
const directorySearch = ref('');

const adviserCount = computed(() => props.alumniDirectory.filter((a) => a.category === 'adviser').length);
const officerCount = computed(() => props.alumniDirectory.filter((a) => a.category === 'officer').length);
const memberCount = computed(() => props.alumniDirectory.filter((a) => a.category === 'member').length);

const filteredAlumni = computed(() => {
    const q = directorySearch.value.trim().toLowerCase();

    return props.alumniDirectory.filter((entry) => {
        if (directoryCategory.value !== 'all' && entry.category !== directoryCategory.value) {
            return false;
        }
        if (!q) return true;
        return (
            entry.full_name_last_first_mi.toLowerCase().includes(q) ||
            entry.former_role_label.toLowerCase().includes(q) ||
            entry.course_year_section.toLowerCase().includes(q) ||
            entry.tenure.academic_years.some((ay) => ay.toLowerCase().includes(q))
        );
    });
});
</script>

<template>
    <Head title="Former Members & Legacy Archive — Circle of Peer Facilitators" />

    <div class="flex flex-col gap-5 p-4 md:p-6">
        <!-- Verified Club Tenure & Read-Only Legacy Banner -->
        <section class="bento p-6">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-black/10 pb-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-bold"
                            :class="isAlumni ? 'bg-[#0384C5]/12 text-[#0384C5]' : 'bg-[#1A8F26]/12 text-[#1A8F26]'"
                        >
                            <ShieldCheck class="size-3.5" />
                            {{ isAlumni ? 'Read-Only Former Account' : 'Active Member Archive Preview' }}
                        </span>
                        <span
                            v-if="myTenure.is_fully_verified"
                            class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-1 text-[11px] font-bold text-[#1A8F26]"
                        >
                            <BadgeCheck class="size-3.5" />
                            HMAC-SHA256 Verified Club Tenure
                        </span>
                    </div>

                    <h1 class="font-editorial mt-2 text-2xl font-bold text-slate-900">
                        {{ campusName }} — Former Members &amp; Legacy Archive
                    </h1>
                    <p class="mt-1 text-xs text-slate-600">
                        Standing: <strong class="text-slate-900">{{ roleLabel }}</strong>
                        <span v-if="isAlumni">
                            • Zero active operational control; permanent read-only access to Awards, Time Capsules, Archived GCs, and Verified Club Tenure.
                        </span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        href="/my-credentials"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-[#0384C5]/35 bg-white px-3.5 py-2 text-xs font-semibold text-[#0384C5] hover:bg-[#0384C5] hover:text-white"
                    >
                        <Award class="size-3.5" />
                        My Credential Ledger
                    </Link>
                    <button
                        type="button"
                        @click="showMyTimeline = !showMyTimeline"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-black/15 bg-[#FBF6EE] px-3.5 py-2 text-xs font-semibold text-slate-800 hover:border-[#0384C5]"
                    >
                        <Clock class="size-3.5 text-[#0384C5]" />
                        {{ showMyTimeline ? 'Hide My Tenure Proof' : 'View My Tenure Proof' }}
                    </button>
                </div>
            </div>

            <!-- Tenure KPI Row -->
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-3.5 text-xs">
                    <div class="text-[11px] font-semibold text-slate-500">Time at CPF</div>
                    <div class="mt-1 text-base font-extrabold text-[#0384C5]">
                        {{ myTenure.total_duration_label }}
                    </div>
                    <div class="mt-0.5 text-[11px] text-slate-600">
                        {{ myTenure.first_joined_at }} &rarr; {{ myTenure.served_until }}
                    </div>
                </div>

                <div class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-3.5 text-xs">
                    <div class="text-[11px] font-semibold text-slate-500">Academic Years</div>
                    <div class="mt-1 text-sm font-bold text-slate-900">
                        {{ myTenure.academic_years.join(', ') }}
                    </div>
                    <div class="mt-0.5 text-[11px] text-slate-600">
                        {{ myTenure.roles.length }} verified term record(s)
                    </div>
                </div>

                <div class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-3.5 text-xs">
                    <div class="text-[11px] font-semibold text-slate-500">Events Attended</div>
                    <div class="mt-1 text-base font-extrabold text-[#1A8F26]">
                        {{ myTenure.events_attended_count }}
                    </div>
                    <div class="mt-0.5 text-[11px] text-slate-600">Checked-in activities</div>
                </div>

                <div class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-3.5 text-xs">
                    <div class="text-[11px] font-semibold text-slate-500">My Awards &amp; Certs</div>
                    <div class="mt-1 text-base font-extrabold text-[#FF8D06]">
                        {{ myTenure.awards_count + myTenure.achievements_count + myTenure.certificates_count }}
                    </div>
                    <div class="mt-0.5 text-[11px] text-slate-600">
                        {{ myTenure.awards_count }} awards • {{ myTenure.certificates_count }} certs
                    </div>
                </div>

                <div class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-3.5 text-xs">
                    <div class="text-[11px] font-semibold text-slate-500">Archive Vault</div>
                    <div class="mt-1 text-base font-extrabold text-slate-900">
                        {{ gcArchives.length }} GCs • {{ alumniDirectory.length }} Former
                    </div>
                    <div class="mt-0.5 text-[11px] text-slate-600">Dynamic campus history</div>
                </div>
            </div>

            <!-- Chronological Verified Role Timeline -->
            <div v-if="showMyTimeline" class="mt-4 rounded-2xl border border-[#0384C5]/25 bg-white p-4 text-xs">
                <div class="mb-2.5 flex items-center justify-between gap-2">
                    <span class="font-bold text-slate-900">Chronological Verified Service Timeline</span>
                    <span class="text-[11px] text-slate-500">Tamper-evident HMAC-SHA256 tenure seals</span>
                </div>
                <div class="space-y-2">
                    <div
                        v-for="(seg, idx) in myTenure.roles"
                        :key="`my-seg-${idx}`"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-black/10 bg-[#FBF6EE] px-3.5 py-2.5"
                    >
                        <div>
                            <div class="font-bold text-slate-900">
                                {{ seg.role_label }}
                                <span class="ml-1.5 rounded-full bg-[#0384C5]/10 px-2 py-0.5 text-[10px] text-[#0384C5]">
                                    AY {{ seg.academic_year }}
                                </span>
                            </div>
                            <div class="mt-0.5 text-[11px] text-slate-600">
                                {{ seg.started_at }} &rarr; {{ seg.ended_at }} ({{ seg.duration_label }})
                                <span v-if="seg.end_reason_label"> • {{ seg.end_reason_label }}</span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold"
                                :class="seg.verified ? 'bg-[#1A8F26]/15 text-[#1A8F26]' : 'bg-slate-200 text-slate-600'"
                            >
                                <CheckCircle2 v-if="seg.verified" class="size-3" />
                                {{ seg.verified ? 'Verified Seal' : 'Legacy Record' }}
                            </span>
                            <code v-if="seg.verification_code" class="rounded bg-white px-2 py-0.5 font-mono text-[10px] text-slate-600">
                                #{{ seg.verification_code }}
                            </code>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Sub-navigation Tabs -->
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                @click="activeTab = 'awards'"
                class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold transition"
                :class="activeTab === 'awards' ? 'bg-[#0384C5] text-white shadow-sm' : 'border border-black/10 bg-white text-slate-700 hover:bg-[#FBF6EE]'"
            >
                <Award class="size-4" />
                Awards &amp; Honors ({{ campusAwards.length }})
            </button>
            <button
                type="button"
                @click="activeTab = 'times'"
                class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold transition"
                :class="activeTab === 'times' ? 'bg-[#FF8D06] text-white shadow-sm' : 'border border-black/10 bg-white text-slate-700 hover:bg-[#FBF6EE]'"
            >
                <Clock class="size-4" />
                Time Capsules &amp; Times ({{ timeCapsules.length + myAttendedEvents.length }})
            </button>
            <button
                type="button"
                @click="activeTab = 'gcs'"
                class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold transition"
                :class="activeTab === 'gcs' ? 'bg-[#1A8F26] text-white shadow-sm' : 'border border-black/10 bg-white text-slate-700 hover:bg-[#FBF6EE]'"
            >
                <Archive class="size-4" />
                Archived GCs ({{ gcArchives.length }})
            </button>
            <button
                type="button"
                @click="activeTab = 'directory'"
                class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-bold transition"
                :class="activeTab === 'directory' ? 'bg-slate-900 text-white shadow-sm' : 'border border-black/10 bg-white text-slate-700 hover:bg-[#FBF6EE]'"
            >
                <Users class="size-4" />
                Former Advisers, Officers &amp; Members ({{ alumniDirectory.length }})
            </button>
        </div>

        <div
            v-if="noticeBanner"
            class="rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-3 text-xs font-semibold text-[#1A8F26]"
        >
            {{ noticeBanner }}
        </div>
        <div
            v-if="errorBanner"
            class="rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700"
        >
            {{ errorBanner }}
        </div>

        <!-- TAB 1: AWARDS, ACHIEVEMENTS & CERTIFICATES -->
        <div v-if="activeTab === 'awards'" class="flex flex-col gap-5">
            <section class="bento p-5">
                <h2 class="text-base font-bold text-slate-900">
                    My Blockchain-Protected Awards, Achievements &amp; Certificates
                </h2>
                <p class="mt-0.5 text-xs text-slate-600">
                    Permanent, tamper-evident honors earned during your service at {{ campusName }}.
                </p>

                <div
                    v-if="myAwards.length === 0 && myAchievements.length === 0 && myCertificates.length === 0"
                    class="mt-4 rounded-2xl border border-black/10 bg-[#FBF6EE] p-4 text-xs text-slate-600"
                >
                    No personal awards, achievements, or certificates anchored to your account yet.
                </div>
                <div v-else class="mt-4 grid gap-3 md:grid-cols-2">
                    <div
                        v-for="aw in myAwards"
                        :key="`my-aw-${aw.id}`"
                        class="rounded-2xl border border-[#FF8D06]/30 bg-[#FFF9EF] p-4 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="rounded-full bg-[#FF8D06]/15 px-2.5 py-0.5 text-[10px] font-bold text-[#FF8D06]">
                                AWARD • AY {{ aw.academic_year }}
                            </span>
                            <code class="font-mono text-[10px] text-slate-500">{{ aw.award_code }}</code>
                        </div>
                        <h3 class="mt-2 text-sm font-bold text-slate-900">{{ aw.title }}</h3>
                        <div class="mt-0.5 text-slate-600">{{ aw.award_category }} • {{ aw.awarded_on }}</div>
                        <p v-if="aw.citation" class="mt-2 text-slate-700">{{ aw.citation }}</p>
                    </div>

                    <div
                        v-for="ach in myAchievements"
                        :key="`my-ach-${ach.id}`"
                        class="rounded-2xl border border-[#0384C5]/30 bg-[#FBF6EE] p-4 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="rounded-full bg-[#0384C5]/12 px-2.5 py-0.5 text-[10px] font-bold text-[#0384C5]">
                                ACHIEVEMENT • {{ ach.category }}
                            </span>
                            <code class="font-mono text-[10px] text-slate-500">{{ ach.achievement_code }}</code>
                        </div>
                        <h3 class="mt-2 text-sm font-bold text-slate-900">{{ ach.title }}</h3>
                        <div class="mt-0.5 text-slate-600">{{ ach.achieved_on }}</div>
                        <p v-if="ach.description" class="mt-2 text-slate-700">{{ ach.description }}</p>
                    </div>

                    <div
                        v-for="cert in myCertificates"
                        :key="`my-cert-${cert.id}`"
                        class="rounded-2xl border border-[#1A8F26]/30 bg-white p-4 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-[10px] font-bold text-[#1A8F26]">
                                <FileBadge2 class="size-3" />
                                CERTIFICATE
                            </span>
                            <code class="font-mono text-[10px] text-slate-500">{{ cert.certificate_code }}</code>
                        </div>
                        <h3 class="mt-2 text-sm font-bold text-slate-900">{{ cert.title }}</h3>
                        <div class="mt-0.5 text-slate-600">{{ cert.event_name }} • {{ cert.issued_on }}</div>
                    </div>
                </div>
            </section>

            <section class="bento p-5">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-black/10 pb-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Campus Hall of Honors (Read-Only)</h2>
                        <p class="text-xs text-slate-600">
                            All campus awards, milestones, and certificates preserved in the archive.
                        </p>
                    </div>
                    <div class="relative min-w-[220px]">
                        <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-slate-400" />
                        <input
                            v-model="awardsSearch"
                            type="text"
                            placeholder="Search award or recipient..."
                            class="w-full rounded-xl border border-black/15 bg-[#FBF6EE] py-1.5 pr-3 pl-8 text-xs"
                        />
                    </div>
                </div>

                <div v-if="filteredCampusAwards.length === 0" class="py-6 text-center text-xs text-slate-500">
                    No campus awards recorded yet.
                </div>
                <div v-else class="mt-4 grid gap-3 md:grid-cols-2">
                    <div
                        v-for="aw in filteredCampusAwards"
                        :key="`camp-aw-${aw.id}`"
                        class="rounded-2xl border border-black/10 bg-white p-4 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-slate-900">{{ aw.title }}</span>
                            <span class="rounded-full bg-[#FF8D06]/15 px-2.5 py-0.5 text-[10px] font-bold text-[#FF8D06]">
                                AY {{ aw.academic_year }}
                            </span>
                        </div>
                        <div class="mt-1 text-slate-700">
                            Recipient: <strong>{{ aw.recipient_name }}</strong> • {{ aw.award_category }}
                        </div>
                        <div class="mt-0.5 text-[11px] text-slate-500">
                            Awarded {{ aw.awarded_on }} by {{ aw.issuer_name }} • <code class="font-mono">{{ aw.award_code }}</code>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- TAB 2: TIME CAPSULES & EVENT ATTENDANCE TIMES -->
        <div v-else-if="activeTab === 'times'" class="grid gap-5 lg:grid-cols-2">
            <section class="bento p-5">
                <h2 class="text-base font-bold text-slate-900">My Encrypted Time Capsules</h2>
                <p class="mt-0.5 text-xs text-slate-600">
                    Read-only vault for your sealed reflections. Unlocked capsules can be opened when their unlock date arrives.
                </p>

                <div v-if="timeCapsules.length === 0" class="mt-4 text-xs text-slate-500">
                    No Time Capsules recorded on your account.
                </div>
                <div v-else class="mt-4 space-y-3">
                    <div
                        v-for="cap in timeCapsules"
                        :key="`arc-cap-${cap.id}`"
                        class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-4 text-xs"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2 font-bold text-slate-900">
                                <span>{{ cap.title }}</span>
                                <span
                                    v-if="cap.letter_style === 'letter_in_sunrise'"
                                    class="inline-flex items-center gap-1 rounded-full bg-[#FFEDE6] px-2 py-0.5 text-[10px] text-[#C8436A]"
                                >
                                    <Sunrise class="size-3" /> Sunrise
                                </span>
                                <span
                                    v-else-if="cap.letter_style === 'joy_blobs'"
                                    class="inline-flex items-center gap-1 rounded-full bg-[#FFF7DF] px-2 py-0.5 text-[10px] text-[#2C7FB8]"
                                >
                                    <Sparkles class="size-3" /> Joy Blobs
                                </span>
                                <span
                                    v-else-if="cap.letter_style === 'vice_dusk'"
                                    class="inline-flex items-center gap-1 rounded-full bg-[#1C112E] px-2 py-0.5 text-[10px] text-[#FF5CAA]"
                                >
                                    <Zap class="size-3" /> Vice Dusk
                                </span>
                                <span
                                    v-else-if="cap.letter_style === 'twilight_calm'"
                                    class="inline-flex items-center gap-1 rounded-full bg-slate-900 px-2 py-0.5 text-[10px] text-[#56B4E3]"
                                >
                                    <Moon class="size-3" /> Twilight Calm
                                </span>
                            </div>
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold"
                                :class="cap.is_opened ? 'bg-[#1A8F26]/15 text-[#1A8F26]' : 'bg-amber-500/15 text-amber-800'"
                            >
                                <Unlock v-if="cap.is_opened" class="size-3" />
                                <Lock v-else class="size-3" />
                                {{ cap.is_opened ? 'Opened' : cap.ready_to_open ? 'Ready to Open' : 'Date-Locked' }}
                            </span>
                        </div>
                        <div class="mt-1 text-[11px] text-slate-500">
                            Unlock Date: {{ cap.unlock_at }}
                        </div>
                        <p
                            v-if="cap.is_opened && cap.message"
                            class="font-editorial mt-2 rounded-xl border border-black/10 bg-white p-3 text-sm leading-relaxed text-slate-800"
                        >
                            {{ cap.message }}
                        </p>
                        <button
                            v-else-if="cap.ready_to_open"
                            type="button"
                            :disabled="openingCapsuleId === cap.id"
                            @click="openCapsule(cap.id)"
                            class="bento-btn mt-3 py-1.5"
                        >
                            {{ openingCapsuleId === cap.id ? 'Opening...' : 'Open Unlocked Capsule' }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="bento p-5">
                <h2 class="text-base font-bold text-slate-900">My Club Activity &amp; Attendance Times</h2>
                <p class="mt-0.5 text-xs text-slate-600">
                    Verified event check-in/check-out timestamps recorded across your time at CPF.
                </p>

                <div v-if="myAttendedEvents.length === 0" class="mt-4 text-xs text-slate-500">
                    No recorded event attendance timestamps yet.
                </div>
                <div v-else class="mt-4 space-y-2.5">
                    <div
                        v-for="ev in myAttendedEvents"
                        :key="`att-${ev.id}`"
                        class="rounded-2xl border border-black/10 bg-white p-3.5 text-xs"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1.5 font-bold text-slate-900">
                                <CalendarCheck2 class="size-4 text-[#1A8F26]" />
                                {{ ev.title }}
                            </span>
                            <span class="text-[11px] font-semibold text-slate-600">{{ ev.event_date }}</span>
                        </div>
                        <div class="mt-1 text-[11px] text-slate-500">
                            <span v-if="ev.location">{{ ev.location }} • </span>
                            <span v-if="ev.time_window">{{ ev.time_window }}</span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px]">
                            <span class="rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 font-semibold text-[#1A8F26]">
                                In: {{ ev.logged_in_at }}
                            </span>
                            <span
                                class="rounded-full px-2.5 py-0.5 font-semibold"
                                :class="ev.logged_out_at ? 'bg-[#0384C5]/12 text-[#0384C5]' : 'bg-slate-100 text-slate-500'"
                            >
                                {{ ev.logged_out_at ? `Out: ${ev.logged_out_at}` : 'Out: Not Recorded' }}
                            </span>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- TAB 3: ALL ARCHIVED GCs -->
        <div v-else-if="activeTab === 'gcs'" class="flex flex-col gap-5">
            <section class="bento p-5">
                <h2 class="text-base font-bold text-slate-900">Archived Officers Private Group Chats (All Terms)</h2>
                <p class="mt-0.5 text-xs text-slate-600">
                    Read-only historical Officer GC bundles with SHA-256 verification checksums. Open any archive to inspect its saved transcript.
                </p>

                <div v-if="gcArchives.length === 0 && inactiveGcChannels.length === 0" class="mt-4 text-xs text-slate-500">
                    No archived GCs have been finalized yet.
                </div>
                <div v-else class="mt-4 grid gap-3 md:grid-cols-2">
                    <div
                        v-for="arc in gcArchives"
                        :key="`gc-${arc.id}`"
                        class="flex flex-col justify-between gap-3 rounded-2xl border border-black/10 bg-[#FBF6EE] p-4 text-xs"
                    >
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="rounded-full bg-[#0384C5]/12 px-2.5 py-0.5 text-[11px] font-bold text-[#0384C5]">
                                    AY {{ arc.academic_year }}
                                </span>
                                <span class="font-semibold text-slate-700">
                                    {{ arc.total_messages }} message(s)
                                </span>
                            </div>
                            <div class="mt-2 font-bold text-slate-900">{{ arc.zip_file_name }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-600">
                                Archived {{ arc.archived_at }} by {{ arc.archived_by_name }}
                            </div>
                            <div class="mt-1 truncate font-mono text-[10px] text-slate-500">
                                SHA-256: {{ arc.sha256_checksum }}
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button
                                type="button"
                                @click="viewGcTranscript(arc)"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-[#0384C5] px-3.5 py-2 font-bold text-white hover:bg-[#026da3]"
                            >
                                <MessageSquareText class="size-3.5" />
                                Read Transcript
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="inactiveGcChannels.length > 0" class="bento p-5">
                <h3 class="text-sm font-bold text-slate-900">Closed Term Officer Channels (Unbundled)</h3>
                <div class="mt-3 space-y-3">
                    <div
                        v-for="ch in inactiveGcChannels"
                        :key="`inact-ch-${ch.id}`"
                        class="rounded-2xl border border-black/10 bg-white p-4 text-xs"
                    >
                        <div class="font-bold text-slate-900">
                            AY {{ ch.academic_year }} <span v-if="ch.reset_at" class="font-normal text-slate-500">• Closed {{ ch.reset_at }}</span>
                        </div>
                        <div class="mt-2 max-h-56 space-y-1.5 overflow-y-auto">
                            <div v-for="msg in ch.messages" :key="`ch-msg-${msg.id}`" class="rounded-lg bg-[#FBF6EE] p-2">
                                <span class="font-bold text-slate-900">{{ msg.sender_name }}</span>
                                <span class="ml-1 text-[10px] text-[#0384C5] uppercase">({{ msg.sender_role }})</span>:
                                <span class="ml-1 text-slate-700">{{ msg.message_body }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="schoolYearArchives.length > 0" class="bento p-5">
                <h3 class="text-sm font-bold text-slate-900">School Year Archive Ledger</h3>
                <div class="mt-3 grid gap-2.5 md:grid-cols-2">
                    <div
                        v-for="sy in schoolYearArchives"
                        :key="`sy-${sy.id}`"
                        class="rounded-xl border border-black/10 bg-white p-3 text-xs"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-bold text-slate-900">{{ sy.title }}</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-700">{{ sy.academic_year }}</span>
                        </div>
                        <div class="mt-1 text-[11px] text-slate-600">{{ sy.zip_file_name }} • {{ sy.archived_at }}</div>
                    </div>
                </div>
            </section>
        </div>

        <!-- TAB 4: DYNAMIC FORMER ADVISERS, OFFICERS & MEMBERS DIRECTORY -->
        <div v-else class="bento p-5">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-black/10 pb-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">
                        Former Club Advisers, Officers &amp; Members Directory
                    </h2>
                    <p class="text-xs text-slate-600">
                        Dynamically updated whenever a Club Adviser completes a handover or an officer/member graduates or completes their term. Every former officer is automatically titled by the highest position they ever held (e.g., Former President).
                    </p>
                </div>

                <div class="relative min-w-[240px]">
                    <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-slate-400" />
                    <input
                        v-model="directorySearch"
                        type="text"
                        placeholder="Search name, former role, AY..."
                        class="w-full rounded-xl border border-black/15 bg-[#FBF6EE] py-1.5 pr-3 pl-8 text-xs"
                    />
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                <button
                    type="button"
                    @click="directoryCategory = 'all'"
                    class="rounded-full px-3 py-1 font-bold transition"
                    :class="directoryCategory === 'all' ? 'bg-slate-900 text-white' : 'bg-[#FBF6EE] text-slate-700'"
                >
                    All ({{ alumniDirectory.length }})
                </button>
                <button
                    type="button"
                    @click="directoryCategory = 'adviser'"
                    class="rounded-full px-3 py-1 font-bold transition"
                    :class="directoryCategory === 'adviser' ? 'bg-[#0384C5] text-white' : 'bg-[#0384C5]/10 text-[#0384C5]'"
                >
                    Former Advisers ({{ adviserCount }})
                </button>
                <button
                    type="button"
                    @click="directoryCategory = 'officer'"
                    class="rounded-full px-3 py-1 font-bold transition"
                    :class="directoryCategory === 'officer' ? 'bg-[#FF8D06] text-white' : 'bg-[#FF8D06]/12 text-[#FF8D06]'"
                >
                    Former Officers ({{ officerCount }})
                </button>
                <button
                    type="button"
                    @click="directoryCategory = 'member'"
                    class="rounded-full px-3 py-1 font-bold transition"
                    :class="directoryCategory === 'member' ? 'bg-[#1A8F26] text-white' : 'bg-[#1A8F26]/12 text-[#1A8F26]'"
                >
                    Former Members ({{ memberCount }})
                </button>
            </div>

            <div v-if="filteredAlumni.length === 0" class="py-8 text-center text-xs text-slate-500">
                No former advisers, officers, or members match this filter yet.
            </div>
            <div v-else class="mt-4 space-y-3">
                <article
                    v-for="alum in filteredAlumni"
                    :key="`alum-${alum.id}`"
                    class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-4 text-xs"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div class="size-11 shrink-0 overflow-hidden rounded-full border border-[#0384C5]/30 bg-white">
                                <img
                                    v-if="alum.avatar_url"
                                    :src="alum.avatar_url"
                                    :alt="alum.full_name_last_first_mi"
                                    class="size-full object-cover"
                                />
                                <div
                                    v-else
                                    class="flex size-full items-center justify-center bg-[#0384C5]/10 text-xs font-bold text-[#0384C5]"
                                >
                                    {{ alum.full_name_last_first_mi.slice(0, 2).toUpperCase() }}
                                </div>
                            </div>

                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-bold text-slate-900">{{ alum.full_name_last_first_mi }}</h3>
                                    <span
                                        class="rounded-full px-2.5 py-0.5 text-[10px] font-bold"
                                        :class="
                                            alum.category === 'adviser'
                                                ? 'bg-[#0384C5] text-white'
                                                : alum.category === 'officer'
                                                  ? 'bg-[#FF8D06]/15 text-[#FF8D06]'
                                                  : 'bg-[#1A8F26]/15 text-[#1A8F26]'
                                        "
                                    >
                                        {{ alum.former_role_label }}
                                    </span>
                                    <span
                                        v-if="alum.tenure.is_fully_verified"
                                        class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2 py-0.5 text-[10px] font-bold text-[#1A8F26]"
                                    >
                                        <BadgeCheck class="size-3" />
                                        Verified Tenure
                                    </span>
                                </div>
                                <div class="mt-0.5 text-[11px] text-slate-600">
                                    {{ alum.course_year_section }}
                                    <span v-if="alum.alumni_reason_label"> • {{ alum.alumni_reason_label }}</span>
                                    <span v-if="alum.alumni_since"> • Former since {{ alum.alumni_since }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 text-[11px]">
                            <span class="rounded-full border border-[#0384C5]/30 bg-white px-2.5 py-1 font-bold text-[#0384C5]">
                                Time at CPF: {{ alum.tenure.total_duration_label }}
                            </span>
                            <span class="rounded-full bg-white px-2.5 py-1 font-semibold text-slate-700">
                                {{ alum.tenure.events_attended_count }} Events • {{ alum.tenure.awards_count }} Awards
                            </span>
                        </div>
                    </div>

                    <!-- Chronological Role Segments -->
                    <div class="mt-3 flex flex-wrap gap-2">
                        <div
                            v-for="(seg, idx) in alum.tenure.roles"
                            :key="`al-seg-${alum.id}-${idx}`"
                            class="inline-flex items-center gap-1.5 rounded-xl border border-black/10 bg-white px-2.5 py-1.5 text-[11px]"
                        >
                            <span class="font-bold text-slate-900">{{ seg.role_label }}</span>
                            <span class="text-slate-500">({{ seg.started_at }} – {{ seg.ended_at }})</span>
                            <code v-if="seg.verification_code" class="rounded bg-[#FBF6EE] px-1.5 py-0.5 font-mono text-[10px] text-[#0384C5]">
                                #{{ seg.verification_code }}
                            </code>
                        </div>
                    </div>
                </article>
            </div>
        </div>

        <!-- Read-Only Archived GC Transcript Modal -->
        <div
            v-if="transcriptModalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-md"
            @click.self="transcriptModalOpen = false"
        >
            <div class="bento flex max-h-[85vh] w-full max-w-2xl flex-col p-5 text-xs">
                <div class="flex items-start justify-between gap-3 border-b border-black/10 pb-3">
                    <div>
                        <span class="rounded-full bg-[#0384C5]/12 px-2.5 py-0.5 text-[10px] font-bold text-[#0384C5]">
                            Read-Only Archived GC Transcript
                        </span>
                        <h3 class="mt-1.5 text-base font-bold text-slate-900">
                            Officers Private GC — AY {{ activeArchiveMeta?.academic_year }}
                        </h3>
                        <div class="mt-0.5 truncate font-mono text-[10px] text-slate-500">
                            {{ activeArchiveMeta?.zip_file_name }} • SHA-256: {{ activeArchiveMeta?.sha256_checksum }}
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="transcriptModalOpen = false"
                        class="rounded-lg border border-black/10 bg-white p-1.5 text-slate-600 hover:bg-slate-50"
                        aria-label="Close Transcript Modal"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div v-if="loadingTranscript" class="py-10 text-center font-semibold text-slate-600">
                    Reading archived GC transcript...
                </div>
                <div v-else-if="transcriptError" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 font-semibold text-red-700">
                    {{ transcriptError }}
                </div>
                <div v-else-if="transcriptNotice" class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-3 font-semibold text-amber-900">
                    {{ transcriptNotice }}
                </div>
                <div v-else-if="transcriptMessages.length === 0" class="py-8 text-center text-slate-500">
                    No messages were saved in this archived term bundle.
                </div>
                <div v-else class="mt-4 flex-1 space-y-2.5 overflow-y-auto pr-1">
                    <div
                        v-for="(msg, idx) in transcriptMessages"
                        :key="`tr-msg-${msg.id ?? idx}`"
                        class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-3"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-slate-900">{{ msg.sender_name }}</span>
                                <span class="rounded-full bg-[#0384C5]/10 px-2 py-0.5 text-[10px] font-bold text-[#0384C5] uppercase">
                                    {{ msg.sender_role }}
                                </span>
                            </div>
                            <span v-if="msg.created_at" class="text-[10px] text-slate-500">{{ msg.created_at }}</span>
                        </div>
                        <p class="mt-1.5 whitespace-pre-wrap text-slate-800">{{ msg.message_body }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
