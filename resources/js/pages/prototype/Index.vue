<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ClipboardCheck, LayoutGrid, QrCode, ShieldCheck, Users } from '@lucide/vue';
import { ref } from 'vue';
import OfficersChecklistHub from '@/prototype/components/OfficersChecklistHub.vue';
import OutsideLeadershipAndQr from '@/prototype/components/OutsideLeadershipAndQr.vue';
import PeersBulletinBoard from '@/prototype/components/PeersBulletinBoard.vue';
import QuoteLoaderOverlay from '@/prototype/components/QuoteLoaderOverlay.vue';
import type { CampusMembershipRecord, CampusSummary, CpfRoleValue } from '@/types';

const props = defineProps<{
    campuses: CampusSummary[];
    approvedMembersAZ: CampusMembershipRecord[];
    pendingApplications: CampusMembershipRecord[];
}>();

// No-login prototype state: switch active portal tab & preview any of the 5 roles
const activePortal = ref<'peers' | 'officers' | 'outside'>('peers');
const previewRole = ref<CpfRoleValue>('adviser');

const fallbackCampus: CampusSummary = props.campuses[0] ?? {
    id: 1,
    code: 'TALISAY',
    name: 'CHMSU Talisay (Main Campus)',
    slug: 'talisay',
    adviser_name: 'Dr. Elena V. Montemayor, RGC',
    gc_group_name: 'CPF Talisay Official Peer GC (AY 2026–2027)',
};

const fallbackMembers: CampusMembershipRecord[] = props.approvedMembersAZ.length
    ? props.approvedMembersAZ
    : [
          {
              id: 1,
              user_id: 3,
              campus_id: 1,
              role: 'secretary',
              status: 'approved',
              enrollment_source: 'officer_added',
              recorded_email: 'secretary@cpf.chmsu.edu.ph',
              full_name_last_first_mi: 'Alvarez, Hannah G.',
              course_year_section: 'BSIS 3A',
              sex: 'Female',
              gender_identity: 'Woman',
              birthdate: '2005-02-11',
              active_email: 'secretary@cpf.chmsu.edu.ph',
              active_fb_account: 'Hannah Alvarez (White Filipiniana portrait)',
              cellphone_number: '0917-555-0103',
              is_in_messenger_gc: true,
          },
          {
              id: 2,
              user_id: 4,
              campus_id: 1,
              role: 'treasurer',
              status: 'approved',
              enrollment_source: 'officer_added',
              recorded_email: 'treasurer@cpf.chmsu.edu.ph',
              full_name_last_first_mi: 'Delgado, Liam C.',
              course_year_section: 'BSIT 3B',
              sex: 'Male',
              gender_identity: 'Man',
              birthdate: '2005-07-08',
              active_email: 'treasurer@cpf.chmsu.edu.ph',
              active_fb_account: 'Liam Delgado (Holding acoustic guitar outdoors)',
              cellphone_number: '0917-555-0104',
              is_in_messenger_gc: true,
          },
          {
              id: 3,
              user_id: 2,
              campus_id: 1,
              role: 'president',
              status: 'approved',
              enrollment_source: 'officer_added',
              recorded_email: 'president@cpf.chmsu.edu.ph',
              full_name_last_first_mi: 'Mercado, Sofia R.',
              course_year_section: 'BS Psychology 4A',
              sex: 'Female',
              gender_identity: 'Woman',
              birthdate: '2004-09-21',
              active_email: 'president@cpf.chmsu.edu.ph',
              active_fb_account: 'Sofia Mercado (Wearing CPF cream lanyard smiling)',
              cellphone_number: '0917-555-0102',
              is_in_messenger_gc: true,
          },
          {
              id: 4,
              user_id: 1,
              campus_id: 1,
              role: 'adviser',
              status: 'approved',
              enrollment_source: 'officer_added',
              recorded_email: 'adviser@cpf.chmsu.edu.ph',
              full_name_last_first_mi: 'Montemayor, Elena V.',
              course_year_section: 'Guidance Services / Adviser',
              sex: 'Female',
              gender_identity: 'Woman',
              birthdate: '1984-05-14',
              active_email: 'adviser@cpf.chmsu.edu.ph',
              active_fb_account: 'Elena Montemayor RGC (Blue blazer at CHMSU Guidance Office)',
              cellphone_number: '0917-555-0101',
              is_in_messenger_gc: true,
          },
          {
              id: 5,
              user_id: 5,
              campus_id: 1,
              role: 'member',
              status: 'approved',
              enrollment_source: 'online_form',
              recorded_email: 'katrina@cpf.chmsu.edu.ph',
              full_name_last_first_mi: 'Reyes, Katrina M.',
              course_year_section: 'BSIT 4A',
              sex: 'Female',
              gender_identity: 'Woman',
              birthdate: '2004-11-03',
              active_email: 'katrina@cpf.chmsu.edu.ph',
              active_fb_account: 'Katrina Reyes (Yellow cardigan in library)',
              cellphone_number: '0917-555-0105',
              is_in_messenger_gc: false,
          },
      ];

const fallbackPending: CampusMembershipRecord[] = props.pendingApplications.length
    ? props.pendingApplications
    : [
          {
              id: 6,
              user_id: 6,
              campus_id: 1,
              role: 'member',
              status: 'pending',
              enrollment_source: 'online_form',
              recorded_email: 'paulfelippegtibooc@gmail.com',
              full_name_last_first_mi: 'Tibo-oc, Paul Felippe G.',
              course_year_section: 'BSIT 4A',
              sex: 'Male',
              gender_identity: 'Man',
              birthdate: '2004-06-15',
              active_email: 'paulfelippegtibooc@gmail.com',
              active_fb_account: 'Paul Felippe Tibo-oc (CHMSU BSIT uniform portrait)',
              cellphone_number: '0917-555-0199',
              is_in_messenger_gc: false,
          },
      ];
</script>

<template>
    <Head title="Circle of Peer Facilitators (CPF) • Vue 3 Prototype" />

    <!-- Compact Blurred-Backdrop Quote Loader -->
    <QuoteLoaderOverlay />

    <div class="min-h-screen bg-[#FBF6EE] text-slate-900">
        <!-- Top Frosted Bento Navigation Bar -->
        <header class="sticky top-0 z-30 border-b border-black/10 bg-[#FBF6EE]/85 backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-3 md:px-6">
                <div class="flex items-center gap-3">
                    <img
                        src="/cpf_logo.jpg"
                        alt="CPF Logo"
                        class="size-10 rounded-2xl border border-[#0384C5]/25 object-cover shadow-sm"
                    />
                    <div>
                        <h1 class="text-sm font-bold tracking-tight text-slate-900 md:text-base">
                            Circle of Peer Facilitators
                        </h1>
                        <p class="text-xs text-slate-600">
                            {{ fallbackCampus.name }} • Open Vue 3 Prototype (No Login)
                        </p>
                    </div>
                </div>

                <!-- Portal View Switcher -->
                <nav class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        @click="activePortal = 'peers'"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                        :class="activePortal === 'peers' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700 hover:border-[#0384C5]'"
                    >
                        <LayoutGrid class="size-3.5" />
                        Peers &amp; Bulletin Studio
                    </button>

                    <button
                        type="button"
                        @click="activePortal = 'officers'"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                        :class="activePortal === 'officers' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700 hover:border-[#0384C5]'"
                    >
                        <ClipboardCheck class="size-3.5" />
                        Officers &amp; A–Z GC Checklist
                    </button>

                    <button
                        type="button"
                        @click="activePortal = 'outside'"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-semibold transition"
                        :class="activePortal === 'outside' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700 hover:border-[#0384C5]'"
                    >
                        <QrCode class="size-3.5" />
                        Outside, Leadership &amp; QR ID
                    </button>
                </nav>

                <!-- No-Login Role Preview Switcher -->
                <div class="flex items-center gap-2 rounded-xl border border-[#0384C5]/25 bg-white px-3 py-1.5 text-xs">
                    <ShieldCheck class="size-4 text-[#0384C5]" />
                    <span class="font-semibold text-slate-600">Preview Role:</span>
                    <select v-model="previewRole" class="bg-transparent font-bold text-slate-900 focus:outline-none">
                        <option value="adviser">Club Adviser (In Charge)</option>
                        <option value="president">President</option>
                        <option value="secretary">Secretary</option>
                        <option value="treasurer">Treasurer</option>
                        <option value="member">Verified Member</option>
                    </select>
                </div>
            </div>
        </header>

        <!-- Main Workspace Content -->
        <main class="mx-auto max-w-6xl px-4 py-6 md:px-6">
            <PeersBulletinBoard v-if="activePortal === 'peers'" />

            <OfficersChecklistHub
                v-else-if="activePortal === 'officers'"
                :active-role="previewRole"
                :campus="fallbackCampus"
                :initial-members="fallbackMembers"
                :initial-pending="fallbackPending"
            />

            <OutsideLeadershipAndQr v-else />
        </main>
    </div>
</template>
