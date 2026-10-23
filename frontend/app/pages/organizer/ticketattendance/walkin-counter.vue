<template>
    <div class="space-y-6">
        <section class="wk-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-extrabold tracking-tight text-gray-950">
                        Walk-in Registration Counter
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        <span v-if="selectedEvent">
                            Process on-site ticket purchases for
                            <span class="font-semibold text-gray-700">{{ selectedEvent.name }}</span>
                        </span>

                        <span v-else>Process on-site ticket purchases</span>
                    </p>
                </div>

                <button type="button"
                    class="flex shrink-0 items-center gap-2 rounded-xl bg-[#285F6b] px-5 py-3 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md active:translate-y-0 active:scale-[0.98] disabled:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!selectedEventId" @click="openWalkInForm">
                    <IconBase name="user-plus" class="h-4 w-4" />

                    New Walk-in
                </button>
            </div>

            <div v-if="errorMessage" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ errorMessage }}
            </div>

            <div v-if="!selectedEventId" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <div class="flex items-start gap-3">
                    <IconBase name="calendar" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />

                    <div>
                        <div class="text-sm font-bold text-gray-900">
                            Select an Event
                        </div>

                        <p class="mt-0.5 text-sm text-gray-600">
                            Choose an event from the event selector above before adding walk-in attendees.
                        </p>
                    </div>
                </div>
            </div>

            <div v-else class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="tile in summaryTiles" :key="tile.label" class="wk-tile rounded-2xl bg-gray-50 p-4">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-bold uppercase tracking-[0.08em] text-gray-400">{{ tile.label }}</p>
                        <IconBase :name="tile.icon" class="h-4 w-4 text-primary-700" />
                    </div>
                    <p class="mt-2 text-3xl font-extrabold tracking-tight text-gray-950">{{ tile.value }}</p>
                </div>
            </div>
        </section>

        <div v-if="selectedEventId" class="grid grid-cols-1 gap-6 xl:grid-cols-5">
            <!-- Queue -->
            <section class="wk-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8 xl:col-span-3" style="--d: 80ms">
                <div class="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-extrabold tracking-tight text-gray-950">
                            Walk-in Queue
                        </h3>

                        <p class="mt-0.5 text-sm text-gray-500">
                            Pending walk-in registrations waiting for payment
                        </p>
                    </div>

                    <span class="inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1.5 text-xs font-bold text-primary-700">
                        <span class="relative flex h-2 w-2">
                            <span v-if="pendingQueue.length" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-700 opacity-60" />
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-primary-700" />
                        </span>
                        {{ pendingQueue.length }} pending
                    </span>
                </div>

                <div v-if="isLoadingWalkIns" class="py-10 text-center text-sm text-gray-500">
                    <IconBase name="refresh-cw" class="mx-auto mb-2 h-5 w-5 animate-spin text-gray-400" />

                    Loading walk-in queue...
                </div>

                <div v-else-if="pendingQueue.length" class="space-y-3">
                    <div v-for="(entry, index) in pendingQueue" :key="entry.id"
                        class="wk-row flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-gray-200 p-4 transition duration-200 hover:border-primary-200 hover:bg-gray-50/60"
                        :style="{ '--d': index * 50 + 'ms' }">
                        <div class="flex min-w-0 items-center gap-3.5">
                            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-700">
                                {{ getInitials(entry.name) }}
                            </div>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="truncate text-sm font-bold text-gray-950">{{ entry.name }}</span>
                                    <span v-if="entry.amount" class="rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-bold text-gray-700">
                                        {{ formatCurrency(entry.amount) }}
                                    </span>
                                </div>

                                <div class="mt-0.5 text-xs text-gray-500">
                                    {{ entry.ticket_type?.name || 'Ticket type' }}
                                    <span v-if="entry.email || entry.phone" class="text-gray-300"> · </span>
                                    <span v-if="entry.email">{{ entry.email }}</span>
                                    <span v-if="entry.email && entry.phone" class="text-gray-300"> · </span>
                                    <span v-if="entry.phone">{{ entry.phone }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <span class="text-xs font-semibold text-gray-400">{{ formatTime(entry.created_at) }}</span>

                            <button type="button"
                                class="flex items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-xs font-bold text-white shadow-sm transition duration-200 hover:bg-[#1f4a54] hover:shadow-md active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="processingPaymentId ===
                                    entry.id
                                    " @click="
                                        processPayment(
                                            entry,
                                        )
                                        ">
                                <IconBase v-if="
                                    processingPaymentId ===
                                    entry.id
                                " name="refresh-cw" class="h-3.5 w-3.5 animate-spin" />

                                <IconBase v-else name="credit-card" class="h-3.5 w-3.5" />

                                {{
                                    processingPaymentId ===
                                        entry.id
                                        ? 'Processing...'
                                        : 'Process Payment'
                                }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="rounded-2xl border border-dashed border-gray-300 px-5 py-12 text-center">
                    <div class="wk-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
                        <IconBase name="user-plus" class="h-7 w-7" />
                    </div>

                    <p class="mt-4 text-sm font-bold text-gray-900">
                        No walk-ins in queue
                    </p>

                    <p class="mx-auto mt-1 max-w-xs text-xs leading-5 text-gray-500">
                        New walk-in registrations for this event will appear here.
                    </p>

                    <button type="button" class="mt-5 inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-xs font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700" @click="openWalkInForm">
                        <IconBase name="plus" class="h-3.5 w-3.5" />
                        Add a walk-in
                    </button>
                </div>
            </section>

            <!-- Paid -->
            <section class="wk-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8 xl:col-span-2" style="--d: 160ms">
                <div class="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-extrabold tracking-tight text-gray-950">
                            Paid at the counter
                        </h3>

                        <p class="mt-0.5 text-sm text-gray-500">
                            Latest completed walk-in payments
                        </p>
                    </div>

                    <div v-if="paidEntries.length" class="text-right">
                        <p class="text-xl font-extrabold tracking-tight text-gray-950">{{ formatCurrency(paidTotal) }}</p>
                        <p class="text-[11px] font-semibold text-gray-400">collected</p>
                    </div>
                </div>

                <div v-if="paidEntries.length" class="max-h-[420px] space-y-1 overflow-y-auto pr-1">
                    <div v-for="(entry, index) in paidEntries" :key="entry.id"
                        class="wk-row flex items-center justify-between gap-3 rounded-2xl px-3 py-3 transition-colors hover:bg-gray-50"
                        :style="{ '--d': index * 40 + 'ms' }">
                        <div class="flex min-w-0 items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-700">
                                <IconBase name="check-circle" class="h-5 w-5" />
                            </div>

                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-gray-900">{{ entry.name }}</p>
                                <p class="text-xs text-gray-500">{{ entry.ticket_type?.name || 'Ticket' }}</p>
                            </div>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="text-sm font-bold text-gray-900">{{ formatCurrency(entry.amount) }}</p>
                            <p class="text-[11px] font-semibold text-gray-400">{{ formatTime(entry.paid_at) }}</p>
                        </div>
                    </div>
                </div>

                <div v-else class="rounded-2xl border border-dashed border-gray-300 px-5 py-12 text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-50 text-gray-400">
                        <IconBase name="credit-card" class="h-7 w-7" />
                    </div>

                    <p class="mt-4 text-sm font-bold text-gray-900">No payments yet</p>

                    <p class="mx-auto mt-1 max-w-xs text-xs leading-5 text-gray-500">
                        Paid walk-ins show up here once you process their payment.
                    </p>
                </div>
            </section>
        </div>

        <Transition name="fade">
            <div v-if="showWalkInForm" class="fixed inset-0 z-40 bg-gray-900/40 backdrop-blur-[2px]" @click="closeWalkInForm" />
        </Transition>

        <Transition name="slide">
            <div v-if="showWalkInForm"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-2xl">
                <div class="flex shrink-0 items-start justify-between border-b border-gray-200 px-6 py-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#285F6b] text-white">
                            <IconBase name="user-plus" class="h-5 w-5" />
                        </div>

                        <div>
                            <h2 class="text-base font-extrabold text-gray-950">
                                New Walk-in
                            </h2>

                            <p class="text-sm text-gray-500">
                                {{
                                    selectedEvent?.name ||
                                    'Register a walk-in attendee'
                                }}
                            </p>
                        </div>
                    </div>

                    <button type="button" class="tap-target rounded-lg p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600" @click="closeWalkInForm">
                        <IconBase name="x" class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5">
                    <div v-if="walkInFormError"
                        class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        {{ walkInFormError }}
                    </div>

                    <FormsLabel text="Full Name" required />

                    <FormsTextField v-model="walkInForm.name" icon="user" placeholder="e.g. Robert Brown" required />

                    <label class="mb-1.5 mt-4 block text-sm font-semibold text-gray-700">
                        Ticket Type *
                    </label>

                    <FormsSelect v-model="walkInForm.ticketTypeId"
                        :options="ticketTiers.map(tier => ({ value: tier.id, label: `${tier.name} — ${formatCurrency(tier.price)}` }))"
                        placeholder="Select ticket type" :can-clear="false" />

                    <div v-if="!ticketTiers.length"
                        class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                        No ticket types are configured for this event.
                        <NuxtLink to="/organizer/eventmanagement/ticket-tiers" class="ml-1 font-semibold underline">Add ticket tiers</NuxtLink>
                    </div>

                    <div v-if="selectedTicketType" class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-medium text-gray-500">
                                Ticket Price
                            </span>

                            <span class="text-sm font-bold text-gray-900">
                                {{
                                    formatCurrency(
                                        selectedTicketType.price,
                                    )
                                }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <FormsLabel text="Email Address" />

                            <FormsTextField v-model="walkInForm.email" type="email"
                                placeholder="e.g. robert@example.com" />
                        </div>

                        <div>
                            <FormsLabel text="Phone Number" />

                            <FormsTextField v-model="walkInForm.phone" placeholder="e.g. 0917 123 4567" />
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 gap-3 border-t border-gray-200 px-6 py-4">
                    <FormsButton variant="secondary" class="flex-1" :disabled="isSubmittingWalkIn"
                        @click="closeWalkInForm">
                        Cancel
                    </FormsButton>

                    <FormsButton class="flex-1" :disabled="isSubmittingWalkIn ||
                        !walkInForm.name.trim() ||
                        !walkInForm.ticketTypeId
                        " @click="submitWalkIn">
                        <IconBase v-if="isSubmittingWalkIn" name="refresh-cw" class="h-4 w-4 animate-spin" />

                        <IconBase v-else name="user-plus" class="h-4 w-4" />

                        {{
                            isSubmittingWalkIn
                                ? 'Adding...'
                                : 'Add to Queue'
                        }}
                    </FormsButton>
                </div>
            </div>
        </Transition>
    
    </div>
</template>

<script setup lang="ts">
interface OrganizerEvent {
    id: number
    name: string
    event_type: string
    event_date: string | null
    location: string | null
    status: string
    ticket_types?: TicketType[]
}

interface TicketType {
    id: number
    name: string
    price: string | number
    capacity: number
}

interface WalkIn {
    id: number
    event_id: number
    event_ticket_type_id: number | null
    name: string
    email: string | null
    phone: string | null
    amount: string | number

    payment_status:
    | 'pending'
    | 'paid'
    | 'cancelled'

    paid_at: string | null
    created_at: string
    ticket_type?: TicketType | null
}

interface WalkInSummary {
    pending: number
    paid: number
    capacity: number
    registered: number
    remaining: number
}

type EventDetailResponse =
    | OrganizerEvent
    | {
        data: OrganizerEvent
    }

interface WalkInResponse {
    data: WalkIn[]
}

interface WalkInSummaryResponse {
    data: WalkInSummary
}

interface CreateWalkInResponse {
    message?: string
    data: WalkIn
}

interface PaymentResponse {
    message?: string

    data: {
        walk_in: WalkIn
        ticket: unknown
    }
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
    data?: {
        message?: string
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
            if (
                !selectedEventId.value
            ) {
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

const ticketTiers =
    ref<TicketType[]>([])

const walkIns =
    ref<WalkIn[]>([])

const walkInSummary =
    ref<WalkInSummary>({
        pending: 0,
        paid: 0,
        capacity: 0,
        registered: 0,
        remaining: 0,
    })

const isLoadingWalkIns =
    ref(false)

const errorMessage =
    ref('')

const showWalkInForm =
    ref(false)

const walkInFormError =
    ref('')

const isSubmittingWalkIn =
    ref(false)

const processingPaymentId =
    ref<number | null>(
        null,
    )

const pendingQueue =
    computed(() =>
        walkIns.value.filter(
            entry =>
                entry.payment_status ===
                'pending',
        ),
    )

const paidEntries =
    computed(() =>
        walkIns.value
            .filter(entry => entry.payment_status === 'paid')
            .sort((a, b) =>
                (b.paid_at ?? b.created_at).localeCompare(a.paid_at ?? a.created_at),
            )
            .slice(0, 12),
    )

const paidTotal =
    computed(() =>
        walkIns.value
            .filter(entry => entry.payment_status === 'paid')
            .reduce((sum, entry) => sum + Number(entry.amount || 0), 0),
    )

const summaryTiles =
    computed(() => [
        { label: 'Pending', value: walkInSummary.value.pending, icon: 'clock' },
        { label: 'Paid walk-ins', value: walkInSummary.value.paid, icon: 'check-circle' },
        { label: 'Registered', value: walkInSummary.value.registered, icon: 'users' },
        { label: 'Spots left', value: walkInSummary.value.remaining, icon: 'ticket' },
    ])

function getInitials(name: string): string {
    return (
        name
            .split(' ')
            .filter(Boolean)
            .slice(0, 2)
            .map(part => part[0]?.toUpperCase())
            .join('') || 'W'
    )
}

function emptyWalkInForm() {
    return {
        name: '',

        ticketTypeId:
            null as number | null,

        email: '',
        phone: '',
    }
}

const walkInForm =
    reactive(
        emptyWalkInForm(),
    )

const selectedTicketType =
    computed<TicketType | null>(
        () => {
            if (
                !walkInForm.ticketTypeId
            ) {
                return null
            }

            return (
                ticketTiers.value.find(
                    ticket =>
                        ticket.id ===
                        walkInForm.ticketTypeId,
                ) ?? null
            )
        },
    )

function getApiErrorMessage(
    error: unknown,
    fallback: string,
): string {
    const apiError =
        error as ApiErrorResponse

    const validationError =
        Object.values(
            apiError.data?.errors ?? {},
        )[0]?.[0]

    return (
        validationError ??
        apiError.data?.message ??
        fallback
    )
}

function extractEvent(
    response: EventDetailResponse,
): OrganizerEvent {
    if (
        typeof response === 'object' &&
        response !== null &&
        'data' in response
    ) {
        return response.data
    }

    return response as OrganizerEvent
}

const walkInApi = useCheckIn()
const orgIdOf = async () => (await useOrganization().fetchOrganization())?.id ?? ''
const eventKey = () => String(selectedEventId.value)

/** Door ticket types come with the walk-in desk (on-sale tiers and what's left). */
async function loadSelectedEvent() {
    if (!selectedEventId.value) {
        ticketTiers.value = []
        return
    }
    await loadWalkIns()
}

async function loadWalkIns() {
    if (!selectedEventId.value) {
        walkIns.value = []
        return
    }
    isLoadingWalkIns.value = true
    errorMessage.value = ''
    try {
        const data = await walkInApi.walkIns(await orgIdOf(), eventKey())
        ticketTiers.value = data.tiers.filter(t => t.status === 'on_sale')
            .map(t => ({ id: t.id as unknown as number, name: t.zone ? `${t.name} · ${t.zone}` : t.name, price: t.price, capacity: t.remaining }))
        // Every door entry is already paid (cash is confirmed before the ticket is issued).
        walkIns.value = data.recent.map(w => ({
            id: w.id as unknown as number,
            event_id: 0,
            event_ticket_type_id: null,
            name: w.name ?? (w.category === 'headcount_only' ? `Headcount (${w.count})` : 'Walk-in'),
            email: null,
            phone: null,
            amount: w.amount ?? 0,
            payment_status: 'paid',
            paid_at: w.recorded_at,
            created_at: w.recorded_at,
            ticket_type: w.tier ? { id: 0, name: w.tier, price: w.amount ?? 0, capacity: 0 } : null,
        }))
        walkInSummary.value = {
            pending: 0,
            paid: data.totals.walk_ins,
            capacity: data.capacity.limit ?? 0,
            registered: data.capacity.used,
            remaining: data.capacity.remaining ?? 0,
        }
    } catch (error: unknown) {
        errorMessage.value = authMessage(error) || 'Unable to load walk-ins.'
    } finally {
        isLoadingWalkIns.value = false
    }
}

async function refreshTopSummary() {
    if (!selectedEventId.value) return
    try {
        const data = await walkInApi.summary(await orgIdOf(), eventKey())
        const capacity = ticketingSummary.value.capacity || data.expected
        ticketingSummary.value = {
            total_registered: data.expected,
            checked_in: data.checked_in,
            walk_ins: data.walk_ins,
            capacity,
            capacity_percent: capacity ? Math.round((data.arrived / capacity) * 100) : 0,
        }
    } catch {
        // The top numbers are a nicety.
    }
}

function openWalkInForm() {
    if (
        !selectedEventId.value
    ) {
        return
    }

    Object.assign(
        walkInForm,
        emptyWalkInForm(),
    )

    if (
        ticketTiers.value.length ===
        1
    ) {
        walkInForm.ticketTypeId =
            ticketTiers.value[0]?.id ??
            null
    }

    walkInFormError.value =
        ''

    showWalkInForm.value =
        true
}

function closeWalkInForm() {
    if (
        isSubmittingWalkIn.value
    ) {
        return
    }

    showWalkInForm.value =
        false

    walkInFormError.value =
        ''
}

async function submitWalkIn() {
    if (
        !token.value ||
        !selectedEventId.value
    ) {
        return
    }

    if (
        !walkInForm.name.trim()
    ) {
        walkInFormError.value =
            'Full name is required.'

        return
    }

    if (
        !walkInForm.ticketTypeId
    ) {
        walkInFormError.value =
            'Please select a ticket type.'

        return
    }

    isSubmittingWalkIn.value =
        true

    walkInFormError.value =
        ''

    const tier = ticketTiers.value.find(t => String(t.id) === String(walkInForm.ticketTypeId))
    const price = Number(tier?.price ?? 0)
    // Door sales are paid on the spot: the ticket is issued only once the cash is in hand.
    if (price > 0 && !(await confirmDialog(`Collect ${formatCurrency(price)} in cash from ${walkInForm.name.trim()} now, then issue the ticket.`, { title: 'Cash collected?', confirmLabel: 'Cash collected, issue ticket', cancelLabel: 'Not yet', danger: false }))) {
        isSubmittingWalkIn.value = false
        return
    }
    try {
        await walkInApi.sell(await orgIdOf(), eventKey(), {
            full_name: walkInForm.name.trim(),
            email: walkInForm.email.trim() || null,
            contact_number: walkInForm.phone.trim() || null,
            ticket_type_id: String(walkInForm.ticketTypeId),
            cash_received: price > 0,
        })
        showWalkInForm.value = false
        Object.assign(walkInForm, emptyWalkInForm())
        await Promise.all([loadWalkIns(), refreshTopSummary()])
    } catch (error: unknown) {
        walkInFormError.value = authMessage(error) || 'Unable to add walk-in.'
    } finally {
        isSubmittingWalkIn.value = false
    }
}

/** Door entries are paid when recorded, so there is nothing left to process. */
async function processPayment(
    _entry: WalkIn,
) {
    await loadWalkIns()
}

function formatCurrency(
    value: string | number,
): string {
    const amount =
        Number(value)

    return new Intl.NumberFormat(
        'en-PH',
        {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0,
        },
    ).format(
        Number.isNaN(amount)
            ? 0
            : amount,
    )
}

function formatTime(
    value: string | null,
): string {
    if (!value) {
        return ''
    }

    return new Date(
        value,
    ).toLocaleTimeString(
        'en-US',
        {
            hour: 'numeric',
            minute: '2-digit',
        },
    )
}

watch(
    selectedEventId,
    async () => {
        showWalkInForm.value =
            false

        walkIns.value =
            []

        ticketTiers.value =
            []

        walkInSummary.value = {
            pending: 0,
            paid: 0,
            capacity: 0,
            registered: 0,
            remaining: 0,
        }

        if (
            !selectedEventId.value
        ) {
            return
        }

        await Promise.all([
            loadSelectedEvent(),
            loadWalkIns(),
        ])
    },
    {
        immediate: true,
    },
)
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

.wk-rise {
    animation: wk-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: var(--d, 0ms);
}

.wk-row {
    animation: wk-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
    animation-delay: var(--d, 0ms);
}

.wk-tile {
    transition: transform 0.2s ease, background-color 0.2s ease;
}

.wk-tile:hover {
    transform: translateY(-2px);
    background-color: #f3f6f7;
}

.wk-float {
    animation: wk-float 3.2s ease-in-out infinite;
}

@keyframes wk-rise {
    from { opacity: 0; transform: translateY(14px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes wk-float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
    .wk-rise, .wk-row, .wk-float { animation: none; }
    .wk-tile { transition: none; }
}
</style>
