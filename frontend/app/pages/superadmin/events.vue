<template>
	<div>
		<SuperadminPageHeader title="Events" description="Every event across the platform, with its organizer, client, tickets and value." icon="calendar" />

		<div v-if="errorMessage" class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
			<SuperadminStatCard label="Total Events" :value="allEvents.length" icon="calendar"
				:hint="`${countByStatus('draft')} drafts, ${countByStatus('published')} published`" />
			<SuperadminStatCard label="Published" :value="countByStatus('published')" icon="check-circle"
				:hint="`${percentOf(countByStatus('published'))}% of all events`" />
			<SuperadminStatCard label="Completed" :value="countByStatus('completed')" icon="award"
				:hint="`${percentOf(countByStatus('completed'))}% completion rate`" />
			<SuperadminStatCard label="Ticket Sales" :value="formatCurrency(totalRevenue)" icon="cash" :hint="`The organizers' money · ${formatCurrency(avgRevenue)} avg. per event`" />
		</div>

		<!-- Charts -->
		<div v-if="allEvents.length" class="mb-6 grid grid-cols-1 gap-5 xl:grid-cols-3">
			<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 120ms">
				<h2 class="text-lg font-extrabold tracking-tight text-gray-950">Events by Status</h2>
				<p class="mt-0.5 text-sm text-gray-500">Where every event is in its lifecycle</p>
				<ClientOnly>
					<apexchart type="donut" height="260" :options="statusChartOptions" :series="statusChartSeries" />
				</ClientOnly>
			</div>

			<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 180ms">
				<h2 class="text-lg font-extrabold tracking-tight text-gray-950">Top Events by Value</h2>
				<p class="mt-0.5 text-sm text-gray-500">Accepted quotation value per event</p>
				<ClientOnly v-if="topValueEvents.length">
					<apexchart type="bar" height="260" :options="valueChartOptions" :series="valueChartSeries" />
				</ClientOnly>
				<p v-else class="py-16 text-center text-sm text-gray-400">No event value yet.</p>
			</div>

			<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 240ms">
				<h2 class="text-lg font-extrabold tracking-tight text-gray-950">Events by Type</h2>
				<p class="mt-0.5 text-sm text-gray-500">What kinds of events are being planned</p>
				<ClientOnly>
					<apexchart type="bar" height="260" :options="typeChartOptions" :series="typeChartSeries" />
				</ClientOnly>
			</div>
		</div>

		<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 300ms">
			<div class="mb-5">
				<h2 class="text-xl font-extrabold tracking-tight text-gray-950">All Events</h2>
				<p class="mt-1 text-sm text-gray-500">Showing {{ events.length }} of {{ allEvents.length }} event{{ allEvents.length === 1 ? '' : 's' }}. Click a row to open its organizer.</p>
			</div>

			<div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
				<div class="w-full lg:max-w-md">
					<FormsTextField v-model="search" icon="search" placeholder="Search events, organizers, clients..." />
				</div>

				<div class="chip-row flex flex-wrap items-center gap-2">
					<button v-for="filter in statusFilters" :key="filter.value" type="button"
						class="inline-flex min-h-9 items-center rounded-full px-3 py-1.5 text-xs font-semibold transition" :class="statusFilter === filter.value
							? 'bg-primary-700 text-white'
							: 'border border-gray-200 bg-white text-gray-600 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700'
							" @click="statusFilter = filter.value">
						{{ filter.label }}
						<span class="ml-1" :class="statusFilter === filter.value ? 'text-white/70' : 'text-gray-400'">{{ filter.value === 'all' ? allEvents.length : countByStatus(filter.value) }}</span>
					</button>
				</div>
			</div>

			<div class="mb-5 flex flex-wrap items-center justify-end gap-2">
				<span class="mr-1 text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Sort by</span>
				<button v-for="option in sortOptions" :key="option.value" type="button"
					class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold transition duration-200" :class="sortBy === option.value
						? 'bg-primary-700 text-white'
						: 'border border-gray-200 bg-white text-gray-600 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700'
						" @click="chooseSort(option.value)">
					{{ option.label }}
					<IconBase v-if="sortBy === option.value" name="chevron-down" class="h-3.5 w-3.5 transition-transform duration-200" :class="sortDir === 'asc' ? 'rotate-180' : ''" />
				</button>
			</div>

			<div v-if="isLoading" class="py-12 text-center text-sm text-gray-500">
				Loading events...
			</div>

			<div v-else-if="!events.length" class="rounded-2xl border border-dashed border-gray-300 px-6 py-14 text-center">
				<span class="sa-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
					<IconBase name="calendar" class="h-7 w-7" />
				</span>
				<p class="mt-4 text-sm font-bold text-gray-900">{{ search.trim() ? 'No events match your search' : 'No events found' }}</p>
				<p class="mx-auto mt-1 max-w-xs text-xs leading-5 text-gray-500">{{ search.trim() ? 'Try a different name, organizer or client.' : 'Events appear here once an organizer starts planning one.' }}</p>
			</div>

			<div v-else class="overflow-x-auto">
				<table class="w-full text-left text-sm">
					<thead>
						<tr
							class="bg-gray-50/80 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-400 [&>th]:py-3 [&>th:first-child]:rounded-l-xl [&>th:first-child]:pl-3 [&>th:last-child]:rounded-r-xl [&>th:last-child]:pr-3">
							<th class="pr-4">Event</th>
							<th class="pr-4">Organizer</th>
							<th class="pr-4">Client</th>
							<th class="pr-4">Date</th>
							<th class="pr-4">Tickets</th>
							<th class="pr-4">Ticket sales</th>
							<th>Status</th>
						</tr>
					</thead>

					<tbody class="divide-y divide-gray-100">
						<tr v-for="event in pagedEvents" :key="event.id" class="sa-row group cursor-pointer transition-colors hover:bg-primary-50/40"
							@click="navigateTo(`/superadmin/organizers/${event.organizer_id}`)">
							<td class="py-4 pl-3 pr-4">
								<div class="font-bold text-gray-950 transition group-hover:text-primary-700">{{ event.name }}</div>
								<div class="mt-1 flex items-center gap-2">
									<span v-if="event.event_type" class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-bold text-primary-700">{{ event.event_type }}</span>
									<span class="text-xs text-gray-400">{{ event.expected_guests }} guests</span>
								</div>
							</td>

							<td class="py-4 pr-4">
								<div class="flex items-center gap-2.5">
									<SuperadminAvatar :name="event.organizer" size="sm" />
									<span class="font-semibold text-gray-700">{{ event.organizer }}</span>
								</div>
							</td>

							<td class="py-4 pr-4">
								<div class="flex items-center gap-2.5">
									<SuperadminAvatar :name="event.client" size="sm" soft />
									<span class="font-semibold text-gray-700">{{ event.client }}</span>
								</div>
							</td>

							<td class="py-4 pr-4">
								<div class="font-semibold text-gray-800">{{ formatDate(event.event_date) }}</div>
								<div class="mt-0.5 text-xs text-gray-400">{{ relativeDay(event.event_date) }}</div>
							</td>

							<td class="py-4 pr-4">
								<div v-if="event.capacity" class="flex items-center gap-2.5">
									<ClientOnly>
										<apexchart type="donut" width="46" height="46" :options="ticketRingOptions" :series="ticketRingSeries(event)" />
									</ClientOnly>
									<div>
										<div class="font-bold text-gray-900">{{ event.tickets_sold.toLocaleString() }}<span class="font-semibold text-gray-400"> / {{ event.capacity.toLocaleString() }}</span></div>
										<div class="text-[11px] font-semibold text-gray-400">{{ ticketPercent(event) }}% sold</div>
									</div>
								</div>
								<span v-else class="text-xs text-gray-400">No ticket types</span>
							</td>

							<td class="py-4 pr-4">
								<div class="font-extrabold text-gray-950">{{ formatCurrency(event.revenue) }}</div>
								<div class="mt-1.5 h-1.5 w-24 overflow-hidden rounded-full bg-gray-100">
									<div class="h-full rounded-full bg-primary-700 transition-all duration-700" :style="{ width: valueShare(event) + '%' }" />
								</div>
							</td>

							<td class="py-4 pr-3">
								<span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(event.status)">
									<span class="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
									{{ formatStatus(event.status) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="pageCount > 1" class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-5">
					<p class="text-xs font-semibold text-gray-400">
						Showing {{ (page - 1) * PAGE_SIZE + 1 }}–{{ Math.min(page * PAGE_SIZE, sortedEvents.length) }} of {{ sortedEvents.length }}
					</p>

					<div class="flex items-center gap-1.5">
						<button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-500 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="page === 1" aria-label="Previous page" @click="page--">
							<IconBase name="arrow-left" class="h-4 w-4" />
						</button>
						<button v-for="n in pageCount" :key="n" type="button" class="h-9 min-w-9 rounded-xl px-3 text-xs font-bold transition"
							:class="page === n ? 'bg-primary-700 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:border-primary-200 hover:bg-primary-50'" @click="page = n">{{ n }}</button>
						<button type="button" class="flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-500 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 disabled:cursor-not-allowed disabled:opacity-40" :disabled="page === pageCount" aria-label="Next page" @click="page++">
							<IconBase name="arrow-right" class="h-4 w-4" />
						</button>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'
import { superAdminService, type AdminPlatformEvent } from '~/services/superAdminService'

definePageMeta({
	layout: 'superadmin',
	middleware: ['superadmin'],
})

const { token } = useAuth('superadmin')

const events = ref<AdminPlatformEvent[]>([])
// the whole platform, loaded once: stat cards, chips and charts read this, so they don't change while the table is filtered
const allEvents = ref<AdminPlatformEvent[]>([])
const isLoading = ref(false)
const errorMessage = ref('')
const search = ref('')
const statusFilter = ref('all')

type SortField = 'date' | 'value' | 'tickets'

const sortBy = ref<SortField>('date')
const sortDir = ref<'asc' | 'desc'>('desc')
const page = ref(1)
const PAGE_SIZE = 8
const sortOptions: Array<{ label: string, value: SortField }> = [
	{ label: 'Date', value: 'date' },
	{ label: 'Ticket sales', value: 'value' },
	{ label: 'Tickets Sold', value: 'tickets' },
]

let searchTimeout: ReturnType<typeof setTimeout> | null = null

const statusFilters = [
	{ label: 'All', value: 'all' },
	{ label: 'Draft', value: 'draft' },
	{ label: 'Published', value: 'published' },
	{ label: 'Completed', value: 'completed' },
	{ label: 'Cancelled', value: 'cancelled' },
]

const totalRevenue = computed(() => allEvents.value.reduce((sum, e) => sum + e.revenue, 0))
const avgRevenue = computed(() => (allEvents.value.length ? Math.round(totalRevenue.value / allEvents.value.length) : 0))

function countByStatus(status: string) {
	return allEvents.value.filter(e => e.status === status).length
}

function percentOf(count: number) {
	if (!allEvents.value.length) return 0
	return Math.round((count / allEvents.value.length) * 100)
}

// One teal palette for every chart: strong = active, deep = finished, soft = in progress, muted = inactive.
// Colors: utils/chartColors.ts
const CHART_COLORS = { strong: CHART.brand, soft: CHART.brand }
const statusColor = eventStatusColor


const chartText = { colors: '#6b7280', fontSize: '12px', fontWeight: 600 }

const presentStatuses = computed(() =>
	[...new Set(allEvents.value.map(event => event.status))],
)

const statusChartSeries = computed(() => presentStatuses.value.map(status => countByStatus(status)))

const statusChartOptions = computed<ApexOptions>(() => ({
	chart: { type: 'donut', fontFamily: 'inherit' },
	labels: presentStatuses.value.map(formatStatus),
	colors: presentStatuses.value.map(statusColor),
	dataLabels: { enabled: false },
	stroke: { width: 3, colors: ['#fff'] },
	legend: { position: 'bottom', fontSize: '13px', fontWeight: 600, markers: { size: 8 } },
	plotOptions: {
		pie: {
			donut: {
				size: '72%',
				labels: {
					show: true,
					name: { fontSize: '12px', fontWeight: 700, color: '#6b7280' },
					value: { fontSize: '26px', fontWeight: 800, color: '#111827' },
					total: { show: true, label: 'Events', color: '#6b7280', fontSize: '12px', fontWeight: 700, formatter: () => String(allEvents.value.length) },
				},
			},
		},
	},
	tooltip: { y: { formatter: (value: number) => `${value} event${value === 1 ? '' : 's'}` } },
}))

const topValueEvents = computed(() =>
	[...allEvents.value].filter(event => event.revenue > 0).sort((a, b) => b.revenue - a.revenue).slice(0, 6),
)

const valueChartSeries = computed(() => [{ name: 'Value', data: topValueEvents.value.map(event => event.revenue) }])

const valueChartOptions = computed<ApexOptions>(() => ({
	chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
	colors: [CHART_COLORS.strong],
	plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 6, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
	dataLabels: { enabled: true, textAnchor: 'start', offsetX: 8, formatter: (value: number) => formatCurrency(value), style: { fontSize: '11px', fontWeight: 700, colors: [CHART_COLORS.strong] } },
	grid: { show: false, padding: { left: 4, right: 58, top: -10 } },
	xaxis: { categories: topValueEvents.value.map(event => (event.name.length > 16 ? `${event.name.slice(0, 15)}…` : event.name)), labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
	yaxis: { labels: { minWidth: 110, maxWidth: 130, style: { colors: '#374151', fontSize: '12px', fontWeight: 700 } } },
	tooltip: { y: { formatter: (value: number) => formatCurrency(value) } },
	states: { hover: { filter: { type: 'none' } } },
}))

const eventTypes = computed(() => {
	const counts = new Map<string, number>()

	for (const event of allEvents.value) {
		const type = event.event_type || 'Other'
		counts.set(type, (counts.get(type) ?? 0) + 1)
	}

	return [...counts.entries()].sort((a, b) => b[1] - a[1])
})

const typeChartSeries = computed(() => [{ name: 'Events', data: eventTypes.value.map(([, count]) => count) }])

const typeChartOptions = computed<ApexOptions>(() => ({
	chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
	colors: [CHART_COLORS.soft],
	plotOptions: { bar: { columnWidth: '46%', borderRadius: 6, borderRadiusApplication: 'end' } },
	dataLabels: { enabled: false },
	grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
	xaxis: { categories: eventTypes.value.map(([type]) => type), labels: { style: chartText }, axisBorder: { show: false }, axisTicks: { show: false } },
	yaxis: { labels: { style: { colors: '#9ca3af', fontSize: '11px' }, formatter: (value: number) => String(Math.round(value)) }, forceNiceScale: true },
	tooltip: { y: { formatter: (value: number) => `${value} event${value === 1 ? '' : 's'}` } },
}))

const sortedEvents = computed(() => {
	const list = [...events.value]
	const direction = sortDir.value === 'desc' ? 1 : -1

	if (sortBy.value === 'value') {
		return list.sort((a, b) => (b.revenue - a.revenue) * direction)
	}

	if (sortBy.value === 'tickets') {
		return list.sort((a, b) => (b.tickets_sold - a.tickets_sold) * direction)
	}

	return list.sort((a, b) => {
		const aTime = a.event_date ? new Date(a.event_date).getTime() : 0
		const bTime = b.event_date ? new Date(b.event_date).getTime() : 0
		return (bTime - aTime) * direction
	})
})

const pageCount = computed(() => Math.max(1, Math.ceil(sortedEvents.value.length / PAGE_SIZE)))
const pagedEvents = computed(() => sortedEvents.value.slice((page.value - 1) * PAGE_SIZE, page.value * PAGE_SIZE))

// choosing the active sort again flips its direction
function chooseSort(field: SortField) {
	if (sortBy.value === field) {
		sortDir.value = sortDir.value === 'desc' ? 'asc' : 'desc'
		return
	}

	sortBy.value = field
	sortDir.value = 'desc'
}

watch([sortBy, sortDir, events], () => {
	page.value = 1
})

const maxRevenue = computed(() => Math.max(1, ...allEvents.value.map(event => event.revenue)))

function valueShare(event: AdminPlatformEvent) {
	return event.revenue > 0 ? Math.max(6, Math.round((event.revenue / maxRevenue.value) * 100)) : 0
}

function ticketPercent(event: AdminPlatformEvent) {
	if (!event.capacity) return 0
	const percent = (event.tickets_sold / event.capacity) * 100
	return percent > 0 && percent < 1 ? Math.round(percent * 10) / 10 : Math.round(percent)
}

// [sold, remaining]; a sliver is shown when only a few tickets are sold so the ring never looks empty
function ticketRingSeries(event: AdminPlatformEvent): number[] {
	const sold = Math.min(event.tickets_sold, event.capacity)
	const minimum = event.capacity * 0.04
	const arc = sold > 0 ? Math.max(sold, minimum) : 0

	return [arc, Math.max(event.capacity - arc, 0.0001)]
}

const ticketRingOptions: ApexOptions = {
	chart: { type: 'donut', sparkline: { enabled: true }, animations: { speed: 700 } },
	colors: [CHART.brand, CHART.track],
	labels: ['Sold', 'Available'],
	dataLabels: { enabled: false },
	stroke: { width: 0 },
	legend: { show: false },
	plotOptions: { pie: { donut: { size: '66%' } } },
	tooltip: { enabled: false },
	states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } },
}

function relativeDay(value: string | null) {
	if (!value) return ''

	const days = Math.round((new Date(value).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) / 86_400_000)

	if (days === 0) return 'Today'
	if (days === 1) return 'Tomorrow'
	if (days === -1) return 'Yesterday'
	return days > 0 ? `In ${days} days` : `${Math.abs(days)} days ago`
}

async function loadEvents() {
	if (!token.value) {
		return
	}

	isLoading.value = true
	errorMessage.value = ''

	try {
		const response = await superAdminService.getEvents(token.value, search.value.trim(), statusFilter.value)
		events.value = response.data
	} catch (error: unknown) {
		errorMessage.value = getApiErrorMessage(error, 'Unable to load events.')
	} finally {
		isLoading.value = false
	}
}

function getApiErrorMessage(error: unknown, fallback: string): string {
	const apiError = error as { data?: { message?: string } }
	return apiError?.data?.message || fallback
}

function formatCurrency(amount: number) {
	if (amount >= 1000000) return `₱${(amount / 1000000).toFixed(1)}M`
	if (amount >= 1000) return `₱${(amount / 1000).toFixed(0)}K`
	return `₱${amount}`
}

function formatDate(value: string | null) {
	if (!value) return 'Not scheduled'
	return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatStatus(status: string) {
	return status.charAt(0).toUpperCase() + status.slice(1)
}

function statusClass(status: string) {
	switch (status) {
		case 'completed':
			return 'bg-primary-700 text-white'
		case 'published':
		case 'ongoing':
			return 'bg-primary-50 text-primary-700'
		case 'cancelled':
			return 'bg-red-50 text-red-600'
		default:
			return 'bg-gray-100 text-gray-600'
	}
}

watch(search, () => {
	if (searchTimeout) clearTimeout(searchTimeout)
	searchTimeout = setTimeout(loadEvents, 350)
})

watch(statusFilter, () => {
	loadEvents()
})

onMounted(async () => {
	await loadEvents()
	allEvents.value = [...events.value]
})
</script>

<style scoped>
.sa-rise {
	animation: sa-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards;
	animation-delay: var(--d, 0ms);
}

.sa-row {
	animation: sa-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) backwards;
}

.sa-row:nth-child(2) { animation-delay: 40ms; }
.sa-row:nth-child(3) { animation-delay: 80ms; }
.sa-row:nth-child(4) { animation-delay: 120ms; }
.sa-row:nth-child(5) { animation-delay: 160ms; }
.sa-row:nth-child(n + 6) { animation-delay: 200ms; }

@keyframes sa-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

.sa-float {
	animation: sa-float 3.2s ease-in-out infinite;
}

@keyframes sa-rise {
	from { opacity: 0; transform: translateY(12px); }
	to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
	.sa-rise, .sa-row, .sa-float { animation: none; }
}
</style>
