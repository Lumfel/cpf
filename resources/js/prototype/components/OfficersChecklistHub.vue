<script setup lang="ts">
import {
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    MessageCircle,
    Search,
    UserCheck,
    UserPlus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { CampusMembershipRecord, CampusSummary, CpfRoleValue } from '@/types';

const props = defineProps<{
    activeRole: CpfRoleValue;
    campus: CampusSummary;
    initialMembers: CampusMembershipRecord[];
    initialPending: CampusMembershipRecord[];
}>();

// Local reactive copies so prototype works seamlessly both with MySQL data and instant in-browser interactions
const members = ref<CampusMembershipRecord[]>([...props.initialMembers]);
const pendingApps = ref<CampusMembershipRecord[]>([...props.initialPending]);

const searchQuery = ref('');
const gcFilter = ref<'all' | 'unconfirmed' | 'confirmed'>('all');
const showOnlineForm = ref(false);
const showAddModal = ref(false);
const statusToast = ref<string | null>(null);

// Role permissions matching exact CPF Governance (No Admin role; Adviser in charge)
const canAddOrApprove = computed(() =>
    ['adviser', 'president', 'secretary'].includes(props.activeRole),
);
const canConfirmGc = computed(() =>
    ['adviser', 'president'].includes(props.activeRole),
);
const isAdviser = computed(() => props.activeRole === 'adviser');

// Strict A-Z ordering (Last name, First name, M.I.)
const sortedFilteredMembersAZ = computed(() => {
    return [...members.value]
        .sort((a, b) => a.full_name_last_first_mi.localeCompare(b.full_name_last_first_mi))
        .filter((m) => {
            const matchesGc =
                gcFilter.value === 'all' ||
                (gcFilter.value === 'confirmed' && m.is_in_messenger_gc) ||
                (gcFilter.value === 'unconfirmed' && !m.is_in_messenger_gc);
            if (!matchesGc) return false;
            if (!searchQuery.value.trim()) return true;
            const q = searchQuery.value.toLowerCase();
            return (
                m.full_name_last_first_mi.toLowerCase().includes(q) ||
                m.course_year_section.toLowerCase().includes(q) ||
                m.active_fb_account.toLowerCase().includes(q)
            );
        });
});

const gcConfirmedCount = computed(
    () => members.value.filter((m) => m.is_in_messenger_gc).length,
);

// Sample Officer Date Planner events
const plannerEvents = ref([
    {
        date: 'Nov 22, 2026',
        title: 'Peer Facilitation Orientation & GC Roll Call',
        location: 'CHMSU Talisay Student Center',
        lead: 'President & Adviser',
    },
    {
        date: 'Nov 28, 2026',
        title: 'Capstone Wellness Drop-In Lounge',
        location: 'Room 108 Guidance Wing',
        lead: 'Verified Peers',
    },
]);

// 9-Field Online Membership Form State (Matching uploaded Google Form screenshots)
const newForm = ref({
    recorded_email: 'student.applicant@chmsu.edu.ph',
    full_name_last_first_mi: '',
    course_year_section: '',
    sex: 'Female' as 'Male' | 'Female',
    gender_identity: 'Prefer not to say' as 'Man' | 'Woman' | 'Non-binary' | 'Prefer not to say' | 'Others',
    birthdate: '',
    active_email: '',
    active_fb_account: '',
    cellphone_number: '',
    role: 'member' as CpfRoleValue,
});

function showMessage(msg: string) {
    statusToast.value = msg;
    setTimeout(() => {
        statusToast.value = null;
    }, 3500);
}

function submitApplication() {
    if (!newForm.value.full_name_last_first_mi.trim()) return;
    pendingApps.value.push({
        id: Date.now(),
        user_id: Date.now(),
        campus_id: props.campus.id,
        role: 'member',
        status: 'pending',
        enrollment_source: 'online_form',
        recorded_email: newForm.value.recorded_email,
        full_name_last_first_mi: newForm.value.full_name_last_first_mi.trim(),
        course_year_section: newForm.value.course_year_section.trim(),
        sex: newForm.value.sex,
        gender_identity: newForm.value.gender_identity,
        birthdate: newForm.value.birthdate || '2005-01-15',
        active_email: newForm.value.active_email || newForm.value.recorded_email,
        active_fb_account: newForm.value.active_fb_account.trim(),
        cellphone_number: newForm.value.cellphone_number.trim(),
        is_in_messenger_gc: false,
    });
    showOnlineForm.value = false;
    newForm.value.full_name_last_first_mi = '';
    showMessage('Online Membership Application submitted for Adviser / President / Secretary review.');
}

function directAddMember() {
    if (!canAddOrApprove.value || !newForm.value.full_name_last_first_mi.trim()) return;
    const assignedRole = isAdviser.value ? newForm.value.role : 'member';
    members.value.push({
        id: Date.now(),
        user_id: Date.now(),
        campus_id: props.campus.id,
        role: assignedRole,
        status: 'approved',
        enrollment_source: 'officer_added',
        recorded_email: newForm.value.active_email || 'member@cpf.chmsu.edu.ph',
        full_name_last_first_mi: newForm.value.full_name_last_first_mi.trim(),
        course_year_section: newForm.value.course_year_section.trim() || 'BSIS 3A',
        sex: newForm.value.sex,
        gender_identity: newForm.value.gender_identity,
        birthdate: newForm.value.birthdate || '2005-01-15',
        active_email: newForm.value.active_email || 'member@cpf.chmsu.edu.ph',
        active_fb_account: newForm.value.active_fb_account.trim() || 'Sample FB Profile',
        cellphone_number: newForm.value.cellphone_number.trim() || '0917-555-0123',
        is_in_messenger_gc: false,
    });
    showAddModal.value = false;
    newForm.value.full_name_last_first_mi = '';
    showMessage('Member added directly and sorted into the A–Z Messenger GC Checklist.');
}

function approvePending(app: CampusMembershipRecord) {
    if (!canAddOrApprove.value) return;
    pendingApps.value = pendingApps.value.filter((p) => p.id !== app.id);
    members.value.push({
        ...app,
        status: 'approved',
        approved_at: new Date().toISOString(),
    });
    showMessage(`Verified ${app.full_name_last_first_mi} and moved to the A–Z GC Checklist.`);
}

function toggleGc(member: CampusMembershipRecord) {
    if (!canConfirmGc.value) return;
    member.is_in_messenger_gc = !member.is_in_messenger_gc;
}

function updateRole(member: CampusMembershipRecord, role: string) {
    if (!isAdviser.value) return;
    member.role = role as CpfRoleValue;
    showMessage(`Club Adviser updated ${member.full_name_last_first_mi}'s role to ${role.toUpperCase()}.`);
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <!-- Header & Action Buttons -->
        <div class="bento flex flex-wrap items-center justify-between gap-4 rounded-2xl p-5">
            <div>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-[#1A8F26]/10 px-3 py-1 text-xs font-bold text-[#1A8F26]">
                    <ClipboardCheck class="size-3.5" />
                    {{ campus.gc_group_name ?? 'Official Campus Peer GC' }}
                </span>
                <h2 class="mt-2 text-xl font-bold text-slate-900">
                    Officers Hub &amp; A–Z Messenger GC Checklist
                </h2>
                <p class="mt-0.5 text-xs text-slate-600">
                    <strong>Active Role:</strong> {{ activeRole.toUpperCase() }} •
                    Accounted in GC: <strong>{{ gcConfirmedCount }} / {{ members.length }}</strong> members
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    @click="showOnlineForm = !showOnlineForm"
                    class="rounded-xl border border-black/15 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:border-[#0384C5] hover:text-[#0384C5]"
                >
                    {{ showOnlineForm ? 'Hide 9-Field Application Form' : 'Open 9-Field Membership Form' }}
                </button>

                <button
                    v-if="canAddOrApprove"
                    type="button"
                    @click="showAddModal = !showAddModal"
                    class="bento-btn inline-flex items-center gap-1.5 rounded-xl px-4 py-2 text-xs font-semibold"
                >
                    <UserPlus class="size-4" />
                    + Add Member (Adviser / Pres / Sec)
                </button>
            </div>
        </div>

        <div
            v-if="statusToast"
            class="flex items-center gap-2 rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-2.5 text-xs font-semibold text-[#1A8F26]"
        >
            <CheckCircle2 class="size-4 shrink-0" />
            <span>{{ statusToast }}</span>
        </div>

        <!-- 9-Field Online Membership Form (Based on Uploaded Google Form Screenshots) -->
        <div v-if="showOnlineForm" class="bento rounded-2xl p-5">
            <h3 class="text-base font-bold text-slate-900">
                Official CPF Online Membership Form (9 Fields)
            </h3>
            <p class="mt-0.5 text-xs italic text-slate-600">
                Your response helps us respect and recognize your identity. All information is kept confidential.
            </p>
            <form @submit.prevent="submitApplication" class="mt-4 grid gap-3 sm:grid-cols-2">
                <input v-model="newForm.full_name_last_first_mi" required placeholder="Name (Last name, First name, M.I.) *" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs sm:col-span-2" />
                <input v-model="newForm.course_year_section" required placeholder="Course, Year & Section (e.g. BSIS 3A) *" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs" />
                <input v-model="newForm.birthdate" type="date" required class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs" />
                <select v-model="newForm.sex" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs">
                    <option value="Female">Sex (Legal/Medical): Female</option>
                    <option value="Male">Sex (Legal/Medical): Male</option>
                </select>
                <select v-model="newForm.gender_identity" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs">
                    <option value="Woman">Gender Identity: Woman</option>
                    <option value="Man">Gender Identity: Man</option>
                    <option value="Non-binary">Gender Identity: Non-binary</option>
                    <option value="Prefer not to say">Gender Identity: Prefer not to say</option>
                    <option value="Others">Gender Identity: Others</option>
                </select>
                <input v-model="newForm.active_email" type="email" required placeholder="Active Email Address *" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs" />
                <input v-model="newForm.cellphone_number" required placeholder="Active Cellphone No. *" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs" />
                <input v-model="newForm.active_fb_account" required placeholder="Active FB Account (Name, Profile Photo Description) *" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs sm:col-span-2" />
                <div class="flex justify-end sm:col-span-2">
                    <button type="submit" class="bento-btn rounded-xl px-4 py-2 text-xs font-semibold">
                        Submit Online Application
                    </button>
                </div>
            </form>
        </div>

        <!-- Officer Direct Add Member Panel -->
        <div v-if="showAddModal && canAddOrApprove" class="bento rounded-2xl p-5">
            <h3 class="text-base font-bold text-slate-900">
                Directly Add Verified Member ({{ activeRole.toUpperCase() }})
            </h3>
            <form @submit.prevent="directAddMember" class="mt-3 grid gap-3 sm:grid-cols-3">
                <input v-model="newForm.full_name_last_first_mi" required placeholder="Last name, First name, M.I. *" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs sm:col-span-2" />
                <select v-if="isAdviser" v-model="newForm.role" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs">
                    <option value="member">Role: Member</option>
                    <option value="secretary">Role: Secretary</option>
                    <option value="treasurer">Role: Treasurer</option>
                    <option value="president">Role: President</option>
                    <option value="adviser">Role: Adviser</option>
                </select>
                <input v-model="newForm.course_year_section" placeholder="Course, Year & Section" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs" />
                <input v-model="newForm.active_fb_account" placeholder="FB Account & Photo Description" class="rounded-xl border border-black/15 bg-white px-3 py-2 text-xs sm:col-span-2" />
                <div class="flex justify-end sm:col-span-3">
                    <button type="submit" class="bento-btn rounded-xl px-4 py-2 text-xs font-semibold">
                        Verify &amp; Add to A–Z Checklist
                    </button>
                </div>
            </form>
        </div>

        <!-- Pending Online Applications Queue -->
        <div v-if="pendingApps.length > 0" class="bento rounded-2xl p-5">
            <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                <Clock class="size-4 text-[#FF8D06]" />
                Pending Online Membership Applications ({{ pendingApps.length }})
            </div>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <div v-for="app in pendingApps" :key="app.id" class="flex items-center justify-between gap-3 rounded-xl border border-black/10 bg-white p-3.5">
                    <div class="text-xs">
                        <div class="font-bold text-slate-900">{{ app.full_name_last_first_mi }}</div>
                        <div class="text-[#0384C5]">{{ app.course_year_section }} • {{ app.active_fb_account }}</div>
                    </div>
                    <button
                        v-if="canAddOrApprove"
                        type="button"
                        @click="approvePending(app)"
                        class="bento-btn inline-flex shrink-0 items-center gap-1 rounded-xl px-3 py-1.5 text-xs font-semibold"
                    >
                        <UserCheck class="size-3.5" />
                        Approve
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Grid: Upward A-Z Checklist (Left 2 cols) + Officer Date Planner (Right 1 col) -->
        <div class="grid gap-5 lg:grid-cols-3">
            <div class="bento rounded-2xl p-5 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-black/10 pb-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <MessageCircle class="size-4 text-[#0384C5]" />
                        Upward A–Z Member &amp; GC Checklist
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <Search class="pointer-events-none absolute top-2 left-2.5 size-3.5 text-slate-400" />
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search A–Z..."
                                class="rounded-xl border border-black/15 bg-white py-1.5 pr-3 pl-7 text-xs focus:border-[#0384C5] focus:outline-none"
                            />
                        </div>
                        <select v-model="gcFilter" class="rounded-xl border border-black/15 bg-white px-2.5 py-1.5 text-xs">
                            <option value="all">All ({{ members.length }})</option>
                            <option value="unconfirmed">Needs GC ({{ members.length - gcConfirmedCount }})</option>
                            <option value="confirmed">In GC ({{ gcConfirmedCount }})</option>
                        </select>
                    </div>
                </div>

                <!-- Card-by-Card A-Z Stream -->
                <div class="mt-3 flex flex-col gap-2.5">
                    <div
                        v-for="(m, idx) in sortedFilteredMembersAZ"
                        :key="m.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-3.5 transition duration-200 hover:-translate-y-0.5"
                        :class="m.is_in_messenger_gc ? 'border-[#1A8F26]/30 bg-white/95' : 'border-amber-400/50 bg-[#FEF7D6]/60'"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl text-xs font-bold"
                                :class="m.is_in_messenger_gc ? 'bg-[#1A8F26] text-white' : 'bg-[#F3EBDD] text-slate-800'"
                            >
                                #{{ idx + 1 }}
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-sm font-bold text-slate-900">{{ m.full_name_last_first_mi }}</span>
                                    <span class="rounded-full bg-[#0384C5]/10 px-2 py-0.5 text-xs font-bold text-[#0384C5] uppercase">
                                        {{ m.role }}
                                    </span>
                                </div>
                                <div class="text-sm text-slate-600">
                                    {{ m.course_year_section }} • <strong>FB:</strong> {{ m.active_fb_account }}
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <select
                                v-if="isAdviser"
                                :value="m.role"
                                @change="updateRole(m, ($event.target as HTMLSelectElement).value)"
                                class="rounded-xl border border-black/15 bg-white px-2.5 py-1.5 text-xs font-semibold"
                            >
                                <option value="adviser">Adviser</option>
                                <option value="president">President</option>
                                <option value="secretary">Secretary</option>
                                <option value="treasurer">Treasurer</option>
                                <option value="member">Member</option>
                            </select>

                            <button
                                type="button"
                                :disabled="!canConfirmGc"
                                @click="toggleGc(m)"
                                class="rounded-xl px-3 py-1.5 text-xs font-semibold transition"
                                :class="[
                                    m.is_in_messenger_gc
                                        ? 'bg-[#1A8F26] text-white'
                                        : 'bento-btn',
                                    !canConfirmGc && 'cursor-not-allowed opacity-60',
                                ]"
                            >
                                {{ m.is_in_messenger_gc ? 'In Messenger GC ✓' : 'Confirm GC' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Officer Date Planner -->
            <div class="bento flex flex-col gap-3 rounded-2xl p-5">
                <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                    <CalendarDays class="size-4 text-[#FF8D06]" />
                    Officer Date Planner
                </div>
                <div
                    v-for="(ev, i) in plannerEvents"
                    :key="i"
                    class="rounded-xl border border-black/10 bg-white p-3.5 text-xs"
                >
                    <div class="font-bold text-[#0384C5]">{{ ev.date }}</div>
                    <div class="mt-0.5 font-bold text-slate-900">{{ ev.title }}</div>
                    <div class="mt-1 text-slate-600">{{ ev.location }} • {{ ev.lead }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
