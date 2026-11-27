<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    CheckCircle2,
    ExternalLink,
    HeartHandshake,
    Lock,
    MessageCircle,
    Search,
    Send,
    Users,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { apiRequest } from '@/lib/csrfFetch';

export interface MessengerOfficerMessage {
    id: number;
    sender_user_id?: number;
    sender_name?: string;
    sender_role: string;
    message_body: string;
    created_at: string | null;
    sender?: {
        id: number;
        name: string;
    } | null;
}

const props = withDefaults(
    defineProps<{
        channel: {
            id: number;
            academic_year?: string;
            is_active?: boolean;
        } | null;
        initialMessages: MessengerOfficerMessage[];
        counselorMessages?: MessengerOfficerMessage[];
        counselorPeers?: Array<{
            user_id: number;
            name: string;
            role: string;
            role_label: string;
        }>;
        canAccessCounselorGc?: boolean;
        hideOfficersGc?: boolean;
        officersGcPaused?: boolean;
        defaultThread?: 'officers' | 'counselors';
        currentUserId?: number | null;
        campusName?: string;
        showCampusOpsLink?: boolean;
        canArchive?: boolean;
    }>(),
    {
        counselorMessages: () => [],
        counselorPeers: () => [],
        canAccessCounselorGc: false,
        hideOfficersGc: false,
        officersGcPaused: false,
        defaultThread: 'officers',
        currentUserId: null,
        campusName: 'CHMSU Alijis Campus',
        showCampusOpsLink: false,
        canArchive: false,
    },
);

const emit = defineEmits<{
    (e: 'archive'): void;
    (e: 'counselor-archived'): void;
    (e: 'message-sent', message: MessengerOfficerMessage): void;
}>();

const officerMsgs = ref<MessengerOfficerMessage[]>([...props.initialMessages]);
const counselorMsgs = ref<MessengerOfficerMessage[]>([...props.counselorMessages]);
const dmMessagesByPeer = ref<Record<number, MessengerOfficerMessage[]>>({});
const activeDmPeerId = ref<number | null>(null);
const activeThread = ref<'officers' | 'counselors' | 'counselor_dm'>(
    (props.hideOfficersGc || props.defaultThread === 'counselors') && props.canAccessCounselorGc
        ? 'counselors'
        : 'officers',
);
const activeDmPeer = computed(
    () => props.counselorPeers.find((p) => p.user_id === activeDmPeerId.value) ?? null,
);
let liveSyncTimer: ReturnType<typeof setInterval> | null = null;
const draft = ref('');
const sending = ref(false);
const errorText = ref<string | null>(null);
const archiveNotice = ref<string | null>(null);
const threadSearch = ref('');
const comingSoonNotice = ref<string | null>(null);
const scrollContainer = ref<HTMLElement | null>(null);
const composerRef = ref<HTMLTextAreaElement | null>(null);
const confirmArchiveTarget = ref<'officers' | 'counselors' | null>(null);
const archiving = ref(false);

function autoResizeComposer() {
    const el = composerRef.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 144)}px`;
}

function resetComposerHeight() {
    nextTick(() => {
        if (composerRef.value) {
            composerRef.value.style.height = 'auto';
        }
    });
}

function requestArchiveConfirmation(target: 'officers' | 'counselors') {
    errorText.value = null;
    archiveNotice.value = null;
    confirmArchiveTarget.value = target;
}

async function confirmArchiveAction() {
    if (!confirmArchiveTarget.value || archiving.value) return;

    const target = confirmArchiveTarget.value;
    if (target === 'officers') {
        confirmArchiveTarget.value = null;
        emit('archive');
        return;
    }

    archiving.value = true;
    errorText.value = null;
    archiveNotice.value = null;

    const { ok, data } = await apiRequest<{ message?: string; total_messages?: number }>(
        '/counselors-gc/archive',
        'POST',
        {},
    );

    archiving.value = false;
    confirmArchiveTarget.value = null;

    if (!ok) {
        errorText.value = data.message ?? 'Unable to archive Counselors Private GC.';
        return;
    }

    counselorMsgs.value = [];
    archiveNotice.value = `Counselors Private GC archived (${data.total_messages ?? 0} message(s) saved to SHA-256 ZIP) and cleared.`;
    emit('counselor-archived');
}

watch(
    () => props.initialMessages,
    (next) => {
        officerMsgs.value = [...next];
        if (activeThread.value === 'officers') scrollToBottom();
    },
);

watch(
    () => props.counselorMessages,
    (next) => {
        counselorMsgs.value = [...next];
        if (activeThread.value === 'counselors') scrollToBottom();
    },
);

const displayedMessages = computed(() => {
    if (activeThread.value === 'counselors') return counselorMsgs.value;
    if (activeThread.value === 'counselor_dm' && activeDmPeerId.value !== null) {
        return dmMessagesByPeer.value[activeDmPeerId.value] ?? [];
    }
    return officerMsgs.value;
});

const comingSoonCampuses = [
    {
        code: 'BINALBAGAN',
        initials: 'BN',
        title: 'Binalbagan Officers GC',
        subtitle: 'Coming Soon • Campus On Hold',
    },
    {
        code: 'TALISAY',
        initials: 'TL',
        title: 'Talisay Officers GC',
        subtitle: 'Coming Soon • Campus On Hold',
    },
    {
        code: 'FORTUNE_TOWNE',
        initials: 'FT',
        title: 'Fortune Towne Officers GC',
        subtitle: 'Coming Soon • Campus On Hold',
    },
];

const filteredComingSoon = computed(() => {
    const q = threadSearch.value.trim().toLowerCase();
    if (!q) return comingSoonCampuses;
    return comingSoonCampuses.filter((c) => c.title.toLowerCase().includes(q));
});

const latestOfficerPreview = computed(() => {
    const last = officerMsgs.value[officerMsgs.value.length - 1];
    if (!last) return 'Start coordinating with Alijis officers...';
    const sender = getSenderName(last).split(',')[0];
    return `${sender}: ${last.message_body}`;
});

const latestCounselorPreview = computed(() => {
    if (!props.canAccessCounselorGc) return 'Restricted to Campus Counselors';
    const last = counselorMsgs.value[counselorMsgs.value.length - 1];
    if (!last) return 'Coordinate campus counseling slots & triage...';
    const sender = getSenderName(last).split(',')[0];
    return `${sender}: ${last.message_body}`;
});

function getSenderName(msg: MessengerOfficerMessage): string {
    return msg.sender?.name ?? msg.sender_name ?? 'CPF Officer';
}

function getInitials(name: string): string {
    const parts = name.replace(/,/g, ' ').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return 'OF';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[1][0]).toUpperCase();
}

function isMine(msg: MessengerOfficerMessage): boolean {
    if (!props.currentUserId) return false;
    return (msg.sender_user_id ?? msg.sender?.id) === props.currentUserId;
}

function scrollToBottom() {
    nextTick(() => {
        if (scrollContainer.value) {
            scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight;
        }
    });
}

function mergeMessages(target: MessengerOfficerMessage[], incoming: MessengerOfficerMessage[]) {
    if (!incoming.length) return false;
    const seen = new Set(target.map((m) => m.id));
    let added = false;
    for (const msg of incoming) {
        if (!seen.has(msg.id)) {
            target.push(msg);
            seen.add(msg.id);
            added = true;
        }
    }
    return added;
}

async function syncActiveThreadMessages() {
    if (typeof document !== 'undefined' && document.hidden) return;
    if (activeThread.value === 'officers') {
        if (props.hideOfficersGc || !props.channel?.id) return;
        const afterId = officerMsgs.value.length ? officerMsgs.value[officerMsgs.value.length - 1].id : 0;
        const { ok, data } = await apiRequest<{ messages?: MessengerOfficerMessage[] }>(
            `/officers-gc/${props.channel.id}/messages?after_id=${afterId}`,
            'GET',
        );
        if (ok && Array.isArray(data.messages) && mergeMessages(officerMsgs.value, data.messages)) {
            scrollToBottom();
        }
    } else if (activeThread.value === 'counselors') {
        if (!props.canAccessCounselorGc) return;
        const afterId = counselorMsgs.value.length ? counselorMsgs.value[counselorMsgs.value.length - 1].id : 0;
        const { ok, data } = await apiRequest<{ messages?: MessengerOfficerMessage[] }>(
            `/counselors-gc/messages?after_id=${afterId}`,
            'GET',
        );
        if (ok && Array.isArray(data.messages) && mergeMessages(counselorMsgs.value, data.messages)) {
            scrollToBottom();
        }
    } else if (activeThread.value === 'counselor_dm' && activeDmPeerId.value !== null) {
        const peerId = activeDmPeerId.value;
        const currentList = dmMessagesByPeer.value[peerId] ?? [];
        const afterId = currentList.length ? currentList[currentList.length - 1].id : 0;
        const { ok, data } = await apiRequest<{ messages?: MessengerOfficerMessage[] }>(
            `/counselors-dm/${peerId}/messages?after_id=${afterId}`,
            'GET',
        );
        if (ok && Array.isArray(data.messages)) {
            if (!dmMessagesByPeer.value[peerId]) dmMessagesByPeer.value[peerId] = [];
            if (mergeMessages(dmMessagesByPeer.value[peerId], data.messages)) {
                scrollToBottom();
            }
        }
    }
}

onMounted(() => {
    scrollToBottom();
    liveSyncTimer = setInterval(() => {
        void syncActiveThreadMessages();
    }, 2500);
});

onBeforeUnmount(() => {
    if (liveSyncTimer) {
        clearInterval(liveSyncTimer);
        liveSyncTimer = null;
    }
});

function switchThread(target: 'officers' | 'counselors') {
    if (target === 'officers' && props.hideOfficersGc) {
        return;
    }
    if (target === 'counselors' && !props.canAccessCounselorGc) {
        selectComingSoon('Counselors Private GC (Adviser & Guidance Counselors Only)');
        return;
    }
    comingSoonNotice.value = null;
    errorText.value = null;
    activeDmPeerId.value = null;
    activeThread.value = target;
    scrollToBottom();
    void syncActiveThreadMessages();
}

function openCounselorDm(peerUserId: number) {
    comingSoonNotice.value = null;
    errorText.value = null;
    activeDmPeerId.value = peerUserId;
    activeThread.value = 'counselor_dm';
    if (!dmMessagesByPeer.value[peerUserId]) {
        dmMessagesByPeer.value[peerUserId] = [];
    }
    scrollToBottom();
    void syncActiveThreadMessages();
}

function selectComingSoon(title: string) {
    comingSoonNotice.value = `${title} — currently unavailable or on hold. Main Officers GC (${props.campusName}) is active.`;
    setTimeout(() => {
        if (comingSoonNotice.value?.startsWith(title)) {
            comingSoonNotice.value = null;
        }
    }, 4000);
}

const canSendInCurrentThread = computed(() => {
    if (activeThread.value === 'counselors') {
        return props.canAccessCounselorGc;
    }
    if (activeThread.value === 'counselor_dm') {
        return props.canAccessCounselorGc && activeDmPeerId.value !== null;
    }
    return Boolean(!props.hideOfficersGc && !props.officersGcPaused && props.channel && props.channel.is_active !== false);
});

async function sendMessage() {
    const body = draft.value.trim();
    if (!body || sending.value || !canSendInCurrentThread.value) return;

    const url =
        activeThread.value === 'counselors'
            ? '/counselors-gc/messages'
            : activeThread.value === 'counselor_dm' && activeDmPeerId.value !== null
              ? `/counselors-dm/${activeDmPeerId.value}/messages`
              : `/officers-gc/${props.channel?.id}/messages`;

    sending.value = true;
    errorText.value = null;

    const { ok, data } = await apiRequest<{ status?: string; errors?: Record<string, string[]> }>(url, 'POST', {
        message_body: body,
    });

    sending.value = false;

    if (!ok) {
        errorText.value = data.errors?.message_body?.[0] ?? data.message ?? 'Unable to send message.';
        return;
    }

    const payload = data as unknown as { message?: MessengerOfficerMessage };
    if (payload.message && typeof payload.message === 'object') {
        if (activeThread.value === 'counselors') {
            mergeMessages(counselorMsgs.value, [payload.message]);
        } else if (activeThread.value === 'counselor_dm' && activeDmPeerId.value !== null) {
            if (!dmMessagesByPeer.value[activeDmPeerId.value]) {
                dmMessagesByPeer.value[activeDmPeerId.value] = [];
            }
            mergeMessages(dmMessagesByPeer.value[activeDmPeerId.value], [payload.message]);
        } else {
            mergeMessages(officerMsgs.value, [payload.message]);
        }
        emit('message-sent', payload.message);
    }
    draft.value = '';
    resetComposerHeight();
    scrollToBottom();
}
</script>

<template>
    <div class="overflow-hidden rounded-[14px] border border-border bg-card text-foreground shadow-sm backdrop-blur-md">
        <div class="grid h-[calc(100vh-8.5rem)] min-h-[480px] md:grid-cols-[240px_1fr]">
            <!-- LEFT SIDEBAR: MESSENGER CHAT THREADS LIST -->
            <aside class="flex min-h-0 flex-col border-b border-border bg-muted/40 md:border-r md:border-b-0">
                <div class="border-b border-border p-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <MessageCircle class="size-4 text-primary" />
                            <h2 class="text-base font-bold text-foreground">Chats</h2>
                        </div>
                        <span class="rounded-full bg-[#1A8F26]/12 px-2 py-0.5 text-xs font-bold text-[#1A8F26]">
                            Alijis Active
                        </span>
                    </div>

                    <!-- Messenger-style Thread Search -->
                    <div class="relative mt-2.5">
                        <Search class="pointer-events-none absolute top-2.5 left-2.5 size-3.5 text-muted-foreground" />
                        <input
                            v-model="threadSearch"
                            type="text"
                            placeholder="Search groups..."
                            class="w-full rounded-lg border border-border bg-card py-1.5 pr-2.5 pl-8 text-sm text-foreground focus:border-primary focus:outline-none"
                        />
                    </div>
                </div>

                <div class="flex-1 space-y-1 overflow-y-auto p-2">
                    <!-- ACTIVE THREAD 1: MAIN OFFICERS GC (ALIJIS) -->
                    <button
                        v-if="!hideOfficersGc"
                        type="button"
                        @click="switchThread('officers')"
                        class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition"
                        :class="activeThread === 'officers' ? 'bg-primary/12' : 'hover:bg-accent/60'"
                    >
                        <div class="relative flex size-9 shrink-0 items-center justify-center rounded-full bg-[#0384C5] text-xs font-extrabold text-white shadow-xs">
                            <span>AL</span>
                            <span class="absolute right-0 bottom-0 size-2.5 rounded-full border-2 border-card bg-[#1A8F26]" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <span class="truncate text-sm font-bold text-foreground">Main Officers GC</span>
                                <span class="shrink-0 text-xs font-bold text-[#1A8F26]">Active</span>
                            </div>
                            <div class="truncate text-xs font-semibold text-primary">
                                {{ campusName }}
                            </div>
                            <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                {{ latestOfficerPreview }}
                            </p>
                        </div>
                    </button>

                    <!-- ACTIVE THREAD 2: CAMPUS COUNSELORS PRIVATE GC (ALIJIS) -->
                    <button
                        type="button"
                        @click="switchThread('counselors')"
                        class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition"
                        :class="[
                            activeThread === 'counselors'
                                ? 'bg-[#1A8F26]/14'
                                : canAccessCounselorGc
                                  ? 'hover:bg-accent/60'
                                  : 'opacity-70 hover:bg-accent/60',
                        ]"
                    >
                        <div
                            class="relative flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-extrabold shadow-xs"
                            :class="canAccessCounselorGc ? 'bg-[#1A8F26] text-white' : 'bg-muted text-muted-foreground'"
                        >
                            <HeartHandshake class="size-4" />
                            <span
                                v-if="!canAccessCounselorGc"
                                class="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-card shadow-xs"
                            >
                                <Lock class="size-2.5 text-muted-foreground" />
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <span class="truncate text-sm font-bold text-foreground">Counselors Private GC</span>
                                <span
                                    v-if="canAccessCounselorGc"
                                    class="shrink-0 text-xs font-bold text-[#1A8F26]"
                                >
                                    Active
                                </span>
                            </div>
                            <div class="truncate text-xs font-semibold text-[#1A8F26]">
                                {{ campusName }} Guidance
                            </div>
                            <p class="mt-0.5 truncate text-xs text-muted-foreground">
                                {{ latestCounselorPreview }}
                            </p>
                        </div>
                    </button>

                    <!-- AUTOMATIC 1-ON-1 COUNSELOR DIRECT CHATS -->
                    <template v-if="canAccessCounselorGc && counselorPeers.length > 0">
                        <div class="px-2 pt-2.5 pb-1 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                            Direct Counselor Chats
                        </div>
                        <button
                            v-for="peer in counselorPeers"
                            :key="`dm-${peer.user_id}`"
                            type="button"
                            @click="openCounselorDm(peer.user_id)"
                            class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left transition"
                            :class="activeThread === 'counselor_dm' && activeDmPeerId === peer.user_id ? 'bg-[#1A8F26]/14' : 'hover:bg-accent/60'"
                        >
                            <div class="relative flex size-9 shrink-0 items-center justify-center rounded-full bg-[#1A8F26]/85 text-xs font-extrabold text-white">
                                <span>{{ getInitials(peer.name) }}</span>
                                <span class="absolute right-0 bottom-0 size-2.5 rounded-full border-2 border-card bg-[#1A8F26]" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-bold text-foreground">{{ peer.name }}</div>
                                <div class="truncate text-xs font-medium text-[#1A8F26]">{{ peer.role_label }}</div>
                            </div>
                        </button>
                    </template>

                    <!-- COMING SOON CAMPUS CHANNELS (Hidden for Guidance Counselors) -->
                    <template v-if="!hideOfficersGc">
                    <div class="px-2 pt-2.5 pb-1 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                        Other Campuses
                    </div>

                    <button
                        v-for="group in filteredComingSoon"
                        :key="group.code"
                        type="button"
                        @click="selectComingSoon(group.title)"
                        class="flex w-full items-center gap-2.5 rounded-xl p-2 text-left opacity-75 transition hover:bg-accent/60 hover:opacity-95"
                    >
                        <div class="relative flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold text-muted-foreground">
                            <span>{{ group.initials }}</span>
                            <span class="absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full bg-card shadow-xs">
                                <Lock class="size-2.5 text-muted-foreground" />
                            </span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-1">
                                <span class="truncate text-sm font-semibold text-foreground">{{ group.title }}</span>
                            </div>
                            <div class="mt-0.5 flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-300">
                                <span>Coming Soon</span>
                                <span>•</span>
                                <span>On Hold</span>
                            </div>
                        </div>
                    </button>
                    </template>
                </div>
            </aside>

            <!-- RIGHT PANE: ACTIVE MESSENGER CONVERSATION -->
            <div class="flex min-h-0 flex-col bg-card">
                <!-- Chat Top Header -->
                <header class="flex flex-wrap items-center justify-between gap-2.5 border-b border-border px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                        <div
                            class="relative flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-extrabold text-white"
                            :class="activeThread === 'counselors' ? 'bg-[#1A8F26]' : 'bg-[#0384C5]'"
                        >
                            <HeartHandshake v-if="activeThread === 'counselors'" class="size-4" />
                            <Users v-else class="size-4" />
                            <span class="absolute right-0 bottom-0 size-2.5 rounded-full border-2 border-card bg-[#1A8F26]" />
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="text-base font-bold text-foreground">
                                    {{
                                        activeThread === 'counselors'
                                            ? `Counselors Private GC — ${campusName}`
                                            : activeThread === 'counselor_dm' && activeDmPeer
                                              ? `Direct Chat: ${activeDmPeer.name}`
                                              : `Main Officers GC — ${campusName}`
                                    }}
                                </h3>
                                <span
                                    v-if="activeThread === 'officers' && channel?.academic_year"
                                    class="rounded-full bg-secondary px-2 py-0.5 text-xs font-semibold text-secondary-foreground"
                                >
                                    AY {{ channel.academic_year }}
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    activeThread === 'counselors'
                                        ? 'Campus Guidance Counselors & Club Adviser confidential channel'
                                        : 'Active now • Adviser, President, VP, Secretary, Treasurer, Auditor, Business Mgr, PIO & SAA'
                                }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <Link
                            v-if="showCampusOpsLink"
                            href="/officers-chat"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-primary/30 bg-background px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-primary-foreground"
                        >
                            <span>Full Officers Chat</span>
                            <ExternalLink class="size-3.5" />
                        </Link>
                        <button
                            v-if="activeThread === 'officers' && canArchive && channel?.is_active !== false"
                            type="button"
                            @click="requestArchiveConfirmation('officers')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-card px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50"
                        >
                            <Archive class="size-3.5" />
                            Archive ZIP &amp; Reset
                        </button>
                        <button
                            v-if="activeThread === 'counselors' && canAccessCounselorGc"
                            type="button"
                            @click="requestArchiveConfirmation('counselors')"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-card px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50"
                        >
                            <Archive class="size-3.5" />
                            Archive ZIP &amp; Clear
                        </button>
                    </div>
                </header>

                <!-- Archive Success Banner -->
                <div
                    v-if="archiveNotice"
                    class="flex items-center gap-1.5 border-b border-[#1A8F26]/25 bg-[#1A8F26]/10 px-4 py-2 text-xs font-semibold text-[#1A8F26]"
                >
                    <CheckCircle2 class="size-3.5 shrink-0" />
                    <span>{{ archiveNotice }}</span>
                </div>

                <!-- Coming Soon Toast Banner -->
                <div
                    v-if="comingSoonNotice"
                    class="border-b border-amber-300/60 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-900"
                >
                    <Lock class="mr-1 inline size-3.5" />
                    {{ comingSoonNotice }}
                </div>

                <!-- Scrollable Messenger Bubbles -->
                <div
                    ref="scrollContainer"
                    class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto bg-background/55 px-4 py-3.5"
                >
                    <div
                        v-if="displayedMessages.length === 0"
                        class="m-auto flex max-w-xs flex-col items-center text-center text-sm text-muted-foreground"
                    >
                        <div class="mb-2 flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <MessageCircle class="size-6" />
                        </div>
                        <p class="font-bold text-foreground">
                            {{ activeThread === 'counselors' ? `Counselors GC (${campusName})` : `Main Officers GC (${campusName})` }}
                        </p>
                        <p class="mt-1 text-xs leading-relaxed">
                            No messages yet. Say hello to start coordinating.
                        </p>
                    </div>

                    <div
                        v-for="msg in displayedMessages"
                        :key="`messenger-${activeThread}-${msg.id}`"
                        class="flex items-end gap-2.5"
                        :class="isMine(msg) ? 'justify-end' : 'justify-start'"
                    >
                        <!-- Other Avatar -->
                        <div
                            v-if="!isMine(msg)"
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/15 text-xs font-bold text-primary"
                        >
                            {{ getInitials(getSenderName(msg)) }}
                        </div>

                        <div
                            class="flex max-w-[78%] flex-col"
                            :class="isMine(msg) ? 'items-end' : 'items-start'"
                        >
                            <div
                                v-if="!isMine(msg)"
                                class="mb-1 flex flex-wrap items-center gap-1.5 px-1"
                            >
                                <span class="text-xs font-bold text-foreground">{{ getSenderName(msg) }}</span>
                                <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-bold text-primary uppercase">
                                    {{ msg.sender_role.replace(/_/g, ' ') }}
                                </span>
                            </div>

                            <div
                                class="whitespace-pre-wrap break-words px-3.5 py-2 text-sm leading-relaxed shadow-2xs"
                                :class="
                                    isMine(msg)
                                        ? 'rounded-2xl rounded-br-sm bg-primary text-primary-foreground'
                                        : 'rounded-2xl rounded-bl-sm border border-border bg-card text-foreground'
                                "
                            >
                                {{ msg.message_body }}
                            </div>

                            <span v-if="msg.created_at" class="mt-0.5 px-1 text-xs text-muted-foreground">
                                {{ msg.created_at }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Error Banner -->
                <div
                    v-if="errorText"
                    class="border-t border-red-200 bg-red-50 px-4 py-2 text-xs font-semibold text-red-700"
                >
                    {{ errorText }}
                </div>

                <!-- Messenger Multi-line Pill Composer (Enter sends, Shift+Enter adds newline) -->
                <form
                    v-if="canSendInCurrentThread"
                    @submit.prevent="sendMessage"
                    class="flex items-end gap-2 border-t border-border bg-card px-3.5 py-2.5"
                >
                    <textarea
                        ref="composerRef"
                        v-model="draft"
                        rows="1"
                        maxlength="2000"
                        :placeholder="
                            activeThread === 'counselors'
                                ? 'Aa  Message Campus Counselors GC... (Shift+Enter for new line)'
                                : 'Aa  Message Main Officers GC... (Shift+Enter for new line)'
                        "
                        @input="autoResizeComposer"
                        @keydown.enter.exact.prevent="sendMessage"
                        class="max-h-36 min-w-0 flex-1 resize-none overflow-y-auto rounded-xl border border-border bg-background px-3.5 py-2 text-sm leading-relaxed text-foreground focus:border-primary focus:outline-none"
                    />
                    <button
                        type="submit"
                        :disabled="sending || !draft.trim()"
                        aria-label="Send message"
                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground transition hover:opacity-90 disabled:opacity-45"
                    >
                        <Send class="size-4" />
                    </button>
                </form>
                <div v-else class="border-t border-border bg-muted/40 px-4 py-2.5 text-center text-sm text-muted-foreground">
                    No active channel is currently open for messaging.
                </div>
            </div>
        </div>

        <!-- In-Page Archive Confirmation Modal (Officers GC & Counselors GC) -->
        <div
            v-if="confirmArchiveTarget"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4 backdrop-blur-sm"
            @click.self="confirmArchiveTarget = null"
        >
            <div class="w-full max-w-md rounded-[14px] border border-border bg-card p-4 text-sm text-foreground shadow-xl">
                <div class="flex items-start justify-between gap-3 border-b border-border pb-2.5">
                    <div class="flex items-center gap-2 text-base font-bold text-red-700">
                        <AlertTriangle class="size-4 shrink-0" />
                        <span>
                            {{
                                confirmArchiveTarget === 'counselors'
                                    ? 'Archive & Clear Counselors Private GC?'
                                    : 'Archive & Reset Main Officers GC?'
                            }}
                        </span>
                    </div>
                    <button
                        type="button"
                        @click="confirmArchiveTarget = null"
                        class="rounded-lg border border-border bg-background p-1 text-muted-foreground hover:text-foreground"
                        aria-label="Close confirmation modal"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                    <template v-if="confirmArchiveTarget === 'counselors'">
                        Are you sure? All current messages in <strong class="text-foreground">Counselors Private GC</strong> will be exported into a SHA-256 verified ZIP archive and cleared from the live thread.
                    </template>
                    <template v-else>
                        Are you sure? All messages in <strong class="text-foreground">Main Officers GC ({{ channel?.academic_year ?? 'Current Term' }})</strong> will be exported into a SHA-256 verified ZIP archive, cleared, and a fresh active channel will immediately open for the next term.
                    </template>
                </p>

                <div class="mt-4 flex justify-end gap-2">
                    <button
                        type="button"
                        :disabled="archiving"
                        @click="confirmArchiveTarget = null"
                        class="rounded-lg border border-border bg-background px-3.5 py-2 text-sm font-semibold text-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="archiving"
                        @click="confirmArchiveAction"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3.5 py-2 text-sm font-bold text-white transition hover:bg-red-700 disabled:opacity-50"
                    >
                        <Archive class="size-3.5" />
                        {{
                            archiving
                                ? 'Archiving...'
                                : confirmArchiveTarget === 'counselors'
                                  ? 'Yes, Archive & Clear'
                                  : 'Yes, Archive & Reset'
                        }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
