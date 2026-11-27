<script setup lang="ts">
import { Form, Head, useForm } from '@inertiajs/vue3';
import { KeyRound, Mail } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import FirstBootAdviserModal from '@/components/system/FirstBootAdviserModal.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { home } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Welcome back',
        description: 'Sign in to access your peer dashboard, Attendance QR, and chapter tools',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const loginMode = ref<'password' | 'passkey_code'>('password');

const passkeyForm = useForm({
    email: '',
    passkey_code: '',
});

watch(
    () => passkeyForm.passkey_code,
    (val) => {
        const upper = val.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 12);
        if (upper !== val) {
            passkeyForm.passkey_code = upper;
        }
    },
);

function transformPastedCode(pasted?: string): string {
    return (pasted ?? '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 12);
}

function submitPasskeyCode() {
    passkeyForm.post('/login/passkey-code', {
        onError: () => {
            passkeyForm.reset('passkey_code');
        },
    });
}
</script>

<template>
    <Head title="Log in" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600 dark:text-[#45B36B]"
    >
        {{ status }}
    </div>

    <!-- Sign-In Mode Switcher -->
    <div class="grid grid-cols-2 gap-1.5 rounded-xl border border-border bg-secondary/80 p-1 text-xs font-semibold dark:bg-[#10171E]">
        <button
            type="button"
            @click="loginMode = 'password'"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 transition"
            :class="
                loginMode === 'password'
                    ? 'bg-card text-foreground shadow-xs dark:bg-[#22303E]'
                    : 'text-muted-foreground hover:text-foreground'
            "
        >
            <Mail class="size-3.5" />
            Email &amp; Password
        </button>
        <button
            type="button"
            @click="loginMode = 'passkey_code'"
            class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 transition"
            :class="
                loginMode === 'passkey_code'
                    ? 'bg-card text-foreground shadow-xs dark:bg-[#22303E]'
                    : 'text-muted-foreground hover:text-foreground'
            "
        >
            <KeyRound class="size-3.5" />
            Passkey Code
        </button>
    </div>

    <!-- MODE 1: Standard Email + Password + Device Passkey -->
    <template v-if="loginMode === 'password'">
        <PasskeyVerify />

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-5">
                <div class="grid gap-2">
                    <Label for="email">Email address</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        v-focus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            Forgot your password?
                        </TextLink>
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Remember me</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-2 w-full rounded-xl bg-[#FF8D06] py-2.5 text-sm font-bold text-white shadow-xs transition hover:bg-[#e57e00] dark:bg-[#F2A341] dark:text-[#0F1419] dark:hover:bg-[#f5b35d]"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Sign in to CPF Portal
                </Button>
            </div>

            <div class="text-center text-xs text-muted-foreground">
                New to Circle of Peer Facilitators?
                <TextLink :href="home()" :tabindex="5" class="font-semibold text-[#0384C5] dark:text-[#4AA8D8]">
                    Apply for membership
                </TextLink>
            </div>
        </Form>
    </template>

    <!-- MODE 2: Boxed 12-Character Passkey Code Login (XXXX-XXXX-XXXX) -->
    <form v-else @submit.prevent="submitPasskeyCode" class="flex flex-col gap-5">
        <div class="grid gap-2">
            <Label for="passkey_email">Account Email</Label>
            <Input
                id="passkey_email"
                v-model="passkeyForm.email"
                type="email"
                required
                autocomplete="email"
                placeholder="your.email@chmsu.edu.ph"
            />
            <InputError :message="passkeyForm.errors.email" />
        </div>

        <div class="grid gap-2">
            <Label for="passkey_code_otp">12-Character Passkey Code</Label>
            <p class="text-xs text-muted-foreground">
                First time signing in with an officer-issued passkey code? Enter it below (e.g. <span class="font-mono font-semibold">TQ2Z-R5WZ-VIDD</span>) — you&apos;ll create your own personal password right after.
            </p>
            <div class="flex justify-center pt-1">
                <InputOTP
                    id="passkey_code_otp"
                    v-model="passkeyForm.passkey_code"
                    :maxlength="12"
                    pattern="^[A-Za-z0-9]+$"
                    :paste-transformer="transformPastedCode"
                    :disabled="passkeyForm.processing"
                    class="flex-wrap justify-center gap-1.5"
                >
                    <InputOTPGroup>
                        <InputOTPSlot
                            v-for="idx in 4"
                            :key="`g1-${idx}`"
                            :index="idx - 1"
                            class="h-10 w-7 border-[#0384C5]/35 bg-white font-mono text-xs font-bold text-slate-900 uppercase sm:w-8 sm:text-sm dark:border-[#56B4E3]/45 dark:bg-[#10171E] dark:text-[#EFE9DF]"
                        />
                    </InputOTPGroup>
                    <span class="font-mono text-sm font-bold text-muted-foreground">-</span>
                    <InputOTPGroup>
                        <InputOTPSlot
                            v-for="idx in 4"
                            :key="`g2-${idx}`"
                            :index="idx + 3"
                            class="h-10 w-7 border-[#0384C5]/35 bg-white font-mono text-xs font-bold text-slate-900 uppercase sm:w-8 sm:text-sm dark:border-[#56B4E3]/45 dark:bg-[#10171E] dark:text-[#EFE9DF]"
                        />
                    </InputOTPGroup>
                    <span class="font-mono text-sm font-bold text-muted-foreground">-</span>
                    <InputOTPGroup>
                        <InputOTPSlot
                            v-for="idx in 4"
                            :key="`g3-${idx}`"
                            :index="idx + 7"
                            class="h-10 w-7 border-[#0384C5]/35 bg-white font-mono text-xs font-bold text-slate-900 uppercase sm:w-8 sm:text-sm dark:border-[#56B4E3]/45 dark:bg-[#10171E] dark:text-[#EFE9DF]"
                        />
                    </InputOTPGroup>
                </InputOTP>
            </div>
            <InputError :message="passkeyForm.errors.passkey_code" />
        </div>

        <Button
            type="submit"
            class="mt-1 w-full rounded-xl bg-[#0384C5] py-2.5 text-sm font-bold text-white shadow-xs transition hover:bg-[#026ea5] dark:bg-[#4AA8D8] dark:text-[#0F1419] dark:hover:bg-[#64b7e2]"
            :disabled="passkeyForm.processing || passkeyForm.passkey_code.length < 12"
        >
            <Spinner v-if="passkeyForm.processing" />
            Sign in with Passkey Code
        </Button>
    </form>

    <FirstBootAdviserModal />
</template>
