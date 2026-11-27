<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    Heart,
    Layers,
    MessageSquarePlus,
    Move,
    Plus,
    Send,
    Sparkles,
    Trash2,
    Type,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';

type TextBlock = {
    id: string;
    text: string;
    x: number;
    y: number;
    zIndex: number;
    bg: 'none' | 'yellow' | 'blue' | 'orange' | 'green';
};

type NotePage = {
    title: string;
    body: string;
    blocks: TextBlock[];
};

type BulletinNote = {
    id: number;
    author: string;
    role: string;
    tag: string;
    timeAgo: string;
    paperTheme: 'yellow' | 'cream' | 'blue';
    supportCount: number;
    pages: NotePage[];
    replies: Array<{ author: string; text: string }>;
};

const notes = ref<BulletinNote[]>([
    {
        id: 1,
        author: 'Katrina Reyes (BSIT 4A)',
        role: 'Verified Member',
        tag: 'Encouragement',
        timeAgo: '18m ago',
        paperTheme: 'yellow',
        supportCount: 15,
        pages: [
            {
                title: 'Capstone Defense Week Shoutout!',
                body: 'Thank you to everyone covering the Room 108 Drop-In Lounge this week. Remember to take a 10-minute breather between sessions!',
                blocks: [
                    {
                        id: 'b-1',
                        text: 'Tea & water stocked in Room 108',
                        x: 24,
                        y: 165,
                        zIndex: 3,
                        bg: 'orange',
                    },
                    {
                        id: 'b-2',
                        text: 'You got this, BSIT & BSIS!',
                        x: 230,
                        y: 185,
                        zIndex: 2,
                        bg: 'blue',
                    },
                ],
            },
            {
                title: 'Peer Duty Reminders',
                body: 'Log your check-in using the Attendance QR ID tab before starting your afternoon shift.',
                blocks: [],
            },
        ],
        replies: [
            { author: 'Sofia M. (Pres)', text: 'Fresh chamomile tea is stocked in Room 108!' },
        ],
    },
    {
        id: 2,
        author: 'Hannah Alvarez (BSIS 3A)',
        role: 'Secretary',
        tag: 'Announcement',
        timeAgo: '2h ago',
        paperTheme: 'cream',
        supportCount: 9,
        pages: [
            {
                title: 'New Applicants A–Z Review',
                body: 'All online membership submissions from this week are queued in the Officers Hub for Adviser, President, or Secretary confirmation.',
                blocks: [
                    {
                        id: 'b-3',
                        text: 'Check FB Photo Description before GC confirm',
                        x: 30,
                        y: 155,
                        zIndex: 2,
                        bg: 'green',
                    },
                ],
            },
        ],
        replies: [],
    },
    {
        id: 3,
        author: 'Liam Delgado (BSIT 3B)',
        role: 'Treasurer',
        tag: 'Reminder',
        timeAgo: '1d ago',
        paperTheme: 'blue',
        supportCount: 12,
        pages: [
            {
                title: 'Semester Merch & Dues Notice',
                body: 'Payment destinations are pending Adviser sign-off before publication. Keep your official receipts ready!',
                blocks: [],
            },
        ],
        replies: [],
    },
]);

// Focus Studio Modal State (Draft before posting OR view/edit existing note)
const studioOpen = ref(false);
const isDrafting = ref(false);
const activeNoteId = ref<number | null>(null);
const activePageIdx = ref(0);
const replyInput = ref('');

// Draft state
const draftTitle = ref('');
const draftBody = ref('');
const draftTag = ref('Encouragement');
const draftBlocks = ref<TextBlock[]>([]);

// Selected text block for moving / layering / styling
const selectedBlockId = ref<string | null>(null);
const draggingBlockId = ref<string | null>(null);
const dragOffset = ref({ x: 0, y: 0 });

const activeNote = computed(() =>
    notes.value.find((n) => n.id === activeNoteId.value) ?? null,
);

const currentBlocks = computed(() => {
    if (isDrafting.value) return draftBlocks.value;
    return activeNote.value?.pages[activePageIdx.value]?.blocks ?? [];
});

function openNoteStudio(note: BulletinNote) {
    isDrafting.value = false;
    activeNoteId.value = note.id;
    activePageIdx.value = 0;
    selectedBlockId.value = null;
    studioOpen.value = true;
}

function openDraftStudio() {
    isDrafting.value = true;
    activeNoteId.value = null;
    activePageIdx.value = 0;
    draftTitle.value = 'Midterm Wellness Check-In';
    draftBody.value = 'Drop by the Peer Lounge if you need someone to talk to or a quiet space to recharge.';
    draftTag.value = 'Wellness';
    draftBlocks.value = [
        {
            id: `blk-${Date.now()}`,
            text: 'Free-floating Text Block (Drag me!)',
            x: 40,
            y: 155,
            zIndex: 2,
            bg: 'yellow',
        },
    ];
    selectedBlockId.value = draftBlocks.value[0].id;
    studioOpen.value = true;
}

function addTextBlock() {
    const newBlock: TextBlock = {
        id: `blk-${Date.now()}`,
        text: 'New Text Block',
        x: 36 + (currentBlocks.value.length * 16) % 120,
        y: 130 + (currentBlocks.value.length * 18) % 90,
        zIndex: currentBlocks.value.length + 2,
        bg: 'yellow',
    };
    if (isDrafting.value) {
        draftBlocks.value.push(newBlock);
    } else if (activeNote.value) {
        activeNote.value.pages[activePageIdx.value].blocks.push(newBlock);
    }
    selectedBlockId.value = newBlock.id;
}

function setBlockBg(block: TextBlock, bg: TextBlock['bg']) {
    block.bg = bg;
}

function changeBlockLayer(block: TextBlock, direction: 'front' | 'back' | 'forward' | 'backward') {
    if (direction === 'front') block.zIndex = 20;
    else if (direction === 'back') block.zIndex = 0;
    else if (direction === 'forward') block.zIndex = Math.min(20, block.zIndex + 1);
    else block.zIndex = Math.max(0, block.zIndex - 1);
}

function removeBlock(id: string) {
    if (isDrafting.value) {
        draftBlocks.value = draftBlocks.value.filter((b) => b.id !== id);
    } else if (activeNote.value) {
        const page = activeNote.value.pages[activePageIdx.value];
        page.blocks = page.blocks.filter((b) => b.id !== id);
    }
    if (selectedBlockId.value === id) selectedBlockId.value = null;
}

function startDrag(event: MouseEvent, block: TextBlock) {
    selectedBlockId.value = block.id;
    draggingBlockId.value = block.id;
    dragOffset.value = {
        x: event.clientX - block.x,
        y: event.clientY - block.y,
    };
}

function onPaperMouseMove(event: MouseEvent) {
    if (!draggingBlockId.value) return;
    const block = currentBlocks.value.find((b) => b.id === draggingBlockId.value);
    if (!block) return;
    block.x = Math.max(8, Math.min(360, event.clientX - dragOffset.value.x));
    block.y = Math.max(8, Math.min(210, event.clientY - dragOffset.value.y));
}

function stopDrag() {
    draggingBlockId.value = null;
}

function publishDraft() {
    if (!draftTitle.value.trim()) return;
    notes.value.unshift({
        id: Date.now(),
        author: 'You (Prototype Preview)',
        role: 'Peer Facilitator',
        tag: draftTag.value,
        timeAgo: 'Just now',
        paperTheme: 'yellow',
        supportCount: 1,
        pages: [
            {
                title: draftTitle.value.trim(),
                body: draftBody.value.trim(),
                blocks: [...draftBlocks.value],
            },
        ],
        replies: [],
    });
    studioOpen.value = false;
}

function addPageToActiveNote() {
    if (!activeNote.value) return;
    activeNote.value.pages.push({
        title: `Page ${activeNote.value.pages.length + 1}`,
        body: 'Write additional peer encouragement or notes here...',
        blocks: [],
    });
    activePageIdx.value = activeNote.value.pages.length - 1;
}

function addReply() {
    if (!activeNote.value || !replyInput.value.trim()) return;
    activeNote.value.replies.push({
        author: 'You (Preview)',
        text: replyInput.value.trim(),
    });
    replyInput.value = '';
}

function blockBgClass(bg: TextBlock['bg']): string {
    switch (bg) {
        case 'yellow':
            return 'bg-amber-200/95 text-slate-900 shadow-sm';
        case 'blue':
            return 'bg-sky-200/95 text-slate-900 shadow-sm';
        case 'orange':
            return 'bg-orange-200/95 text-slate-900 shadow-sm';
        case 'green':
            return 'bg-emerald-200/95 text-slate-900 shadow-sm';
        default:
            return 'bg-transparent text-slate-900';
    }
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <!-- Header Bar -->
        <div class="bento flex flex-wrap items-center justify-between gap-4 rounded-2xl p-5">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                    <Sparkles class="size-3.5" />
                    Peers Corkboard &amp; Focus Studio
                </span>
                <h2 class="mt-2 text-xl font-bold text-slate-900">
                    Campus Peer Bulletin Board
                </h2>
                <p class="mt-0.5 text-xs text-slate-600">
                    Click any pinned note to open the multi-page Focus Studio, or draft a message with free-floating, layered Text Blocks.
                </p>
            </div>

            <button
                type="button"
                @click="openDraftStudio"
                class="bento-btn inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-semibold"
            >
                <MessageSquarePlus class="size-4" />
                Draft New Note in Focus Studio
            </button>
        </div>

        <!-- Compact Cork Bulletin Board Grid -->
        <div class="rounded-3xl border border-amber-900/15 bg-[#E9DCC9] p-5 shadow-inner">
            <div class="grid gap-4 md:grid-cols-3">
                <article
                    v-for="note in notes"
                    :key="note.id"
                    @click="openNoteStudio(note)"
                    class="group cursor-pointer rounded-2xl border border-black/10 p-4 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-md"
                    :class="
                        note.paperTheme === 'yellow'
                            ? 'bg-[#FEF7D6]'
                            : note.paperTheme === 'blue'
                              ? 'bg-[#E9F4FB]'
                              : 'bg-[#FBF6EE]'
                    "
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="rounded-full bg-black/5 px-2.5 py-0.5 text-xs font-bold text-slate-700">
                            {{ note.tag }}
                        </span>
                        <span class="text-xs text-slate-500">{{ note.timeAgo }}</span>
                    </div>

                    <h3 class="mt-2.5 text-sm font-bold text-slate-900 group-hover:text-[#0384C5]">
                        {{ note.pages[0]?.title }}
                    </h3>

                    <p class="mt-1.5 line-clamp-3 text-sm leading-relaxed text-slate-700">
                        {{ note.pages[0]?.body }}
                    </p>

                    <div class="mt-4 flex items-center justify-between border-t border-black/10 pt-2.5 text-xs text-slate-600">
                        <span class="font-semibold">{{ note.author }}</span>
                        <span class="inline-flex items-center gap-1 font-semibold text-[#FF8D06]">
                            <Heart class="size-3.5 fill-current" />
                            {{ note.supportCount }}
                        </span>
                    </div>
                </article>
            </div>
        </div>

        <!-- Focus Studio Modal (Draft Preview + Movable Unaligned Text Blocks) -->
        <div
            v-if="studioOpen"
            class="fixed inset-0 z-40 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm"
            @mouseup="stopDrag"
        >
            <div class="bento flex max-h-[92vh] w-full max-w-2xl flex-col gap-4 overflow-y-auto rounded-3xl p-5 shadow-2xl">
                <!-- Modal Top Bar -->
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-black/10 pb-3">
                    <div class="flex items-center gap-2">
                        <span class="rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                            {{ isDrafting ? 'Draft Preview Studio' : `${activeNote?.tag} • ${activeNote?.timeAgo}` }}
                        </span>

                        <div v-if="!isDrafting && activeNote" class="flex items-center gap-1 text-xs">
                            <button
                                type="button"
                                :disabled="activePageIdx === 0"
                                @click="activePageIdx--"
                                class="rounded-lg border border-black/10 px-2 py-1 disabled:opacity-40"
                            >
                                ← Prev
                            </button>
                            <span class="px-1.5 font-semibold text-slate-700">
                                Page {{ activePageIdx + 1 }} of {{ activeNote.pages.length }}
                            </span>
                            <button
                                type="button"
                                :disabled="activePageIdx >= activeNote.pages.length - 1"
                                @click="activePageIdx++"
                                class="rounded-lg border border-black/10 px-2 py-1 disabled:opacity-40"
                            >
                                Next →
                            </button>
                            <button
                                type="button"
                                @click="addPageToActiveNote"
                                class="rounded-lg bg-[#0384C5] px-2.5 py-1 font-semibold text-white"
                            >
                                + New Page
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        @click="studioOpen = false"
                        class="inline-flex items-center gap-1 rounded-xl border border-black/10 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        <X class="size-3.5" />
                        Close
                    </button>
                </div>

                <!-- Text Block Toolbar (Free-floating, background/none, behind/ahead/way back) -->
                <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-black/10 bg-white/80 p-2.5 text-xs">
                    <button
                        type="button"
                        @click="addTextBlock"
                        class="inline-flex items-center gap-1 rounded-xl bg-[#0384C5] px-3 py-1.5 font-semibold text-white"
                    >
                        <Plus class="size-3.5" />
                        <Type class="size-3.5" />
                        Add Text Block
                    </button>

                    <template v-if="selectedBlockId">
                        <span class="mx-1 text-slate-300">|</span>
                        <span class="font-semibold text-slate-600">BG:</span>
                        <button
                            v-for="bg in (['none', 'yellow', 'blue', 'orange', 'green'] as const)"
                            :key="bg"
                            type="button"
                            @click="setBlockBg(currentBlocks.find((b) => b.id === selectedBlockId)!, bg)"
                            class="rounded-lg border border-black/15 px-2 py-1 capitalize"
                            :class="
                                currentBlocks.find((b) => b.id === selectedBlockId)?.bg === bg
                                    ? 'ring-2 ring-[#0384C5]'
                                    : ''
                            "
                        >
                            {{ bg }}
                        </button>

                        <span class="mx-1 text-slate-300">|</span>
                        <span class="inline-flex items-center gap-1 font-semibold text-slate-600">
                            <Layers class="size-3.5" />
                            Layer:
                        </span>
                        <button
                            type="button"
                            @click="changeBlockLayer(currentBlocks.find((b) => b.id === selectedBlockId)!, 'front')"
                            class="rounded-lg border border-black/10 px-2 py-1 hover:bg-slate-50"
                            title="Bring Ahead / Front"
                        >
                            <ArrowUp class="inline size-3" /> Front
                        </button>
                        <button
                            type="button"
                            @click="changeBlockLayer(currentBlocks.find((b) => b.id === selectedBlockId)!, 'back')"
                            class="rounded-lg border border-black/10 px-2 py-1 hover:bg-slate-50"
                            title="Send Way Back"
                        >
                            <ArrowDown class="inline size-3" /> Way Back
                        </button>
                        <button
                            type="button"
                            @click="removeBlock(selectedBlockId)"
                            class="rounded-lg border border-red-200 px-2 py-1 text-red-600 hover:bg-red-50"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </template>
                </div>

                <!-- Paper Canvas (Supports free-floating unaligned Text Blocks anywhere on the paper) -->
                <div
                    @mousemove="onPaperMouseMove"
                    class="relative min-h-[260px] overflow-hidden rounded-2xl border border-amber-400/50 bg-[#FEF7D6] p-5 shadow-inner"
                    style="background-image: repeating-linear-gradient(transparent, transparent 27px, rgba(3, 132, 197, 0.14) 28px);"
                >
                    <!-- Main Paper Content (zIndex: 1 so blocks can go behind z=0 or ahead z>=2) -->
                    <div class="relative z-[1]">
                        <template v-if="isDrafting">
                            <input
                                v-model="draftTitle"
                                type="text"
                                placeholder="Note headline..."
                                class="w-full bg-transparent text-lg font-bold text-slate-900 focus:outline-none"
                            />
                            <textarea
                                v-model="draftBody"
                                rows="4"
                                placeholder="Write your comforting or peer announcement message..."
                                class="mt-2 w-full resize-none bg-transparent text-sm leading-7 text-slate-800 focus:outline-none"
                            />
                        </template>
                        <template v-else-if="activeNote">
                            <h3 class="text-lg font-bold text-slate-900">
                                {{ activeNote.pages[activePageIdx]?.title }}
                            </h3>
                            <p class="mt-2 text-sm leading-7 text-slate-800">
                                {{ activeNote.pages[activePageIdx]?.body }}
                            </p>
                        </template>
                    </div>

                    <!-- Movable Unaligned Text Blocks -->
                    <div
                        v-for="block in currentBlocks"
                        :key="block.id"
                        @mousedown="startDrag($event, block)"
                        :style="{
                            left: `${block.x}px`,
                            top: `${block.y}px`,
                            zIndex: block.zIndex,
                        }"
                        class="absolute cursor-move rounded-xl px-3 py-1.5 text-xs font-semibold select-none"
                        :class="[
                            blockBgClass(block.bg),
                            selectedBlockId === block.id ? 'ring-2 ring-[#0384C5]' : '',
                        ]"
                    >
                        <div class="flex items-center gap-1.5">
                            <Move class="size-3 shrink-0 opacity-60" />
                            <input
                                v-model="block.text"
                                type="text"
                                @mousedown.stop
                                @focus="selectedBlockId = block.id"
                                class="w-44 bg-transparent focus:outline-none"
                            />
                        </div>
                    </div>
                </div>

                <!-- Draft Publish Action OR Support & Replies -->
                <div v-if="isDrafting" class="flex items-center justify-between pt-1">
                    <span class="text-xs text-slate-500">
                        Drag any Text Block anywhere on the paper and toggle Behind / Ahead layering before posting.
                    </span>
                    <button
                        type="button"
                        @click="publishDraft"
                        class="bento-btn inline-flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-semibold"
                    >
                        <Send class="size-3.5" />
                        Post to Corkboard
                    </button>
                </div>

                <div v-else-if="activeNote" class="flex flex-col gap-3 rounded-2xl border border-black/10 bg-white/80 p-3.5">
                    <div class="flex items-center justify-between">
                        <button
                            type="button"
                            @click="activeNote.supportCount++"
                            class="bento-btn inline-flex items-center gap-1.5 rounded-xl px-3.5 py-1.5 text-xs font-semibold"
                        >
                            <Heart class="size-3.5" />
                            Support ({{ activeNote.supportCount }})
                        </button>
                        <span class="text-xs text-slate-500">
                            Drag Text Blocks on the paper above to test free positioning.
                        </span>
                    </div>

                    <div v-if="activeNote.replies.length" class="flex flex-col gap-1.5">
                        <div
                            v-for="(rep, idx) in activeNote.replies"
                            :key="idx"
                            class="rounded-xl bg-[#FBF6EE] px-3 py-2 text-xs text-slate-700"
                        >
                            <strong class="text-[#0384C5]">{{ rep.author }}:</strong> {{ rep.text }}
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <input
                            v-model="replyInput"
                            type="text"
                            placeholder="Write a supportive reply..."
                            class="flex-1 rounded-xl border border-black/15 bg-white px-3 py-1.5 text-xs focus:border-[#0384C5] focus:outline-none"
                        />
                        <button
                            type="button"
                            @click="addReply"
                            class="bento-btn rounded-xl px-3.5 py-1.5 text-xs font-semibold"
                        >
                            Pin Reply
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
