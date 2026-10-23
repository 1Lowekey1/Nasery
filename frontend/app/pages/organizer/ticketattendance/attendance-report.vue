<template>
    <div class="space-y-6">
        <section class="ar-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-extrabold tracking-tight text-gray-950">
                        Attendance Report
                    </h2>

                    <p class="mt-1 text-sm text-gray-500">
                        <span v-if="selectedEvent">
                            Attendance analytics for
                            <span class="font-semibold text-gray-700">{{ selectedEvent.name }}</span>
                        </span>

                        <span v-else>Comprehensive event attendance analytics</span>
                    </p>
                </div>

                <button v-if="selectedEventId" type="button"
                    class="flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 transition duration-200 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="!attendees.length" @click="exportCsv">
                    <IconBase name="download" class="h-4 w-4" />

                    Export CSV
                </button>
            </div>

            <div v-if="!selectedEventId" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
                <div class="flex items-start gap-3">
                    <IconBase name="calendar" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />

                    <div>
                        <p class="text-sm font-bold text-gray-900">
                            Select an Event
                        </p>

                        <p class="mt-0.5 text-sm text-gray-600">
                            Choose an event from the event selector above to view its attendance report.
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="errorMessage" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ errorMessage }}
            </div>
        </section>

        <template v-if="selectedEventId">
            <div v-if="isLoading" class="rounded-3xl border border-gray-200 bg-white py-20 text-center">
                <IconBase name="refresh-cw" class="mx-auto h-6 w-6 animate-spin text-gray-400" />

                <p class="mt-3 text-sm text-gray-500">
                    Loading attendance report...
                </p>
            </div>

            <template v-else>
                <!-- Gauge + stats -->
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                    <section class="ar-rise rounded-3xl border border-gray-200 bg-white p-6 xl:col-span-4" style="--d: 60ms">
                        <p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Overall attendance</p>

                        <ClientOnly>
                            <DeferredChart type="radialBar" :height="250" :options="gaugeOptions" :series="[attendanceRate]" />
                        </ClientOnly>

                        <p class="-mt-2 text-center text-sm text-gray-500">
                            <span class="font-bold text-gray-900">{{ totalAttended }}</span> of
                            <span class="font-bold text-gray-900">{{ totalRegistered }}</span>
                            registered attendees have checked in
                        </p>
                    </section>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:col-span-8">
                        <div v-for="(card, index) in statCards" :key="card.label"
                            class="ar-rise ar-card group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5"
                            :style="{ '--d': 100 + index * 60 + 'ms' }">
                            <span class="ar-line absolute inset-x-0 top-0 h-[2px] bg-[#285F6b]" />

                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <span class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">{{ card.label }}</span>

                                    <div class="mt-2 text-4xl font-extrabold tracking-tight text-gray-950">
                                        {{ card.value }}
                                    </div>

                                    <div class="mt-2 text-xs font-medium text-gray-500">{{ card.hint }}</div>
                                </div>

                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 transition group-hover:scale-105">
                                    <IconBase :name="card.icon" class="h-5 w-5" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                    <section class="ar-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8 xl:col-span-8" style="--d: 120ms">
                        <h3 class="text-lg font-extrabold tracking-tight text-gray-950">
                            Attendance by Ticket Type
                        </h3>

                        <p class="mt-0.5 text-sm text-gray-500">
                            Checked-in vs not yet attended, for each ticket type
                        </p>

                        <ClientOnly v-if="byTicketType.length">
                            <DeferredChart type="bar" :height="typeChartHeight" :options="typeOptions" :series="typeSeries" />
                        </ClientOnly>

                        <div v-else class="mt-5 rounded-2xl border border-dashed border-gray-300 px-5 py-12 text-center">
                            <IconBase name="ticket" class="mx-auto h-7 w-7 text-gray-400" />

                            <p class="mt-2 text-sm font-semibold text-gray-700">
                                No ticket attendance data
                            </p>

                            <p class="mt-1 text-xs text-gray-500">
                                Attendance by ticket type will appear when tickets are issued.
                            </p>
                        </div>
                    </section>

                    <section class="ar-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8 xl:col-span-4" style="--d: 180ms">
                        <h3 class="text-lg font-extrabold tracking-tight text-gray-950">
                            Registration Source
                        </h3>

                        <p class="mt-0.5 text-sm text-gray-500">
                            How attendees registered
                        </p>

                        <ClientOnly v-if="onlineCount + walkInCount > 0">
                            <DeferredChart type="donut" :height="250" :options="sourceOptions" :series="[onlineCount, walkInCount]" />
                        </ClientOnly>

                        <p v-else class="py-16 text-center text-sm text-gray-400">No registrations yet.</p>

                        <div class="mt-2 grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-gray-50 p-3">
                                <p class="flex items-center gap-2 text-xs font-bold text-gray-500"><span class="h-2 w-2 rounded-full bg-[#285F6b]" />Online</p>
                                <p class="mt-1 text-xl font-extrabold text-gray-950">{{ onlineCount }} <span class="text-xs font-semibold text-gray-400">{{ onlinePercent }}%</span></p>
                            </div>
                            <div class="rounded-2xl bg-gray-50 p-3">
                                <p class="flex items-center gap-2 text-xs font-bold text-gray-500"><span class="h-2 w-2 rounded-full bg-[#9dbfc6]" />Walk-ins</p>
                                <p class="mt-1 text-xl font-extrabold text-gray-950">{{ walkInCount }} <span class="text-xs font-semibold text-gray-400">{{ walkInPercent }}%</span></p>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Details -->
                <section class="ar-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 200ms">
                    <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-extrabold tracking-tight text-gray-950">
                                Attendee Attendance Details
                            </h3>

                            <p class="mt-0.5 text-sm text-gray-500">
                                Complete attendee status for the selected event
                            </p>
                        </div>

                        <div class="flex rounded-xl bg-gray-100 p-1 text-xs font-bold">
                            <button v-for="option in statusOptions" :key="option.value" type="button" class="rounded-lg px-3.5 py-1.5 transition"
                                :class="statusFilter === option.value ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                                @click="statusFilter = option.value">
                                {{ option.label }} <span class="opacity-60">{{ option.count }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="filteredAttendees.length" class="overflow-x-auto">
                        <table class="w-full whitespace-nowrap text-left text-sm">
                            <thead>
                                <tr class="bg-gray-50/80 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-400 [&>th]:py-3 [&>th:first-child]:rounded-l-xl [&>th:first-child]:pl-3 [&>th:last-child]:rounded-r-xl [&>th:last-child]:pr-3">
                                    <th class="pr-4">Attendee</th>
                                    <th class="pr-4">Ticket Type</th>
                                    <th class="pr-4">Category</th>
                                    <th class="pr-4">Source</th>
                                    <th class="pr-4">Attendance</th>
                                    <th>Check-in Time</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="attendee in filteredAttendees" :key="attendee.id" class="ar-row transition-colors hover:bg-gray-50">
                                    <td class="py-3.5 pl-3 pr-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-700">
                                                {{ getInitials(attendee.name) }}
                                            </div>

                                            <div>
                                                <p class="font-semibold text-gray-900">
                                                    {{ attendee.name }}
                                                </p>

                                                <p class="mt-0.5 font-mono text-[11px] text-gray-400">
                                                    {{ attendee.ticket_id }}
                                                </p>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="py-3.5 pr-4 text-gray-600">
                                        {{
                                            attendee.ticket_type?.name ||
                                            'General Admission'
                                        }}
                                    </td>

                                    <td class="py-3.5 pr-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="categoryClass(attendee.attendee_category)">
                                            {{ formatCategory(attendee.attendee_category) }}
                                        </span>
                                    </td>

                                    <td class="py-3.5 pr-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="attendee.source ===
                                            'walk_in'
                                            ? 'bg-gray-100 text-gray-700'
                                            : 'bg-primary-50 text-primary-700'
                                            ">
                                            {{
                                                formatSource(
                                                    attendee.source,
                                                )
                                            }}
                                        </span>
                                    </td>

                                    <td class="py-3.5 pr-4">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                                            :class="attendee.status ===
                                                'checked_in'
                                                ? 'bg-[#285F6b] text-white'
                                                : 'bg-gray-100 text-gray-600'
                                                ">
                                            <IconBase v-if="
                                                attendee.status ===
                                                'checked_in'
                                            " name="check-circle" class="h-3 w-3" />

                                            {{
                                                attendee.status ===
                                                    'checked_in'
                                                    ? 'Checked-in'
                                                    : 'Registered'
                                            }}
                                        </span>
                                    </td>

                                    <td class="py-3.5 pr-3 text-gray-500">
                                        {{
                                            attendee.checked_in_at
                                                ? formatCheckInDateTime(
                                                    attendee.checked_in_at,
                                                )
                                                : '—'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="rounded-2xl border border-dashed border-gray-300 px-5 py-12 text-center">
                        <IconBase name="users" class="mx-auto h-7 w-7 text-gray-400" />

                        <p class="mt-2 text-sm font-semibold text-gray-700">
                            {{ attendees.length ? 'No attendees match this filter' : 'No attendees yet' }}
                        </p>

                        <p class="mt-1 text-xs text-gray-500">
                            {{ attendees.length ? 'Try a different status.' : 'Registered attendees will appear here.' }}
                        </p>
                    </div>
                </section>
            </template>
        </template>
    </div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'

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

interface Attendee {
    id: number
    name: string
    email: string | null

    ticket_type:
    | TicketType
    | null

    ticket_id: string

    attendee_category:
    | 'invited'
    | 'free'
    | 'paid'

    source:
    | 'online'
    | 'walk_in'
    | string

    payment_status:
    | null
    | 'pending'
    | 'paid'
    | 'refunded'
    | string

    status:
    | 'registered'
    | 'checked_in'

    checked_in_at:
    | string
    | null

    created_at: string
}

interface AttendeesResponse {
    data: Attendee[]
}

interface ApiErrorResponse {
    data?: {
        message?: string

        errors?: Record<
            string,
            string[]
        >
    }
}

interface TicketTypeAttendance {
    id: string
    type: string
    attended: number
    total: number
    percent: number
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

const attendees =
    ref<Attendee[]>([])

const isLoading =
    ref(false)

const errorMessage =
    ref('')

const totalRegistered =
    computed(() =>
        attendees.value.length,
    )

const totalAttended =
    computed(() =>
        attendees.value.filter(
            attendee =>
                attendee.status ===
                'checked_in',
        ).length,
    )

const notAttended =
    computed(() =>
        Math.max(
            totalRegistered.value -
            totalAttended.value,
            0,
        ),
    )

const attendanceRate =
    computed(() => {
        if (
            totalRegistered.value ===
            0
        ) {
            return 0
        }

        return Math.min(
            Math.round(
                (
                    totalAttended.value /
                    totalRegistered.value
                ) *
                100,
            ),
            100,
        )
    })

const onlineCount =
    computed(() =>
        attendees.value.filter(
            attendee =>
                attendee.source ===
                'online',
        ).length,
    )

const walkInCount =
    computed(() =>
        attendees.value.filter(
            attendee =>
                attendee.source ===
                'walk_in',
        ).length,
    )

const onlinePercent =
    computed(() => {
        if (
            totalRegistered.value ===
            0
        ) {
            return 0
        }

        return Math.round(
            (
                onlineCount.value /
                totalRegistered.value
            ) *
            100,
        )
    })

const walkInPercent =
    computed(() => {
        if (
            totalRegistered.value ===
            0
        ) {
            return 0
        }

        return Math.round(
            (
                walkInCount.value /
                totalRegistered.value
            ) *
            100,
        )
    })

const byTicketType =
    computed<TicketTypeAttendance[]>(
        () => {
            const grouped =
                new Map<
                    string,
                    {
                        id: string
                        type: string
                        total: number
                        attended: number
                    }
                >()

            attendees.value.forEach(
                attendee => {
                    const ticketTypeId =
                        attendee.ticket_type
                            ?.id

                    const ticketTypeName =
                        attendee.ticket_type
                            ?.name ||
                        'General Admission'

                    const key =
                        ticketTypeId
                            ? String(
                                ticketTypeId,
                            )
                            : `general-${ticketTypeName}`

                    const existing =
                        grouped.get(key)

                    if (existing) {
                        existing.total +=
                            1

                        if (
                            attendee.status ===
                            'checked_in'
                        ) {
                            existing.attended +=
                                1
                        }

                        return
                    }

                    grouped.set(
                        key,
                        {
                            id:
                                key,

                            type:
                                ticketTypeName,

                            total:
                                1,

                            attended:
                                attendee.status ===
                                    'checked_in'
                                    ? 1
                                    : 0,
                        },
                    )
                },
            )

            return Array.from(
                grouped.values(),
            )
                .map(row => ({
                    ...row,

                    percent:
                        row.total > 0
                            ? Math.round(
                                (
                                    row.attended /
                                    row.total
                                ) *
                                100,
                            )
                            : 0,
                }))
                .sort(
                    (
                        first,
                        second,
                    ) =>
                        second.total -
                        first.total,
                )
        },
    )

const COLORS = { primary: '#285F6b', soft: '#9dbfc6', track: '#e5eaec' }

const statCards = computed(() => [
    { label: 'Total Registered', value: totalRegistered.value, hint: 'Issued attendee tickets', icon: 'users' },
    { label: 'Total Attended', value: totalAttended.value, hint: 'Check-ins completed', icon: 'check-circle' },
    { label: 'Attendance Rate', value: `${attendanceRate.value}%`, hint: 'Registered vs attended', icon: 'chart-bar' },
    { label: 'Not Yet Attended', value: notAttended.value, hint: 'Registered attendees remaining', icon: 'clock' },
])

const gaugeOptions = computed<ApexOptions>(() => ({
    chart: { type: 'radialBar', fontFamily: 'inherit', animations: { speed: 900 } },
    colors: [COLORS.primary],
    plotOptions: {
        radialBar: {
            startAngle: -135,
            endAngle: 135,
            hollow: { size: '66%' },
            track: { background: COLORS.track, strokeWidth: '100%' },
            dataLabels: {
                name: { show: true, offsetY: 24, color: '#6b7280', fontSize: '12px', fontWeight: 700 },
                value: { offsetY: -16, color: '#111827', fontSize: '38px', fontWeight: 800, formatter: (value: number) => `${value}%` },
            },
        },
    },
    stroke: { lineCap: 'round' },
    labels: ['attendance rate'],
}))

const typeSeries = computed(() => [
    { name: 'Checked in', data: byTicketType.value.map(row => row.attended) },
    { name: 'Not yet attended', data: byTicketType.value.map(row => Math.max(row.total - row.attended, 0)) },
])

const typeChartHeight = computed(() => Math.max(150, byTicketType.value.length * 64 + 70))

const typeOptions = computed<ApexOptions>(() => ({
    chart: { type: 'bar', stacked: true, toolbar: { show: false }, fontFamily: 'inherit', animations: { speed: 700 } },
    colors: [COLORS.primary, COLORS.track],
    plotOptions: { bar: { horizontal: true, barHeight: '46%', borderRadius: 6, borderRadiusApplication: 'around' } },
    dataLabels: { enabled: false },
    stroke: { width: 2, colors: ['#fff'] },
    grid: { borderColor: '#f1f5f9', strokeDashArray: 4, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
    xaxis: {
        categories: byTicketType.value.map(row => row.type),
        labels: { style: { colors: '#9ca3af', fontSize: '11px' }, formatter: (value: string) => String(Math.round(Number(value))) },
        axisBorder: { show: false },
        axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: '#374151', fontSize: '13px', fontWeight: 700 } } },
    legend: { position: 'bottom', fontSize: '13px', fontWeight: 600, markers: { size: 8 } },
    tooltip: { shared: true, intersect: false, y: { formatter: (value: number) => `${value} attendee${value === 1 ? '' : 's'}` } },
}))

const sourceOptions = computed<ApexOptions>(() => ({
    chart: { type: 'donut', fontFamily: 'inherit' },
    labels: ['Online', 'Walk-ins'],
    colors: [COLORS.primary, COLORS.soft],
    dataLabels: { enabled: false },
    stroke: { width: 3, colors: ['#fff'] },
    legend: { show: false },
    plotOptions: {
        pie: {
            donut: {
                size: '72%',
                labels: {
                    show: true,
                    name: { fontSize: '12px', fontWeight: 700, color: '#6b7280' },
                    value: { fontSize: '26px', fontWeight: 800, color: '#111827' },
                    total: { show: true, label: 'Registered', color: '#6b7280', fontSize: '12px', fontWeight: 700, formatter: () => String(onlineCount.value + walkInCount.value) },
                },
            },
        },
    },
    tooltip: { y: { formatter: (value: number) => `${value} attendee${value === 1 ? '' : 's'}` } },
}))

const statusFilter = ref<'all' | 'checked_in' | 'registered'>('all')

const statusOptions = computed(() => [
    { label: 'All', value: 'all' as const, count: attendees.value.length },
    { label: 'Checked-in', value: 'checked_in' as const, count: attendees.value.filter(a => a.status === 'checked_in').length },
    { label: 'Registered', value: 'registered' as const, count: attendees.value.filter(a => a.status !== 'checked_in').length },
])

const filteredAttendees = computed(() => {
    if (statusFilter.value === 'checked_in') return attendees.value.filter(a => a.status === 'checked_in')
    if (statusFilter.value === 'registered') return attendees.value.filter(a => a.status !== 'checked_in')
    return attendees.value
})

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

function getApiErrorMessage(
    error: unknown,
    fallback: string,
): string {
    const apiError =
        error as ApiErrorResponse

    const validationMessage =
        Object.values(
            apiError.data?.errors ??
            {},
        )[0]?.[0]

    return (
        validationMessage ??
        apiError.data?.message ??
        fallback
    )
}

/** The event's guest list (every ticket) as this report's attendee rows. */
async function loadAttendanceReport() {
    if (!selectedEventId.value) {
        attendees.value = []
        return
    }
    isLoading.value = true
    errorMessage.value = ''
    try {
        const org = await useOrganization().fetchOrganization()
        if (!org) throw new Error('Set up your organizer workspace first.')
        const ticketing = useOrganizerTicketing()
        const rows = await allItems(page => ticketing.guests(org.id, String(selectedEventId.value), { page }))
        attendees.value = rows.map(g => ({
            id: g.id,
            name: g.name,
            email: g.email,
            ticket_type: { id: 0, name: g.tier },
            ticket_id: g.id.replace(/-/g, '').slice(-10).toUpperCase(),
            attendee_category: g.admission_type === 'paid' ? 'paid' : g.admission_type === 'invited' ? 'invited' : 'free',
            source: g.source,
            payment_status: g.admission_type === 'paid' ? 'paid' : null,
            status: g.status === 'used' ? 'checked_in' : g.status === 'valid' ? 'registered' : 'rejected',
            checked_in_at: null,
            created_at: g.issued_at ?? '',
        })) as unknown as typeof attendees.value
    } catch (error: unknown) {
        attendees.value = []
        errorMessage.value = authMessage(error) || 'Unable to load attendance report.'
    } finally {
        isLoading.value = false
    }
}

function formatSource(
    source: string,
): string {
    if (
        source ===
        'walk_in'
    ) {
        return 'Walk-in'
    }

    if (
        source ===
        'online'
    ) {
        return 'Online'
    }

    return source ||
        'Unknown'
}

function formatCategory(
    category: Attendee['attendee_category'],
): string {
    return category.charAt(0).toUpperCase() + category.slice(1)
}

function categoryClass(
    category: Attendee['attendee_category'],
): string {
    switch (category) {
        case 'invited':
            return 'bg-gray-100 text-gray-600'
        case 'free':
            return 'bg-gray-100 text-gray-600'
        case 'paid':
            return 'bg-primary-50 text-primary-700'
    }
}

function formatCheckInDateTime(
    value: string,
): string {
    const date =
        new Date(value)

    return date.toLocaleString(
        'en-US',
        {
            month:
                'short',

            day:
                'numeric',

            year:
                'numeric',

            hour:
                'numeric',

            minute:
                '2-digit',
        },
    )
}

function escapeCsvValue(
    value:
        | string
        | number
        | null
        | undefined,
): string {
    const stringValue =
        String(
            value ?? '',
        )

    return `"${stringValue.replace(
        /"/g,
        '""',
    )}"`
}

function exportCsv() {
    if (
        !attendees.value.length
    ) {
        return
    }

    const headers = [
        'Name',
        'Email',
        'Ticket Type',
        'Category',
        'Ticket ID',
        'Source',
        'Status',
        'Payment Status',
        'Checked In At',
    ]

    const rows =
        attendees.value.map(
            attendee => [
                attendee.name,

                attendee.email ??
                '',

                attendee.ticket_type
                    ?.name ??
                'General Admission',

                formatCategory(attendee.attendee_category),

                attendee.ticket_id,

                formatSource(
                    attendee.source,
                ),

                attendee.status ===
                    'checked_in'
                    ? 'Checked-in'
                    : 'Registered',

                attendee.payment_status ?? 'Not required',

                attendee.checked_in_at
                    ? formatCheckInDateTime(
                        attendee.checked_in_at,
                    )
                    : '',
            ],
        )

    const csv =
        [
            headers,
            ...rows,
        ]
            .map(row =>
                row
                    .map(
                        escapeCsvValue,
                    )
                    .join(','),
            )
            .join('\n')

    const blob =
        new Blob(
            [csv],
            {
                type:
                    'text/csv;charset=utf-8;',
            },
        )

    const url =
        URL.createObjectURL(
            blob,
        )

    const link =
        document.createElement(
            'a',
        )

    const eventName =
        (
            selectedEvent.value
                ?.name ??
            'attendance-report'
        )
            .trim()
            .replace(
                /[^a-zA-Z0-9]+/g,
                '-',
            )
            .replace(
                /^-|-$/g,
                '',
            )
            .toLowerCase()

    link.href =
        url

    link.download =
        `${eventName}-attendance-report.csv`

    document.body.appendChild(
        link,
    )

    link.click()

    document.body.removeChild(
        link,
    )

    URL.revokeObjectURL(
        url,
    )
}

watch(
    selectedEventId,
    async () => {
        attendees.value =
            []

        await loadAttendanceReport()
    },
    {
        immediate:
            true,
    },
)
</script>

<style scoped>
.ar-rise {
    animation: ar-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards;
    animation-delay: var(--d, 0ms);
}

.ar-row {
    animation: ar-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) backwards;
}

.ar-row:nth-child(2) { animation-delay: 50ms; }
.ar-row:nth-child(3) { animation-delay: 100ms; }
.ar-row:nth-child(4) { animation-delay: 150ms; }
.ar-row:nth-child(n + 5) { animation-delay: 200ms; }

.ar-card {
    transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.ar-card:hover {
    transform: translateY(-3px);
    border-color: rgba(40, 95, 107, 0.28);
    box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.ar-line {
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.ar-card:hover .ar-line {
    transform: scaleX(1);
}

@keyframes ar-rise {
    from { opacity: 0; transform: translateY(14px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
    .ar-rise, .ar-row { animation: none; }
    .ar-card, .ar-line { transition: none; }
}
</style>
