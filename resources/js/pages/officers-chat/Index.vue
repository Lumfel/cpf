<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Archive, MessageCircle, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import OfficersMessengerChat from '@/components/chat/OfficersMessengerChat.vue';
import type { MessengerOfficerMessage } from '@/components/chat/OfficersMessengerChat.vue';
import { apiRequest } from '@/lib/csrfFetch';
import type { Auth } from '@/types';

const props = defineProps<{
    campusName: string;
    isClubAdviser: boolean;
    officerChannel: {
        id: number;
        academic_year: string;
        channel_key: string;
        is_active: boolean;
    } | null;
    officerMessages: MessengerOfficerMessage[];
    counselorGcMessages: MessengerOfficerMessage[];
    counselorPeers?: Array<{ user_id: number; name: string; role: string; role_label: string }>;
    officersGcPaused?: boolean;
    gcArchives: Array<{
        id: number;
        academic_year: string;
        zip_file_name: string;
        sha256_checksum: string;
        total_messages: number;
        archived_at: string;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Officers Chat',
                href: '/officers-chat',
            },
        ],
    },
});

const page = usePage<{ auth: Auth }>();
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);
const statusBanner = ref<string | null>(null);
const errorBanner = ref<string | null>(null);

async function archiveAndResetGc() {
    if (!props.officerChannel || !props.isClubAdviser) return;
    statusBanner.value = null;
    errorBanner.value = null;

    const { ok, data } = await apiRequest(`/officers-gc/${props.officerChannel.id}/archive`, 'POST', {});
    if (!ok) {
        errorBanner.value = data.message ?? 'Unable to archive and reset Officers GC.';
        return;
    }

    statusBanner.value = 'Officers GC archived into a SHA-256 verified ZIP and a fresh active channel has been opened for the new term.';
    router.reload({ only: ['officerChannel', 'officerMessages', 'gcArchives'] });
}

function handleCounselorArchived() {
    statusBanner.value = 'Counselors Private GC archived into a SHA-256 verified ZIP and cleared.';
    router.reload({ only: ['counselorGcMessages'] });
}
</script>

<template>
    <Head title="Officers Chat — CPF Messenger" />

    <div class="flex min-h-full w-full max-w-none flex-1 flex-col gap-3.5 bg-background p-3 text-foreground md:p-4">
        <div class="flex flex-wrap items-center justify-between gap-2.5">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="font-editorial text-xl font-bold tracking-tight text-foreground md:text-2xl">
                    Officers Private Group Chat
                </h1>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-bold text-primary">
                    <MessageCircle class="size-3.5" />
                    CPF Executive Messenger
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]">
                    <ShieldCheck class="size-3.5" />
                    {{ campusName }}
                </span>
            </div>
        </div>

        <div
            v-if="statusBanner"
            class="rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3.5 py-2 text-sm font-semibold text-[#1A8F26]"
        >
            {{ statusBanner }}
        </div>
        <div
            v-if="errorBanner"
            class="rounded-xl border border-red-300 bg-red-50 px-3.5 py-2 text-sm font-semibold text-red-700"
        >
            {{ errorBanner }}
        </div>

        <!-- Full Messenger-Style Officers & Counselors Chat -->
        <OfficersMessengerChat
            :channel="officerChannel"
            :initial-messages="officerMessages"
            :counselor-messages="counselorGcMessages"
            :counselor-peers="counselorPeers ?? []"
            :can-access-counselor-gc="isClubAdviser"
            :officers-gc-paused="officersGcPaused ?? false"
            :current-user-id="currentUserId"
            :campus-name="campusName"
            :can-archive="isClubAdviser"
            @archive="archiveAndResetGc"
            @counselor-archived="handleCounselorArchived"
        />

        <!-- Annual Officer GC ZIP Archives (Adviser & Officers Read Record) -->
        <section class="bento p-4">
            <div class="flex items-center gap-2 text-base font-bold text-foreground">
                <Archive class="size-4 text-primary" />
                Officer GC Annual ZIP Archives
            </div>
            <div v-if="gcArchives.length === 0" class="mt-1.5 text-sm text-muted-foreground">
                No annual GC archives recorded yet. The Club Adviser can archive and reset the channel at the end of each academic year.
            </div>
            <div v-else class="mt-2.5 grid gap-2.5 md:grid-cols-2">
                <div
                    v-for="arc in gcArchives"
                    :key="arc.id"
                    class="rounded-xl border border-border bg-card p-3.5 text-sm"
                >
                    <div class="font-bold text-foreground">{{ arc.zip_file_name }}</div>
                    <div class="text-muted-foreground">AY {{ arc.academic_year }} • {{ arc.total_messages }} messages</div>
                    <div class="mt-1 truncate font-mono text-xs text-muted-foreground">
                        SHA-256: {{ arc.sha256_checksum }}
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
