<template>
    <div class="space-y-6">
        <div v-if="!eventId" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            Select an event from the event selector above before starting QR check-in.
        </div>

        <Transition name="toast">
            <div v-if="scannerMessage" class="rounded-2xl border px-4 py-3.5" :class="scannerMessageClass">
                <div class="flex items-start gap-3">
                    <IconBase :name="scannerResultType === 'success'
                        ? 'check-circle'
                        : scannerResultType === 'warning'
                            ? 'alert-circle'
                            : 'x-circle'
                        " class="mt-0.5 h-5 w-5 shrink-0" />

                    <div>
                        <p class="text-sm font-bold">
                            {{ scannerMessage }}
                        </p>

                        <p v-if="lastScannedTicket" class="mt-1 text-xs opacity-80">
                            {{ lastScannedTicket.attendee_name }} ·
                            {{
                                lastScannedTicket.ticket_type?.name ||
                                'General Admission'
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </Transition>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
            <!-- Scanner -->
            <section class="qr-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8 xl:col-span-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-extrabold tracking-tight text-gray-950">
                            QR Code Check-in Scanner
                        </h2>

                        <p class="mt-1 text-sm text-gray-500">
                            <span v-if="selectedEvent">
                                Scan attendee QR tickets for
                                <span class="font-semibold text-gray-700">{{ selectedEvent.name }}</span>
                            </span>

                            <span v-else>Scan attendee QR tickets for entry</span>
                        </p>
                    </div>

                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold transition-colors"
                        :class="isScanning ? 'bg-primary-50 text-primary-700' : 'bg-gray-100 text-gray-500'">
                        <span class="relative flex h-2 w-2">
                            <span v-if="isScanning" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-700 opacity-60" />
                            <span class="relative inline-flex h-2 w-2 rounded-full" :class="isScanning ? 'bg-primary-700' : 'bg-gray-400'" />
                        </span>
                        {{ isScanning ? 'Camera live' : 'Camera off' }}
                    </span>
                </div>

                <div class="mt-7 flex flex-col items-center">
                    <div class="qr-frame relative aspect-square w-full max-w-sm overflow-hidden rounded-3xl"
                        :class="isScanning ? 'bg-gray-950' : 'bg-gray-50 ring-1 ring-gray-200'">
                        <video ref="scannerVideo" class="h-full w-full object-cover" autoplay muted playsinline />

                        <div v-if="!isScanning"
                            class="absolute inset-0 flex flex-col items-center justify-center px-8 text-center">
                            <div class="qr-idle flex h-20 w-20 items-center justify-center rounded-2xl bg-white text-primary-700 shadow-sm ring-1 ring-gray-200">
                                <IconBase name="qr-code" class="h-10 w-10" />
                            </div>

                            <div class="mt-5 text-base font-extrabold text-gray-950">
                                QR Scanner Ready
                            </div>

                            <p class="mt-1 text-sm leading-6 text-gray-500">
                                Start the camera and position the attendee QR code within the frame.
                            </p>
                        </div>

                        <div class="pointer-events-none absolute inset-6">
                            <span class="qr-corner left-0 top-0 rounded-tl-xl border-l-4 border-t-4" :class="isScanning ? 'border-white' : 'border-primary-700/40'" />
                            <span class="qr-corner right-0 top-0 rounded-tr-xl border-r-4 border-t-4" :class="isScanning ? 'border-white' : 'border-primary-700/40'" />
                            <span class="qr-corner bottom-0 left-0 rounded-bl-xl border-b-4 border-l-4" :class="isScanning ? 'border-white' : 'border-primary-700/40'" />
                            <span class="qr-corner bottom-0 right-0 rounded-br-xl border-b-4 border-r-4" :class="isScanning ? 'border-white' : 'border-primary-700/40'" />

                            <span v-if="isScanning" class="qr-scanline absolute inset-x-3 h-0.5 rounded-full bg-white" />
                        </div>

                        <div v-if="isProcessingScan" class="absolute inset-0 flex items-center justify-center bg-gray-950/55 backdrop-blur-[2px]">
                            <div class="rounded-xl bg-white px-4 py-3 text-sm font-semibold text-gray-700 shadow-lg">
                                <div class="flex items-center gap-2">
                                    <IconBase name="refresh-cw" class="h-4 w-4 animate-spin" />

                                    Checking ticket...
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-7 flex w-full max-w-sm gap-3">
                        <button type="button"
                            class="flex flex-1 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-5 py-3 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md active:translate-y-0 active:scale-[0.98] disabled:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!eventId ||
                                isProcessingScan
                                " @click="
                                    isScanning
                                        ? stopScanner()
                                        : startScanner()
                                    ">
                            <IconBase :name="isScanning
                                ? 'x'
                                : 'qr-code'
                                " class="h-4 w-4" />

                            {{
                                isScanning
                                    ? 'Stop Scanning'
                                    : 'Start Scanning'
                            }}
                        </button>

                        <button type="button"
                            class="rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm font-bold text-gray-700 transition duration-200 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!eventId" @click="openManualEntry">
                            Manual Entry
                        </button>
                    </div>
                </div>
            </section>

            <!-- Progress + recent -->
            <div class="space-y-6 xl:col-span-2">
                <section class="qr-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 80ms">
                    <div class="flex items-end justify-between">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">QR check-in progress</p>
                            <p class="mt-2 text-4xl font-extrabold tracking-tight text-gray-950">
                                {{ checkInSummary.checked_in }}<span class="text-xl font-bold text-gray-400"> / {{ checkInSummary.registered }}</span>
                            </p>
                        </div>
                        <span class="rounded-full bg-primary-50 px-3 py-1 text-xs font-bold text-primary-700">{{ qrProgress }}%</span>
                    </div>

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100">
                        <div class="h-full rounded-full bg-[#285F6b] transition-all duration-700 ease-out" :style="{ width: qrProgress + '%' }" />
                    </div>

                    <div class="mt-5 grid grid-cols-3 gap-3 text-center">
                        <div class="rounded-2xl bg-gray-50 px-2 py-3">
                            <p class="text-xl font-extrabold text-gray-950">{{ checkInSummary.registered }}</p>
                            <p class="mt-0.5 text-[11px] font-semibold text-gray-500">Registered</p>
                        </div>
                        <div class="rounded-2xl bg-gray-50 px-2 py-3">
                            <p class="text-xl font-extrabold text-primary-700">{{ checkInSummary.checked_in }}</p>
                            <p class="mt-0.5 text-[11px] font-semibold text-gray-500">Checked in</p>
                        </div>
                        <div class="rounded-2xl bg-gray-50 px-2 py-3">
                            <p class="text-xl font-extrabold text-gray-950">{{ remainingToCheckIn }}</p>
                            <p class="mt-0.5 text-[11px] font-semibold text-gray-500">Remaining</p>
                        </div>
                    </div>
                </section>

                <section class="qr-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 160ms">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-extrabold text-gray-950">
                                Recent QR Check-ins
                            </h3>

                            <p class="mt-0.5 text-xs text-gray-500">
                                Attendees checked in using the QR scanner
                            </p>
                        </div>

                        <button v-if="eventId" type="button"
                            class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-bold text-primary-700 transition hover:bg-primary-50"
                            @click="loadCheckInSummary()">
                            <IconBase name="refresh-cw" class="h-3.5 w-3.5" :class="{ 'animate-spin': isLoadingSummary }" />
                            Refresh
                        </button>
                    </div>

                    <div v-if="isLoadingSummary && !checkInSummary.recent_check_ins.length" class="py-8 text-center text-sm text-gray-500">
                        Loading QR check-ins...
                    </div>

                    <div v-else-if="
                        checkInSummary.recent_check_ins.length
                    " class="max-h-[420px] space-y-1 overflow-y-auto pr-1">
                        <div v-for="(entry, index) in checkInSummary.recent_check_ins" :key="entry.id"
                            class="qr-row flex items-center justify-between gap-3 rounded-2xl px-3 py-3 transition-colors hover:bg-gray-50"
                            :style="{ '--d': index * 45 + 'ms' }">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-700">
                                    {{ getInitials(entry.ticket.attendee_name) }}
                                </div>

                                <div class="min-w-0">
                                    <div class="break-words text-sm font-bold text-gray-900">
                                        {{
                                            entry.ticket.attendee_name
                                        }}
                                    </div>

                                    <div class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                                        <span>
                                            {{
                                                entry.ticket.ticket_type
                                                    ?.name ||
                                                'General Admission'
                                            }}
                                        </span>

                                        <span v-if="entry.ticket.source"
                                            class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-600">
                                            {{
                                                formatTicketSource(
                                                    entry.ticket.source,
                                                )
                                            }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-col items-end">
                                <span class="text-sm font-semibold text-gray-700">
                                    {{
                                        formatCheckInTime(
                                            entry.checked_in_at,
                                        )
                                    }}
                                </span>

                                <span class="mt-0.5 inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wide text-primary-700">
                                    <IconBase name="check-circle" class="h-3 w-3" />
                                    QR Scan
                                </span>
                            </div>
                        </div>
                    </div>

                    <div v-else class="rounded-2xl border border-dashed border-gray-300 px-5 py-10 text-center">
                        <IconBase name="qr-code" class="mx-auto h-7 w-7 text-gray-400" />

                        <p class="mt-2 text-sm font-semibold text-gray-700">
                            No QR check-ins yet
                        </p>

                        <p class="mt-1 text-xs text-gray-500">
                            Successful QR scans for this event will appear here.
                        </p>
                    </div>
                </section>
            </div>
        </div>

        <Transition name="fade">
            <div v-if="showManualEntry" class="fixed inset-0 z-40 bg-gray-900/40 backdrop-blur-[2px]" @click="closeManualEntry" />
        </Transition>

        <Transition name="slide">
            <div v-if="showManualEntry"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-2xl">
                <div class="flex items-start justify-between border-b border-gray-200 px-6 py-5">
                    <div>
                        <h2 class="text-lg font-extrabold text-gray-950">
                            Manual Check-in
                        </h2>

                        <p class="mt-1 text-sm text-gray-500">
                            <span v-if="selectedEvent">
                                Check in an attendee for
                                {{ selectedEvent.name }} manually.
                            </span>

                            <span v-else>
                                Find the guest by name, e-mail or ticket ID (for a phone that won't scan).
                            </span>
                        </p>
                    </div>

                    <button type="button" class="tap-target rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600" @click="closeManualEntry">
                        <IconBase name="x" class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5">
                    <div v-if="manualEntryError"
                        class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ manualEntryError }}
                    </div>

                    <FormsLabel text="Name, e-mail or ticket ID" required />

                    <FormsTextField v-model="manualTicketId" placeholder="e.g. Bea Santos, bea@example.com or 3B8D41D8A6" />

                    <p v-if="manualSearching" class="mt-3 text-xs text-gray-400">Searching…</p>
                    <p v-else-if="manualTicketId.trim().length >= 2 && !manualMatches.length" class="mt-3 text-xs text-gray-500">No ticket on this event matches “{{ manualTicketId.trim() }}”.</p>
                    <ul v-else-if="manualMatches.length" class="mt-3 divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200">
                        <li v-for="g in manualMatches" :key="g.ticket_id">
                            <button type="button" class="flex min-h-12 w-full items-center justify-between gap-3 px-3 py-2.5 text-left transition hover:bg-gray-50 disabled:opacity-50"
                                :disabled="isManualCheckingIn || g.status === 'cancelled'" @click="checkInMatch(g)">
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-bold text-gray-900">{{ g.name }}</span>
                                    <span class="block truncate text-xs text-gray-500">{{ g.tier }} · {{ shortTicketId(g.ticket_id) }}</span>
                                </span>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold"
                                    :class="g.status === 'cancelled' ? 'bg-red-50 text-red-600' : g.last_checked_in_at ? 'bg-gray-100 text-gray-600' : 'bg-[#285F6b] text-white'">
                                    {{ g.status === 'cancelled' ? 'Cancelled' : g.last_checked_in_at ? 'Already in' : 'Check in' }}
                                </span>
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="border-t border-gray-200 px-6 py-4">
                    <div class="flex justify-end gap-3">
                        <button type="button"
                            class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50"
                            :disabled="isManualCheckingIn" @click="closeManualEntry">
                            Cancel
                        </button>

                        <button type="button"
                            class="flex items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-bold text-white transition hover:bg-[#1f4a54] disabled:opacity-50"
                            :disabled="isManualCheckingIn ||
                                !manualTicketId
                                " @click="submitManualCheckIn">
                            <IconBase v-if="isManualCheckingIn" name="refresh-cw" class="h-4 w-4 animate-spin" />

                            {{
                                isManualCheckingIn
                                    ? 'Checking In...'
                                    : 'Check In'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<script setup lang="ts">
import {
    BrowserQRCodeReader,
    type IScannerControls,
} from '@zxing/browser'

interface OrganizerEvent {
    id: number
    name: string
    event_type: string
    event_date: string | null
    location: string | null
    status: string
}

interface TicketType {
    id: number
    name: string
}

interface ScannedTicket {
    id: number
    event_id?: number
    attendee_name: string
    attendee_email: string | null
    status?: string
    source?: 'online' | 'walk_in' | string
    payment_status?: 'pending' | 'paid' | 'refunded' | string
    checked_in_at: string | null
    ticket_type?: TicketType | null
}

interface QRCheckInEntry {
    id: number
    checked_in_at: string
    ticket: ScannedTicket
}

interface ScanResponse {
    message: string
    code?: string
    data: ScannedTicket
}

interface CheckInSummary {
    registered: number
    checked_in: number
    recent_check_ins: QRCheckInEntry[]
}

interface CheckInSummaryResponse {
    data: CheckInSummary
}

interface AttendeeSummary {
    total_registered: number
    checked_in: number
    walk_ins: number
    capacity: number
    capacity_percent: number
}

interface AttendeeSummaryResponse {
    data: AttendeeSummary
}

interface ApiErrorResponse {
    status?: number

    data?: {
        message?: string
        code?: string
        data?: ScannedTicket
        errors?: Record<string, string[]>
    }
}

definePageMeta({
    layout: 'dashboard',
    middleware: ['organizer'],
})

const config =
    useRuntimeConfig()

const {
    token,
} = useAuth()

const organizerEvents =
    useState<OrganizerEvent[]>(
        'ticketingOrganizerEvents',
        () => [],
    )

const selectedEventId =
    useState<number | null>(
        'ticketingSelectedEventId',
        () => null,
    )

const ticketingSummary =
    useState<AttendeeSummary>(
        'ticketingAttendeeSummary',
        () => ({
            total_registered: 0,
            checked_in: 0,
            walk_ins: 0,
            capacity: 0,
            capacity_percent: 0,
        }),
    )

const selectedEvent =
    computed<OrganizerEvent | null>(
        () => {
            if (!selectedEventId.value) {
                return null
            }

            return (
                organizerEvents.value.find(
                    event =>
                        event.id ===
                        selectedEventId.value,
                ) ?? null
            )
        },
    )

const eventId =
    computed(() => selectedEventId.value as unknown as string | null)

const scannerControls =
    shallowRef<IScannerControls | null>(
        null,
    )

const scannerVideo =
    ref<HTMLVideoElement | null>(
        null,
    )

const isScanning =
    ref(false)

const isProcessingScan =
    ref(false)

const scannerMessage =
    ref('')

const scannerResultType =
    ref<
        | 'success'
        | 'warning'
        | 'error'
        | null
    >(null)

const lastScannedTicket =
    ref<ScannedTicket | null>(
        null,
    )

const lastScannedValue =
    ref('')

const lastScannedAt =
    ref(0)

const isLoadingSummary =
    ref(false)

const checkInSummary =
    ref<CheckInSummary>({
        registered: 0,
        checked_in: 0,
        recent_check_ins: [],
    })

const qrProgress =
    computed(() =>
        checkInSummary.value.registered > 0
            ? Math.min(
                Math.round(
                    (checkInSummary.value.checked_in /
                        checkInSummary.value.registered) *
                    100,
                ),
                100,
            )
            : 0,
    )

const remainingToCheckIn =
    computed(() =>
        Math.max(
            checkInSummary.value.registered -
            checkInSummary.value.checked_in,
            0,
        ),
    )

function getInitials(name: string): string {
    return (
        name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map(part => part[0]?.toUpperCase())
            .join('') || 'A'
    )
}

const showManualEntry =
    ref(false)

const manualTicketId =
    ref('')

const manualEntryError =
    ref('')

const isManualCheckingIn =
    ref(false)

const scannerMessageClass =
    computed(() => {
        if (
            scannerResultType.value ===
            'success'
        ) {
            return 'border-green-200 bg-green-50 text-green-700'
        }

        if (
            scannerResultType.value ===
            'warning'
        ) {
            return 'border-amber-200 bg-amber-50 text-amber-700'
        }

        return 'border-red-200 bg-red-50 text-red-700'
    })

function formatTicketSource(
    source?: string,
): string {
    if (source === 'walk_in') {
        return 'Walk-in'
    }

    if (source === 'online') {
        return 'Online'
    }

    return 'Ticket'
}

function getApiErrorMessage(
    error: unknown,
    fallback: string,
): string {
    const apiError =
        error as ApiErrorResponse

    const validationMessage =
        Object.values(
            apiError.data?.errors ?? {},
        )[0]?.[0]

    return (
        validationMessage ??
        apiError.data?.message ??
        fallback
    )
}

const checkInApi = useCheckIn()
const orgIdOf = async () => (await useOrganization().fetchOrganization())?.id ?? ''
const eventKey = () => String(eventId.value)

/** A check-in result as this page's ScannedTicket. */
function scannedFrom(outcome: import('~/composables/useCheckIn').ScanOutcome): ScannedTicket {
    return {
        id: 0,
        attendee_name: outcome.guest?.name ?? 'Guest',
        attendee_email: null,
        status: outcome.result,
        checked_in_at: outcome.checked_in_at ?? null,
        ticket_type: outcome.guest ? { id: 0, name: outcome.guest.zone ? `${outcome.guest.tier} · ${outcome.guest.zone}` : outcome.guest.tier } : null,
    }
}

/** Show a scan / manual result: green = in, amber = look closer, red = stop. */
async function showOutcome(outcome: import('~/composables/useCheckIn').ScanOutcome) {
    const tone = scanTone[outcome.result]
    lastScannedTicket.value = outcome.guest ? scannedFrom(outcome) : null
    scannerResultType.value = tone === 'ok' ? 'success' : tone === 'warn' ? 'warning' : 'error'
    scannerMessage.value = tone === 'ok' ? `${outcome.guest?.name ?? 'Guest'} checked in.` : `${scanTitle[outcome.result]}: ${outcome.message}`
    if (tone === 'ok') await Promise.all([loadCheckInSummary(), refreshTopSummary()])
}

async function refreshTopSummary() {
    if (!eventId.value) return
    try {
        const data = await checkInApi.summary(await orgIdOf(), eventKey())
        const capacity = ticketingSummary.value.capacity || data.expected
        ticketingSummary.value = {
            total_registered: data.expected,
            checked_in: data.checked_in,
            walk_ins: data.walk_ins + (data.door_sales ?? 0),
            capacity,
            capacity_percent: capacity ? Math.round((data.arrived / capacity) * 100) : 0,
        }
    } catch {
        // The top numbers are a nicety.
    }
}

async function startScanner() {
    if (!eventId.value) {
        scannerResultType.value =
            'error'

        scannerMessage.value =
            'Please select an event before scanning.'

        return
    }

    scannerMessage.value =
        ''

    lastScannedTicket.value =
        null

    if (
        !window.isSecureContext ||
        !navigator.mediaDevices?.getUserMedia
    ) {
        scannerResultType.value =
            'error'

        scannerMessage.value =
            'Camera scanning requires a secure browser connection. Open this page on HTTPS or localhost and try again.'

        return
    }

    try {
        stopScanner()

        await nextTick()

        const video =
            scannerVideo.value

        if (!video) {
            throw new Error(
                'Scanner video is unavailable.',
            )
        }

        const codeReader =
            new BrowserQRCodeReader()

        const onResult =
            async (result: { getText: () => string } | undefined) => {
                if (
                    !result ||
                    isProcessingScan.value
                ) {
                    return
                }

                const text =
                    result.getText()

                const now =
                    Date.now()

                if (
                    text ===
                    lastScannedValue.value &&
                    now -
                    lastScannedAt.value <
                    2000
                ) {
                    return
                }

                lastScannedValue.value =
                    text

                lastScannedAt.value =
                    now

                await handleScannedCode(
                    text,
                )
            }

        let stream: MediaStream

        try {
            stream =
                await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: {
                        facingMode: {
                            ideal:
                                'environment',
                        },
                        width: {
                            ideal: 1280,
                        },
                        height: {
                            ideal: 720,
                        },
                    },
                })
        } catch (error: unknown) {
            const errorName =
                error instanceof DOMException
                    ? error.name
                    : ''

            if (
                errorName !== 'OverconstrainedError' &&
                errorName !== 'ConstraintNotSatisfiedError'
            ) {
                throw error
            }

            stream =
                await navigator.mediaDevices.getUserMedia({
                    audio: false,
                    video: true,
                })
        }

        isScanning.value =
            true

        try {
            scannerControls.value =
                await codeReader.decodeFromStream(
                    stream,
                    video,
                    onResult,
                )
        } catch (error: unknown) {
            stream
                .getTracks()
                .forEach(track => track.stop())

            throw error
        }

    } catch (error: unknown) {
        console.error(
            'Unable to start QR scanner:',
            error,
        )

        stopScanner()

        scannerResultType.value =
            'error'

        scannerMessage.value =
            getCameraErrorMessage(error)
    }
}

function getCameraErrorMessage(
    error: unknown,
): string {
    const errorName =
        error instanceof DOMException
            ? error.name
            : ''

    if (
        errorName === 'NotAllowedError' ||
        errorName === 'PermissionDeniedError' ||
        errorName === 'SecurityError'
    ) {
        return 'Brave has not granted this site camera access. Click the camera icon beside the address bar, set Camera to Allow for localhost, reload the page, then click Start Scanning.'
    }

    if (
        errorName === 'NotFoundError' ||
        errorName === 'DevicesNotFoundError'
    ) {
        return 'No camera was found on this device. Connect a camera or use Manual Entry.'
    }

    if (
        errorName === 'NotReadableError' ||
        errorName === 'TrackStartError' ||
        errorName === 'AbortError'
    ) {
        return 'The camera is busy or unavailable. Close other apps using it, then try again.'
    }

    return 'The camera could not be started. Check browser and system camera permissions, then try again.'
}

function stopScanner() {
    scannerControls.value
        ?.stop()

    scannerControls.value =
        null

    const stream =
        scannerVideo.value
            ?.srcObject as MediaStream | null

    stream
        ?.getTracks()
        .forEach(track => track.stop())

    if (scannerVideo.value) {
        scannerVideo.value.srcObject =
            null
    }

    isScanning.value =
        false
}

async function handleScannedCode(
    qrValue: string,
) {
    if (!eventId.value) return
    isProcessingScan.value = true
    scannerMessage.value = ''
    lastScannedTicket.value = null
    try {
        await showOutcome(await checkInApi.scan(await orgIdOf(), eventKey(), qrValue.trim()))
    } catch (error: unknown) {
        scannerResultType.value = 'error'
        scannerMessage.value = authMessage(error) || 'Unable to check in this ticket.'
    } finally {
        // A short pause so the same QR isn't read twice.
        await new Promise<void>(resolve => setTimeout(resolve, 1200))
        isProcessingScan.value = false
    }
}

async function loadCheckInSummary(quiet = false) {
    if (!eventId.value) {
        checkInSummary.value = { registered: 0, checked_in: 0, recent_check_ins: [] }
        return
    }
    if (!quiet) isLoadingSummary.value = true
    try {
        const data = await checkInApi.summary(await orgIdOf(), eventKey())
        checkInSummary.value = {
            registered: data.expected,
            checked_in: data.checked_in,
            recent_check_ins: data.recent.map(r => ({
                id: r.id as unknown as number,
                checked_in_at: r.checked_in_at,
                ticket: {
                    id: 0,
                    attendee_name: r.name,
                    attendee_email: null,
                    source: r.method === 'manual' ? 'manual' : 'online',
                    checked_in_at: r.checked_in_at,
                    ticket_type: { id: 0, name: r.zone ? `${r.tier} · ${r.zone}` : r.tier },
                },
            })),
        }
    } catch {
        checkInSummary.value = { registered: 0, checked_in: 0, recent_check_ins: [] }
    } finally {
        isLoadingSummary.value = false
    }
}
// Close to live during the event (review #12): check-ins and sales appear on their own.
// Event day: other doors check people in too, so the counts stay close to live.

function openManualEntry() {
    if (!eventId.value) {
        return
    }

    manualTicketId.value =
        ''

    manualEntryError.value =
        ''

    showManualEntry.value =
        true
}

function closeManualEntry() {
    if (
        isManualCheckingIn.value
    ) {
        return
    }

    showManualEntry.value =
        false

    manualTicketId.value =
        ''

    manualEntryError.value =
        ''
}

// Manual entry: matches appear as you type (name, e-mail or the ticket ID printed on
// the ticket); tap one to check them in. The button checks in a single match.
type DoorMatch = { ticket_id: string, name: string, tier: string, status: string, last_checked_in_at: string | null }
const manualMatches = ref<DoorMatch[]>([])
const manualSearching = ref(false)
const shortTicketId = (id: string) => id.replace(/-/g, '').slice(-10).toUpperCase()
let manualTimer: ReturnType<typeof setTimeout> | undefined
watch(manualTicketId, (value) => {
    clearTimeout(manualTimer)
    const query = value.trim()
    if (query.length < 2 || !eventId.value) { manualMatches.value = []; manualSearching.value = false; return }
    manualSearching.value = true
    manualTimer = setTimeout(async () => {
        try {
            const results = await checkInApi.search(await orgIdOf(), eventKey(), query)
            if (manualTicketId.value.trim() === query) manualMatches.value = results as DoorMatch[]
        } catch { manualMatches.value = [] } finally { manualSearching.value = false }
    }, 250)
})
async function checkInMatch(g: DoorMatch) {
    isManualCheckingIn.value = true
    manualEntryError.value = ''
    try {
        await showOutcome(await checkInApi.manual(await orgIdOf(), eventKey(), g.ticket_id))
        showManualEntry.value = false
        manualTicketId.value = ''
    } catch (error: unknown) {
        manualEntryError.value = authMessage(error) || 'Unable to check in this guest.'
    } finally {
        isManualCheckingIn.value = false
    }
}

async function submitManualCheckIn() {
    if (!eventId.value) return
    const query = manualTicketId.value.trim()
    if (query.length < 2) {
        manualEntryError.value = 'Type the guest\'s name, e-mail or ticket ID.'
        return
    }
    isManualCheckingIn.value = true
    manualEntryError.value = ''
    try {
        const orgId = await orgIdOf()
        const matches = (await checkInApi.search(orgId, eventKey(), query)).filter(g => g.status !== 'cancelled')
        if (!matches.length) {
            manualEntryError.value = 'No ticket on this event matches that.'
            return
        }
        if (matches.length > 1) {
            manualEntryError.value = `${matches.length} guests match. Tap the right one in the list.`
            return
        }
        await showOutcome(await checkInApi.manual(orgId, eventKey(), matches[0]!.ticket_id))
        showManualEntry.value = false
        manualTicketId.value = ''
    } catch (error: unknown) {
        manualEntryError.value = authMessage(error) || 'Unable to check in this guest.'
    } finally {
        isManualCheckingIn.value = false
    }
}

function formatCheckInTime(
    value: string | null,
): string {
    if (!value) {
        return ''
    }

    const date =
        new Date(value)

    return date.toLocaleTimeString(
        'en-US',
        {
            hour:
                'numeric',

            minute:
                '2-digit',
        },
    )
}

watch(
    eventId,
    async () => {
        stopScanner()

        scannerMessage.value =
            ''

        scannerResultType.value =
            null

        lastScannedTicket.value =
            null

        lastScannedValue.value =
            ''

        lastScannedAt.value =
            0

        showManualEntry.value =
            false

        await loadCheckInSummary()
    },
    {
        immediate: true,
    },
)

onBeforeUnmount(() => {
    stopScanner()
})
</script>

<style scoped>
.slide-enter-active,
.slide-leave-active {
    transition: transform 0.25s ease;
}

.slide-enter-from,
.slide-leave-to {
    transform: translateX(100%);
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.toast-enter-active,
.toast-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

.qr-rise {
    animation: qr-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: var(--d, 0ms);
}

.qr-row {
    animation: qr-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: var(--d, 0ms);
}

.qr-corner {
    position: absolute;
    height: 2rem;
    width: 2rem;
    transition: border-color 0.3s ease;
}

.qr-idle {
    animation: qr-float 3.2s ease-in-out infinite;
}

.qr-scanline {
    top: 0;
    box-shadow: 0 0 14px 2px rgba(255, 255, 255, 0.55);
    animation: qr-scan 2.2s ease-in-out infinite;
}

@keyframes qr-rise {
    from { opacity: 0; transform: translateY(14px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes qr-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-5px); }
}

@keyframes qr-scan {
    0% { top: 4%; opacity: 0; }
    12% { opacity: 1; }
    88% { opacity: 1; }
    100% { top: 96%; opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .qr-rise, .qr-row, .qr-idle, .qr-scanline { animation: none; }
}
</style>
