<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Download,
    Lock,
    Maximize2,
    Play,
    RotateCcw,
    Sparkles,
    Volume2,
    VolumeX,
    X,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { SystemBootstrapState } from '@/types';

const SHOWCASE_SEEN_KEY = 'cpf_welcome_showcase_completed_v1';
const VIDEO_SRC = '/media/cpf-system-showcase.mp4';
const PPTX_SRC = '/media/cpf-system-presentation.pptx';

const page = usePage<{
    systemBootstrap?: SystemBootstrapState;
    auth?: { user?: unknown };
}>();

const inlineVideoRef = ref<HTMLVideoElement | null>(null);
const inlineMuted = ref(true);

const fullscreenContainerRef = ref<HTMLElement | null>(null);
const fullscreenVideoRef = ref<HTMLVideoElement | null>(null);

const showFullscreenOverlay = ref(false);
const isMandatoryMode = ref(false);
const mandatoryCompleted = ref(false);
const fullscreenMuted = ref(true);
const loadFailed = ref(false);

const currentTimeSec = ref(0);
const durationSec = ref(0);
const maxWatchedSec = ref(0);

const progressPercent = computed(() => {
    if (mandatoryCompleted.value) return 100;
    if (!durationSec.value || durationSec.value <= 0) return 0;
    return Math.min(100, Math.round((currentTimeSec.value / durationSec.value) * 100));
});

const remainingLabel = computed(() => {
    if (!durationSec.value || durationSec.value <= 0) return '--:--';
    const rem = Math.max(0, Math.ceil(durationSec.value - currentTimeSec.value));
    const mins = Math.floor(rem / 60);
    const secs = String(rem % 60).padStart(2, '0');
    return `${mins}:${secs}`;
});

function readSeenFlag(): boolean {
    try {
        return typeof window !== 'undefined' && window.localStorage.getItem(SHOWCASE_SEEN_KEY) === '1';
    } catch {
        return false;
    }
}

function writeSeenFlag(): void {
    try {
        if (typeof window !== 'undefined') {
            window.localStorage.setItem(SHOWCASE_SEEN_KEY, '1');
        }
    } catch {
        // Ignore restricted browser storage environments
    }
}

function lockBodyScroll(locked: boolean): void {
    if (typeof document !== 'undefined') {
        document.body.style.overflow = locked ? 'hidden' : '';
    }
}

async function openFullscreenTheater(mandatory: boolean): Promise<void> {
    if (inlineVideoRef.value) {
        inlineVideoRef.value.pause();
    }

    isMandatoryMode.value = mandatory;
    mandatoryCompleted.value = !mandatory;
    fullscreenMuted.value = mandatory;
    loadFailed.value = false;
    currentTimeSec.value = 0;
    maxWatchedSec.value = 0;
    showFullscreenOverlay.value = true;
    lockBodyScroll(true);

    await nextTick();

    const fsVideo = fullscreenVideoRef.value;
    if (fsVideo) {
        fsVideo.currentTime = 0;
        fsVideo.muted = fullscreenMuted.value;
        fsVideo.play().catch(() => {});
    }

    if (!mandatory) {
        try {
            await fullscreenContainerRef.value?.requestFullscreen?.();
        } catch {
            // Fixed inset-0 theater mode still fills the viewport gracefully
        }
    }
}

async function enableMusicAndFullscreen(): Promise<void> {
    const fsVideo = fullscreenVideoRef.value;
    if (fsVideo) {
        fsVideo.muted = false;
        fullscreenMuted.value = false;
        fsVideo.play().catch(() => {});
    }

    try {
        await fullscreenContainerRef.value?.requestFullscreen?.();
    } catch {
        // Fixed inset-0 fallback stays fullscreen across viewport
    }
}

function toggleFullscreenSound(): void {
    const fsVideo = fullscreenVideoRef.value;
    if (!fsVideo) return;
    fsVideo.muted = !fsVideo.muted;
    fullscreenMuted.value = fsVideo.muted;
    if (fsVideo.paused) {
        fsVideo.play().catch(() => {});
    }
}

function toggleInlineSound(): void {
    const inlineVid = inlineVideoRef.value;
    if (!inlineVid) return;
    inlineVid.muted = !inlineVid.muted;
    inlineMuted.value = inlineVid.muted;
    if (inlineVid.paused) {
        inlineVid.play().catch(() => {});
    }
}

function onFullscreenMetadata(): void {
    const fsVideo = fullscreenVideoRef.value;
    if (!fsVideo) return;
    if (Number.isFinite(fsVideo.duration) && fsVideo.duration > 0) {
        durationSec.value = fsVideo.duration;
    }
}

function onFullscreenTimeUpdate(): void {
    const fsVideo = fullscreenVideoRef.value;
    if (!fsVideo) return;

    currentTimeSec.value = fsVideo.currentTime;
    if (Number.isFinite(fsVideo.duration) && fsVideo.duration > 0) {
        durationSec.value = fsVideo.duration;
    }

    if (fsVideo.currentTime > maxWatchedSec.value) {
        maxWatchedSec.value = fsVideo.currentTime;
    }

    if (
        isMandatoryMode.value &&
        !mandatoryCompleted.value &&
        durationSec.value > 0 &&
        fsVideo.currentTime >= durationSec.value - 0.8
    ) {
        mandatoryCompleted.value = true;
    }
}

function onFullscreenSeeking(): void {
    const fsVideo = fullscreenVideoRef.value;
    if (!fsVideo || !isMandatoryMode.value || mandatoryCompleted.value) return;

    if (fsVideo.currentTime > maxWatchedSec.value + 1) {
        fsVideo.currentTime = maxWatchedSec.value;
    }
}

function onFullscreenEnded(): void {
    const fsVideo = fullscreenVideoRef.value;
    if (isMandatoryMode.value && !mandatoryCompleted.value) {
        mandatoryCompleted.value = true;
    }
    if (fsVideo) {
        fsVideo.loop = true;
        fsVideo.play().catch(() => {});
    }
}

function onFullscreenError(): void {
    loadFailed.value = true;
    mandatoryCompleted.value = true;
}

async function closeFullscreenTheater(): Promise<void> {
    if (isMandatoryMode.value && !mandatoryCompleted.value) {
        return;
    }

    if (isMandatoryMode.value) {
        writeSeenFlag();
    }

    if (typeof document !== 'undefined' && document.fullscreenElement) {
        try {
            await document.exitFullscreen();
        } catch {
            // Ignore browser fullscreen exit restrictions
        }
    }

    if (fullscreenVideoRef.value) {
        fullscreenVideoRef.value.pause();
    }

    showFullscreenOverlay.value = false;
    isMandatoryMode.value = false;
    lockBodyScroll(false);

    if (inlineVideoRef.value) {
        inlineVideoRef.value.muted = inlineMuted.value;
        inlineVideoRef.value.play().catch(() => {});
    }
}

function handleKeydown(e: KeyboardEvent): void {
    if (!showFullscreenOverlay.value) return;
    if (e.key === 'Escape' && isMandatoryMode.value && !mandatoryCompleted.value) {
        e.preventDefault();
        e.stopPropagation();
    } else if (e.key === 'Escape' && (!isMandatoryMode.value || mandatoryCompleted.value)) {
        void closeFullscreenTheater();
    }
}

onMounted(() => {
    if (
        page.props.systemBootstrap?.mandatoryTourEnabled === false ||
        page.props.systemBootstrap?.exemptFromMandatoryTour === true
    ) {
        return;
    }
    if (typeof window !== 'undefined') {
        window.addEventListener('keydown', handleKeydown, true);
    }

    const needsSetup = Boolean(page.props.systemBootstrap?.needsAdviserSetup);
    const isAuthenticated = Boolean(page.props.auth?.user);
    const hasSeen = readSeenFlag();

    if (!needsSetup && !isAuthenticated && !hasSeen) {
        void openFullscreenTheater(true);
    }
});

onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('keydown', handleKeydown, true);
    }
    lockBodyScroll(false);
});
</script>

<template>
    <!-- Bottom-of-Landing Looping PowerPoint Showcase Section -->
    <section id="cpf-system-showcase" class="bento overflow-hidden p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-900/10 pb-4">
            <div class="max-w-2xl">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-[#0384C5]/25 bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                    <Sparkles class="size-3.5" />
                    Dynamic System Showcase • Looping Video &amp; Soundtrack
                </span>
                <h2 class="font-editorial mt-2 text-xl font-bold text-slate-900 md:text-2xl">
                    Explore the CPF Platform in Motion
                </h2>
                <p class="mt-1.5 text-sm leading-relaxed text-slate-600">
                    Walk through our interactive PowerPoint presentation in continuous 1080p loop—highlighting QR event attendance, sequential chapter elections, encrypted time capsules, and all five signature visual themes.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <button
                    type="button"
                    class="bento-btn"
                    @click="openFullscreenTheater(false)"
                >
                    <Maximize2 class="size-3.5" />
                    Watch Fullscreen
                </button>
                <a
                    :href="PPTX_SRC"
                    download="CPF_Dynamic_System_Presentation.pptx"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-[#0384C5]/30 bg-white px-3.5 py-2 text-xs font-semibold text-[#0384C5] transition hover:bg-[#0384C5] hover:text-white"
                >
                    <Download class="size-3.5" />
                    Download PowerPoint (.pptx)
                </a>
            </div>
        </div>

        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-900/15 bg-slate-950 shadow-xl">
            <div class="aspect-video w-full bg-slate-950">
                <video
                    ref="inlineVideoRef"
                    :src="VIDEO_SRC"
                    autoplay
                    loop
                    muted
                    controls
                    playsinline
                    preload="metadata"
                    class="size-full object-contain"
                />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-white/10 bg-slate-900/95 px-4 py-3 text-xs text-slate-200">
                <div class="flex items-center gap-2.5">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/15 px-2.5 py-0.5 text-xs font-bold text-emerald-300">
                        <span class="size-2 animate-pulse rounded-full bg-emerald-400" />
                        LOOPING 1080P SHOWCASE
                    </span>
                    <span class="hidden text-slate-400 sm:inline">12 Animated Slides • 5 Themes • With Background Music</span>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="toggleInlineSound"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/15"
                    >
                        <VolumeX v-if="inlineMuted" class="size-3.5 text-amber-300" />
                        <Volume2 v-else class="size-3.5 text-emerald-300" />
                        {{ inlineMuted ? 'Enable Music' : 'Mute Music' }}
                    </button>
                    <button
                        type="button"
                        @click="openFullscreenTheater(true)"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/15"
                    >
                        <RotateCcw class="size-3.5 text-sky-300" />
                        Replay Intro Tour
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Fullscreen Onboarding / Cinema Showcase Overlay -->
    <div
        v-if="showFullscreenOverlay"
        ref="fullscreenContainerRef"
        role="dialog"
        aria-modal="true"
        aria-label="Circle of Peer Facilitators System Showcase"
        class="fixed inset-0 z-[70] flex flex-col justify-between bg-slate-950 text-white"
    >
        <!-- Top Overlay Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 bg-slate-950/90 px-5 py-3.5 backdrop-blur-md">
            <div class="flex items-center gap-3">
                <div class="size-8 overflow-hidden rounded-full border border-sky-400/40 bg-white">
                    <img src="/cpf_logo.png" alt="CPF Logo" class="size-full object-cover" />
                </div>
                <div>
                    <div class="text-xs font-extrabold tracking-wide text-white uppercase">
                        Circle of Peer Facilitators — System Tour
                    </div>
                    <div class="text-xs text-slate-400">
                        <template v-if="isMandatoryMode && !mandatoryCompleted">
                            Welcome! Please watch the complete onboarding showcase before entering the portal.
                        </template>
                        <template v-else-if="isMandatoryMode && mandatoryCompleted">
                            Onboarding showcase complete — ready to explore the CPF portal.
                        </template>
                        <template v-else>
                            Fullscreen looping PowerPoint showcase with soundtrack.
                        </template>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <button
                    type="button"
                    @click="enableMusicAndFullscreen"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-amber-400/40 bg-amber-500/20 px-3.5 py-1.5 text-xs font-bold text-amber-200 transition hover:bg-amber-500/30"
                >
                    <Play v-if="fullscreenMuted" class="size-3.5" />
                    <Volume2 v-else class="size-3.5 text-emerald-300" />
                    {{ fullscreenMuted ? 'Tap to Enable Music & Fullscreen' : 'Fullscreen + Music On' }}
                </button>

                <button
                    v-if="!fullscreenMuted"
                    type="button"
                    @click="toggleFullscreenSound"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/20"
                >
                    <VolumeX class="size-3.5" />
                    Mute
                </button>

                <button
                    v-if="!isMandatoryMode"
                    type="button"
                    @click="closeFullscreenTheater"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-white/20 bg-white/10 px-3.5 py-1.5 text-xs font-bold text-white transition hover:bg-white/20"
                >
                    <X class="size-4" />
                    Close
                </button>
            </div>
        </div>

        <!-- Center Video Canvas -->
        <div class="relative flex min-h-0 flex-1 items-center justify-center bg-black p-2 sm:p-4">
            <video
                ref="fullscreenVideoRef"
                :src="VIDEO_SRC"
                autoplay
                :loop="!isMandatoryMode || mandatoryCompleted"
                :controls="!isMandatoryMode"
                playsinline
                preload="auto"
                class="max-h-full max-w-full rounded-xl shadow-2xl"
                @loadedmetadata="onFullscreenMetadata"
                @timeupdate="onFullscreenTimeUpdate"
                @seeking="onFullscreenSeeking"
                @ended="onFullscreenEnded"
                @error="onFullscreenError"
            />

            <div
                v-if="loadFailed"
                class="absolute inset-x-4 bottom-6 mx-auto max-w-md rounded-2xl border border-amber-400/40 bg-slate-900/95 p-4 text-center text-xs text-amber-100 shadow-2xl"
            >
                The showcase video stream could not be loaded offline. You may proceed directly to the CPF portal below.
            </div>
        </div>

        <!-- Bottom Action / Mandatory Progress Footer -->
        <div class="border-t border-white/10 bg-slate-950/95 px-5 py-3.5 backdrop-blur-md">
            <div class="mx-auto flex max-w-5xl flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-1 flex-col gap-1.5">
                    <div class="flex items-center justify-between text-xs font-semibold">
                        <span v-if="isMandatoryMode && !mandatoryCompleted" class="inline-flex items-center gap-1.5 text-sky-300">
                            <Lock class="size-3.5" />
                            Mandatory First-Visit Showcase • Remaining: {{ remainingLabel }}
                        </span>
                        <span v-else-if="isMandatoryMode && mandatoryCompleted" class="inline-flex items-center gap-1.5 text-emerald-300">
                            <CheckCircle2 class="size-3.5" />
                            Showcase Completed ({{ progressPercent }}%) • Continuing in Loop Mode
                        </span>
                        <span v-else class="text-slate-300">
                            Continuous 1080p Loop • {{ progressPercent }}%
                        </span>
                    </div>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-white/10">
                        <div
                            class="h-full rounded-full transition-all duration-300"
                            :class="mandatoryCompleted ? 'bg-emerald-400' : 'bg-sky-400'"
                            :style="{ width: `${progressPercent}%` }"
                        />
                    </div>
                </div>

                <div v-if="isMandatoryMode" class="flex shrink-0 items-center justify-end">
                    <button
                        v-if="mandatoryCompleted"
                        type="button"
                        @click="closeFullscreenTheater"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-500 px-5 py-2.5 text-xs font-extrabold text-slate-950 shadow-lg transition hover:bg-emerald-400"
                    >
                        Enter Circle of Peer Facilitators
                        <ArrowRight class="size-4" />
                    </button>
                    <div
                        v-else
                        class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-xs font-bold text-slate-300"
                    >
                        <Lock class="size-3.5 text-amber-300" />
                        Watch Intro to Continue ({{ remainingLabel }})
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
