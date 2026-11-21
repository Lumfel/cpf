<script setup lang="ts">
import { Camera, CameraOff, CheckCircle2, LogIn, LogOut, QrCode, ScanLine, ShieldAlert } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import { apiRequest } from '@/lib/csrfFetch';

const props = defineProps<{
    eventId: number;
    eventTitle: string;
    campusMembers: Array<{
        user_id: number;
        full_name_last_first_mi: string;
        course_year_section: string;
        member_id_code: string;
    }>;
}>();

const emit = defineEmits<{
    (e: 'scanned'): void;
}>();

const scanType = ref<'login' | 'logout'>('login');
const manualCode = ref('');
const selectedQuickMember = ref('');
const submitting = ref(false);
const resultNotice = ref<string | null>(null);
const errorNotice = ref<string | null>(null);

// Camera barcode scanner state
const videoRef = ref<HTMLVideoElement | null>(null);
const cameraActive = ref(false);
const cameraWarning = ref<string | null>(null);
let mediaStream: MediaStream | null = null;
let scanIntervalId: ReturnType<typeof setInterval> | null = null;
let lastScannedPayload = '';
let lastScannedAt = 0;

interface DetectedBarcode {
    rawValue?: string;
}

interface BarcodeDetectorCtor {
    new (options: { formats: string[] }): {
        detect(source: HTMLVideoElement): Promise<DetectedBarcode[]>;
    };
}

async function submitScan(payload: string) {
    const trimmed = payload.trim();
    if (!trimmed || submitting.value) return;

    submitting.value = true;
    resultNotice.value = null;
    errorNotice.value = null;

    const { ok, data } = await apiRequest<{
        status?: string;
        member_name?: string;
        course_year_section?: string;
        message?: string;
    }>(`/events-activities/${props.eventId}/scan`, 'POST', {
        qr_payload: trimmed,
        scan_type: scanType.value,
    });

    submitting.value = false;

    if (!ok) {
        errorNotice.value = data.message ?? 'Unable to record attendance scan.';
        return;
    }

    const label = data.member_name ? `${data.member_name} (${data.course_year_section ?? ''})` : 'Member';

    if (data.status === 'logged_in') {
        resultNotice.value = `Log-In recorded at activity start for ${label}.`;
    } else if (data.status === 'already_logged_in') {
        resultNotice.value = `${label} is already logged in for this event.`;
    } else if (data.status === 'logged_out') {
        resultNotice.value = `Log-Out recorded at activity end for ${label}.`;
    } else {
        resultNotice.value = `${label} is already logged out for this event.`;
    }

    manualCode.value = '';
    selectedQuickMember.value = '';
    emit('scanned');
}

function handleQuickMemberPick() {
    if (!selectedQuickMember.value) return;
    manualCode.value = selectedQuickMember.value;
}

async function startCamera() {
    cameraWarning.value = null;
    errorNotice.value = null;

    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
        cameraWarning.value =
            'Camera API unavailable on this connection/browser. Use a USB QR scanner or enter the signed QR / Member ID code below.';
        return;
    }

    const BarcodeDetectorClass = (window as unknown as { BarcodeDetector?: BarcodeDetectorCtor }).BarcodeDetector;

    try {
        mediaStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
        });
        cameraActive.value = true;

        if (videoRef.value) {
            videoRef.value.srcObject = mediaStream;
            await videoRef.value.play();
        }

        if (!BarcodeDetectorClass) {
            cameraWarning.value =
                'Camera preview started, but native BarcodeDetector is unsupported in this browser. Paste or type the Member ID / QR payload below.';
            return;
        }

        const detector = new BarcodeDetectorClass({ formats: ['qr_code'] });

        scanIntervalId = setInterval(async () => {
            if (!videoRef.value || videoRef.value.readyState < 2 || submitting.value) return;
            try {
                const detected = await detector.detect(videoRef.value);
                const raw = detected[0]?.rawValue?.trim();
                if (!raw) return;

                const nowMs = Date.now();
                if (raw === lastScannedPayload && nowMs - lastScannedAt < 2500) {
                    return;
                }

                lastScannedPayload = raw;
                lastScannedAt = nowMs;
                await submitScan(raw);
            } catch {
                // Ignore transient video frame decode errors
            }
        }, 450);
    } catch {
        stopCamera();
        cameraWarning.value =
            'Could not access device camera (permission denied or no camera detected). You can still scan or enter Member IDs below.';
    }
}

function stopCamera() {
    if (scanIntervalId) {
        clearInterval(scanIntervalId);
        scanIntervalId = null;
    }
    if (mediaStream) {
        for (const track of mediaStream.getTracks()) {
            track.stop();
        }
        mediaStream = null;
    }
    if (videoRef.value) {
        videoRef.value.srcObject = null;
    }
    cameraActive.value = false;
}

onBeforeUnmount(() => {
    stopCamera();
});
</script>

<template>
    <section class="bento p-5">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-black/10 pb-3">
            <div>
                <div class="inline-flex items-center gap-1.5 rounded-full bg-[#1A8F26]/12 px-3 py-1 text-[11px] font-bold text-[#1A8F26]">
                    <ScanLine class="size-3.5" />
                    Officer QR Attendance Scanner Active
                </div>
                <h3 class="mt-1.5 text-base font-bold text-slate-900">
                    Scan Member Attendance QR • {{ eventTitle }}
                </h3>
                <p class="text-xs text-slate-600">
                    Switch between <strong>Log-In (Start)</strong> and <strong>Log-Out (End)</strong> to record each member’s timestamps onto the checklist below.
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="scanType = 'login'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold transition"
                    :class="
                        scanType === 'login'
                            ? 'bg-[#1A8F26] text-white shadow-sm'
                            : 'border border-black/15 bg-white text-slate-700'
                    "
                >
                    <LogIn class="size-3.5" />
                    Log-In (Start)
                </button>
                <button
                    type="button"
                    @click="scanType = 'logout'"
                    class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold transition"
                    :class="
                        scanType === 'logout'
                            ? 'bg-[#FF8D06] text-white shadow-sm'
                            : 'border border-black/15 bg-white text-slate-700'
                    "
                >
                    <LogOut class="size-3.5" />
                    Log-Out (End)
                </button>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <!-- Camera QR Stream Panel -->
            <div class="flex flex-col justify-between rounded-2xl border border-black/10 bg-[#FBF6EE] p-4">
                <div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-900">
                            <QrCode class="size-4 text-[#0384C5]" />
                            Live Camera QR Scanner
                        </span>
                        <button
                            v-if="!cameraActive"
                            type="button"
                            @click="startCamera"
                            class="inline-flex items-center gap-1 rounded-xl bg-[#0384C5] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#026da3]"
                        >
                            <Camera class="size-3.5" />
                            Start Camera
                        </button>
                        <button
                            v-else
                            type="button"
                            @click="stopCamera"
                            class="inline-flex items-center gap-1 rounded-xl border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700"
                        >
                            <CameraOff class="size-3.5" />
                            Stop Camera
                        </button>
                    </div>

                    <div class="mt-3 overflow-hidden rounded-xl border border-black/10 bg-slate-950/90">
                        <video
                            v-show="cameraActive"
                            ref="videoRef"
                            playsinline
                            muted
                            class="h-44 w-full object-cover"
                        />
                        <div
                            v-if="!cameraActive"
                            class="flex h-36 flex-col items-center justify-center gap-1 px-4 text-center text-xs text-slate-300"
                        >
                            <Camera class="size-6 text-slate-400" />
                            <span>Point camera at a member’s Dashboard Attendance QR code</span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="cameraWarning"
                    class="mt-2.5 flex items-start gap-1.5 rounded-xl border border-amber-300 bg-amber-50 p-2.5 text-[11px] text-amber-900"
                >
                    <ShieldAlert class="mt-0.5 size-3.5 shrink-0" />
                    <span>{{ cameraWarning }}</span>
                </div>
            </div>

            <!-- USB Scanner / Manual Signed QR or Member ID Entry -->
            <div class="flex flex-col justify-between rounded-2xl border border-black/10 bg-white p-4">
                <form class="space-y-3 text-xs" @submit.prevent="submitScan(manualCode)">
                    <div>
                        <label class="mb-1 block font-bold text-slate-800">
                            Scan / Enter Attendance QR Payload or Member ID Code *
                        </label>
                        <input
                            v-model="manualCode"
                            type="text"
                            required
                            placeholder="CPF|campus:ALIJIS|user:...|sig:... or CPF-ALIJIS-2026-0001"
                            class="w-full rounded-xl border border-black/15 bg-[#FBF6EE] px-3 py-2 font-mono text-xs text-slate-900"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block font-bold text-slate-700">
                            Quick Select Approved Campus Member (Optional)
                        </label>
                        <select
                            v-model="selectedQuickMember"
                            @change="handleQuickMemberPick"
                            class="w-full rounded-xl border border-black/15 bg-white px-3 py-2 text-xs text-slate-800"
                        >
                            <option value="">Choose a member to autofill their ID code...</option>
                            <option
                                v-for="m in campusMembers"
                                :key="`pick-${m.user_id}`"
                                :value="m.member_id_code"
                            >
                                {{ m.full_name_last_first_mi }} ({{ m.course_year_section }}) — {{ m.member_id_code }}
                            </option>
                        </select>
                    </div>

                    <button
                        type="submit"
                        :disabled="submitting || !manualCode.trim()"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-xs font-bold text-white transition disabled:opacity-50"
                        :class="scanType === 'login' ? 'bg-[#1A8F26] hover:bg-[#15741f]' : 'bg-[#FF8D06] hover:bg-[#e57d00]'"
                    >
                        <LogIn v-if="scanType === 'login'" class="size-4" />
                        <LogOut v-else class="size-4" />
                        {{
                            submitting
                                ? 'Recording Scan...'
                                : scanType === 'login'
                                  ? 'Record Member Log-In (Start)'
                                  : 'Record Member Log-Out (End)'
                        }}
                    </button>
                </form>

                <div
                    v-if="resultNotice"
                    class="mt-3 flex items-center gap-1.5 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3 py-2 text-xs font-semibold text-[#1A8F26]"
                >
                    <CheckCircle2 class="size-4 shrink-0" />
                    <span>{{ resultNotice }}</span>
                </div>
                <div
                    v-if="errorNotice"
                    class="mt-3 rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700"
                >
                    {{ errorNotice }}
                </div>
            </div>
        </div>
    </section>
</template>
