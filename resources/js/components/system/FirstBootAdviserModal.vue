<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Eye, ShieldCheck, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { SystemBootstrapState } from '@/types';

const page = usePage<{ systemBootstrap?: SystemBootstrapState }>();
const bootstrap = computed(() => page.props.systemBootstrap);
const needsSetup = computed(() => Boolean(bootstrap.value?.needsAdviserSetup));
const campuses = computed(() => bootstrap.value?.campuses ?? []);
const activeCampus = computed(() => bootstrap.value?.activeCampus ?? campuses.value[0] ?? null);

const collapsed = ref(false);

const form = useForm({
    campus_id: activeCampus.value?.id ?? ('' as number | ''),
    campus_name: activeCampus.value?.name ?? 'CHMSU Alijis Campus',
    academic_year: activeCampus.value?.current_academic_year ?? '2026-2027',
    first_name: '',
    middle_name: '',
    last_name: '',
    email: '',
    sex: 'Female' as 'Male' | 'Female',
    cellphone_number: '',
    password: '',
    password_confirmation: '',
});

watch(activeCampus, (val) => {
    if (val) {
        if (!form.campus_id) form.campus_id = val.id;
        if (!form.campus_name) form.campus_name = val.name;
        if (!form.academic_year && val.current_academic_year) {
            form.academic_year = val.current_academic_year;
        }
    }
});

function handleCampusChange() {
    const picked = campuses.value.find((c) => c.id === Number(form.campus_id));
    if (picked) {
        form.campus_name = picked.name;
        if (picked.current_academic_year) {
            form.academic_year = picked.current_academic_year;
        }
    }
}

function submitFirstBootSetup() {
    form.post('/first-boot/setup-adviser', {
        onError: () => {
            form.reset('password', 'password_confirmation');
        },
    });
}
</script>

<template>
    <div v-if="needsSetup">
        <!-- Floating Reopen Pill if temporarily collapsed -->
        <button
            v-if="collapsed"
            type="button"
            @click="collapsed = false"
            class="fixed right-5 bottom-5 z-50 inline-flex items-center gap-2 rounded-2xl border-2 border-[#FF8D06] bg-[#0384C5] px-4 py-3 text-xs font-extrabold text-white shadow-xl transition hover:bg-[#026ea5]"
        >
            <ShieldCheck class="size-4 text-[#FF8D06]" />
            First-Boot Setup: Enter as Club Adviser
        </button>

        <!-- Automatic First-Boot Adviser Setup Pop-Out Modal -->
        <div
            v-else
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/65 p-4 backdrop-blur-sm"
        >
            <div class="my-auto w-full max-w-2xl rounded-3xl border border-[#0384C5]/30 bg-[#FBF6EE] p-6 text-slate-900 shadow-2xl">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-black/10 pb-4">
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#FF8D06]/15 px-3 py-1 text-[11px] font-bold text-[#FF8D06]">
                            <Sparkles class="size-3.5" />
                            First-Boot System Initialization
                        </span>
                        <h2 class="font-editorial mt-2 text-2xl font-bold text-slate-900">
                            Enter as Club Adviser
                        </h2>
                        <p class="mt-1 text-xs text-slate-600">
                            This blank environment has no Club Adviser yet. Create the Founding Campus Adviser account below to automatically provision the Testing Room, 3 Campus Counselors, Officers GC, and enter your Dashboard immediately.
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="collapsed = true"
                        class="inline-flex items-center gap-1 rounded-xl border border-black/15 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:border-[#0384C5]"
                    >
                        <Eye class="size-3.5" />
                        Preview Page Behind
                    </button>
                </div>

                <form class="mt-4 grid gap-3 text-xs" @submit.prevent="submitFirstBootSetup">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div v-if="campuses.length > 0">
                            <label class="mb-1 block font-bold text-slate-800">Campus Chapter *</label>
                            <select
                                v-model="form.campus_id"
                                @change="handleCampusChange"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            >
                                <option v-for="c in campuses" :key="`boot-c-${c.id}`" :value="c.id">
                                    {{ c.name }} ({{ c.code }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Chapter Display Name</label>
                            <input
                                v-model="form.campus_name"
                                placeholder="CHMSU Alijis Campus"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                            <p v-if="form.errors.campus_name" class="mt-1 text-[11px] font-semibold text-red-600">{{ form.errors.campus_name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Academic Year *</label>
                            <input
                                v-model="form.academic_year"
                                required
                                placeholder="2026-2027"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                            <p v-if="form.errors.academic_year" class="mt-1 text-[11px] font-semibold text-red-600">{{ form.errors.academic_year }}</p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">First Name *</label>
                            <input
                                v-model="form.first_name"
                                required
                                placeholder="e.g. Elena"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                            <p v-if="form.errors.first_name" class="mt-1 text-[11px] font-semibold text-red-600">{{ form.errors.first_name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Middle Initial</label>
                            <input
                                v-model="form.middle_name"
                                placeholder="e.g. V."
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Last Name *</label>
                            <input
                                v-model="form.last_name"
                                required
                                placeholder="e.g. Montemayor"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                            <p v-if="form.errors.last_name" class="mt-1 text-[11px] font-semibold text-red-600">{{ form.errors.last_name }}</p>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block font-bold text-slate-800">Adviser Official Email *</label>
                            <input
                                v-model="form.email"
                                type="email"
                                required
                                placeholder="adviser@cpf.chmsu.edu.ph"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                            <p v-if="form.errors.email" class="mt-1 text-[11px] font-semibold text-red-600">{{ form.errors.email }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Sex *</label>
                            <select v-model="form.sex" required class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900">
                                <option value="Female">Female</option>
                                <option value="Male">Male</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Cellphone No. (Optional)</label>
                            <input
                                v-model="form.cellphone_number"
                                type="tel"
                                placeholder="0917-555-0101"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Password * (min 8 chars)</label>
                            <input
                                v-model="form.password"
                                type="password"
                                minlength="8"
                                required
                                placeholder="Create password"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                            <p v-if="form.errors.password" class="mt-1 text-[11px] font-semibold text-red-600">{{ form.errors.password }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block font-bold text-slate-800">Confirm Password *</label>
                            <input
                                v-model="form.password_confirmation"
                                type="password"
                                minlength="8"
                                required
                                placeholder="Repeat password"
                                class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-slate-900"
                            />
                        </div>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#1A8F26]/25 bg-[#1A8F26]/8 p-3.5 text-[11px] text-slate-700">
                        <span>
                            Once submitted, first-boot setup permanently locks and only the <strong>Club Adviser</strong> (or Adviser Role Handover) manages roles.
                        </span>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 rounded-xl bg-[#0384C5] px-5 py-2.5 text-xs font-extrabold text-white shadow-sm transition hover:bg-[#026da3] disabled:opacity-60"
                        >
                            <ShieldCheck class="size-4" />
                            {{ form.processing ? 'Initializing System...' : 'Initialize System & Enter as Club Adviser' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
