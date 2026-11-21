<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Moon, Sun } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const { resolvedAppearance, updateAppearance } = useAppearance();

function toggleTheme() {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-6 text-foreground transition-colors duration-300 md:p-10"
    >
        <!-- Subtle Calming Ambient Glows -->
        <div
            class="pointer-events-none absolute -top-28 -left-24 h-80 w-80 rounded-full bg-[#0384C5]/12 blur-3xl dark:bg-[#4AA8D8]/14"
        />
        <div
            class="pointer-events-none absolute -right-24 -bottom-28 h-80 w-80 rounded-full bg-[#FF8D06]/12 blur-3xl dark:bg-[#F2A341]/12"
        />

        <!-- Top Navigation & Theme Switcher -->
        <div class="relative z-10 mb-6 flex w-full max-w-md items-center justify-between">
            <Link
                :href="home()"
                class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card/80 px-3 py-1.5 text-xs font-semibold text-muted-foreground backdrop-blur-md transition hover:border-primary/40 hover:text-primary"
            >
                <ArrowLeft class="size-3.5" />
                Back to CPF Home
            </Link>

            <button
                type="button"
                @click="toggleTheme"
                class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card/80 px-3 py-1.5 text-xs font-semibold text-foreground backdrop-blur-md transition hover:border-primary/40 hover:text-primary"
            >
                <Sun v-if="resolvedAppearance === 'dark'" class="size-3.5 text-[#F2A341]" />
                <Moon v-else class="size-3.5 text-[#0384C5]" />
                <span>{{ resolvedAppearance === 'dark' ? 'Light Mode' : 'Dark Mode' }}</span>
            </button>
        </div>

        <!-- Centered Frosted CPF Bento Auth Card -->
        <div class="bento relative z-10 w-full max-w-md p-7 sm:p-8">
            <div class="flex flex-col gap-7">
                <div class="flex flex-col items-center gap-3 text-center">
                    <Link
                        :href="home()"
                        class="group flex flex-col items-center gap-2"
                    >
                        <div
                            class="cpf-logo-badge size-16 overflow-hidden rounded-full border-2 border-[#0384C5]/35 bg-white p-0.5 shadow-sm transition duration-300 group-hover:scale-105 dark:border-[#56B4E3]/45"
                        >
                            <img
                                src="/cpf_logo.jpg"
                                alt="Circle of Peer Facilitators Emblem"
                                class="size-full rounded-full object-cover"
                            />
                        </div>
                        <span
                            class="rounded-full bg-[#0384C5]/10 px-3 py-0.5 text-[11px] font-bold tracking-wide text-[#0384C5] dark:bg-[#4AA8D8]/15 dark:text-[#4AA8D8]"
                        >
                            Circle of Peer Facilitators
                        </span>
                    </Link>

                    <div class="space-y-1.5">
                        <h1 class="font-editorial text-2xl font-bold tracking-tight text-foreground">
                            {{ title }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>

                <slot />
            </div>
        </div>

        <p class="relative z-10 mt-6 text-center text-xs text-muted-foreground">
            CHMSU Multi-Campus Peer Support &amp; Wellness Portal
        </p>
    </div>
</template>
