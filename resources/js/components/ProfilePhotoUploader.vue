<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, CheckCircle2, Trash2, Upload } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { useInitials } from '@/composables/useInitials';
import type { Auth } from '@/types';

const props = withDefaults(
    defineProps<{
        compact?: boolean;
    }>(),
    {
        compact: false,
    },
);

const page = usePage<{ auth: Auth }>();
const user = computed(() => page.props.auth.user);
const canUpload = computed(() => Boolean(page.props.auth.role));
const { getInitials } = useInitials();

const fileInput = ref<HTMLInputElement | null>(null);
const localPreviewUrl = ref<string | null>(null);
const localNotice = ref<string | null>(null);

const form = useForm<{ photo: File | null }>({
    photo: null,
});

const displayedUrl = computed(() => localPreviewUrl.value ?? user.value?.avatar ?? null);

function revokePreview() {
    if (localPreviewUrl.value) {
        URL.revokeObjectURL(localPreviewUrl.value);
        localPreviewUrl.value = null;
    }
}

onBeforeUnmount(() => {
    revokePreview();
});

function pickFile() {
    if (!canUpload.value) return;
    localNotice.value = null;
    fileInput.value?.click();
}

function handleFileChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    revokePreview();
    form.clearErrors('photo');

    if (!file) {
        form.photo = null;
        return;
    }

    form.photo = file;
    localPreviewUrl.value = URL.createObjectURL(file);
}

function submitPhoto() {
    if (!form.photo || form.processing || !canUpload.value) return;

    form.post('/settings/profile/photo', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('photo');
            revokePreview();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
            localNotice.value = 'Profile picture updated and synced to election seats.';
        },
    });
}

function removePhoto() {
    if (!user.value?.avatar || form.processing) return;
    localNotice.value = null;

    router.delete('/settings/profile/photo', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('photo');
            revokePreview();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
            localNotice.value = 'Profile picture removed.';
        },
    });
}
</script>

<template>
    <div
        class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-4 dark:border-[#2B3947] dark:bg-[#141C24]"
        :class="props.compact ? 'p-4' : 'p-5'"
    >
        <div class="flex flex-wrap items-center gap-4">
            <div
                class="relative shrink-0 overflow-hidden rounded-full border-2 border-[#0384C5]/35 bg-white shadow-xs dark:border-[#56B4E3]/40 dark:bg-[#18212A]"
                :class="props.compact ? 'size-16' : 'size-20'"
            >
                <img
                    v-if="displayedUrl"
                    :src="displayedUrl"
                    :alt="user?.name ?? 'Profile Picture'"
                    class="size-full object-cover"
                />
                <div
                    v-else
                    class="flex size-full items-center justify-center bg-[#0384C5]/10 font-bold text-[#0384C5] dark:bg-[#56B4E3]/15 dark:text-[#56B4E3]"
                    :class="props.compact ? 'text-base' : 'text-xl'"
                >
                    {{ getInitials(user?.name ?? 'CPF') }}
                </div>
            </div>

            <div class="min-w-0 flex-1">
                <div class="text-sm font-bold text-slate-900 dark:text-[#EFE9DF]">
                    Single Profile Picture (Visible on Election Seats)
                </div>
                <p class="mt-0.5 text-xs text-slate-600 dark:text-[#C2CDD7]">
                    1 photo per account (JPG, PNG, or WebP, max 2 MB). Uploading a new picture replaces your current one.
                </p>

                <div v-if="canUpload" class="mt-3 flex flex-wrap items-center gap-2">
                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        class="sr-only"
                        @change="handleFileChange"
                    />

                    <button
                        type="button"
                        @click="pickFile"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-black/15 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 transition hover:border-[#0384C5] hover:text-[#0384C5] dark:border-[#2E3E4E] dark:bg-[#18212A] dark:text-[#EFE9DF]"
                    >
                        <Camera class="size-3.5" />
                        Choose Photo
                    </button>

                    <button
                        v-if="form.photo"
                        type="button"
                        :disabled="form.processing"
                        @click="submitPhoto"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-[#0384C5] px-3.5 py-1.5 text-xs font-semibold text-white transition hover:bg-[#026da3] disabled:opacity-60"
                    >
                        <Upload class="size-3.5" />
                        {{ form.processing ? 'Saving...' : 'Save Photo' }}
                    </button>

                    <button
                        v-if="user?.avatar && !form.photo"
                        type="button"
                        :disabled="form.processing"
                        @click="removePhoto"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50 disabled:opacity-60 dark:border-red-400/35 dark:bg-[#18212A] dark:text-red-300"
                    >
                        <Trash2 class="size-3.5" />
                        Remove
                    </button>
                </div>

                <p
                    v-else
                    class="mt-2 text-xs font-medium text-amber-800 dark:text-amber-300"
                >
                    Profile picture upload unlocks once your campus membership application is approved.
                </p>

                <InputError class="mt-2" :message="form.errors.photo" />

                <p
                    v-if="localNotice"
                    class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-[#1A8F26] dark:text-[#4CC37A]"
                >
                    <CheckCircle2 class="size-3.5" />
                    {{ localNotice }}
                </p>
            </div>
        </div>
    </div>
</template>
