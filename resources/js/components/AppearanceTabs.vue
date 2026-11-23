<script setup lang="ts">
import { Monitor, Moon, Sparkles, Sun, Sunrise, Zap } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/types';

const { appearance, updateAppearance } = useAppearance();

const tabs = [
    { value: 'light', Icon: Sun, label: 'Light' },
    { value: 'dark', Icon: Moon, label: 'Dark' },
    { value: 'sunrise', Icon: Sunrise, label: 'Letter in Sunrise' },
    { value: 'joy', Icon: Sparkles, label: 'In Little Blobs of Joy' },
    { value: 'vice', Icon: Zap, label: 'Somehow, We Got Here Before Anything Worse' },
    { value: 'system', Icon: Monitor, label: 'System' },
] as const;

const themeVarieties: Array<{
    value: Appearance;
    name: string;
    subtitle: string;
    gradient: string;
    blobs?: boolean;
    vice?: boolean;
    swatches: string[];
}> = [
    {
        value: 'light',
        name: 'Soft Cream',
        subtitle: 'Warm ivory paper, clear sky blue & calm focus.',
        gradient: 'linear-gradient(135deg, #FBF6EE 0%, #F3EBDD 60%, #DCEEF8 100%)',
        swatches: ['#FBF6EE', '#0384C5', '#FF8D06', '#1A8F26'],
    },
    {
        value: 'dark',
        name: 'Twilight Calm',
        subtitle: 'Deep evening slate with gentle amber & muted teal accents.',
        gradient: 'linear-gradient(135deg, #0E141A 0%, #141C24 55%, #1D2B38 100%)',
        swatches: ['#0E141A', '#56B4E3', '#F4A94A', '#4CC37A'],
    },
    {
        value: 'sunrise',
        name: 'Letter in Sunrise',
        subtitle: 'Vibrant sunset coral, rosy pink horizon & dusk plum.',
        gradient: 'linear-gradient(135deg, #FF9E57 0%, #F46B6E 45%, #E0538C 80%, #9E4E9C 100%)',
        swatches: ['#FFD0CE', '#F26B3A', '#D94B7C', '#8C4E96'],
    },
    {
        value: 'joy',
        name: 'In Little Blobs of Joy',
        subtitle: 'Warm buttercream canvas with calm pastel color blobs.',
        gradient: '#FBF4E4',
        blobs: true,
        swatches: ['#FBF4E4', '#E27664', '#4A9ED6', '#E8B949'],
    },
    {
        value: 'vice',
        name: 'Somehow, We Got Here Before Anything Worse',
        subtitle: 'Vice neon pink, midnight violet, ocean cyan & sunset amber.',
        gradient: 'linear-gradient(135deg, #1B0E2E 0%, #5B1E66 36%, #FF4FA3 74%, #FFA84C 100%)',
        vice: true,
        swatches: ['#160C26', '#FF5CAA', '#3CE4E8', '#FFB357'],
    },
];
</script>

<template>
    <div class="space-y-5">
        <div
            class="inline-flex flex-wrap gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800"
        >
            <button
                v-for="{ value, Icon, label } in tabs"
                :key="value"
                type="button"
                @click="updateAppearance(value)"
                :class="[
                    'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
                    appearance === value
                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                ]"
            >
                <component :is="Icon" class="-ml-1 h-4 w-4" />
                <span class="ml-1.5 text-sm">{{ label }}</span>
            </button>
        </div>

        <div>
            <p class="mb-2.5 text-xs font-semibold text-muted-foreground">
                Theme Palette Varieties
            </p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="item in themeVarieties"
                    :key="item.value"
                    type="button"
                    @click="updateAppearance(item.value)"
                    class="group overflow-hidden rounded-2xl border text-left transition hover:-translate-y-0.5 hover:shadow-md"
                    :class="
                        appearance === item.value
                            ? 'border-primary ring-2 ring-primary/35'
                            : 'border-border bg-card'
                    "
                >
                    <div
                        class="relative h-20 w-full overflow-hidden"
                        :style="{ background: item.gradient }"
                    >
                        <template v-if="item.blobs">
                            <span class="pointer-events-none absolute -top-2 left-2 size-12 rounded-full bg-[#E56C66]/45 blur-sm" />
                            <span class="pointer-events-none absolute top-3 left-14 size-11 rounded-full bg-[#4A9ED6]/45 blur-sm" />
                            <span class="pointer-events-none absolute -bottom-2 left-8 size-11 rounded-full bg-[#F2C85C]/55 blur-sm" />
                            <span class="pointer-events-none absolute top-1 right-6 size-10 rounded-full bg-[#68B886]/45 blur-sm" />
                        </template>
                        <template v-if="item.vice">
                            <span class="pointer-events-none absolute -bottom-3 -left-2 size-16 rounded-full bg-[#3CE4E8]/40 blur-md" />
                            <span class="pointer-events-none absolute -top-2 right-5 size-14 rounded-full bg-[#FFB357]/45 blur-md" />
                        </template>
                        <div
                            class="absolute inset-0 bg-gradient-to-t from-black/15 via-transparent to-transparent"
                        />
                        <div class="absolute right-2.5 bottom-2 flex items-center gap-1">
                            <span
                                v-for="(hex, sIdx) in item.swatches"
                                :key="`${item.value}-${sIdx}`"
                                class="size-3.5 rounded-full border border-white/80 shadow-xs"
                                :style="{ backgroundColor: hex }"
                            />
                        </div>
                    </div>
                    <div class="p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-bold text-foreground">
                                {{ item.name }}
                            </span>
                            <span
                                v-if="appearance === item.value"
                                class="rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-bold text-primary"
                            >
                                Active
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] leading-snug text-muted-foreground">
                            {{ item.subtitle }}
                        </p>
                    </div>
                </button>
            </div>
        </div>
    </div>
</template>
