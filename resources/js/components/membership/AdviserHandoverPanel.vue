<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    CheckCircle2,
    Copy,
    KeyRound,
    ShieldAlert,
    ShieldCheck,
    UserCheck,
    UserPlus,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { apiRequest } from '@/lib/csrfFetch';
import type { PendingAdviserHandoverState } from '@/types';

withDefaults(
    defineProps<{
        isClubAdviser: boolean;
        showInitiateForm?: boolean;
    }>(),
    {
        showInitiateForm: false,
    },
);

const page = usePage<{ pendingAdviserHandover?: PendingAdviserHandoverState }>();
const incoming = computed(() => page.props.pendingAdviserHandover?.incoming ?? null);
const outgoing = computed(() => page.props.pendingAdviserHandover?.outgoing ?? null);
const availableCounselors = computed(
    () => page.props.pendingAdviserHandover?.availableGuidanceCounselors ?? [],
);

const busy = ref(false);
const notice = ref<string | null>(null);
const errorText = ref<string | null>(null);

// Incoming handover acceptance state
const verificationCode = ref('');

// Initiate handover state (Select Existing Guidance Counselor OR Invite/Create New fallback)
const handoverMode = ref<'existing_counselor' | 'invite_new'>(
    availableCounselors.value.length > 0 ? 'existing_counselor' : 'invite_new',
);
const selectedMembershipId = ref<number | ''>(
    availableCounselors.value[0]?.membership_id ?? '',
);

watch(
    availableCounselors,
    (list) => {
        if (list.length > 0 && selectedMembershipId.value === '') {
            selectedMembershipId.value = list[0].membership_id;
        }
        if (list.length === 0 && handoverMode.value === 'existing_counselor') {
            handoverMode.value = 'invite_new';
        }
    },
    { immediate: true },
);

const currentPassword = ref('');
const handoverNotes = ref('');
const newFirstName = ref('');
const newMiddleName = ref('');
const newLastName = ref('');
const newEmail = ref('');
const newSex = ref<'Male' | 'Female' | ''>('');
const newPhone = ref('');
const newInitialPassword = ref('');
const codeCopied = ref(false);

async function initiateHandover() {
    if (busy.value) return;
    busy.value = true;
    notice.value = null;
    errorText.value = null;

    const payload: Record<string, unknown> =
        handoverMode.value === 'existing_counselor'
            ? {
                  mode: 'existing_counselor',
                  target_membership_id: selectedMembershipId.value,
                  current_password: currentPassword.value,
                  handover_notes: handoverNotes.value || null,
              }
            : {
                  mode: 'invite_new',
                  current_password: currentPassword.value,
                  handover_notes: handoverNotes.value || null,
                  first_name: newFirstName.value,
                  middle_name: newMiddleName.value || null,
                  last_name: newLastName.value,
                  email: newEmail.value,
                  sex: newSex.value,
                  cellphone_number: newPhone.value || null,
                  initial_password: newInitialPassword.value,
              };

    const { ok, data } = await apiRequest<{
        status?: string;
        handover?: { confirmation_code: string; to_name: string };
        message?: string;
    }>('/adviser-handover', 'POST', payload);

    busy.value = false;

    if (!ok) {
        errorText.value = data.message ?? 'Unable to initiate Adviser role handover.';
        return;
    }

    currentPassword.value = '';
    handoverNotes.value = '';
    newFirstName.value = '';
    newMiddleName.value = '';
    newLastName.value = '';
    newEmail.value = '';
    newSex.value = '';
    newPhone.value = '';
    newInitialPassword.value = '';
    notice.value = data.handover
        ? `Handover initiated for ${data.handover.to_name}. Share verification code ${data.handover.confirmation_code} with your successor.`
        : 'Adviser role handover initiated.';
    router.reload();
}

async function respondHandover(decision: 'accepted' | 'declined') {
    if (!incoming.value || busy.value) return;
    busy.value = true;
    notice.value = null;
    errorText.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/adviser-handover/${incoming.value.id}/respond`,
        'POST',
        {
            decision,
            confirmation_code: decision === 'accepted' ? verificationCode.value : null,
        },
    );

    busy.value = false;

    if (!ok) {
        errorText.value = data.message ?? 'Unable to process handover response.';
        return;
    }

    verificationCode.value = '';
    notice.value =
        decision === 'accepted'
            ? 'Handover complete! You are now the Club Adviser in charge of this campus.'
            : 'Adviser handover declined.';
    router.reload();
}

async function cancelHandover() {
    if (!outgoing.value || busy.value) return;
    busy.value = true;
    notice.value = null;
    errorText.value = null;

    const { ok, data } = await apiRequest<{ message?: string }>(
        `/adviser-handover/${outgoing.value.id}`,
        'DELETE',
    );

    busy.value = false;

    if (!ok) {
        errorText.value = data.message ?? 'Unable to cancel pending handover.';
        return;
    }

    notice.value = 'Pending Adviser role handover cancelled. You remain Club Adviser.';
    router.reload();
}

async function copyCode() {
    if (!outgoing.value?.confirmation_code || typeof navigator === 'undefined' || !navigator.clipboard) return;
    await navigator.clipboard.writeText(outgoing.value.confirmation_code);
    codeCopied.value = true;
    setTimeout(() => {
        codeCopied.value = false;
    }, 2000);
}
</script>

<template>
    <div v-if="incoming || (outgoing && isClubAdviser) || (showInitiateForm && isClubAdviser)" class="flex flex-col gap-4">
        <div
            v-if="notice"
            class="rounded-2xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-4 py-3 text-xs font-semibold text-[#1A8F26]"
        >
            {{ notice }}
        </div>
        <div
            v-if="errorText"
            class="rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700"
        >
            {{ errorText }}
        </div>

        <!-- 1. INCOMING HANDOVER INVITATION CARD (SHOWN TO SUCCESSOR) -->
        <section
            v-if="incoming"
            class="rounded-3xl border-2 border-[#0384C5]/45 bg-[#FFF9EF] p-5 text-xs text-slate-900 shadow-sm"
        >
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-black/10 pb-3">
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/12 px-3 py-1 text-[11px] font-bold text-[#0384C5]">
                        <ArrowRightLeft class="size-3.5" />
                        Pending Club Adviser Role Handover
                    </span>
                    <h2 class="font-editorial mt-2 text-lg font-bold text-slate-900">
                        Accept Club Adviser Authority for {{ incoming.campus_name }}
                    </h2>
                    <p class="mt-0.5 text-slate-600">
                        Initiated by current Club Adviser <strong>{{ incoming.from_name }}</strong>
                        <span v-if="incoming.expires_at"> • Expires {{ incoming.expires_at }}</span>
                    </p>
                </div>
            </div>

            <p v-if="incoming.handover_notes" class="mt-3 rounded-xl border border-black/10 bg-white p-3 text-slate-700">
                <strong>Handover Notes:</strong> {{ incoming.handover_notes }}
            </p>

            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="respondHandover('accepted')">
                <div class="min-w-[220px] flex-1">
                    <label class="mb-1 block font-bold text-slate-800">
                        Enter 6-Character Handover Verification Code *
                    </label>
                    <input
                        v-model="verificationCode"
                        required
                        maxlength="12"
                        placeholder="e.g. A7K9P2"
                        class="w-full rounded-xl border border-[#0384C5]/40 bg-white px-3 py-2 font-mono text-sm font-extrabold tracking-widest text-[#0384C5] uppercase"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="busy || !verificationCode.trim()"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-[#1A8F26] px-4 py-2.5 font-bold text-white hover:bg-[#15741f] disabled:opacity-50"
                >
                    <CheckCircle2 class="size-4" />
                    Accept &amp; Assume Club Adviser Role
                </button>
                <button
                    type="button"
                    :disabled="busy"
                    @click="respondHandover('declined')"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-red-300 bg-white px-4 py-2.5 font-bold text-red-700 hover:bg-red-50"
                >
                    <XCircle class="size-4" />
                    Decline
                </button>
            </form>
        </section>

        <!-- 2. OUTGOING PENDING HANDOVER CARD (SHOWN TO CURRENT ADVISER) -->
        <section
            v-if="outgoing && isClubAdviser"
            class="bento p-5 text-xs text-slate-900"
        >
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-black/10 pb-3">
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/20 px-3 py-1 text-[11px] font-bold text-amber-900">
                        <KeyRound class="size-3.5" />
                        Handover Pending Successor Confirmation
                    </span>
                    <h2 class="font-editorial mt-2 text-lg font-bold text-slate-900">
                        Incoming Adviser: {{ outgoing.to_name }}
                    </h2>
                    <p class="mt-0.5 text-slate-600">
                        {{ outgoing.to_email }}
                        <span v-if="outgoing.to_course_year_section"> • {{ outgoing.to_course_year_section }}</span>
                        <span v-if="outgoing.expires_at"> • Valid until {{ outgoing.expires_at }}</span>
                    </p>
                </div>

                <button
                    type="button"
                    :disabled="busy"
                    @click="cancelHandover"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-red-300 bg-white px-3 py-2 font-bold text-red-700 hover:bg-red-50"
                >
                    <XCircle class="size-4" />
                    Cancel Pending Handover
                </button>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[#0384C5]/30 bg-[#FBF6EE] p-4">
                <div>
                    <div class="font-bold text-slate-800">Share this 6-Character Handover Code with your Successor:</div>
                    <p class="mt-0.5 text-[11px] text-slate-600">
                        You remain full Club Adviser until {{ outgoing.to_name }} signs in and confirms this code.
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <code class="rounded-xl border border-[#0384C5]/40 bg-white px-4 py-2 font-mono text-lg font-extrabold tracking-widest text-[#0384C5]">
                        {{ outgoing.confirmation_code }}
                    </code>
                    <button
                        type="button"
                        @click="copyCode"
                        class="inline-flex items-center gap-1 rounded-xl border border-black/15 bg-white px-3 py-2 font-semibold text-slate-700 hover:border-[#0384C5]"
                    >
                        <Copy class="size-3.5" />
                        {{ codeCopied ? 'Copied ✓' : 'Copy' }}
                    </button>
                </div>
            </div>
        </section>

        <!-- 3. INITIATE ADVISER ROLE SHIFT FORM -->
        <section
            v-if="showInitiateForm && isClubAdviser && !outgoing"
            class="bento p-6 text-xs text-slate-900"
        >
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-black/10 pb-4">
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 text-[11px] font-bold text-[#0384C5]">
                        <ShieldCheck class="size-3.5" />
                        Club Adviser Succession &amp; Role Shift Process
                    </span>
                    <h2 class="font-editorial mt-2 text-lg font-bold text-slate-900">
                        Transfer Club Adviser Authority to Incoming Guidance Counselor
                    </h2>
                    <p class="mt-1 text-slate-600">
                        Select a registered campus Guidance Counselor or invite/create a new Counselor account as fallback. Authority transfers atomically only after your successor confirms the 6-character handover code.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        :disabled="availableCounselors.length === 0"
                        @click="handoverMode = 'existing_counselor'"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-50"
                        :class="
                            handoverMode === 'existing_counselor'
                                ? 'bg-[#0384C5] text-white'
                                : 'border border-black/15 bg-white text-slate-700'
                        "
                    >
                        <UserCheck class="size-3.5" />
                        Select Registered Counselor ({{ availableCounselors.length }})
                    </button>
                    <button
                        type="button"
                        @click="handoverMode = 'invite_new'"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-bold transition"
                        :class="
                            handoverMode === 'invite_new'
                                ? 'bg-[#FF8D06] text-white'
                                : 'border border-black/15 bg-white text-slate-700'
                        "
                    >
                        <UserPlus class="size-3.5" />
                        Invite / Create New
                    </button>
                </div>
            </div>

            <form class="mt-4 grid gap-3" @submit.prevent="initiateHandover">
                <div
                    v-if="handoverMode === 'existing_counselor' && availableCounselors.length > 0"
                    class="grid gap-2 rounded-2xl border border-[#0384C5]/25 bg-[#FBF6EE] p-4"
                >
                    <label class="block font-bold text-slate-800">
                        Select Registered Campus Guidance Counselor *
                    </label>
                    <select
                        v-model="selectedMembershipId"
                        required
                        class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                    >
                        <option
                            v-for="counselor in availableCounselors"
                            :key="counselor.membership_id"
                            :value="counselor.membership_id"
                        >
                            {{ counselor.full_name }} ({{ counselor.email }})
                        </option>
                    </select>
                    <p class="text-[11px] text-slate-600">
                        Only verified Guidance Counselors on your campus appear here — student officers and members are excluded.
                    </p>
                </div>

                <div v-else class="grid gap-3 rounded-2xl border border-black/10 bg-[#FBF6EE] p-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">First Name *</label>
                            <input v-model="newFirstName" required placeholder="e.g. Marco" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Middle Initial</label>
                            <input v-model="newMiddleName" placeholder="e.g. L." class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Last Name *</label>
                            <input v-model="newLastName" required placeholder="e.g. Villanueva" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Counselor Email *</label>
                            <input v-model="newEmail" type="email" required placeholder="counselor@chmsu.edu.ph" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Sex *</label>
                            <select v-model="newSex" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2">
                                <option value="" disabled>Select sex...</option>
                                <option value="Female">Female</option>
                                <option value="Male">Male</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Cellphone No.</label>
                            <input v-model="newPhone" type="tel" placeholder="0917-555-0200" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Initial Sign-In Password * (min 8)</label>
                            <input v-model="newInitialPassword" type="password" minlength="8" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Your Current Adviser Password * (Authorize Transfer)</label>
                        <input
                            v-model="currentPassword"
                            type="password"
                            required
                            placeholder="Verify your password..."
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Handover Notes (Optional)</label>
                        <input
                            v-model="handoverNotes"
                            placeholder="e.g. Turnover for AY 2026–2027..."
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2"
                        />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-300/60 bg-amber-50 p-3 text-[11px] text-amber-900">
                    <span class="inline-flex items-center gap-1.5">
                        <ShieldAlert class="size-4 shrink-0 text-amber-700" />
                        Once your successor accepts with the 6-character code, your account transitions to Former Club Adviser with read-only access to Awards, Time Capsules, and Archived GCs, and zero active system control.
                    </span>
                    <button type="submit" :disabled="busy" class="bento-btn">
                        <ArrowRightLeft class="size-3.5" />
                        {{ busy ? 'Initiating...' : 'Initiate Adviser Handover & Generate Code' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
