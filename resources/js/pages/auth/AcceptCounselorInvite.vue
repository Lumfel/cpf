<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, KeyRound, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import { apiRequest } from '@/lib/csrfFetch';

const props = defineProps<{
    acceptUrl: string;
    email: string;
    counselorName: string | null;
    campusName: string;
    inviterName: string;
    expiresAt: string;
}>();

const otpCode = ref('');
const firstName = ref('');
const middleName = ref('');
const lastName = ref('');
const sex = ref<'Female' | 'Male' | ''>('');
const cellphoneNumber = ref('');
const specialty = ref('Student Wellness & Peer Referral Triage');
const password = ref('');
const passwordConfirmation = ref('');
const submitting = ref(false);
const errorMessage = ref<string | null>(null);

async function submitAcceptance() {
    if (submitting.value) return;
    submitting.value = true;
    errorMessage.value = null;

    const { ok, data } = await apiRequest<{
        redirect?: string;
        message?: string;
        errors?: Record<string, string[]>;
    }>(props.acceptUrl, 'POST', {
        otp_code: otpCode.value.trim(),
        first_name: firstName.value,
        middle_name: middleName.value || null,
        last_name: lastName.value,
        sex: sex.value,
        cellphone_number: cellphoneNumber.value || null,
        specialty: specialty.value || null,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
    });

    submitting.value = false;

    if (!ok) {
        const firstFieldErr = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        errorMessage.value = firstFieldErr ?? data.message ?? 'Unable to verify counselor invitation.';
        return;
    }

    router.visit(data.redirect ?? '/campus-hub');
}
</script>

<template>
    <Head title="Accept Guidance Counselor Invitation" />

    <div class="flex min-h-screen items-center justify-center bg-[#FBF6EE] p-4 text-slate-900">
        <div class="bento w-full max-w-xl p-6 sm:p-8">
            <div class="flex items-center gap-2 text-xs font-bold text-[#0384C5]">
                <ShieldCheck class="size-4" />
                <span>{{ campusName }} • Guidance Counselor Onboarding</span>
            </div>

            <h1 class="font-editorial mt-2 text-2xl font-bold text-slate-900">
                Complete Guidance Counselor Registration
            </h1>
            <p class="mt-1 text-xs leading-relaxed text-slate-600">
                Invited by <strong>{{ inviterName }}</strong> for <strong>{{ email }}</strong>. This coordination-only account grants access to the Counselors Private GC, Testing Room schedules, and confidential Guidance Referrals.
                <span class="block text-[11px] text-slate-500">Invitation valid until {{ expiresAt }}.</span>
            </p>

            <div
                v-if="errorMessage"
                class="mt-4 rounded-2xl border border-red-300 bg-red-50 px-4 py-3 text-xs font-semibold text-red-700"
            >
                {{ errorMessage }}
            </div>

            <form class="mt-5 grid gap-3.5 text-xs" @submit.prevent="submitAcceptance">
                <div class="rounded-2xl border border-[#0384C5]/30 bg-[#FFF9EF] p-4">
                    <label class="mb-1 flex items-center gap-1.5 font-bold text-slate-800">
                        <KeyRound class="size-3.5 text-[#0384C5]" />
                        6-Digit Verification OTP Code *
                    </label>
                    <input
                        v-model="otpCode"
                        inputmode="numeric"
                        maxlength="6"
                        required
                        placeholder="000000"
                        class="w-full rounded-xl border border-[#0384C5]/40 bg-white px-3 py-2 font-mono text-base font-extrabold tracking-[0.35em] text-[#0384C5]"
                    />
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">First Name *</label>
                        <input v-model="firstName" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Middle Initial</label>
                        <input v-model="middleName" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Last Name *</label>
                        <input v-model="lastName" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Sex *</label>
                        <select v-model="sex" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2">
                            <option value="" disabled>Select...</option>
                            <option value="Female">Female</option>
                            <option value="Male">Male</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Cellphone No.</label>
                        <input v-model="cellphoneNumber" type="tel" placeholder="0917-555-0190" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Counseling Specialty</label>
                        <input v-model="specialty" class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Personal Password * (min 8)</label>
                        <input v-model="password" type="password" minlength="8" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                    </div>
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">Confirm Password *</label>
                        <input v-model="passwordConfirmation" type="password" minlength="8" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2" />
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="submitting"
                    class="bento-btn mt-2 w-full justify-center py-2.5"
                >
                    <CheckCircle2 class="size-4" />
                    {{ submitting ? 'Verifying OTP & Activating Account...' : 'Verify OTP & Activate Guidance Counselor Account' }}
                </button>
            </form>
        </div>
    </div>
</template>
