<template>
	<div>
		<SuperadminPageHeader title="Analytics" description="Revenue, signups and how events and quotations are progressing across the platform." icon="chart-bar" />

		<div v-if="isLoading" class="rounded-2xl border border-gray-100 bg-white p-10 text-center text-sm text-gray-500">
			Loading analytics...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else-if="analytics">
			<div class="mb-5 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
				<SuperadminStatCard label="NaSeRy Revenue (12 months)" :value="formatCompactCurrency(sixMonthRevenue)" icon="cash" hint="Ticket fees + plan subscriptions" />
				<SuperadminStatCard label="New Signups" :value="sixMonthSignups" icon="users"
					hint="Organizers + clients, last 12 months" />
				<SuperadminStatCard label="Total Events" :value="totalEvents" icon="calendar"
					:hint="`${percentOf(publishedOrOngoingEvents, totalEvents)}% live or published`" />
				<SuperadminStatCard label="Quotation Win Rate" :value="`${percentOf(acceptedQuotations, totalQuotations)}%`"
					icon="award" hint="Accepted vs. all quotations" />
			</div>

			<div class="mb-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
				<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 140ms">
					<h2 class="text-lg font-extrabold tracking-tight text-gray-950">NaSeRy Revenue Trend</h2>
					<p class="mt-1 text-sm text-gray-500">Ticket fees + plan subscriptions per month (ticket money itself goes to organizers)</p>

					<div v-if="maxRevenue > 0" class="mt-4">
						<ClientOnly>
							<apexchart type="area" height="260" :options="revenueChartOptions" :series="revenueChartSeries" />
						</ClientOnly>
					</div>
					<p v-else class="mt-8 py-10 text-center text-sm text-gray-400">No accepted quotations yet.</p>
				</div>

				<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 200ms">
					<div class="mb-1 flex items-center justify-between">
						<h2 class="text-lg font-extrabold tracking-tight text-gray-950">Platform Growth</h2>
						<div class="flex items-center gap-3 text-xs font-semibold text-gray-500">
							<span class="flex items-center gap-1.5">
								<span class="h-2.5 w-2.5 rounded-full" style="background-color: #285F6b" />
								Organizers
							</span>
							<span class="flex items-center gap-1.5">
								<span class="h-2.5 w-2.5 rounded-full" style="background-color: #7bacb2" />
								Clients
							</span>
						</div>
					</div>
					<p class="mt-1 text-sm text-gray-500">New signups, last 12 months</p>

					<div v-if="maxSignups > 0" class="mt-4">
						<ClientOnly>
							<apexchart type="line" height="260" :options="growthChartOptions" :series="growthChartSeries" />
						</ClientOnly>
					</div>
					<p v-else class="mt-8 py-10 text-center text-sm text-gray-400">No signups yet.</p>
				</div>
			</div>

			<div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
				<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 260ms">
					<h2 class="text-lg font-extrabold tracking-tight text-gray-950">Events by Status</h2>
					<p class="mt-1 text-sm text-gray-500">How events are distributed across their lifecycle</p>

					<div v-if="analytics.events_by_status.length" class="mt-2 flex flex-col items-center gap-4 sm:flex-row">
						<ClientOnly>
							<apexchart type="donut" width="190" height="190" :options="eventDonutOptions" :series="eventDonutSeries" />
						</ClientOnly>

						<div class="w-full flex-1 space-y-3">
							<div v-for="row in analytics.events_by_status" :key="row.status" class="flex items-center justify-between gap-3">
								<span class="flex items-center gap-2 text-sm font-semibold capitalize text-gray-700">
									<span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: eventStatusColor(row.status) }" />
									{{ row.status }}
								</span>
								<span class="text-sm text-gray-500">{{ row.total }} · {{ percentOf(row.total, totalEvents) }}%</span>
							</div>
						</div>
					</div>
					<p v-else class="mt-6 text-sm text-gray-400">No events yet.</p>
				</div>

				<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 320ms">
					<h2 class="text-lg font-extrabold tracking-tight text-gray-950">Quotations by Status</h2>
					<p class="mt-1 text-sm text-gray-500">Win rate across every bid submitted platform-wide</p>

					<div v-if="analytics.quotations_by_status.length" class="mt-2 flex flex-col items-center gap-4 sm:flex-row">
						<ClientOnly>
							<apexchart type="donut" width="190" height="190" :options="quotationDonutOptions" :series="quotationDonutSeries" />
						</ClientOnly>

						<div class="w-full flex-1 space-y-3">
							<div v-for="row in analytics.quotations_by_status" :key="row.status" class="flex items-center justify-between gap-3">
								<span class="flex items-center gap-2 text-sm font-semibold capitalize text-gray-700">
									<span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: quotationStatusColor(row.status) }" />
									{{ row.status }}
								</span>
								<span class="text-sm text-gray-500">{{ row.total }} · {{ percentOf(row.total, totalQuotations) }}%</span>
							</div>
						</div>
					</div>
					<p v-else class="mt-6 text-sm text-gray-400">No quotations yet.</p>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'
import { superAdminService, type AdminAnalytics } from '~/services/superAdminService'

definePageMeta({
	layout: 'superadmin',
	middleware: ['superadmin'],
})

const { token } = useAuth('superadmin')

const analytics = ref<AdminAnalytics | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')

const maxRevenue = computed(() => Math.max(0, ...(analytics.value?.revenue_by_month.map(p => p.value) ?? [0])))
const maxSignups = computed(() => Math.max(0, ...(analytics.value?.signups_by_month.flatMap(p => [p.organizers, p.clients]) ?? [0])))
const totalEvents = computed(() => (analytics.value?.events_by_status ?? []).reduce((sum, r) => sum + r.total, 0))
const totalQuotations = computed(() => (analytics.value?.quotations_by_status ?? []).reduce((sum, r) => sum + r.total, 0))

const sixMonthRevenue = computed(() => (analytics.value?.revenue_by_month ?? []).reduce((sum, p) => sum + p.value, 0))
const sixMonthSignups = computed(() =>
	(analytics.value?.signups_by_month ?? []).reduce((sum, p) => sum + p.organizers + p.clients, 0),
)
const publishedOrOngoingEvents = computed(() =>
	(analytics.value?.events_by_status ?? [])
		.filter(row => row.status === 'published' || row.status === 'ongoing')
		.reduce((sum, r) => sum + r.total, 0),
)
const acceptedQuotations = computed(() =>
	(analytics.value?.quotations_by_status ?? []).find(row => row.status === 'accepted')?.total ?? 0,
)

// One teal palette for every chart. Same meaning everywhere:
// strong = active / positive, deep = finished, soft = in progress, muted = inactive / lost.
const CHART_COLORS = { strong: '#285F6b', deep: '#103f4d', soft: '#7bacb2', muted: '#cdd9dc' }

const chartLabelStyle = { colors: '#9ca3af', fontSize: '12px', fontWeight: 600 }

const revenueChartSeries = computed(() => [{
	name: 'Revenue',
	data: (analytics.value?.revenue_by_month ?? []).map(p => p.value),
}])

const revenueChartOptions = computed<ApexOptions>(() => ({
	chart: { type: 'area', toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false } },
	colors: [CHART_COLORS.strong],
	dataLabels: { enabled: false },
	stroke: { curve: 'smooth', width: 2.5 },
	fill: {
		type: 'gradient',
		gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0, stops: [0, 90, 100] },
	},
	grid: { borderColor: '#f1f5f9', strokeDashArray: 4, padding: { left: 8, right: 8 } },
	markers: { size: 4, strokeWidth: 2, strokeColors: '#fff', hover: { size: 6 } },
	xaxis: {
		categories: (analytics.value?.revenue_by_month ?? []).map(p => p.label),
		axisBorder: { show: false },
		axisTicks: { show: false },
		labels: { style: chartLabelStyle },
	},
	yaxis: {
		labels: { formatter: (value: number) => formatCompactCurrency(value), style: { colors: '#9ca3af', fontSize: '11px' } },
	},
	tooltip: { y: { formatter: (value: number) => formatCurrency(value) } },
}))

const growthChartSeries = computed(() => [
	{ name: 'Organizers', data: (analytics.value?.signups_by_month ?? []).map(p => p.organizers) },
	{ name: 'Clients', data: (analytics.value?.signups_by_month ?? []).map(p => p.clients) },
])

const growthChartOptions = computed<ApexOptions>(() => ({
	chart: { type: 'line', toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false } },
	colors: [CHART_COLORS.strong, CHART_COLORS.soft],
	dataLabels: { enabled: false },
	stroke: { curve: 'smooth', width: [2.5, 2.5], dashArray: [0, 5] },
	legend: { show: false },
	grid: { borderColor: '#f1f5f9', strokeDashArray: 4, padding: { left: 8, right: 8 } },
	markers: { size: 4, strokeWidth: 2, strokeColors: '#fff', hover: { size: 6 } },
	xaxis: {
		categories: (analytics.value?.signups_by_month ?? []).map(p => p.label),
		axisBorder: { show: false },
		axisTicks: { show: false },
		labels: { style: chartLabelStyle },
	},
	yaxis: {
		forceNiceScale: true,
		labels: { formatter: (value: number) => Math.round(value).toString(), style: { colors: '#9ca3af', fontSize: '11px' } },
	},
	tooltip: { shared: true, intersect: false },
}))

function capitalize(value: string) {
	return value.charAt(0).toUpperCase() + value.slice(1)
}

function donutOptions(labels: string[], colors: string[], total: number): ApexOptions {
	return {
		chart: { type: 'donut', fontFamily: 'inherit' },
		labels,
		colors,
		dataLabels: { enabled: false },
		stroke: { width: 2, colors: ['#fff'] },
		legend: { show: false },
		plotOptions: {
			pie: {
				donut: {
					size: '72%',
					labels: {
						show: true,
						total: {
							show: true,
							label: 'Total',
							color: '#111827',
							fontSize: '20px',
							fontWeight: 800,
							formatter: () => String(total),
						},
						value: { fontSize: '20px', fontWeight: 800, color: '#111827' },
					},
				},
			},
		},
		tooltip: { y: { formatter: (value: number) => `${value} (${percentOf(value, total)}%)` } },
	}
}

const eventDonutSeries = computed(() => (analytics.value?.events_by_status ?? []).map(row => row.total))
const eventDonutOptions = computed<ApexOptions>(() => donutOptions(
	(analytics.value?.events_by_status ?? []).map(row => capitalize(row.status)),
	(analytics.value?.events_by_status ?? []).map(row => eventStatusColor(row.status)),
	totalEvents.value,
))

const quotationDonutSeries = computed(() => (analytics.value?.quotations_by_status ?? []).map(row => row.total))
const quotationDonutOptions = computed<ApexOptions>(() => donutOptions(
	(analytics.value?.quotations_by_status ?? []).map(row => capitalize(row.status)),
	(analytics.value?.quotations_by_status ?? []).map(row => quotationStatusColor(row.status)),
	totalQuotations.value,
))

function percentOf(value: number, total: number) {
	if (!total) return 0
	return Math.round((value / total) * 100)
}

async function loadAnalytics() {
	if (!token.value) {
		return
	}

	isLoading.value = true
	errorMessage.value = ''

	try {
		const response = await superAdminService.getAnalytics(token.value)
		analytics.value = response.data
	} catch (error: unknown) {
		errorMessage.value = getApiErrorMessage(error, 'Unable to load analytics.')
	} finally {
		isLoading.value = false
	}
}

function getApiErrorMessage(error: unknown, fallback: string): string {
	const apiError = error as { data?: { message?: string } }
	return apiError?.data?.message || fallback
}

function formatCurrency(amount: number) {
	return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(amount)
}

function formatCompactCurrency(amount: number) {
	if (amount >= 1000000) return `₱${(amount / 1000000).toFixed(1)}M`
	if (amount >= 1000) return `₱${(amount / 1000).toFixed(0)}K`
	return `₱${amount}`
}

function eventStatusColor(status: string) {
	switch (status) {
		case 'published':
		case 'ongoing':
			return CHART_COLORS.strong
		case 'completed':
			return CHART_COLORS.deep
		case 'cancelled':
			return CHART_COLORS.muted
		default:
			return CHART_COLORS.soft
	}
}

function quotationStatusColor(status: string) {
	switch (status) {
		case 'accepted':
			return CHART_COLORS.strong
		case 'rejected':
		case 'withdrawn':
			return CHART_COLORS.muted
		default:
			return CHART_COLORS.soft
	}
}

onMounted(() => {
	loadAnalytics()
})
</script>

<style scoped>
.sa-rise {
	animation: sa-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards;
	animation-delay: var(--d, 0ms);
}

@keyframes sa-rise {
	from { opacity: 0; transform: translateY(12px); }
	to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
	.sa-rise { animation: none; }
}
</style>
