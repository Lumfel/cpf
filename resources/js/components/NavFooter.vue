<script setup lang="ts">
import {
    SidebarGroup,
    SidebarGroupContent,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

type Props = {
    items: NavItem[];
    class?: string;
};

defineProps<Props>();
</script>

<template>
    <SidebarGroup
        :class="`group-data-[collapsible=icon]:p-0 ${$props.class || ''}`"
    >
        <SidebarGroupContent>
            <SidebarMenu>
                <SidebarMenuItem v-for="item in items" :key="item.title">
                    <SidebarMenuButton
                        v-if="item.disabled"
                        type="button"
                        disabled
                        aria-disabled="true"
                        class="cursor-not-allowed text-muted-foreground opacity-80"
                    >
                        <component :is="item.icon" />
                        <span class="flex-1 truncate">{{ item.title }}</span>
                        <span
                            v-if="item.badge"
                            class="rounded-full bg-[#0384C5]/10 px-2 py-0.5 text-[10px] font-semibold text-[#0384C5] dark:bg-[#4AA8D8]/15 dark:text-[#4AA8D8]"
                        >
                            {{ item.badge }}
                        </span>
                    </SidebarMenuButton>
                    <SidebarMenuButton
                        v-else
                        class="text-neutral-600 hover:text-neutral-800 dark:text-neutral-300 dark:hover:text-neutral-100"
                        as-child
                    >
                        <a
                            :href="toUrl(item.href)"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <component :is="item.icon" />
                            <span>{{ item.title }}</span>
                        </a>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroupContent>
    </SidebarGroup>
</template>
