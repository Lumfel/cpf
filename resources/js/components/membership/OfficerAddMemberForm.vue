<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { UserPlus } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { officerAdd } from '@/routes/membership';
import type { CampusSummary, CpfRoleValue } from '@/types';

const props = defineProps<{
    campuses: CampusSummary[];
    activeCampus: CampusSummary | null;
    availableRoles: Array<{
        value: CpfRoleValue;
        label: string;
        canApproveMembers?: boolean;
        canIssuePasskeys?: boolean;
    }>;
    isClubAdviser: boolean;
    verifiedRoleLabel: string;
}>();

const emit = defineEmits<{
    (e: 'added'): void;
}>();

const facebookUrlPlaceholder = 'https://www.facebook.com/member.username';

const officerForm = useForm({
    campus_id: props.activeCampus?.id ?? props.campuses[0]?.id ?? 1,
    role: 'member' as CpfRoleValue,
    first_name: '',
    middle_name: '',
    last_name: '',
    course: '',
    year_level: '',
    section: '',
    sex: 'Female' as 'Male' | 'Female',
    gender_identity: 'Prefer not to say' as
        | 'Man'
        | 'Woman'
        | 'Non-binary'
        | 'Prefer not to say'
        | 'Others',
    gender_identity_other: '',
    birthdate: '',
    active_email: '',
    facebook_profile_url: '',
    active_fb_account: '',
    cellphone_number: '',
});

function submitOfficerAdd() {
    officerForm.post(officerAdd.url(), {
        preserveScroll: true,
        onSuccess: () => {
            officerForm.reset(
                'first_name',
                'middle_name',
                'last_name',
                'course',
                'year_level',
                'section',
                'birthdate',
                'active_email',
                'facebook_profile_url',
                'active_fb_account',
                'cellphone_number',
            );
            emit('added');
        },
    });
}
</script>

<template>
    <div
        class="mx-auto w-full max-w-3xl rounded-2xl border border-black/10 bg-white p-6 shadow-sm"
    >
        <div class="border-b border-black/10 pb-4">
            <span class="rounded-full bg-[#FF8D06]/15 px-3 py-1 text-xs font-bold text-[#FF8D06]">
                Authorized Officer Action • {{ verifiedRoleLabel }}
            </span>
            <h2 class="mt-2 text-xl font-bold text-slate-900">
                Directly Add &amp; Verify a CPF Member
            </h2>
            <p class="mt-1 text-xs text-slate-600">
                Authorized for <strong>Adviser</strong>, <strong>President</strong>, and <strong>Vice President</strong>.
                Members added here are immediately verified, receive a locked 12-character <strong>Passkey Code</strong> to sign in and create their own personal password, and appear in the A–Z Messenger GC checklist.
            </p>
        </div>

        <form @submit.prevent="submitOfficerAdd" class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2" v-if="isClubAdviser">
                <Label for="officer_role">Assign Role (Club Adviser Exclusive Authority)</Label>
                <select
                    id="officer_role"
                    v-model="officerForm.role"
                    class="rounded-xl border border-black/15 bg-[#FBF6EE] px-3.5 py-2 text-sm text-slate-900"
                >
                    <option v-for="r in availableRoles" :key="r.value" :value="r.value">
                        {{ r.label }}
                    </option>
                </select>
            </div>

            <div class="grid gap-3 sm:col-span-2 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="add_first_name">First Name *</Label>
                    <Input
                        id="add_first_name"
                        v-model="officerForm.first_name"
                        type="text"
                        required
                        placeholder="e.g. Bea"
                        class="bg-[#FBF6EE]"
                    />
                    <InputError :message="officerForm.errors.first_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="add_middle_name">Middle Name / M.I.</Label>
                    <Input
                        id="add_middle_name"
                        v-model="officerForm.middle_name"
                        type="text"
                        placeholder="e.g. T."
                        class="bg-[#FBF6EE]"
                    />
                    <InputError :message="officerForm.errors.middle_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="add_last_name">Last Name *</Label>
                    <Input
                        id="add_last_name"
                        v-model="officerForm.last_name"
                        type="text"
                        required
                        placeholder="e.g. Villanueva"
                        class="bg-[#FBF6EE]"
                    />
                    <InputError :message="officerForm.errors.last_name" />
                </div>
            </div>

            <div class="grid gap-4 sm:col-span-2 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="add_course">Course / Program *</Label>
                    <Input
                        id="add_course"
                        v-model="officerForm.course"
                        type="text"
                        required
                        placeholder="e.g. BSIS"
                        class="bg-[#FBF6EE]"
                    />
                    <InputError :message="officerForm.errors.course" />
                </div>
                <div class="grid gap-2">
                    <Label for="add_year_level">Year Level *</Label>
                    <select
                        id="add_year_level"
                        v-model="officerForm.year_level"
                        required
                        class="h-9 rounded-md border border-black/15 bg-[#FBF6EE] px-3 py-1 text-sm"
                    >
                        <option value="" disabled>Select Year</option>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                        <option value="5">5th Year</option>
                    </select>
                    <InputError :message="officerForm.errors.year_level" />
                </div>
                <div class="grid gap-2">
                    <Label for="add_section">Section *</Label>
                    <Input
                        id="add_section"
                        v-model="officerForm.section"
                        type="text"
                        required
                        placeholder="e.g. A"
                        class="bg-[#FBF6EE]"
                    />
                    <InputError :message="officerForm.errors.section" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="add_birthdate">Birthdate *</Label>
                <Input
                    id="add_birthdate"
                    v-model="officerForm.birthdate"
                    type="date"
                    required
                    class="bg-[#FBF6EE]"
                />
                <InputError :message="officerForm.errors.birthdate" />
            </div>

            <div class="grid gap-2">
                <Label for="add_sex">Sex (for legal/medical records) *</Label>
                <select
                    id="add_sex"
                    v-model="officerForm.sex"
                    class="rounded-xl border border-black/15 bg-[#FBF6EE] px-3 py-2 text-sm"
                >
                    <option value="Female">Female</option>
                    <option value="Male">Male</option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="add_gender">Gender Identity</Label>
                <select
                    id="add_gender"
                    v-model="officerForm.gender_identity"
                    class="rounded-xl border border-black/15 bg-[#FBF6EE] px-3 py-2 text-sm"
                >
                    <option value="Woman">Woman</option>
                    <option value="Man">Man</option>
                    <option value="Non-binary">Non-binary</option>
                    <option value="Prefer not to say">Prefer not to say</option>
                    <option value="Others">Others</option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="add_email">Active Email Address *</Label>
                <Input
                    id="add_email"
                    v-model="officerForm.active_email"
                    type="email"
                    required
                    placeholder="member@cpf.chmsu.edu.ph"
                    class="bg-[#FBF6EE]"
                />
                <InputError :message="officerForm.errors.active_email" />
            </div>

            <div class="grid gap-2">
                <Label for="add_phone">Active Cellphone No. *</Label>
                <Input
                    id="add_phone"
                    v-model="officerForm.cellphone_number"
                    type="text"
                    required
                    placeholder="0917-555-0190"
                    class="bg-[#FBF6EE]"
                />
                <InputError :message="officerForm.errors.cellphone_number" />
            </div>

            <div class="grid gap-2 sm:col-span-2">
                <Label for="add_fb_url">Facebook Profile Link *</Label>
                <Input
                    id="add_fb_url"
                    v-model="officerForm.facebook_profile_url"
                    type="url"
                    required
                    :placeholder="facebookUrlPlaceholder"
                    class="bg-[#FBF6EE]"
                />
                <InputError :message="officerForm.errors.facebook_profile_url" />
            </div>

            <div class="grid gap-2 sm:col-span-2">
                <Label for="add_fb">FB Account Name / Photo Note (Optional)</Label>
                <Input
                    id="add_fb"
                    v-model="officerForm.active_fb_account"
                    type="text"
                    placeholder="e.g. Bea Villanueva (Smiling in white polo shirt)"
                    class="bg-[#FBF6EE]"
                />
                <InputError :message="officerForm.errors.active_fb_account" />
            </div>

            <div class="flex justify-end gap-3 pt-2 sm:col-span-2">
                <button
                    type="submit"
                    :disabled="officerForm.processing"
                    class="inline-flex items-center gap-2 rounded-xl border-[1.5px] border-[#FF8D06] bg-[#FF8D06] px-5 py-2.5 text-sm font-semibold text-white transition-colors duration-200 hover:bg-[#FBF6EE] hover:text-[#FF8D06]"
                >
                    <UserPlus class="size-4" />
                    Verify &amp; Add Member to Campus Roster
                </button>
            </div>
        </form>
    </div>
</template>
