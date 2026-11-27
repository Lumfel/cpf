<script setup lang="ts">
import {
    Camera,
    CameraOff,
    CheckCircle2,
    ClipboardCheck,
    LogIn,
    LogOut,
    QrCode,
    ScanLine,
    Search,
    ShieldAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { apiRequest } from '@/lib/csrfFetch';

interface ChecklistMemberRow {
    user_id: number;
    full_name_last_first_mi: string;
    course_year_section: string;
    member_id_code: string;
    logged_in_at: string | null;
    logged_out_at: string | null;
}

const props = withDefaults(
    defineProps<{
        eventId: number;
        eventTitle: string;
        campusMembers: Array<{
            id?: number;
            user_id: number;
            full_name_last_first_mi: string;
            course_year_section: string;
            member_id_code: string;
        }>;
        checklist?: ChecklistMemberRow[];
    }>(),
    {
        checklist: () => [],
    },
);

const emit = defineEmits<{
    (e: 'scanned'): void;
}>();

const activeMode = ref<'scanner' | 'manual'>('scanner');
const scanType = ref<'login' | 'logout'>('login');
const manualCode = ref('');
const selectedQuickMember = ref('');
const manualSearch = ref('');
const manualSubmittingUserId = ref<number | null>(null);
const submitting = ref(false);
const resultNotice = ref<string | null>(null);
const errorNotice = ref<string | null>(null);

// Camera barcode scanner state
const videoRef = ref<HTMLVideoElement | null>(null);
const cameraActive = ref(false);
const cameraWarning = ref<string | null>(null);
const videoDevices = ref<MediaDeviceInfo[]>([]);
const selectedDeviceId = ref<string>('');
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

const manualChecklistRows = computed(() => {
    const statusByUserId = new Map<number, ChecklistMemberRow>();
    for (const row of props.checklist) {
        statusByUserId.set(row.user_id, row);
    }

    const baseRoster =
        props.campusMembers.length > 0
            ? props.campusMembers.map((member) => {
                  const status = statusByUserId.get(member.user_id);
                  return {
                      user_id: member.user_id,
                      full_name_last_first_mi: member.full_name_last_first_mi,
                      course_year_section: member.course_year_section,
                      member_id_code: member.member_id_code,
                      logged_in_at: status?.logged_in_at ?? null,
                      logged_out_at: status?.logged_out_at ?? null,
                  };
              })
            : props.checklist;

    const query = manualSearch.value.trim().toLowerCase();
    if (!query) return baseRoster;

    return baseRoster.filter(
        (row) =>
            row.full_name_last_first_mi.toLowerCase().includes(query) ||
            row.course_year_section.toLowerCase().includes(query) ||
            row.member_id_code.toLowerCase().includes(query),
    );
});

function formatAttendanceResult(
    data: {
        status?: string;
        member_name?: string;
        course_year_section?: string;
    },
): string {
    const label = data.member_name ? `${data.member_name} (${data.course_year_section ?? ''})` : 'Member';

    if (data.status === 'logged_in') {
        return `Log-In recorded at activity start for ${label}.`;
    }
    if (data.status === 'already_logged_in') {
        return `${label} is already logged in for this event.`;
    }
    if (data.status === 'logged_out') {
        return `Log-Out recorded at activity end for ${label}.`;
    }

    return `${label} is already logged out for this event.`;
}

function switchMode(mode: 'scanner' | 'manual') {
    if (mode === 'manual') {
        stopCamera();
    }
    activeMode.value = mode;
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

    resultNotice.value = formatAttendanceResult(data);
    manualCode.value = '';
    selectedQuickMember.value = '';
    emit('scanned');
}

async function submitManualAttendance(userId: number, type: 'login' | 'logout') {
    if (manualSubmittingUserId.value !== null) return;

    manualSubmittingUserId.value = userId;
    resultNotice.value = null;
    errorNotice.value = null;

    const { ok, data } = await apiRequest<{
        status?: string;
        member_name?: string;
        course_year_section?: string;
        message?: string;
    }>(`/events-activities/${props.eventId}/manual-attendance`, 'POST', {
        user_id: userId,
        scan_type: type,
    });

    manualSubmittingUserId.value = null;

    if (!ok) {
        errorNotice.value = data.message ?? 'Unable to record manual attendance.';
        return;
    }

    resultNotice.value = formatAttendanceResult(data);
    emit('scanned');
}

function handleQuickMemberPick() {
    if (!selectedQuickMember.value) return;
    manualCode.value = selectedQuickMember.value;
}

async function refreshVideoDevices() {
    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.enumerateDevices) {
        return;
    }

    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const inputs = devices.filter((device) => device.kind === 'videoinput');
        videoDevices.value = inputs;

        const activeDeviceId = mediaStream?.getVideoTracks()[0]?.getSettings().deviceId ?? '';
        if (!selectedDeviceId.value && (activeDeviceId || inputs[0]?.deviceId)) {
            selectedDeviceId.value = activeDeviceId || inputs[0].deviceId;
        }
    } catch {
        // Ignore device enumeration permission errors before camera starts
    }
}

async function acquireCameraStream(deviceId?: string): Promise<MediaStream> {
    if (deviceId) {
        try {
            return await navigator.mediaDevices.getUserMedia({
                video: { deviceId: { exact: deviceId } },
            });
        } catch {
            return await navigator.mediaDevices.getUserMedia({ video: true });
        }
    }

    try {
        return await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
        });
    } catch {
        return await navigator.mediaDevices.getUserMedia({ video: true });
    }
}

async function startCamera(deviceIdOverride?: string) {
    cameraWarning.value = null;
    errorNotice.value = null;

    if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia) {
        cameraWarning.value =
            'Camera API unavailable on this connection/browser. Use a USB QR scanner, enter the Member ID code below, or switch to Manual Checklist.';
        return;
    }

    stopCamera();

    const BarcodeDetectorClass = (window as unknown as { BarcodeDetector?: BarcodeDetectorCtor }).BarcodeDetector;

    try {
        mediaStream = await acquireCameraStream(deviceIdOverride ?? (selectedDeviceId.value || undefined));
        cameraActive.value = true;

        if (videoRef.value) {
            videoRef.value.srcObject = mediaStream;
            await videoRef.value.play();
        }

        await refreshVideoDevices();

        if (!BarcodeDetectorClass) {
            cameraWarning.value =
                'Camera preview started, but native BarcodeDetector is unsupported in this browser. Paste or type the Member ID / QR payload below, or switch to Manual Checklist.';
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
            'Could not access device camera (permission denied or no camera detected). You can still enter Member IDs below or use the Manual Checklist tab.';
    }
}

async function handleCameraDeviceChange() {
    if (cameraActive.value) {
        await startCamera(selectedDeviceId.value);
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

onMounted(() => {
    void refreshVideoDevices();
});

onBeforeUnmount(() => {
    stopCamera();
});
</script>

<template>
    <section class="bento p-4">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
            <div>
                <div class="inline-flex items-center gap-1.5 rounded-full bg-[#1A8F26]/12 px-2.5 py-0.5 text-xs font-bold text-[#1A8F26]">
                    <ScanLine class="size-3.5" />
                    Officer Attendance Check-In Active
                </div>
                <h3 class="mt-1 text-base font-bold text-foreground">
                    Member Attendance • {{ eventTitle }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    Scan Member QR codes (mobile or PC/laptop webcam) or switch to <strong class="text-foreground">Manual Checklist</strong> for students without a phone.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg border border-border bg-background p-1">
                    <button
                        type="button"
                        @click="switchMode('scanner')"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-bold transition"
                        :class="
                            activeMode === 'scanner'
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-foreground hover:bg-card'
                        "
                    >
                        <QrCode class="size-3.5" />
                        QR Scanner
                    </button>
                    <button
                        type="button"
                        @click="switchMode('manual')"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-bold transition"
                        :class="
                            activeMode === 'manual'
                                ? 'bg-[#1A8F26] text-white shadow-sm'
                                : 'text-foreground hover:bg-card'
                        "
                    >
                        <ClipboardCheck class="size-3.5" />
                        Manual Checklist
                    </button>
                </div>

                <template v-if="activeMode === 'scanner'">
                    <button
                        type="button"
                        @click="scanType = 'login'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold transition"
                        :class="
                            scanType === 'login'
                                ? 'bg-[#1A8F26] text-white shadow-sm'
                                : 'border border-border bg-card text-foreground'
                        "
                    >
                        <LogIn class="size-3.5" />
                        Log-In (Start)
                    </button>
                    <button
                        type="button"
                        @click="scanType = 'logout'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold transition"
                        :class="
                            scanType === 'logout'
                                ? 'bg-[#FF8D06] text-white shadow-sm'
                                : 'border border-border bg-card text-foreground'
                        "
                    >
                        <LogOut class="size-3.5" />
                        Log-Out (End)
                    </button>
                </template>
            </div>
        </div>

        <div v-if="activeMode === 'scanner'" class="mt-3.5 grid gap-3.5 lg:grid-cols-2">
            <!-- Camera QR Stream Panel -->
            <div class="flex flex-col justify-between rounded-xl border border-border bg-background/80 p-3.5">
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="inline-flex items-center gap-1.5 text-sm font-bold text-foreground">
                            <QrCode class="size-4 text-primary" />
                            Live Camera QR Scanner
                        </span>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="!cameraActive"
                                type="button"
                                @click="startCamera()"
                                class="inline-flex items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-primary-foreground hover:opacity-90"
                            >
                                <Camera class="size-3.5" />
                                Start Camera
                            </button>
                            <button
                                v-else
                                type="button"
                                @click="stopCamera"
                                class="inline-flex items-center gap-1 rounded-lg border border-red-300 bg-card px-3 py-1.5 text-sm font-semibold text-red-700"
                            >
                                <CameraOff class="size-3.5" />
                                Stop Camera
                            </button>
                        </div>
                    </div>

                    <div v-if="videoDevices.length > 1" class="mt-2.5">
                        <label class="mb-1 block text-xs font-bold text-foreground">
                            Camera Source
                        </label>
                        <select
                            v-model="selectedDeviceId"
                            @change="handleCameraDeviceChange"
                            class="w-full rounded-lg border border-border bg-card px-2.5 py-1.5 text-sm text-foreground"
                        >
                            <option
                                v-for="(device, idx) in videoDevices"
                                :key="device.deviceId || `cam-${idx}`"
                                :value="device.deviceId"
                            >
                                {{ device.label || `Camera ${idx + 1}` }}
                            </option>
                        </select>
                    </div>

                    <div class="mt-3 overflow-hidden rounded-xl border border-border bg-slate-950/90">
                        <video
                            v-show="cameraActive"
                            ref="videoRef"
                            playsinline
                            muted
                            class="h-44 w-full object-cover"
                        />
                        <div
                            v-if="!cameraActive"
                            class="flex h-36 flex-col items-center justify-center gap-1 px-4 text-center text-sm text-slate-300"
                        >
                            <Camera class="size-6 text-slate-400" />
                            <span>Point camera or laptop webcam at a member’s Attendance QR code</span>
                        </div>
                    </div>
                </div>

                <div
                    v-if="cameraWarning"
                    class="mt-2.5 flex items-start gap-1.5 rounded-xl border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900"
                >
                    <ShieldAlert class="mt-0.5 size-3.5 shrink-0" />
                    <span>{{ cameraWarning }}</span>
                </div>
            </div>

            <!-- USB Scanner / Manual Signed QR or Member ID Entry -->
            <div class="flex flex-col justify-between rounded-xl border border-border bg-card p-3.5">
                <form class="space-y-3 text-sm" @submit.prevent="submitScan(manualCode)">
                    <div>
                        <label class="mb-1 block font-bold text-foreground">
                            Scan / Enter Attendance QR Payload or Member ID Code *
                        </label>
                        <input
                            v-model="manualCode"
                            type="text"
                            required
                            placeholder="CPF|campus:ALIJIS|user:...|sig:... or CPF-ALIJIS-2026-0001"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm text-foreground"
                        />
                    </div>

                    <div>
                        <label class="mb-1 block font-bold text-foreground">
                            Quick Select Approved Campus Member (Optional)
                        </label>
                        <select
                            v-model="selectedQuickMember"
                            @change="handleQuickMemberPick"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm text-foreground"
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
                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold text-white transition disabled:opacity-50"
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
                    class="mt-3 flex items-center gap-1.5 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3 py-2 text-sm font-semibold text-[#1A8F26]"
                >
                    <CheckCircle2 class="size-4 shrink-0" />
                    <span>{{ resultNotice }}</span>
                </div>
                <div
                    v-if="errorNotice"
                    class="mt-3 rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700"
                >
                    {{ errorNotice }}
                </div>
            </div>
        </div>

        <!-- Compact Manual Attendance Checklist Fallback (No-Phone Students) -->
        <div v-else class="mt-3.5 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="relative min-w-[220px] flex-1">
                    <Search class="pointer-events-none absolute top-2.5 left-3 size-3.5 text-muted-foreground" />
                    <input
                        v-model="manualSearch"
                        type="text"
                        placeholder="Search student name, course/section, or ID..."
                        class="w-full rounded-lg border border-border bg-background py-1.5 pr-3 pl-8 text-sm text-foreground"
                    />
                </div>
                <span class="text-xs font-semibold text-muted-foreground">
                    Showing {{ manualChecklistRows.length }} approved campus member(s)
                </span>
            </div>

            <div
                v-if="resultNotice"
                class="flex items-center gap-1.5 rounded-xl border border-[#1A8F26]/30 bg-[#1A8F26]/10 px-3 py-2 text-sm font-semibold text-[#1A8F26]"
            >
                <CheckCircle2 class="size-4 shrink-0" />
                <span>{{ resultNotice }}</span>
            </div>
            <div
                v-if="errorNotice"
                class="rounded-xl border border-red-300 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700"
            >
                {{ errorNotice }}
            </div>

            <div
                v-if="manualChecklistRows.length === 0"
                class="rounded-xl border border-border bg-background/80 py-6 text-center text-sm text-muted-foreground"
            >
                No approved campus members match your search.
            </div>
            <div v-else class="max-h-96 space-y-2 overflow-y-auto pr-1">
                <div
                    v-for="row in manualChecklistRows"
                    :key="`manual-${row.user_id}`"
                    class="flex flex-wrap items-center justify-between gap-2.5 rounded-xl border bg-card px-3 py-2.5 text-sm"
                    :class="
                        row.logged_in_at && row.logged_out_at
                            ? 'border-[#1A8F26]/35 bg-[#1A8F26]/5'
                            : row.logged_in_at
                              ? 'border-amber-300 bg-amber-50/70'
                              : 'border-border'
                    "
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-foreground">{{ row.full_name_last_first_mi }}</span>
                            <span class="rounded-full bg-primary/10 px-2.5 py-0.5 text-sm font-semibold text-primary">
                                {{ row.course_year_section }}
                            </span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                            <span
                                class="rounded-full px-2 py-0.5 font-semibold"
                                :class="row.logged_in_at ? 'bg-[#1A8F26]/15 text-[#1A8F26]' : 'bg-muted text-muted-foreground'"
                            >
                                {{ row.logged_in_at ? `In: ${row.logged_in_at}` : 'Not Checked In' }}
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 font-semibold"
                                :class="row.logged_out_at ? 'bg-primary/15 text-primary' : 'bg-muted text-muted-foreground'"
                            >
                                {{ row.logged_out_at ? `Out: ${row.logged_out_at}` : 'Out: Pending' }}
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            :disabled="Boolean(row.logged_in_at) || manualSubmittingUserId === row.user_id"
                            @click="submitManualAttendance(row.user_id, 'login')"
                            class="inline-flex items-center gap-1 rounded-lg bg-[#1A8F26] px-3 py-1.5 text-sm font-bold text-white transition hover:bg-[#15741f] disabled:cursor-not-allowed disabled:opacity-45"
                        >
                            <LogIn class="size-3.5" />
                            {{ row.logged_in_at ? 'Checked In ✓' : 'Check-In' }}
                        </button>
                        <button
                            type="button"
                            :disabled="!row.logged_in_at || Boolean(row.logged_out_at) || manualSubmittingUserId === row.user_id"
                            @click="submitManualAttendance(row.user_id, 'logout')"
                            class="inline-flex items-center gap-1 rounded-lg bg-[#FF8D06] px-3 py-1.5 text-sm font-bold text-white transition hover:bg-[#e57d00] disabled:cursor-not-allowed disabled:opacity-45"
                        >
                            <LogOut class="size-3.5" />
                            {{ row.logged_out_at ? 'Checked Out ✓' : 'Check-Out' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
