<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Award,
    CheckCircle2,
    FileBadge2,
    Hash,
    Lock,
    Search,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Trophy,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';

interface LedgerEntry {
    id: number;
    block_index: number;
    credential_type: 'achievement' | 'award' | 'certificate';
    credential_code: string;
    payload_hash: string;
    previous_block_hash: string;
    block_hash: string;
    driver: string;
    network: string;
    transaction_hash: string | null;
    status: string;
    anchored_at: string;
}

interface AchievementItem {
    id: number;
    achievement_code: string | null;
    title: string;
    category: string;
    achieved_on: string;
    description: string | null;
    blockchain_status: string;
    credential_hash: string | null;
    campus?: { id: number; code: string; name: string } | null;
    ledger_entry?: LedgerEntry | null;
}

interface AwardItem {
    id: number;
    award_code: string;
    title: string;
    award_category: string;
    academic_year: string;
    awarded_on: string;
    citation: string | null;
    blockchain_status: string;
    credential_hash: string | null;
    campus?: { id: number; code: string; name: string } | null;
    issuer?: { id: number; name: string } | null;
    ledger_entry?: LedgerEntry | null;
}

interface CertificateItem {
    id: number;
    certificate_code: string;
    title: string;
    event_name: string;
    issued_on: string;
    blockchain_status: string;
    credential_hash: string | null;
    campus?: { id: number; code: string; name: string } | null;
    ledger_entry?: LedgerEntry | null;
}

interface VerificationProof {
    verified: boolean;
    result: 'valid' | 'tampered' | 'revoked' | 'not_found';
    message?: string;
    block_index?: number;
    credential_type?: string;
    credential_code?: string;
    campus?: string;
    recipient?: string;
    issuer?: string;
    network?: string;
    driver?: string;
    payload_hash?: string;
    block_hash?: string;
    previous_block_hash?: string;
    transaction_hash?: string | null;
    anchored_at?: string;
}

const props = defineProps<{
    owner: {
        id: number;
        name: string;
        email: string;
        roleLabel: string;
        campusName: string;
        courseYearSection?: string | null;
    } | null;
    achievements: AchievementItem[];
    awards: AwardItem[];
    certificates: CertificateItem[];
    chainIntegrity: {
        valid: boolean;
        total_blocks: number;
        broken_blocks: number[];
    };
    blockchainNetwork: {
        driver: string;
        network: string;
        algorithm: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'My Achievements & Awards',
                href: '/my-credentials',
            },
        ],
    },
});

const activeTab = ref<'all' | 'achievements' | 'awards' | 'certificates'>('all');
const searchQuery = ref('');
const manualVerifyCode = ref('');
const verifying = ref(false);
const activeProof = ref<VerificationProof | null>(null);
const showProofModal = ref(false);

const totalCount = computed(
    () => props.achievements.length + props.awards.length + props.certificates.length,
);

const filteredAchievements = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.achievements;
    return props.achievements.filter(
        (a) =>
            a.title.toLowerCase().includes(q) ||
            a.category.toLowerCase().includes(q) ||
            (a.achievement_code ?? '').toLowerCase().includes(q) ||
            (a.description ?? '').toLowerCase().includes(q),
    );
});

const filteredAwards = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.awards;
    return props.awards.filter(
        (a) =>
            a.title.toLowerCase().includes(q) ||
            a.award_category.toLowerCase().includes(q) ||
            a.award_code.toLowerCase().includes(q) ||
            (a.citation ?? '').toLowerCase().includes(q),
    );
});

const filteredCertificates = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.certificates;
    return props.certificates.filter(
        (c) =>
            c.title.toLowerCase().includes(q) ||
            c.event_name.toLowerCase().includes(q) ||
            c.certificate_code.toLowerCase().includes(q),
    );
});

function shortHash(hash?: string | null): string {
    if (!hash) return 'Pending';
    if (hash.length <= 18) return hash;
    return `${hash.slice(0, 10)}…${hash.slice(-8)}`;
}

async function verifyCode(codeOrHash: string | null | undefined) {
    const needle = (codeOrHash ?? '').trim();
    if (!needle) return;

    verifying.value = true;
    showProofModal.value = true;
    activeProof.value = null;

    try {
        const res = await fetch(`/credentials/verify/${encodeURIComponent(needle)}`, {
            headers: { Accept: 'application/json' },
        });
        const data = (await res.json()) as VerificationProof;
        activeProof.value = data;
    } catch {
        activeProof.value = {
            verified: false,
            result: 'not_found',
            message: 'Unable to reach the verification service right now.',
        };
    } finally {
        verifying.value = false;
    }
}
</script>

<template>
    <Head title="My Achievements & Awards" />

    <div class="flex min-h-full w-full max-w-none flex-1 flex-col gap-3.5 bg-background p-3 text-foreground md:p-4">
        <!-- Header & Blockchain Protection Summary -->
        <section class="bento p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]">
                            <ShieldCheck class="size-3.5" />
                            Blockchain Protected • {{ blockchainNetwork.algorithm }}
                        </span>
                        <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary">
                            {{ owner?.campusName ?? 'CHMSU Campus Chapter' }}
                        </span>
                    </div>

                    <h1 class="font-editorial mt-1.5 text-xl font-bold tracking-tight text-foreground md:text-2xl">
                        {{ owner?.name ?? 'Peer Member' }} — Achievements &amp; Awards
                    </h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        <strong class="text-foreground">{{ owner?.roleLabel ?? 'Peer Member' }}</strong>
                        <span v-if="owner?.courseYearSection"> • {{ owner.courseYearSection }}</span>
                        • Every record below is cryptographically sealed and verifiable on the CPF credential ledger.
                    </p>
                </div>

                <!-- Chain Integrity Status Pill -->
                <div
                    class="rounded-xl border px-3.5 py-2.5 text-sm"
                    :class="
                        chainIntegrity.valid
                            ? 'border-[#1A8F26]/30 bg-[#1A8F26]/10 text-[#1A8F26]'
                            : 'border-red-300 bg-red-50 text-red-700'
                    "
                >
                    <div class="flex items-center gap-1.5 font-bold">
                        <CheckCircle2 v-if="chainIntegrity.valid" class="size-4" />
                        <ShieldAlert v-else class="size-4" />
                        {{ chainIntegrity.valid ? 'Ledger Integrity Verified' : 'Integrity Warning Detected' }}
                    </div>
                    <div class="mt-0.5 text-xs opacity-90">
                        {{ chainIntegrity.total_blocks }} anchored blocks • {{ blockchainNetwork.network }}
                    </div>
                </div>
            </div>

            <!-- Summary Metric Cards -->
            <div class="mt-3.5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="small-card-fade rounded-xl border border-border bg-card p-3.5">
                    <div class="flex items-center gap-2 text-sm font-bold text-primary">
                        <Sparkles class="size-4" />
                        Achievements
                    </div>
                    <div class="mt-1 text-xl font-extrabold text-foreground">{{ achievements.length }}</div>
                    <div class="text-xs text-muted-foreground">Verified peer milestones</div>
                </div>

                <div class="small-card-fade rounded-xl border border-border bg-card p-3.5">
                    <div class="flex items-center gap-2 text-sm font-bold text-[#FF8D06]">
                        <Trophy class="size-4" />
                        Awards &amp; Distinctions
                    </div>
                    <div class="mt-1 text-xl font-extrabold text-foreground">{{ awards.length }}</div>
                    <div class="text-xs text-muted-foreground">Chapter honors &amp; citations</div>
                </div>

                <div class="small-card-fade rounded-xl border border-border bg-card p-3.5">
                    <div class="flex items-center gap-2 text-sm font-bold text-[#1A8F26]">
                        <FileBadge2 class="size-4" />
                        Digital Certificates
                    </div>
                    <div class="mt-1 text-xl font-extrabold text-foreground">{{ certificates.length }}</div>
                    <div class="text-xs text-muted-foreground">Official event certificates</div>
                </div>
            </div>
        </section>

        <!-- Filter Tabs + Search + Live Hash Verifier -->
        <section class="bento flex flex-col gap-3 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2.5">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        @click="activeTab = 'all'"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                        :class="activeTab === 'all' ? 'bg-[#0384C5] text-white' : 'border border-border bg-card text-foreground hover:border-primary'"
                    >
                        All ({{ totalCount }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'achievements'"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                        :class="activeTab === 'achievements' ? 'bg-[#0384C5] text-white' : 'border border-border bg-card text-foreground hover:border-primary'"
                    >
                        Achievements ({{ achievements.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'awards'"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                        :class="activeTab === 'awards' ? 'bg-[#FF8D06] text-white' : 'border border-border bg-card text-foreground hover:border-[#FF8D06]'"
                    >
                        Awards ({{ awards.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'certificates'"
                        class="rounded-lg px-3 py-1.5 text-sm font-semibold transition"
                        :class="activeTab === 'certificates' ? 'bg-[#1A8F26] text-white' : 'border border-border bg-card text-foreground hover:border-[#1A8F26]'"
                    >
                        Certificates ({{ certificates.length }})
                    </button>
                </div>

                <div class="relative w-full sm:w-64">
                    <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-muted-foreground" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Filter my credentials..."
                        class="w-full rounded-lg border border-border bg-card py-1.5 pr-3 pl-8 text-sm text-foreground focus:border-primary focus:outline-none"
                    />
                </div>
            </div>

            <!-- Public Verification Lookup -->
            <form
                @submit.prevent="verifyCode(manualVerifyCode)"
                class="flex flex-wrap items-center gap-2 rounded-xl border border-primary/20 bg-background/80 p-3"
            >
                <div class="flex items-center gap-1.5 text-sm font-bold text-primary">
                    <Lock class="size-3.5" />
                    Verify Any Code or Hash:
                </div>
                <input
                    v-model="manualVerifyCode"
                    type="text"
                    placeholder="e.g. CPF-AWD-2026-KTRN0001 or SHA-256 hash"
                    class="min-w-[220px] flex-1 rounded-lg border border-border bg-card px-3 py-1.5 text-sm text-foreground focus:border-primary focus:outline-none"
                />
                <button type="submit" class="bento-btn">
                    <ShieldCheck class="size-3.5" />
                    Verify on Ledger
                </button>
            </form>
        </section>

        <!-- AWARDS SECTION -->
        <section
            v-if="(activeTab === 'all' || activeTab === 'awards') && filteredAwards.length > 0"
            class="flex flex-col gap-2.5"
        >
            <div class="flex items-center gap-2 text-base font-bold text-foreground">
                <Trophy class="size-4 text-[#FF8D06]" />
                Awards &amp; Honors
            </div>
            <div class="grid gap-3.5 md:grid-cols-2">
                <article
                    v-for="item in filteredAwards"
                    :key="`award-${item.id}`"
                    class="bento small-card-fade flex flex-col justify-between p-4"
                >
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="rounded-full bg-[#FF8D06]/15 px-2.5 py-0.5 text-xs font-bold text-[#FF8D06]">
                                {{ item.award_category }} • AY {{ item.academic_year }}
                            </span>
                            <span class="font-mono text-xs font-semibold text-muted-foreground">
                                {{ item.award_code }}
                            </span>
                        </div>
                        <h3 class="font-editorial mt-2 text-base font-bold text-foreground">
                            {{ item.title }}
                        </h3>
                        <p class="mt-1 text-xs font-semibold text-primary">
                            Awarded {{ item.awarded_on }}
                            <span v-if="item.issuer"> • Issued by {{ item.issuer.name }}</span>
                        </p>
                        <p v-if="item.citation" class="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                            {{ item.citation }}
                        </p>
                    </div>

                    <!-- Blockchain Proof Strip -->
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#1A8F26]/25 bg-background/80 p-3 text-xs">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-1 font-bold text-[#1A8F26]">
                                <ShieldCheck class="size-3.5" />
                                Anchored • Block #{{ item.ledger_entry?.block_index ?? '—' }}
                            </div>
                            <div class="flex items-center gap-1 font-mono text-muted-foreground">
                                <Hash class="size-3" />
                                {{ shortHash(item.credential_hash) }}
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="verifyCode(item.award_code)"
                            class="bento-btn"
                        >
                            Verify Proof
                        </button>
                    </div>
                </article>
            </div>
        </section>

        <!-- ACHIEVEMENTS SECTION -->
        <section
            v-if="(activeTab === 'all' || activeTab === 'achievements') && filteredAchievements.length > 0"
            class="flex flex-col gap-2.5"
        >
            <div class="flex items-center gap-2 text-base font-bold text-foreground">
                <Award class="size-4 text-primary" />
                Verified Achievements
            </div>
            <div class="grid gap-3.5 md:grid-cols-2">
                <article
                    v-for="item in filteredAchievements"
                    :key="`ach-${item.id}`"
                    class="bento small-card-fade flex flex-col justify-between p-4"
                >
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary">
                                {{ item.category }}
                            </span>
                            <span class="font-mono text-xs font-semibold text-muted-foreground">
                                {{ item.achievement_code ?? `ACH-${item.id}` }}
                            </span>
                        </div>
                        <h3 class="font-editorial mt-2 text-base font-bold text-foreground">
                            {{ item.title }}
                        </h3>
                        <p class="mt-1 text-xs font-semibold text-primary">
                            Achieved {{ item.achieved_on }}
                        </p>
                        <p v-if="item.description" class="mt-1.5 text-sm leading-relaxed text-muted-foreground">
                            {{ item.description }}
                        </p>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#1A8F26]/25 bg-background/80 p-3 text-xs">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-1 font-bold text-[#1A8F26]">
                                <ShieldCheck class="size-3.5" />
                                Anchored • Block #{{ item.ledger_entry?.block_index ?? '—' }}
                            </div>
                            <div class="flex items-center gap-1 font-mono text-muted-foreground">
                                <Hash class="size-3" />
                                {{ shortHash(item.credential_hash) }}
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="verifyCode(item.achievement_code ?? item.credential_hash)"
                            class="bento-btn"
                        >
                            Verify Proof
                        </button>
                    </div>
                </article>
            </div>
        </section>

        <!-- DIGITAL CERTIFICATES SECTION -->
        <section
            v-if="(activeTab === 'all' || activeTab === 'certificates') && filteredCertificates.length > 0"
            class="flex flex-col gap-2.5"
        >
            <div class="flex items-center gap-2 text-base font-bold text-foreground">
                <FileBadge2 class="size-4 text-[#1A8F26]" />
                Digital Certificates
            </div>
            <div class="grid gap-3.5 md:grid-cols-2">
                <article
                    v-for="item in filteredCertificates"
                    :key="`cert-${item.id}`"
                    class="bento small-card-fade flex flex-col justify-between p-4"
                >
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]">
                                Digital Certificate
                            </span>
                            <span class="font-mono text-xs font-semibold text-muted-foreground">
                                {{ item.certificate_code }}
                            </span>
                        </div>
                        <h3 class="font-editorial mt-2 text-base font-bold text-foreground">
                            {{ item.title }}
                        </h3>
                        <p class="mt-1 text-xs font-semibold text-primary">
                            {{ item.event_name }} • Issued {{ item.issued_on }}
                        </p>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-[#1A8F26]/25 bg-background/80 p-3 text-xs">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-1 font-bold text-[#1A8F26]">
                                <ShieldCheck class="size-3.5" />
                                Anchored • Block #{{ item.ledger_entry?.block_index ?? '—' }}
                            </div>
                            <div class="flex items-center gap-1 font-mono text-muted-foreground">
                                <Hash class="size-3" />
                                {{ shortHash(item.credential_hash) }}
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="verifyCode(item.certificate_code)"
                            class="bento-btn"
                        >
                            Verify Proof
                        </button>
                    </div>
                </article>
            </div>
        </section>

        <!-- Empty State -->
        <section
            v-if="filteredAchievements.length === 0 && filteredAwards.length === 0 && filteredCertificates.length === 0"
            class="bento p-6 text-center"
        >
            <Award class="mx-auto size-8 text-primary/50" />
            <h2 class="font-editorial mt-2 text-base font-bold text-foreground">
                No matching credentials found
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Your verified achievements, awards, and digital certificates will appear here once issued by your Campus Officers or Club Adviser.
            </p>
        </section>

        <!-- Live Blockchain Verification Proof Modal -->
        <div
            v-if="showProofModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-md"
            @click.self="showProofModal = false"
        >
            <div class="bento max-h-[90vh] w-full max-w-lg overflow-y-auto p-4">
                <div class="mb-3 flex items-center justify-between border-b border-border pb-2.5">
                    <div class="flex items-center gap-2">
                        <ShieldCheck class="size-5 text-[#1A8F26]" />
                        <h2 class="font-editorial text-base font-bold text-foreground">
                            Blockchain Verification Proof
                        </h2>
                    </div>
                    <button
                        type="button"
                        @click="showProofModal = false"
                        class="rounded-lg border border-border bg-card p-1 text-muted-foreground hover:text-foreground"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div v-if="verifying" class="py-6 text-center text-sm font-semibold text-muted-foreground">
                    Recomputing SHA-256 payload digest and checking block chain linkage...
                </div>

                <div v-else-if="activeProof" class="space-y-3 text-sm">
                    <div
                        class="flex items-center gap-2 rounded-xl border p-3.5 font-bold"
                        :class="
                            activeProof.verified
                                ? 'border-[#1A8F26]/30 bg-[#1A8F26]/10 text-[#1A8F26]'
                                : 'border-red-300 bg-red-50 text-red-700'
                        "
                    >
                        <CheckCircle2 v-if="activeProof.verified" class="size-5 shrink-0" />
                        <ShieldAlert v-else class="size-5 shrink-0" />
                        <span>
                            {{
                                activeProof.verified
                                    ? 'AUTHENTIC & UNALTERED — Cryptographic proof matches the ledger.'
                                    : activeProof.message ?? `Verification status: ${activeProof.result.toUpperCase()}`
                            }}
                        </span>
                    </div>

                    <dl v-if="activeProof.credential_code" class="space-y-2 rounded-xl border border-border bg-background/80 p-3.5">
                        <div class="flex justify-between gap-2">
                            <dt class="font-semibold text-muted-foreground">Credential Code</dt>
                            <dd class="font-mono font-bold text-foreground">{{ activeProof.credential_code }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-semibold text-muted-foreground">Recipient</dt>
                            <dd class="font-bold text-foreground">{{ activeProof.recipient }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-semibold text-muted-foreground">Issued By</dt>
                            <dd class="font-semibold text-foreground">{{ activeProof.issuer ?? 'CPF Campus Authority' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-semibold text-muted-foreground">Campus &amp; Network</dt>
                            <dd class="font-semibold text-primary">{{ activeProof.campus }} • {{ activeProof.network }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="font-semibold text-muted-foreground">Block Index</dt>
                            <dd class="font-mono font-bold text-foreground">#{{ activeProof.block_index }}</dd>
                        </div>
                        <div class="pt-1">
                            <dt class="font-semibold text-muted-foreground">Payload Hash (SHA-256)</dt>
                            <dd class="mt-0.5 break-all rounded-lg bg-card p-2 font-mono text-xs text-foreground">{{ activeProof.payload_hash }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-muted-foreground">Block Hash (SHA-256)</dt>
                            <dd class="mt-0.5 break-all rounded-lg bg-card p-2 font-mono text-xs text-foreground">{{ activeProof.block_hash }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-muted-foreground">Previous Block Hash</dt>
                            <dd class="mt-0.5 break-all rounded-lg bg-card p-2 font-mono text-xs text-muted-foreground">{{ activeProof.previous_block_hash }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="mt-4 flex justify-end">
                    <button type="button" class="bento-btn" @click="showProofModal = false">
                        Done
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
