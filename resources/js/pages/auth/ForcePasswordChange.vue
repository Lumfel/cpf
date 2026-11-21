<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineOptions({
    layout: {
        title: 'Create Your Personal Password',
        description:
            'Your passkey code is verified. Set your own personal password to unlock your Attendance QR Code.',
    },
});

const props = defineProps<{
    email: string;
    fullName: string;
}>();

const form = useForm({
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/force-password-change', {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
}
</script>

<template>
    <Head title="Create Your Personal Password" />

    <form @submit.prevent="submit" class="grid gap-5">
        <div class="rounded-xl border border-[#0384C5]/30 bg-[#0384C5]/10 p-3 text-xs text-slate-800 dark:border-[#56B4E3]/35 dark:bg-[#141C24] dark:text-[#EFE9DF]">
            Welcome, <strong>{{ props.fullName }}</strong>! Your passkey code has been verified. Create your own personal password below to finish setting up your account and generate your Attendance QR Code.
        </div>

        <div class="grid gap-2">
            <Label for="email">Account Email</Label>
            <Input
                id="email"
                type="email"
                :model-value="props.email"
                readonly
                class="bg-muted/40"
            />
        </div>

        <div class="grid gap-2">
            <Label for="password">New Personal Password</Label>
            <PasswordInput
                id="password"
                v-model="form.password"
                autocomplete="new-password"
                required
                autofocus
                placeholder="Choose a strong personal password (min. 8 characters)"
            />
            <InputError :message="form.errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">Confirm Personal Password</Label>
            <PasswordInput
                id="password_confirmation"
                v-model="form.password_confirmation"
                autocomplete="new-password"
                required
                placeholder="Re-type your new personal password"
            />
            <InputError :message="form.errors.password_confirmation" />
        </div>

        <Button
            type="submit"
            class="mt-2 w-full rounded-xl bg-[#FF8D06] py-2.5 text-sm font-bold text-white shadow-xs transition hover:bg-[#e57e00] dark:bg-[#F4A94A] dark:text-[#0E141A] dark:hover:bg-[#f6b868]"
            :disabled="form.processing"
        >
            <Spinner v-if="form.processing" />
            Save Personal Password &amp; Unlock Attendance QR
        </Button>
    </form>
</template>
