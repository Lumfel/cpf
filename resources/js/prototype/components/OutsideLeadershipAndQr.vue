<script setup lang="ts">
import { HeartHandshake, Monitor, QrCode, ShieldCheck, Smartphone, Users } from '@lucide/vue';
import { ref } from 'vue';

const qrDeviceMode = ref<'web' | 'mobile'>('web');

const adviser = {
    name: 'Dr. Elena V. Montemayor, RGC',
    title: 'Club Adviser (In Charge)',
    note: 'Guides all peer facilitation programs, approves payment destinations, and oversees member & officer governance.',
};

const officers = [
    { name: 'Sofia R. Mercado', role: 'President', course: 'BS Psychology 4A' },
    { name: 'Hannah G. Alvarez', role: 'Secretary', course: 'BSIS 3A' },
    { name: 'Liam C. Delgado', role: 'Treasurer', course: 'BSIT 3B' },
];

const counselors = [
    {
        name: 'Prof. Clarissa D. Soriano, RGC',
        office: 'Student Guidance & Counseling Center (Room 104)',
        specialty: 'Confidential Peer Referrals & Academic Wellness',
    },
    {
        name: 'Prof. Marco L. Villanueva, RGC',
        office: 'Student Guidance & Counseling Center (Room 106)',
        specialty: 'Crisis Support & Multi-Campus Peer Supervision',
    },
];
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- 1. Club Adviser Introduction (Top, above Officers) -->
        <div class="bento rounded-3xl p-6">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-[#0384C5]/10 px-3 py-1 text-xs font-bold text-[#0384C5]">
                <ShieldCheck class="size-3.5" />
                Club Adviser Introduction
            </span>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-slate-900">{{ adviser.name }}</h2>
                    <p class="text-xs font-semibold text-[#0384C5]">{{ adviser.title }}</p>
                    <p class="mt-2 max-w-2xl text-xs leading-relaxed text-slate-700">{{ adviser.note }}</p>
                </div>
                <div class="rounded-2xl border border-black/10 bg-[#FBF6EE] px-4 py-3 text-xs text-slate-700">
                    <strong>Campus Authority:</strong><br />
                    Adviser-Led Governance (No Admin Role)
                </div>
            </div>
        </div>

        <!-- 2. Student Leadership: Meet & Greet Our CPF Officers -->
        <div class="bento rounded-3xl p-6">
            <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                <Users class="size-5 text-[#FF8D06]" />
                Student Leadership: Meet &amp; Greet Our CPF Officers
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div
                    v-for="off in officers"
                    :key="off.name"
                    class="rounded-2xl border border-black/10 bg-white p-4 transition hover:-translate-y-0.5 hover:shadow-sm"
                >
                    <span class="rounded-full bg-[#FF8D06]/15 px-2.5 py-0.5 text-[11px] font-bold text-[#FF8D06]">
                        {{ off.role }}
                    </span>
                    <h3 class="mt-2 text-sm font-bold text-slate-900">{{ off.name }}</h3>
                    <p class="mt-0.5 text-xs text-slate-600">{{ off.course }}</p>
                </div>
            </div>
        </div>

        <!-- 3. Attendance QR ID Page (Web & Mobile Toggle, QR in the Middle) -->
        <div class="bento rounded-3xl p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">
                        Attendance QR ID Card (Web &amp; Mobile Preview)
                    </h2>
                    <p class="text-xs text-slate-600">
                        HMAC-signed campus attendance ID with QR code centered for fast event check-in.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="qrDeviceMode = 'web'"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold"
                        :class="qrDeviceMode === 'web' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700'"
                    >
                        <Monitor class="size-3.5" /> Web View
                    </button>
                    <button
                        type="button"
                        @click="qrDeviceMode = 'mobile'"
                        class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold"
                        :class="qrDeviceMode === 'mobile' ? 'bg-[#0384C5] text-white' : 'border border-black/10 bg-white text-slate-700'"
                    >
                        <Smartphone class="size-3.5" /> Mobile View
                    </button>
                </div>
            </div>

            <div class="mt-5 flex justify-center">
                <div
                    class="flex flex-col items-center rounded-3xl border border-black/15 bg-white p-6 text-center shadow-sm transition-all"
                    :class="qrDeviceMode === 'mobile' ? 'w-full max-w-[300px]' : 'w-full max-w-md'"
                >
                    <span class="rounded-full bg-[#1A8F26]/10 px-3 py-1 text-[11px] font-bold text-[#1A8F26]">
                        CHMSU Talisay • Verified Peer ID
                    </span>
                    <h3 class="mt-2 text-base font-bold text-slate-900">Reyes, Katrina M.</h3>
                    <p class="text-xs text-slate-500">BSIT 4A • ID #CPF-2026-0105</p>

                    <!-- QR Code Centered in the Middle -->
                    <div class="my-5 flex size-40 items-center justify-center rounded-2xl border-2 border-dashed border-[#0384C5]/40 bg-[#FBF6EE] p-4">
                        <QrCode class="size-28 text-slate-900" />
                    </div>

                    <p class="text-[11px] text-slate-500">
                        Present this centered QR code at CPF events or Drop-In Lounge shifts for offline/online scanning.
                    </p>
                </div>
            </div>
        </div>

        <!-- 4. Our Guidance Counselors (At the Bottom) -->
        <div class="bento rounded-3xl p-6">
            <div class="flex items-center gap-2 text-base font-bold text-slate-900">
                <HeartHandshake class="size-5 text-[#1A8F26]" />
                Our Guidance Counselors (Confidential Referral Support)
            </div>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div
                    v-for="c in counselors"
                    :key="c.name"
                    class="rounded-2xl border border-black/10 bg-[#FBF6EE] p-4"
                >
                    <h3 class="text-sm font-bold text-slate-900">{{ c.name }}</h3>
                    <p class="mt-0.5 text-xs font-semibold text-[#1A8F26]">{{ c.office }}</p>
                    <p class="mt-1.5 text-xs text-slate-600">{{ c.specialty }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
