<script setup lang="ts">
import { Moon, Sparkles, Sun, Sunrise, Zap } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useAppearance } from '@/composables/useAppearance';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const { resolvedAppearance, updateAppearance } = useAppearance();

const themeChoices = [
    { value: 'light', label: 'Light', title: 'Soft Cream Light Mode', Icon: Sun, iconClass: 'text-[#FF8D06]' },
    { value: 'sunrise', label: 'Sunrise', title: 'Letter in Sunrise Mode', Icon: Sunrise, iconClass: 'text-[#E04A7A]' },
    { value: 'joy', label: 'Joy', title: 'In Little Blobs of Joy Mode', Icon: Sparkles, iconClass: 'text-[#E09F3E]' },
    { value: 'vice', label: 'Vice', title: 'Somehow, We Got Here Before Anything Worse', Icon: Zap, iconClass: 'text-[#FF5CAA]' },
    { value: 'dark', label: 'Dark', title: 'Twilight Calm Dark Mode', Icon: Moon, iconClass: 'text-[#56B4E3]' },
] as const;
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/70 bg-background/80 px-6 backdrop-blur-md transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div
            class="inline-flex items-center gap-0.5 rounded-xl border border-border bg-card p-1 shadow-xs"
            role="group"
            aria-label="Theme Mode Switcher"
        >
            <button
                v-for="item in themeChoices"
                :key="item.value"
                type="button"
                @click="updateAppearance(item.value)"
                :title="item.title"
                class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                :class="
                    resolvedAppearance === item.value
                        ? 'bg-primary text-primary-foreground shadow-2xs'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                <component
                    :is="item.Icon"
                    class="size-3.5"
                    :class="resolvedAppearance === item.value ? '' : item.iconClass"
                />
                <span class="hidden sm:inline">{{ item.label }}</span>
            </button>
        </div>
    </header>
</template>
