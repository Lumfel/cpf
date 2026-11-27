<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Archive, Award, Building2, CalendarDays, LayoutGrid, MessageCircle, ScrollText, ShieldCheck, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as membershipIndex } from '@/routes/membership';
import type { Auth, NavItem } from '@/types';

const page = usePage<{ auth: Auth }>();
const roleLabel = computed(() => page.props.auth?.roleLabel ?? 'Ordinary Account');
const campusName = computed(() => page.props.auth?.membership?.campus?.name ?? 'No Campus Assigned');
const isOfficerOrAdviser = computed(() => Boolean(page.props.auth?.can?.isOfficerOrAdviser));
const isGuidanceCounselor = computed(() => page.props.auth?.role === 'guidance_counselor');
const isAlumni = computed(() => Boolean(page.props.auth?.isAlumni));
const canAccessAlumniArchive = computed(() => Boolean(page.props.auth?.can?.accessAlumniArchive));

const mainNavItems = computed<NavItem[]>(() => {
    if (isAlumni.value) {
        return [
            {
                title: 'Former Members & Archive',
                href: '/alumni-archive',
                icon: Archive,
            },
            {
                title: 'My Achievements & Awards',
                href: '/my-credentials',
                icon: Award,
            },
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
        ];
    }

    if (isGuidanceCounselor.value) {
        return [
            {
                title: 'Dashboard',
                href: dashboard(),
                icon: LayoutGrid,
            },
            {
                title: 'Campus Hub (Guidance)',
                href: '/campus-hub',
                icon: Building2,
            },
        ];
    }

    return [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Events & Activities',
            href: '/events-activities',
            icon: CalendarDays,
        },
        ...(isOfficerOrAdviser.value
            ? [
                  {
                      title: 'Officers Chat',
                      href: '/officers-chat',
                      icon: MessageCircle,
                  },
              ]
            : []),
        {
            title: 'Membership & GC Checklist',
            href: membershipIndex(),
            icon: Users,
        },
        {
            title: 'My Achievements & Awards',
            href: '/my-credentials',
            icon: Award,
        },
        {
            title: 'Campus Hub',
            href: '/campus-hub',
            icon: Building2,
        },
        {
            title: 'Community & Archives',
            href: '/community-hub',
            icon: Archive,
        },
        ...(canAccessAlumniArchive.value
            ? [
                  {
                      title: 'Former Members & Archive',
                      href: '/alumni-archive',
                      icon: Archive,
                  },
              ]
            : []),
    ];
});

const footerNavItems: NavItem[] = [
    {
        title: 'Policy & By-Laws',
        href: '/policy-and-bylaws',
        icon: ScrollText,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="gap-1.5 p-1.5">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child class="h-10 px-2 py-1.5 group-data-[collapsible=icon]:justify-center">
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <div class="mx-1.5 rounded-lg border border-border bg-card px-2.5 py-1.5 text-xs text-foreground group-data-[collapsible=icon]:hidden">
                <div class="flex items-center gap-1.5 font-semibold text-primary">
                    <ShieldCheck class="size-3.5 shrink-0" />
                    <span class="truncate">{{ roleLabel }}</span>
                </div>
                <div class="mt-0.5 truncate text-xs text-muted-foreground">
                    {{ campusName }}
                </div>
            </div>

            <div
                class="hidden size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-card text-primary group-data-[collapsible=icon]:mx-auto group-data-[collapsible=icon]:mt-0.5 group-data-[collapsible=icon]:flex"
                :title="`${roleLabel} • ${campusName}`"
                :aria-label="`${roleLabel} — ${campusName}`"
            >
                <ShieldCheck class="size-4 shrink-0" />
            </div>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter class="gap-1 p-1.5">
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
