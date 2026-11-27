<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, Moon, Sparkles, Sun, Sunrise, Zap } from '@lucide/vue';
import { useAppearance } from '@/composables/useAppearance';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const { resolvedAppearance, updateAppearance } = useAppearance();
const page = usePage<{ auth?: { user?: unknown } }>();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));

const themeChoices = [
    { value: 'light', label: 'Light', title: 'Soft Cream Light Mode', Icon: Sun, iconClass: 'text-[#FF8D06]' },
    { value: 'sunrise', label: 'Sunrise', title: 'Letter in Sunrise Mode', Icon: Sunrise, iconClass: 'text-[#E04A7A]' },
    { value: 'joy', label: 'Joy', title: 'In Little Blobs of Joy Mode', Icon: Sparkles, iconClass: 'text-[#E09F3E]' },
    { value: 'vice', label: 'Vice', title: 'Somehow, We Got Here Before Anything Worse', Icon: Zap, iconClass: 'text-[#FF5CAA]' },
    { value: 'dark', label: 'Dark', title: 'Twilight Calm Dark Mode', Icon: Moon, iconClass: 'text-[#56B4E3]' },
] as const;
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
        <div class="relative z-10 mb-6 flex w-full max-w-md items-center justify-between gap-2">
            <Link
                :href="isAuthenticated ? '/settings/profile' : home()"
                class="inline-flex items-center gap-1.5 rounded-xl border border-border bg-card/80 px-3 py-1.5 text-xs font-semibold text-muted-foreground backdrop-blur-md transition hover:border-primary/40 hover:text-primary"
            >
                <ArrowLeft class="size-3.5" />
                {{ isAuthenticated ? 'Back to Settings' : 'Back to CPF Home' }}
            </Link>

            <div
                class="inline-flex items-center gap-0.5 rounded-xl border border-border bg-card/85 p-1 backdrop-blur-md"
                role="group"
                aria-label="Theme Mode Switcher"
            >
                <button
                    v-for="item in themeChoices"
                    :key="item.value"
                    type="button"
                    @click="updateAppearance(item.value)"
                    :title="item.title"
                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold transition"
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
                                src="/cpf_logo.png"
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
