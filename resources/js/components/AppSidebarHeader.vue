<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
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

function toggleAppearance() {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}
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

        <button
            type="button"
            @click="toggleAppearance"
            class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground shadow-xs transition hover:border-primary/50 hover:text-primary"
            :title="resolvedAppearance === 'dark' ? 'Switch to Soft Cream Light Mode' : 'Switch to Twilight Calm Dark Mode'"
        >
            <Sun v-if="resolvedAppearance === 'dark'" class="size-3.5 text-[#F2A341]" />
            <Moon v-else class="size-3.5 text-[#0384C5]" />
            <span class="hidden sm:inline">{{ resolvedAppearance === 'dark' ? 'Light Mode' : 'Dark Mode' }}</span>
        </button>
    </header>
</template>
