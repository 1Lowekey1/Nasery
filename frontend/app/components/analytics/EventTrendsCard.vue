<template>
	<!-- NaSeRy-wide event trends (counts only): what clients ask for, month by month. -->
	<section class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div class="min-w-0">
				<h2 class="text-base font-bold text-gray-950">Event trends on NaSeRy</h2>
				<p class="mt-0.5 text-sm text-gray-500">{{ subtitle }}</p>
				<NuxtLink v-if="breakdownLink" to="/organizer/analyticsdashboard/event-types" class="mt-1 inline-flex min-h-8 items-center gap-1 text-xs font-semibold text-primary-700 hover:underline">Full breakdown <IconBase name="arrow-right" class="h-3 w-3" /></NuxtLink>
			</div>
			<div class="inline-flex rounded-xl bg-gray-100 p-1" role="tablist" aria-label="Period">
				<button v-for="m in PERIODS" :key="m" type="button" role="tab" :aria-selected="months === m"
					class="min-h-8 rounded-lg px-3 text-xs font-bold transition" :class="months === m ? 'bg-white text-primary-800 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
					@click="months = m">{{ m }} mo</button>
			</div>
		</div>

		<p v-if="error" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>
		<div v-else-if="loading && !data" class="mt-4 h-56 animate-pulse rounded-xl bg-gray-50" />
		<p v-else-if="data && !totalRequests && !totalEvents" class="mt-4 rounded-xl bg-gray-50 px-4 py-10 text-center text-sm text-gray-500">No event requests on NaSeRy in this period yet.</p>

		<div v-else-if="data" class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
			<!-- Over time: requests and booked events (two series, fixed colours) -->
			<div class="min-w-0">
				<dl class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
					<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: CHART.blue }" /><dt class="text-gray-500">Requests</dt><dd class="font-bold tabular-nums text-gray-950">{{ totalRequests }}</dd></div>
					<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: CHART.orange }" /><dt class="text-gray-500">Booked events</dt><dd class="font-bold tabular-nums text-gray-950">{{ totalEvents }}</dd></div>
				</dl>
				<ClientOnly>
					<DeferredChart type="line" :height="220" :options="lineOptions" :series="lineSeries" class="mt-1" />
				</ClientOnly>
			</div>

			<!-- By event type: one hue, sorted, counts written on (no colour-only reading) -->
			<div class="min-w-0">
				<p class="text-sm font-semibold text-gray-900">What clients ask for</p>
				<ul class="mt-3 space-y-2.5">
					<li v-for="t in byType" :key="t.name">
						<div class="flex items-baseline justify-between gap-3 text-sm">
							<span class="truncate text-gray-700">{{ t.name }}</span>
							<span class="shrink-0 tabular-nums text-gray-500"><strong class="font-semibold text-gray-950">{{ t.requests }}</strong> {{ t.requests === 1 ? 'request' : 'requests' }}</span>
						</div>
						<div class="mt-1 h-2 overflow-hidden rounded-full" :style="{ background: CHART.track }">
							<div class="h-full rounded-full" :style="{ width: `${t.share}%`, background: CHART.brand }" />
						</div>
					</li>
				</ul>
				<p v-if="!byType.length" class="mt-3 text-sm text-gray-500">No requests in this period.</p>
			</div>
		</div>
	</section>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'
import { CHART } from '~/utils/chartColors'

// breakdownLink: organizers get a link to Analytics → Event Types.
defineProps<{ subtitle?: string, breakdownLink?: boolean }>()

interface Trends {
	months: string[]
	types: { name: string, requests: number[], events: number[], total: number }[]
	totals: { requests: number[], events: number[] }
}

const PERIODS = [3, 6, 12] as const
const months = ref<(typeof PERIODS)[number]>(6)
const data = ref<Trends | null>(null)
const loading = ref(false)
const error = ref('')

async function load() {
	loading.value = true
	error.value = ''
	try {
		data.value = (await useApi().get<Trends>('/event-trends', { query: { months: months.value } })).data
	} catch (e: unknown) {
		error.value = authMessage(e) || 'Unable to load event trends.'
	} finally {
		loading.value = false
	}
}
watch(months, load)
onMounted(load)

const sum = (list: number[]) => list.reduce((a, b) => a + b, 0)
const totalRequests = computed(() => sum(data.value?.totals.requests ?? []))
const totalEvents = computed(() => sum(data.value?.totals.events ?? []))
const monthLabel = (ym: string) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('en-PH', { month: 'short', year: months.value === 12 ? '2-digit' : undefined })

const lineSeries = computed(() => [
	{ name: 'Requests', data: data.value?.totals.requests ?? [] },
	{ name: 'Booked events', data: data.value?.totals.events ?? [] },
])
const lineOptions = computed<ApexOptions>(() => ({
	chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
	colors: [CHART.blue, CHART.orange],
	stroke: { width: 2, curve: 'smooth' },
	markers: { size: 4, strokeWidth: 2, strokeColors: '#fff', hover: { size: 6 } },
	dataLabels: { enabled: false },
	legend: { show: false },
	grid: { borderColor: '#f1f5f9', strokeDashArray: 4, padding: { left: 4, right: 8 } },
	xaxis: { categories: (data.value?.months ?? []).map(monthLabel), labels: { style: { colors: '#9ca3af', fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
	yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: '#9ca3af', fontSize: '11px' }, formatter: (v: number) => String(Math.round(v)) } },
	tooltip: { shared: true, intersect: false, y: { formatter: (v: number) => String(Math.round(v)) } },
}))

// Requests per event type in the period, busiest first ("Other" last).
const byType = computed(() => {
	const rows = (data.value?.types ?? []).map(t => ({ name: t.name, requests: sum(t.requests) })).filter(t => t.requests > 0)
	rows.sort((a, b) => Number(a.name === 'Other') - Number(b.name === 'Other') || b.requests - a.requests)
	const max = Math.max(1, ...rows.map(r => r.requests))
	return rows.map(r => ({ ...r, share: Math.round((r.requests / max) * 100) }))
})
</script>
