<template>
	<div class="space-y-6">
		<div v-if="isLoading" class="rounded-3xl border border-gray-200 bg-white p-12 text-center text-sm text-gray-500">
			Loading live events...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<div v-else-if="!events.length" class="rounded-3xl border border-dashed border-gray-300 bg-white px-5 py-16 text-center">
			<span class="le-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="calendar" class="h-7 w-7" />
			</span>
			<p class="mt-4 text-sm font-bold text-gray-900">No events yet</p>
			<p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-gray-500">
				Create an event in Event Management to see its live check-in activity here.
			</p>
			<NuxtLink to="/organizer/eventmanagement/all-events" class="mt-3 inline-block text-sm font-semibold text-primary-700 hover:underline">Go to Event Management</NuxtLink>
		</div>

		<template v-else>
			<!-- Selector -->
			<div class="le-rise relative z-30 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
				<div class="w-full sm:max-w-sm">
					<FormsSelect v-model="selectedEventId" :options="eventOptions" :can-clear="false"
						placeholder="Select an event" />
				</div>

				<span v-if="selectedEvent" class="inline-flex w-fit items-center gap-2 rounded-full px-3.5 py-1.5 text-xs font-bold"
					:class="eventStatusClass(selectedEvent.status)">
					<span class="relative flex h-2 w-2">
						<span v-if="selectedEvent.status === 'ongoing'" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary-700 opacity-60" />
						<span class="relative inline-flex h-2 w-2 rounded-full bg-current opacity-80" />
					</span>
					{{ formatEventStatus(selectedEvent.status) }}
				</span>
			</div>

			<div v-if="selectedEvent && selectedEvent.status !== 'ongoing'" class="le-rise flex items-start gap-3 rounded-2xl border border-gray-200 bg-white px-4 py-3.5" style="--d: 60ms">
				<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
					<IconBase name="clock" class="h-4 w-4" />
				</span>
				<p class="pt-1 text-sm leading-6 text-gray-600">
					This event is marked <strong class="text-gray-900">{{ formatEventStatus(selectedEvent.status) }}</strong>, not "Ongoing" yet. The numbers below are real, but check-in activity usually starts once you mark the event Ongoing in Event Management.
				</p>
			</div>

			<!-- Analytics -->
			<section v-if="selectedEvent" class="le-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 100ms">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div>
						<h2 class="text-xl font-extrabold tracking-tight text-gray-950">{{ selectedEvent.name }}</h2>
						<p class="mt-0.5 text-sm text-gray-500">Real-time event analytics</p>
					</div>
				</div>

				<div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
					<div v-for="tile in tiles" :key="tile.label" class="le-tile rounded-2xl bg-gray-50 p-4">
						<div class="flex items-center justify-between">
							<span class="text-xs font-bold uppercase tracking-[0.08em] text-gray-400">{{ tile.label }}</span>
							<IconBase :name="tile.icon" class="h-4 w-4 text-primary-700" />
						</div>
						<div class="mt-2 text-3xl font-extrabold tracking-tight text-gray-950">{{ tile.value }}</div>
					</div>
				</div>

				<div class="mt-8 grid grid-cols-1 items-center gap-8 lg:grid-cols-2">
					<ClientOnly>
						<apexchart type="radialBar" height="290" :options="gaugeOptions" :series="gaugeSeries" />
					</ClientOnly>

					<div class="space-y-7">
						<div>
							<div class="mb-2 flex items-center justify-between text-sm">
								<span class="font-bold text-gray-900">Check-in progress</span>
								<span class="font-semibold text-gray-500">{{ summary.checked_in }} / {{ summary.total_registered }}</span>
							</div>
							<div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100">
								<div class="le-bar h-full rounded-full bg-[#285F6b]" :style="{ width: checkinProgress + '%' }" />
							</div>
						</div>

						<div>
							<div class="mb-2 flex items-center justify-between text-sm">
								<span class="font-bold text-gray-900">Overall capacity</span>
								<span class="font-semibold text-gray-500">{{ summary.total_registered }} / {{ summary.capacity }}</span>
							</div>
							<div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100">
								<div class="le-bar h-full rounded-full bg-[#9dbfc6]" :style="{ width: Math.max(summary.capacity_percent, summary.total_registered ? 1.5 : 0) + '%' }" />
							</div>
						</div>

						<p class="text-xs leading-5 text-gray-400">
							Check-in progress is out of registered attendees. Capacity is out of all available spots.
						</p>
					</div>
				</div>
			</section>

			<!-- Activity -->
			<section v-if="selectedEvent" class="le-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 160ms">
				<div class="flex items-start justify-between gap-3">
					<div>
						<h2 class="text-xl font-extrabold tracking-tight text-gray-950">
							Live Check-in Activity
						</h2>
						<p class="mb-5 mt-0.5 text-sm text-gray-500">
							Recent attendee check-ins
						</p>
					</div>

					<span v-if="checkinActivity.length" class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-gray-600">{{ checkinActivity.length }}</span>
				</div>

				<div v-if="checkinActivity.length" class="space-y-1">
					<div v-for="(person, index) in checkinActivity" :key="person.id"
						class="le-row flex items-center gap-3.5 rounded-2xl px-3 py-3 transition-colors hover:bg-gray-50"
						:style="{ '--d': index * 45 + 'ms' }">
						<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-700">
							{{ getInitials(person.name) }}
						</span>

						<div class="min-w-0 flex-1">
							<div class="truncate text-sm font-bold text-gray-900">
								{{ person.name }}
							</div>
							<div class="text-xs text-gray-400">
								{{ formatRelativeTime(person.checked_in_at) }}
							</div>
						</div>

						<span class="shrink-0 rounded-full px-3 py-1 text-xs font-bold" :class="badgeClass(person.badge)">
							{{ person.badge }}
						</span>
					</div>
				</div>

				<div v-else class="rounded-2xl border border-dashed border-gray-300 px-5 py-12 text-center">
					<span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-50 text-gray-400">
						<IconBase name="check-circle" class="h-6 w-6" />
					</span>
					<p class="mt-3 text-sm font-bold text-gray-900">No check-ins yet</p>
					<p class="mt-1 text-xs text-gray-500">Attendees will appear here as they check in.</p>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'

definePageMeta({ layout: 'dashboard' })

const { token } = useAuth('organizer')
const config = useRuntimeConfig()

interface OrganizerEvent {
	id: number
	name: string
	status: string
}

interface TicketType {
	name: string
}

interface Attendee {
	id: number
	name: string
	ticket_type: TicketType | null
	source: string
	status: string
	checked_in_at: string | null
}

interface AttendeeSummary {
	total_registered: number
	checked_in: number
	walk_ins: number
	capacity: number
	capacity_percent: number
}

const isLoading = ref(false)
const errorMessage = ref('')
const events = ref<OrganizerEvent[]>([])
const selectedEventId = ref<number | null>(null)
const summary = ref<AttendeeSummary>({ total_registered: 0, checked_in: 0, walk_ins: 0, capacity: 0, capacity_percent: 0 })
const checkinActivity = ref<Array<{ id: number, name: string, checked_in_at: string, badge: string }>>([])

const selectedEvent = computed(() => events.value.find(event => event.id === selectedEventId.value) ?? null)
const eventOptions = computed(() => events.value.map(event => ({ value: event.id, label: `${event.name} (${formatEventStatus(event.status)})` })))

const checkinProgress = computed(() => {
	if (!summary.value.total_registered) return 0
	return Math.round((summary.value.checked_in / summary.value.total_registered) * 1000) / 10
})

const gaugeSeries = computed(() => [checkinProgress.value, summary.value.capacity_percent])

const gaugeOptions = computed<ApexOptions>(() => ({
	chart: { type: 'radialBar', fontFamily: 'inherit' },
	labels: ['Check-in', 'Capacity'],
	colors: ['#285F6b', '#9dbfc6'],
	stroke: { lineCap: 'round' },
	plotOptions: {
		radialBar: {
			hollow: { size: '44%' },
			track: { background: '#eef2f3', strokeWidth: '100%', margin: 6 },
			dataLabels: {
				name: { fontSize: '12px', fontWeight: 700, color: '#6b7280' },
				value: { fontSize: '20px', fontWeight: 800, color: '#111827', formatter: (value: number) => `${value}%` },
				total: {
					show: true,
					label: 'Check-in',
					fontSize: '12px',
					fontWeight: 700,
					color: '#6b7280',
					formatter: () => `${checkinProgress.value}%`,
				},
			},
		},
	},
	legend: { show: true, position: 'bottom', fontSize: '13px', fontWeight: 600, markers: { size: 8 } },
}))

const tiles = computed(() => [
	{ label: 'Checked in', value: summary.value.checked_in, icon: 'check-circle' },
	{ label: 'Registered', value: summary.value.total_registered, icon: 'users' },
	{ label: 'Walk-ins', value: summary.value.walk_ins, icon: 'user-plus' },
	{ label: 'Capacity fill', value: `${summary.value.capacity_percent}%`, icon: 'chart-bar' },
])

function getInitials(name: string): string {
	return name.split(' ').filter(Boolean).slice(0, 2).map(part => part[0]?.toUpperCase()).join('') || 'A'
}

function getApiErrorMessage(error: unknown, fallback: string): string {
	const apiError = error as { data?: { message?: string } }
	return apiError?.data?.message || fallback
}

function authHeaders() {
	return { Accept: 'application/json', Authorization: `Bearer ${token.value}` }
}

function badgeClass(badge: string) {
	if (badge === 'Walk-in') return 'bg-gray-100 text-gray-700'
	if (badge.toLowerCase().includes('vip')) return 'bg-primary-50 text-primary-700'
	return 'bg-gray-100 text-gray-600'
}

function formatEventStatus(status: string): string {
	if (!status) return 'Draft'
	return status.charAt(0).toUpperCase() + status.slice(1)
}

function eventStatusClass(status: string): string {
	switch (status) {
		case 'published':
		case 'confirmed':
		case 'ongoing':
			return 'bg-primary-50 text-primary-700'
		case 'completed':
			return 'bg-gray-100 text-gray-600'
		case 'cancelled':
			return 'bg-red-50 text-red-600'
		default:
			return 'bg-gray-100 text-gray-600'
	}
}

function formatRelativeTime(dateString: string) {
	const diffMs = Date.now() - new Date(dateString).getTime()
	const minutes = Math.floor(diffMs / 60000)
	if (minutes < 1) return 'Just now'
	if (minutes < 60) return `${minutes}m ago`
	const hours = Math.floor(minutes / 60)
	if (hours < 24) return `${hours}h ago`
	const days = Math.floor(hours / 24)
	return `${days}d ago`
}

let orgId = ''

async function loadEvents() {
	isLoading.value = true
	errorMessage.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId = org.id
		const list = await allItems(page => useOrganizerEvents().list(orgId, [], page))
		events.value = list.map(e => ({ id: e.id as unknown as number, name: e.name, status: e.status }))
		if (!events.value.length) {
			isLoading.value = false
			return
		}
		const ongoing = events.value.find(event => event.status === 'ongoing')
		const active = events.value.find(event => !['completed', 'cancelled'].includes(event.status))
		selectedEventId.value = (ongoing ?? active ?? events.value[0])!.id
		await loadEventData()
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load your events.'
		isLoading.value = false
	}
}

/** Live numbers and the latest arrivals, from the event's check-in desk. */
async function loadEventData() {
	if (!selectedEventId.value || !orgId) return
	isLoading.value = true
	errorMessage.value = ''
	try {
		const data = await useCheckIn().summary(orgId, String(selectedEventId.value))
		const event = await useOrganizerEvents().show(orgId, String(selectedEventId.value))
		const capacity = event.overall_capacity ?? data.expected
		summary.value = {
			total_registered: data.expected,
			checked_in: data.checked_in,
			walk_ins: data.walk_ins,
			capacity,
			capacity_percent: capacity ? Math.round((data.arrived / capacity) * 100) : 0,
		}
		checkinActivity.value = data.recent.slice(0, 8).map(r => ({
			id: r.id as unknown as number,
			name: r.name,
			checked_in_at: r.checked_in_at,
			badge: r.method === 'manual' ? `${r.tier} · manual` : r.tier,
		}))
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load live event data.'
	} finally {
		isLoading.value = false
	}
}

watch(selectedEventId, async (newId, oldId) => {
	if (newId === oldId || !newId) return
	await loadEventData()
})

onMounted(() => {
	loadEvents()
})
</script>

<style scoped>
.le-rise {
	animation: le-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.le-row {
	animation: le-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.le-tile {
	transition: transform 0.2s ease, background-color 0.2s ease;
}

.le-tile:hover {
	transform: translateY(-2px);
	background-color: #f3f6f7;
}

.le-bar {
	animation: le-grow 1s cubic-bezier(0.22, 1, 0.36, 1) both;
	transform-origin: left;
}

.le-float {
	animation: le-float 3.2s ease-in-out infinite;
}

@keyframes le-rise {
	from { opacity: 0; transform: translateY(14px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes le-grow {
	from { transform: scaleX(0); }
	to { transform: scaleX(1); }
}

@keyframes le-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
	.le-rise, .le-row, .le-bar, .le-float { animation: none; }
	.le-tile { transition: none; }
}
</style>
