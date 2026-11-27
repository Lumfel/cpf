<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Bell, Check, CheckCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { apiRequest } from '@/lib/csrfFetch';
import type { Auth, BreadcrumbItem, HeaderNotificationItem, HeaderNotificationsState } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage<{
    auth: Auth;
    notifications?: HeaderNotificationsState;
}>();

const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const currentUser = computed(() => page.props.auth?.user);
const { getInitials } = useInitials();
const canOpenEvents = computed(
    () => ! page.props.auth?.isAlumni && page.props.auth?.role !== 'guidance_counselor',
);
const notifications = computed<HeaderNotificationItem[]>(
    () => page.props.notifications?.items ?? [],
);
const unreadCount = computed<number>(
    () => page.props.notifications?.unread_count ?? 0,
);

const busyId = ref<number | null>(null);
const markingAll = ref(false);

async function markRead(item: HeaderNotificationItem) {
    if (item.is_read || busyId.value !== null || markingAll.value) {
        return;
    }

    busyId.value = item.id;
    const { ok } = await apiRequest(`/events-activities/notifications/${item.id}/read`, 'PATCH');
    busyId.value = null;

    if (ok) {
        router.reload({ only: ['notifications'] });
    }
}

async function markAllRead() {
    if (unreadCount.value === 0 || markingAll.value) {
        return;
    }

    markingAll.value = true;
    const { ok } = await apiRequest('/events-activities/notifications/read-all', 'POST');
    markingAll.value = false;

    if (ok) {
        router.reload({ only: ['notifications'] });
    }
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

        <div v-if="isAuthenticated" class="flex items-center gap-2">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="relative inline-flex size-9 items-center justify-center rounded-xl border border-border bg-card text-foreground shadow-xs transition hover:border-[#0384C5]/40 hover:text-[#0384C5] focus-visible:ring-2 focus-visible:ring-[#0384C5]/40 focus-visible:outline-none"
                    :aria-label="
                        unreadCount > 0
                            ? `Notifications (${unreadCount} unread)`
                            : 'Notifications'
                    "
                    title="Notifications"
                >
                    <Bell class="size-4" />
                    <span
                        v-if="unreadCount > 0"
                        class="absolute -top-1.5 -right-1.5 inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-[#FF8D06] px-1 text-[10px] leading-none font-bold text-white shadow-xs ring-2 ring-background"
                    >
                        {{ unreadCount > 99 ? '99+' : unreadCount }}
                    </span>
                </button>
            </DropdownMenuTrigger>

            <DropdownMenuContent
                align="end"
                :side-offset="8"
                class="w-80 overflow-hidden rounded-2xl border border-border bg-card p-0 shadow-lg sm:w-96"
            >
                <div class="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-bold text-foreground">Notifications</span>
                        <span
                            v-if="unreadCount > 0"
                            class="rounded-full bg-[#FF8D06]/15 px-2 py-0.5 text-[11px] font-bold text-[#FF8D06]"
                        >
                            {{ unreadCount }} unread
                        </span>
                    </div>

                    <button
                        v-if="unreadCount > 0"
                        type="button"
                        :disabled="markingAll"
                        @click="markAllRead"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold text-[#0384C5] transition hover:bg-[#0384C5]/10 disabled:opacity-50"
                    >
                        <CheckCheck class="size-3.5" />
                        Mark all read
                    </button>
                </div>

                <div v-if="notifications.length === 0" class="px-4 py-8 text-center">
                    <Bell class="mx-auto size-6 text-muted-foreground/50" />
                    <p class="mt-2 text-xs font-semibold text-foreground">You're all caught up</p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        Campus event and activity alerts will appear here.
                    </p>
                </div>

                <ul v-else class="max-h-80 divide-y divide-border overflow-y-auto">
                    <li
                        v-for="item in notifications"
                        :key="item.id"
                        class="px-4 py-3 text-xs transition"
                        :class="item.is_read ? 'bg-card opacity-80' : 'bg-[#0384C5]/5 dark:bg-[#4AA8D8]/10'"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5">
                                    <span
                                        v-if="!item.is_read"
                                        class="size-2 shrink-0 rounded-full bg-[#0384C5]"
                                        aria-hidden="true"
                                    />
                                    <p class="truncate font-bold text-foreground">
                                        {{ item.title }}
                                    </p>
                                </div>
                                <p class="mt-1 line-clamp-2 leading-relaxed text-[11px] text-muted-foreground">
                                    {{ item.message }}
                                </p>
                                <span v-if="item.created_at" class="mt-1 block text-[10px] text-muted-foreground/80">
                                    {{ item.created_at }}
                                </span>
                            </div>

                            <button
                                v-if="!item.is_read"
                                type="button"
                                :disabled="busyId === item.id || markingAll"
                                @click="markRead(item)"
                                class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-[#0384C5]/30 bg-background px-2 py-1 text-[10px] font-semibold text-[#0384C5] transition hover:bg-[#0384C5] hover:text-white disabled:opacity-50"
                            >
                                <Check class="size-3" />
                                Mark read
                            </button>
                            <span
                                v-else
                                class="shrink-0 text-[10px] font-medium text-muted-foreground"
                            >
                                Read
                            </span>
                        </div>
                    </li>
                </ul>

                <div
                    v-if="canOpenEvents"
                    class="border-t border-border bg-muted/30 px-4 py-2 text-center"
                >
                    <Link
                        href="/events-activities"
                        class="text-[11px] font-semibold text-[#0384C5] hover:underline"
                    >
                        View all in Events &amp; Activities
                    </Link>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>

        <DropdownMenu v-if="currentUser">
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="inline-flex size-9 items-center justify-center overflow-hidden rounded-xl border border-border bg-card text-foreground shadow-xs transition hover:border-[#0384C5]/40 focus-visible:ring-2 focus-visible:ring-[#0384C5]/40 focus-visible:outline-none"
                    :title="currentUser.name"
                    aria-label="Account profile menu"
                >
                    <Avatar class="size-8 rounded-lg">
                        <AvatarImage
                            v-if="currentUser.avatar"
                            :src="currentUser.avatar"
                            :alt="currentUser.name"
                        />
                        <AvatarFallback class="rounded-lg bg-primary/15 text-xs font-bold text-primary">
                            {{ getInitials(currentUser.name) }}
                        </AvatarFallback>
                    </Avatar>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                :side-offset="8"
                class="w-56 rounded-2xl border border-border bg-card p-1 shadow-lg"
            >
                <UserMenuContent :user="currentUser" />
            </DropdownMenuContent>
        </DropdownMenu>
        </div>
    </header>
</template>
