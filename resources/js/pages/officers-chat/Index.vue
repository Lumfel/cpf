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

    <div class="flex h-full flex-1 flex-col gap-5 bg-[#FBF6EE] p-4 text-slate-900 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                        <MessageCircle class="size-3.5" />
                        CPF Executive Messenger
                    </span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-[#1A8F26]/12 px-2.5 py-1 text-[11px] font-bold text-[#1A8F26]">
                        <ShieldCheck class="size-3.5" />
                        {{ campusName }}
                    </span>
                </div>
                <h1 class="font-editorial mt-1.5 text-2xl font-bold tracking-tight text-slate-900">
                    Officers Private Group Chat
                </h1>
            </div>
        </div>

        <div
            v-if="statusBanner"
            class="rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-2.5 text-xs font-semibold text-[#1A8F26]"
        >
            {{ statusBanner }}
        </div>
        <div
            v-if="errorBanner"
            class="rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-xs font-semibold text-red-700"
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
        <section class="bento p-5">
            <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                <Archive class="size-4 text-[#0384C5]" />
                Officer GC Annual ZIP Archives
            </div>
            <div v-if="gcArchives.length === 0" class="mt-2 text-xs text-slate-500">
                No annual GC archives recorded yet. The Club Adviser can archive and reset the channel at the end of each academic year.
            </div>
            <div v-else class="mt-3 grid gap-2.5 md:grid-cols-2">
                <div
                    v-for="arc in gcArchives"
                    :key="arc.id"
                    class="rounded-2xl border border-black/10 bg-white p-3 text-xs"
                >
                    <div class="font-bold text-slate-900">{{ arc.zip_file_name }}</div>
                    <div class="text-slate-600">AY {{ arc.academic_year }} • {{ arc.total_messages }} messages</div>
                    <div class="mt-1 truncate font-mono text-[10px] text-slate-500">
                        SHA-256: {{ arc.sha256_checksum }}
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
