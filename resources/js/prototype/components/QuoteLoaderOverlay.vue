<script setup lang="ts">
import { Sparkles, X } from '@lucide/vue';
import { onMounted, ref } from 'vue';

const emit = defineEmits<{
    (e: 'done'): void;
}>();

const quotes = [
    {
        text: 'You do not have to carry everything alone today. Even a small step forward is progress.',
        author: 'CPF Peer Facilitation Note',
    },
    {
        text: 'Listening with kindness can turn someone’s hardest week into a moment of relief.',
        author: 'Circle of Peer Facilitators',
    },
    {
        text: 'Take a breath. Your presence in this community matters more than perfection.',
        author: 'CPF Guidance & Wellness',
    },
];

const visible = ref(true);
const activeQuote = quotes[Math.floor(Math.random() * quotes.length)];

function dismiss() {
    visible.value = false;
    emit('done');
}

onMounted(() => {
    setTimeout(() => {
        if (visible.value) {
            dismiss();
        }
    }, 1700);
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-300 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="visible"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/25 p-4 backdrop-blur-md"
        >
            <div class="bento relative w-full max-w-md rounded-3xl p-6 text-center shadow-xl">
                <button
                    type="button"
                    @click="dismiss"
                    aria-label="Skip quote"
                    class="absolute top-3.5 right-3.5 rounded-full p-1 text-slate-500 transition hover:bg-black/5 hover:text-slate-800"
                >
                    <X class="size-4" />
                </button>

                <div class="mx-auto flex size-10 items-center justify-center rounded-2xl bg-[#0384C5]/10 text-[#0384C5]">
                    <Sparkles class="size-5" />
                </div>

                <p class="mt-3 text-xs font-bold tracking-widest text-[#0384C5] uppercase">
                    Loading CPF Workspace
                </p>

                <blockquote class="mt-2 font-serif text-base leading-relaxed text-slate-900 italic">
                    “{{ activeQuote.text }}”
                </blockquote>

                <p class="mt-2 text-xs font-medium text-slate-500">
                    — {{ activeQuote.author }}
                </p>

                <div class="mt-4 h-1 w-full overflow-hidden rounded-full bg-[#F3EBDD]">
                    <div class="h-full w-2/3 animate-pulse rounded-full bg-[#FF8D06]" />
                </div>
            </div>
        </div>
    </Transition>
</template>
