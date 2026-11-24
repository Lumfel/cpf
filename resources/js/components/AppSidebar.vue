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
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <div class="mx-2 mt-2 rounded-xl border border-[#0384C5]/20 bg-[#FBF6EE] px-3 py-2 text-xs text-slate-800 dark:border-[#4AA8D8]/25 dark:bg-[#1C252E] dark:text-[#EDE6DB]">
                <div class="flex items-center gap-1.5 font-semibold text-[#0384C5] dark:text-[#4AA8D8]">
                    <ShieldCheck class="size-3.5 shrink-0" />
                    <span class="truncate">{{ roleLabel }}</span>
                </div>
                <div class="mt-0.5 truncate text-[11px] text-slate-600 dark:text-[#A8B3BE]">
                    {{ campusName }}
                </div>
            </div>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
