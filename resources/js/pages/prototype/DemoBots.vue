<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Award, Bot, MessageSquare, RotateCcw, ShieldAlert, Vote } from '@lucide/vue';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { apiRequest } from '@/lib/csrfFetch';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    campusName: string;
    demoLog: {
        gc?: Array<{ actor: string; text: string; status: string; note: string }>;
        election?: {
            title: string;
            rounds: Array<{ position: string; round: number; tallies: Record<string, number>; outcome: string }>;
            ephemeral_chat_purged: boolean;
            simulated_at: string;
        } | null;
        awarding?: {
            badge: string;
            notice: string;
            sample_awards: Array<{ recipient: string; award: string; verification_code: string; status: string }>;
            simulated_at: string;
        } | null;
    };
}>();

const gcResults = ref(props.demoLog.gc ?? []);
const electionSim = ref(props.demoLog.election ?? null);
const awardingSim = ref(props.demoLog.awarding ?? null);
const busy = ref<string | null>(null);
const banner = ref<string | null>(null);

async function runBot(kind: 'gc' | 'election' | 'awarding' | 'reset') {
    busy.value = kind;
    banner.value = null;
    const { ok, data } = await apiRequest<any>(`/prototype/demo-bots/${kind}`, 'POST', {});
    busy.value = null;

    if (!ok) {
        banner.value = data?.message ?? 'Prototype bot request failed.';
        return;
    }

    if (kind === 'gc') {
        gcResults.value = data.results ?? [];
        banner.value = 'GC Bot finished — live messages delivered and profanity test blocked automatically.';
    } else if (kind === 'election') {
        electionSim.value = data.simulation ?? null;
        banner.value = 'Election Bot finished — tie-break runoff and auto role turnover simulated.';
    } else if (kind === 'awarding') {
        awardingSim.value = data.preview ?? null;
        banner.value = 'Awarding Prototype Preview generated (deferred from active pilot).';
    } else {
        gcResults.value = [];
        electionSim.value = null;
        awardingSim.value = null;
        banner.value = `Reset complete (${data.deleted_messages ?? 0} demo bot message(s) cleaned up).`;
    }
}
</script>

<template>
    <Head title="Prototype Demo Bots" />

    <div class="flex w-full max-w-none flex-col gap-3.5 p-3 md:p-4">
        <div class="bento flex flex-wrap items-center justify-between gap-3.5 p-4">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full bg-amber-500/15 px-3 py-1 text-xs font-bold text-amber-600 dark:text-amber-300">
                    <ShieldAlert class="size-3.5" />
                    DEV-ONLY PROTOTYPE SANDBOX • DISABLED IN PRODUCTION
                </div>
                <h1 class="mt-1.5 font-editorial text-xl font-bold text-foreground md:text-2xl">
                    CPF Interactive Demo Bot Runner — {{ campusName }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Simulate real-time GC + 5-language profanity filtering, floor election runoffs, and deferred awarding previews safely inside <code class="font-mono">/prototype</code>.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <Link
                    href="/dashboard"
                    class="rounded-xl border border-border bg-card px-3.5 py-2 text-sm font-semibold text-foreground transition hover:border-primary/40"
                >
                    Back to Dashboard
                </Link>
                <button
                    type="button"
                    :disabled="busy !== null"
                    @click="runBot('reset')"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-destructive/30 bg-destructive/10 px-3.5 py-2 text-sm font-semibold text-destructive transition hover:bg-destructive hover:text-white disabled:opacity-50"
                >
                    <RotateCcw class="size-3.5" />
                    Reset Demo Data
                </button>
            </div>
        </div>

        <div
            v-if="banner"
            class="rounded-xl border border-primary/30 bg-primary/10 px-4 py-3 text-sm font-medium text-foreground"
        >
            {{ banner }}
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- 1. GC Bot -->
            <section class="bento flex flex-col justify-between gap-4 p-4">
                <div>
                    <div class="flex items-center gap-2 text-base font-bold text-foreground">
                        <MessageSquare class="size-5 text-[#0384C5]" />
                        1. GC &amp; Profanity Filter Bot
                    </div>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        Sends realistic Adviser, Officer, and Counselor messages and triggers a multilingual profanity test (EN/ES/HIL/CEB/TL).
                    </p>

                    <div v-if="gcResults.length" class="mt-4 flex flex-col gap-2">
                        <div
                            v-for="(item, idx) in gcResults"
                            :key="idx"
                            class="rounded-xl border p-3 text-xs"
                            :class="
                                item.status === 'blocked_by_profanity_filter'
                                    ? 'border-destructive/40 bg-destructive/10 text-foreground'
                                    : 'border-border bg-card text-foreground'
                            "
                        >
                            <div class="font-bold">{{ item.actor }}</div>
                            <div class="mt-0.5 text-muted-foreground">{{ item.text }}</div>
                            <div class="mt-1 font-semibold">{{ item.note }}</div>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    :disabled="busy !== null"
                    @click="runBot('gc')"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#0384C5] px-4 py-2.5 text-sm font-bold text-white transition hover:opacity-90 disabled:opacity-50"
                >
                    <Bot class="size-4" />
                    {{ busy === 'gc' ? 'Running GC Bot...' : 'Run GC Bot Demo' }}
                </button>
            </section>

            <!-- 2. Election Bot -->
            <section class="bento flex flex-col justify-between gap-4 p-4">
                <div>
                    <div class="flex items-center gap-2 text-base font-bold text-foreground">
                        <Vote class="size-5 text-[#FF8D06]" />
                        2. Election Tie-Break Bot
                    </div>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        Simulates 16 floor voters, triggers an 8–8 President tie-break runoff, and demonstrates winner lock + auto role turnover.
                    </p>

                    <div v-if="electionSim" class="mt-4 flex flex-col gap-2">
                        <div
                            v-for="(round, idx) in electionSim.rounds"
                            :key="idx"
                            class="rounded-xl border border-border bg-card p-3 text-xs text-foreground"
                        >
                            <div class="font-bold">{{ round.position }} (Round {{ round.round }})</div>
                            <div class="mt-1 text-muted-foreground">{{ round.outcome }}</div>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    :disabled="busy !== null"
                    @click="runBot('election')"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#FF8D06] px-4 py-2.5 text-sm font-bold text-white transition hover:opacity-90 disabled:opacity-50"
                >
                    <Bot class="size-4" />
                    {{ busy === 'election' ? 'Simulating Election...' : 'Run Election Bot Demo' }}
                </button>
            </section>

            <!-- 3. Awarding Preview Bot -->
            <section class="bento flex flex-col justify-between gap-4 p-4">
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 text-base font-bold text-foreground">
                            <Award class="size-5 text-[#1A8F26]" />
                            3. Awarding Preview
                        </div>
                        <span class="rounded-full bg-amber-500/15 px-2.5 py-0.5 text-xs font-bold text-amber-600 dark:text-amber-300">
                            DEFERRED PREVIEW
                        </span>
                    </div>
                    <p class="mt-1.5 text-sm text-muted-foreground">
                        Safe prototype preview of future certificates/awards without mixing deferred scope into the active Alijis pilot.
                    </p>

                    <div v-if="awardingSim" class="mt-4 flex flex-col gap-2">
                        <div
                            v-for="(award, idx) in awardingSim.sample_awards"
                            :key="idx"
                            class="rounded-xl border border-border bg-card p-3 text-xs text-foreground"
                        >
                            <div class="font-bold">{{ award.award }}</div>
                            <div class="mt-0.5 text-muted-foreground">{{ award.recipient }} • {{ award.verification_code }}</div>
                            <div class="mt-1 font-semibold text-amber-600 dark:text-amber-300">{{ award.status }}</div>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    :disabled="busy !== null"
                    @click="runBot('awarding')"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#1A8F26] px-4 py-2.5 text-sm font-bold text-white transition hover:opacity-90 disabled:opacity-50"
                >
                    <Bot class="size-4" />
                    {{ busy === 'awarding' ? 'Generating Preview...' : 'Run Awarding Preview' }}
                </button>
            </section>
        </div>
    </div>
</template>
