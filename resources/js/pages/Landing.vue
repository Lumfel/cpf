<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { Moon, Sun } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import type { CampusSummary } from '@/types';

const props = defineProps<{
    campuses?: CampusSummary[];
}>();

const { resolvedAppearance, updateAppearance } = useAppearance();

function toggleAppearance() {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}

const page = usePage<{ flash?: { status?: string } }>();
const flashStatus = computed(() => page.props.flash?.status);

const campusList = computed<CampusSummary[]>(() =>
    props.campuses && props.campuses.length > 0
        ? props.campuses
        : [
              { id: 3, code: 'ALIJIS', name: 'CHMSU Alijis Campus', slug: 'alijis' },
          ],
);

interface HeroSlide {
    tag: string;
    title: string;
    desc: string;
    spotlightTitle: string;
    cards: Array<{ track: string; name: string; yr: string }>;
}

const heroSlides: HeroSlide[] = [
    {
        tag: '● Certified Student Mentors',
        title: 'Peer mentorship',
        desc: 'Meet facilitators from BSIT, Psychology, Education, and Business ready to guide freshmen and upperclassmen through every semester milestone.',
        spotlightTitle: 'ON-DUTY FACILITATOR SPOTLIGHT',
        cards: [
            { track: 'Academic Track', name: 'Katrina Reyes', yr: '4th Yr • BSIT' },
            { track: 'Wellness Track', name: 'Joshua Villanueva', yr: '3rd Yr • BS Psych' },
        ],
    },
    {
        tag: '● Safe Campus Space',
        title: 'A quiet room to talk',
        desc: 'Reserve the campus Testing Room for a quiet one-on-one session or connect with our guidance counselors whenever you need support.',
        spotlightTitle: 'CAMPUS SUPPORT',
        cards: [
            { track: 'Guidance Space', name: 'Testing Room', yr: 'Mon–Fri • 9 AM – 5 PM' },
            { track: 'Peer Support', name: 'Drop-In Desk', yr: 'Open for Students' },
        ],
    },
    {
        tag: '● Student Community',
        title: 'Listening that cares',
        desc: 'Trained peer facilitators across CHMSU campuses are here to listen with empathy and help you navigate college life with confidence.',
        spotlightTitle: 'CAMPUS CHAPTERS',
        cards: [
            { track: 'Active Chapter', name: 'CHMSU Alijis Campus', yr: 'Active Now' },
            { track: 'Coming Soon', name: 'Binalbagan, Talisay & Fortune Towne', yr: 'On Hold' },
        ],
    },
];

const currentSlide = ref(0);
const slideKey = ref(0);
let heroTimer: ReturnType<typeof setInterval> | null = null;

function goToSlide(target: number) {
    currentSlide.value = (target + heroSlides.length) % heroSlides.length;
    slideKey.value++;
}

const cardPools = [
    [
        { img: '/images/cards/card_1.jpg', badge: 'Club Adviser', color: '#0384C5', bg: 'rgba(3,132,197,0.12)', title: 'Dr. Elena V. Montemayor, RGC', sub: 'Guidance Services', body: '“Every student deserves a calm, respectful space to be heard.”' },
        { img: '/images/cards/card_2.jpg', badge: 'President', color: '#FF8D06', bg: 'rgba(255,141,6,0.13)', title: 'Sofia R. Mercado', sub: 'BS Psychology 4A', body: '“What we share openly builds trust across every campus.”' },
    ],
    [
        { img: '/images/cards/card_2.jpg', badge: 'Secretary', color: '#1A8F26', bg: 'rgba(26,143,38,0.12)', title: 'Hannah G. Alvarez', sub: 'BSIS 3A', body: '“Encouraging notes and steady care help us see our own growth.”' },
        { img: '/images/cards/card_3.jpg', badge: 'Vice President', color: '#0384C5', bg: 'rgba(3,132,197,0.12)', title: 'Miguel A. Bautista', sub: 'BSIT 4B', body: '“Supporting peers starts with showing up and listening with patience.”' },
    ],
    [
        { img: '/images/cards/card_3.jpg', badge: 'Treasurer', color: '#FF8D06', bg: 'rgba(255,141,6,0.13)', title: 'Liam C. Delgado', sub: 'BSIT 3B', body: '“You never have to carry stress alone—our doors stay open.”' },
        { img: '/images/cards/card_1.jpg', badge: 'Safe Sharing', color: '#FF8D06', bg: 'rgba(255,141,6,0.13)', title: 'Confidential Support', sub: 'Testing Room & Counseling', body: 'Private conversations stay protected with our guidance counselors.' },
    ],
    [
        { img: '/images/cards/card_4.jpg', badge: 'Peer Mentor', color: '#0384C5', bg: 'rgba(3,132,197,0.12)', title: 'Katrina M. Reyes', sub: 'BSIT 4A', body: '“Every small step forward during a busy week is worth celebrating.”' },
        { img: '/images/cards/card_2.jpg', badge: 'Community', color: '#1A8F26', bg: 'rgba(26,143,38,0.12)', title: 'Growing Together', sub: 'CHMSU Campuses', body: 'Lasting friendships begin when you lend an ear to a fellow student.' },
    ],
];

const activeCards = ref([0, 0, 0, 0]);

interface OfficerSlot {
    id: number;
    poolIndex: number;
    animClass: string;
    staggerMs: number;
    animTick: number;
}

const officerSlots = ref<OfficerSlot[]>([
    { id: 0, poolIndex: 0, animClass: 'anim-var-1', staggerMs: 0, animTick: 0 },
    { id: 1, poolIndex: 1, animClass: 'anim-var-1', staggerMs: 80, animTick: 0 },
    { id: 2, poolIndex: 2, animClass: 'anim-var-1', staggerMs: 160, animTick: 0 },
    { id: 3, poolIndex: 3, animClass: 'anim-var-1', staggerMs: 240, animTick: 0 },
]);

const calmIntervalsSec = [3.5, 5, 6.5, 8, 5.5, 4.5, 7];
let intervalStep = 0;
let lastSingleSlot = -1;
let variationDeck: number[] = [];
let cycleTimer: ReturnType<typeof setTimeout> | null = null;

function refillVariationDeck(lastPlayed: number): number[] {
    const deck = [1, 2, 3, 4, 5, 6, 7];
    for (let i = deck.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [deck[i], deck[j]] = [deck[j], deck[i]];
    }
    if (deck[0] === lastPlayed) {
        [deck[0], deck[deck.length - 1]] = [deck[deck.length - 1], deck[0]];
    }
    return deck;
}

function derangeOrder<T>(items: T[]): T[] {
    const indices = items.map((_, i) => i);
    for (let attempt = 0; attempt < 20; attempt++) {
        const shuffled = [...indices];
        for (let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
        }
        if (shuffled.every((val, idx) => val !== idx)) {
            return shuffled.map((idx) => items[idx]);
        }
    }
    return [...items.slice(1), items[0]];
}

function advanceCardInPool(poolIndex: number) {
    const poolLen = cardPools[poolIndex].length;
    activeCards.value[poolIndex] = (activeCards.value[poolIndex] + 1) % poolLen;
}

let lastVariation = 0;

function runNextCardVariation() {
    if (variationDeck.length === 0) {
        variationDeck = refillVariationDeck(lastVariation);
    }
    const variation = variationDeck.shift() ?? 7;
    lastVariation = variation;

    const animClass = `anim-var-${variation}`;

    if (variation === 1) {
        // Variation 1: Single random card gentle lily rise
        let idx = Math.floor(Math.random() * 4);
        if (idx === lastSingleSlot) idx = (idx + 1) % 4;
        lastSingleSlot = idx;
        const target = officerSlots.value[idx];
        advanceCardInPool(target.poolIndex);
        target.animClass = animClass;
        target.staggerMs = 0;
        target.animTick++;
    } else if (variation === 2 || variation === 3) {
        // Variation 2 & 3: Horizontal row breeze (top or bottom pair)
        const pair = Math.random() < 0.5 ? [0, 1] : [2, 3];
        pair.forEach((slotIdx, order) => {
            const target = officerSlots.value[slotIdx];
            advanceCardInPool(target.poolIndex);
            target.animClass = animClass;
            target.staggerMs = order * 110;
            target.animTick++;
        });
    } else if (variation === 4) {
        // Variation 4: Diagonal pair bloom + swap diagonal positions
        const [a, b] = Math.random() < 0.5 ? [0, 3] : [1, 2];
        const next = [...officerSlots.value];
        [next[a], next[b]] = [next[b], next[a]];
        officerSlots.value = next;
        [a, b].forEach((slotIdx, order) => {
            const target = officerSlots.value[slotIdx];
            advanceCardInPool(target.poolIndex);
            target.animClass = animClass;
            target.staggerMs = order * 120;
            target.animTick++;
        });
    } else if (variation === 5) {
        // Variation 5: Calm center ripple on 2 random distinct cards
        const picks = [0, 1, 2, 3].sort(() => Math.random() - 0.5).slice(0, 2);
        picks.forEach((slotIdx, order) => {
            const target = officerSlots.value[slotIdx];
            advanceCardInPool(target.poolIndex);
            target.animClass = animClass;
            target.staggerMs = order * 130;
            target.animTick++;
        });
    } else if (variation === 6) {
        // Variation 6: All 4 cards rotate clockwise or counter-clockwise with pendulum settle
        const clockwise = Math.random() < 0.5;
        const curr = officerSlots.value;
        officerSlots.value = clockwise
            ? [curr[2], curr[0], curr[3], curr[1]]
            : [curr[1], curr[3], curr[0], curr[2]];
        officerSlots.value.forEach((slot, order) => {
            if (Math.random() < 0.6) advanceCardInPool(slot.poolIndex);
            slot.animClass = animClass;
            slot.staggerMs = order * 85;
            slot.animTick++;
        });
    } else {
        // Variation 7: All 4 cards switch into guaranteed-new random places + staggered refresh
        officerSlots.value = derangeOrder(officerSlots.value);
        officerSlots.value.forEach((slot, order) => {
            advanceCardInPool(slot.poolIndex);
            slot.animClass = animClass;
            slot.staggerMs = order * 95;
            slot.animTick++;
        });
    }
}

function scheduleNextCycle() {
    const baseSec = calmIntervalsSec[intervalStep % calmIntervalsSec.length];
    const jitterMs = Math.floor(Math.random() * 900);
    intervalStep++;
    cycleTimer = setTimeout(() => {
        runNextCardVariation();
        scheduleNextCycle();
    }, baseSec * 1000 + jitterMs);
}

onMounted(() => {
    heroTimer = setInterval(() => goToSlide(currentSlide.value + 1), 5500);
    scheduleNextCycle();
});
onBeforeUnmount(() => {
    if (heroTimer) clearInterval(heroTimer);
    if (cycleTimer) clearTimeout(cycleTimer);
});

const showPrivacyModal = ref(false);
const showApplyModal = ref(false);
const applicationSubmittedBanner = ref(false);

const applyForm = useForm({
    campus_id: campusList.value[0]?.id ?? 1,
    first_name: '',
    middle_name: '',
    last_name: '',
    birthdate: '',
    course: '',
    year_level: '',
    section: '',
    active_email: '',
    cellphone_number: '',
    sex: 'Female',
    gender_identity: 'Woman',
    gender_identity_other: '',
    facebook_profile_url: '',
    active_fb_account: '',
});

function submitApplication() {
    applyForm.post('/membership/apply', {
        preserveScroll: true,
        onSuccess: () => {
            showApplyModal.value = false;
            applicationSubmittedBanner.value = true;
            applyForm.reset(
                'first_name',
                'middle_name',
                'last_name',
                'birthdate',
                'course',
                'year_level',
                'section',
                'active_email',
                'cellphone_number',
                'gender_identity_other',
                'facebook_profile_url',
                'active_fb_account',
            );
        },
    });
}
</script>

<template>
    <Head title="Circle of Peer Facilitators" />

    <div class="cpf-soft-cream">
        <header class="sticky top-0 z-30 border-b border-slate-900/10 bg-[#FBF6EE]/90 px-4 py-3 backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="cpf-logo-badge size-10 overflow-hidden rounded-full border border-[#0384C5]/30 bg-white shadow-sm">
                        <img src="/cpf_logo.jpg" alt="Circle of Peer Facilitators Emblem" class="size-full object-cover" />
                    </div>
                    <div>
                        <div class="text-sm font-extrabold tracking-wide text-slate-900">CIRCLE OF PEER FACILITATORS</div>
                        <div class="text-xs font-semibold text-[#0384C5]">Student Guidance &amp; Peer Support</div>
                    </div>
                </div>

                <nav class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-900/10 bg-white/90 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:text-[#0384C5]"
                        @click="toggleAppearance"
                    >
                        <Sun v-if="resolvedAppearance === 'dark'" class="size-3.5 text-[#F4A94A]" />
                        <Moon v-else class="size-3.5 text-[#0384C5]" />
                        <span>{{ resolvedAppearance === 'dark' ? 'Light' : 'Dark' }}</span>
                    </button>
                    <button type="button" class="rounded-xl border border-slate-900/10 bg-white/90 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:text-[#0384C5]" @click="showPrivacyModal = true">
                        Data Privacy
                    </button>
                    <Link href="/login" class="rounded-xl border border-[#0384C5]/30 bg-white/90 px-3.5 py-1.5 text-xs font-semibold text-[#0384C5] hover:bg-[#0384C5] hover:text-white">
                        Sign In
                    </Link>
                    <button type="button" class="bento-btn" @click="showApplyModal = true">Apply Now</button>
                </nav>
            </div>
        </header>

        <main class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 pb-16">
            <div
                v-if="flashStatus || applicationSubmittedBanner"
                class="bento flex items-center justify-between gap-3 border-[#1A8F26]/30 bg-[#1A8F26]/10 px-5 py-3 text-xs font-semibold text-[#1A8F26]"
            >
                <span>{{ flashStatus ?? 'Your CPF Membership Application has been submitted for campus officer review.' }}</span>
                <button type="button" class="rounded-lg px-2 py-0.5 text-[11px] hover:bg-black/5" @click="applicationSubmittedBanner = false">Dismiss</button>
            </div>

            <!-- Top Wave Hero -->
            <section class="bento relative grid grid-cols-1 items-center gap-7 overflow-hidden bg-gradient-to-br from-white via-[#FBF6EE] to-[#DCEEF8] p-7 md:grid-cols-2 md:p-9 dark:from-[#18212A] dark:via-[#121A22] dark:to-[#132635]">
                <div class="relative z-10 flex flex-col gap-4">
                    <span class="w-fit rounded-full border border-[#0384C5]/20 bg-white px-3 py-1 text-xs font-bold text-[#0384C5]">
                        {{ heroSlides[currentSlide].tag }}
                    </span>
                    <h1 class="font-editorial text-3xl leading-tight font-bold text-[#0384C5] md:text-4xl">
                        {{ heroSlides[currentSlide].title }}
                    </h1>
                    <p class="max-w-md text-sm leading-relaxed text-slate-600">
                        {{ heroSlides[currentSlide].desc }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <button type="button" class="bento-btn" @click="showApplyModal = true">APPLY FOR MEMBERSHIP</button>
                        <button type="button" class="flex size-9 items-center justify-center rounded-full border border-[#0384C5]/30 bg-white font-bold text-[#0384C5]" @click="goToSlide(currentSlide - 1)">←</button>
                        <button type="button" class="flex size-9 items-center justify-center rounded-full border border-[#0384C5]/30 bg-white font-bold text-[#0384C5]" @click="goToSlide(currentSlide + 1)">→</button>
                    </div>
                </div>

                <div :key="slideKey" class="bento small-card-fade hero-enter-right relative z-10 bg-white/90 p-5">
                    <div class="mb-3 flex justify-between text-xs font-bold">
                        <span class="text-[#0384C5]">{{ heroSlides[currentSlide].spotlightTitle }}</span>
                        <span class="text-[#1A8F26]">● Available Now</span>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div v-for="c in heroSlides[currentSlide].cards" :key="c.name" class="small-card-fade rounded-2xl border border-slate-900/10 bg-white p-3.5">
                            <span class="rounded-full bg-[#0384C5]/10 px-2.5 py-0.5 text-[10px] font-bold text-[#0384C5]">{{ c.track }}</span>
                            <div class="mt-2 text-sm font-bold text-slate-900">{{ c.name }}</div>
                            <div class="text-xs font-semibold text-[#0384C5]">{{ c.yr }}</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Middle Static Trio -->
            <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div v-for="word in ['Breath', 'Refresh', 'Listen']" :key="word" class="small-card-fade rounded-3xl border border-slate-900/10 bg-white/85 px-5 py-6 text-center backdrop-blur-lg">
                    <h2 class="font-editorial text-2xl font-bold text-slate-900">{{ word }}</h2>
                </div>
            </section>

            <!-- Bottom Taller Meet Our Officers Container with Image Header & 4 Top-Image Cards -->
            <section class="bento overflow-hidden p-0">
                <div class="relative flex h-48 w-full items-start justify-center overflow-hidden pt-6 md:h-56">
                    <img src="/cpf_officers_header.jpg" alt="Campus Peer Support Lounge" class="absolute inset-0 size-full object-cover opacity-75" />
                    <div class="absolute inset-0 bg-gradient-to-b from-[#FBF6EE]/80 via-[#FBF6EE]/20 to-white/95" />
                    <div class="relative z-10 rounded-full border border-white bg-[#FBF6EE]/85 px-7 py-2 shadow-sm backdrop-blur-md">
                        <h2 class="font-editorial text-2xl font-bold text-[#0384C5]">Meet Our Officers</h2>
                    </div>
                </div>

                <TransitionGroup
                    name="officer-flow"
                    tag="div"
                    class="grid grid-cols-1 gap-5 p-6 pt-4 md:grid-cols-2 md:p-8 md:pt-4"
                >
                    <article
                        v-for="slot in officerSlots"
                        :key="slot.id"
                        class="photo-card small-card-fade"
                    >
                        <div
                            :key="`${slot.id}-${slot.animTick}`"
                            :class="slot.animClass"
                            :style="{ animationDelay: `${slot.staggerMs}ms` }"
                            class="flex flex-1 flex-col"
                        >
                            <div class="photo-card-media">
                                <img
                                    :src="cardPools[slot.poolIndex][activeCards[slot.poolIndex]].img"
                                    :alt="cardPools[slot.poolIndex][activeCards[slot.poolIndex]].title"
                                />
                            </div>
                            <div class="flex flex-1 flex-col p-5">
                                <span
                                    class="w-fit rounded-full px-2.5 py-1 text-xs font-bold"
                                    :style="{
                                        backgroundColor: cardPools[slot.poolIndex][activeCards[slot.poolIndex]].bg,
                                        color: cardPools[slot.poolIndex][activeCards[slot.poolIndex]].color,
                                    }"
                                >
                                    {{ cardPools[slot.poolIndex][activeCards[slot.poolIndex]].badge }}
                                </span>
                                <h3 class="font-editorial mt-3 text-lg font-bold text-slate-900">
                                    {{ cardPools[slot.poolIndex][activeCards[slot.poolIndex]].title }}
                                </h3>
                                <div class="text-xs font-bold text-[#0384C5]">
                                    {{ cardPools[slot.poolIndex][activeCards[slot.poolIndex]].sub }}
                                </div>
                                <p class="mt-2 text-xs leading-relaxed text-slate-600">
                                    {{ cardPools[slot.poolIndex][activeCards[slot.poolIndex]].body }}
                                </p>
                            </div>
                        </div>
                    </article>
                </TransitionGroup>
            </section>
        </main>

        <!-- Data Privacy Modal -->
        <div v-if="showPrivacyModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-md" @click.self="showPrivacyModal = false">
            <div class="bento max-h-[90vh] w-full max-w-xl overflow-y-auto p-6">
                <div class="mb-4 flex items-center justify-between border-b border-slate-900/10 pb-3">
                    <h2 class="font-editorial text-xl font-bold text-slate-900">Data Privacy</h2>
                    <button type="button" class="rounded-lg border px-3 py-1 text-xs font-semibold" @click="showPrivacyModal = false">✕ Close</button>
                </div>
                <div class="space-y-3 text-xs leading-relaxed text-slate-700">
                    <p><strong>1. What we collect:</strong> Full name, birthday, course/year/section, active email, phone number, sex and gender identity, and active Facebook account name to confirm CHMSU student membership.</p>
                    <p><strong>2. Why we need it:</strong> To verify membership, manage your campus chapter roster, confirm Messenger GC membership, and coordinate peer support activities.</p>
                    <p><strong>3. Confidentiality:</strong> Only your Club Adviser and authorized officers can review applications. Counseling referrals are encrypted and go strictly to the Guidance Counselor.</p>
                    <p><strong>4. Your rights (RA 10173):</strong> You may view, correct, or request removal of your record at any time through your Club Adviser or Secretary.</p>
                </div>
                <div class="mt-5 flex justify-end">
                    <button type="button" class="bento-btn" @click="showPrivacyModal = false">I Understand</button>
                </div>
            </div>
        </div>

        <!-- Membership Application Modal -->
        <div v-if="showApplyModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4 backdrop-blur-md" @click.self="showApplyModal = false">
            <div class="bento max-h-[92vh] w-full max-w-xl overflow-y-auto p-6">
                <div class="mb-4 flex items-center justify-between border-b border-slate-900/10 pb-3">
                    <h2 class="font-editorial text-xl font-bold text-[#0384C5]">Membership Application</h2>
                    <button type="button" class="rounded-lg border px-3 py-1 text-xs font-semibold" @click="showApplyModal = false">✕ Close</button>
                </div>
                <form class="grid gap-3 text-xs" @submit.prevent="submitApplication">
                    <div>
                        <label class="mb-1 block font-bold">CHMSU Campus *</label>
                        <select v-model="applyForm.campus_id" required class="w-full rounded-lg border px-3 py-2">
                            <option v-for="c in campusList" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <p class="mt-1 text-[11px] text-slate-500">
                            Currently active for <strong>CHMSU Alijis Campus</strong>. Binalbagan, Talisay, and Fortune Towne are on hold (Coming Soon).
                        </p>
                        <p v-if="applyForm.errors.campus_id" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.campus_id }}</p>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block font-bold">First Name *</label>
                            <input v-model="applyForm.first_name" required class="w-full rounded-lg border px-3 py-2" />
                            <p v-if="applyForm.errors.first_name" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.first_name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Middle Name / M.I.</label>
                            <input v-model="applyForm.middle_name" class="w-full rounded-lg border px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Last Name *</label>
                            <input v-model="applyForm.last_name" required class="w-full rounded-lg border px-3 py-2" />
                            <p v-if="applyForm.errors.last_name" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.last_name }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block font-bold">Birthday (Date of Birth) *</label>
                        <input v-model="applyForm.birthdate" type="date" required class="w-full rounded-lg border px-3 py-2" />
                        <p v-if="applyForm.errors.birthdate" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.birthdate }}</p>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block font-bold">Course / Program *</label>
                            <input v-model="applyForm.course" required placeholder="e.g. BSIT" class="w-full rounded-lg border px-3 py-2" />
                            <p v-if="applyForm.errors.course" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.course }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Year Level *</label>
                            <select v-model="applyForm.year_level" required class="w-full rounded-lg border px-3 py-2">
                                <option value="" disabled>Select Year</option>
                                <option value="1">1st Year</option>
                                <option value="2">2nd Year</option>
                                <option value="3">3rd Year</option>
                                <option value="4">4th Year</option>
                                <option value="5">5th Year</option>
                            </select>
                            <p v-if="applyForm.errors.year_level" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.year_level }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Section *</label>
                            <input v-model="applyForm.section" required placeholder="e.g. A" class="w-full rounded-lg border px-3 py-2" />
                            <p v-if="applyForm.errors.section" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.section }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block font-bold">Active Email Address *</label>
                            <input v-model="applyForm.active_email" type="email" required class="w-full rounded-lg border px-3 py-2" />
                            <p v-if="applyForm.errors.active_email" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.active_email }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Active Phone / Cellphone No. *</label>
                            <input v-model="applyForm.cellphone_number" type="tel" required class="w-full rounded-lg border px-3 py-2" />
                            <p v-if="applyForm.errors.cellphone_number" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.cellphone_number }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block font-bold">Sex (legal/medical) *</label>
                            <select v-model="applyForm.sex" required class="w-full rounded-lg border px-3 py-2"><option>Female</option><option>Male</option></select>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold">Gender Identity</label>
                            <select v-model="applyForm.gender_identity" class="w-full rounded-lg border px-3 py-2"><option>Woman</option><option>Man</option><option>Non-binary</option><option>Prefer not to say</option><option>Others</option></select>
                        </div>
                    </div>
                    <div v-if="applyForm.gender_identity === 'Others'">
                        <label class="mb-1 block font-bold">Specify Gender Identity</label>
                        <input v-model="applyForm.gender_identity_other" placeholder="How you identify yourself" class="w-full rounded-lg border px-3 py-2" />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold">Facebook Profile Link *</label>
                        <input
                            v-model="applyForm.facebook_profile_url"
                            type="url"
                            required
                            placeholder="https://www.facebook.com/your.username"
                            class="w-full rounded-lg border px-3 py-2"
                        />
                        <p class="mt-0.5 text-[11px] text-slate-500">Clickable link shown to the Club Adviser and President during verification.</p>
                        <p v-if="applyForm.errors.facebook_profile_url" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.facebook_profile_url }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block font-bold">Facebook Account Name / Profile Photo Note (Optional)</label>
                        <input v-model="applyForm.active_fb_account" placeholder="e.g. Juan Dela Cruz (CHMSU lanyard)" class="w-full rounded-lg border px-3 py-2" />
                        <p v-if="applyForm.errors.active_fb_account" class="mt-1 text-[11px] font-semibold text-red-600">{{ applyForm.errors.active_fb_account }}</p>
                    </div>
                    <div class="mt-2 flex justify-end gap-2">
                        <button type="button" class="rounded-lg border px-3 py-2 font-semibold" @click="showApplyModal = false">Cancel</button>
                        <button type="submit" class="bento-btn" :disabled="applyForm.processing">Submit Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
