<template>
	<div class="space-y-6">
		<!-- Period (what the plan allows) -->
		<div class="flex flex-wrap items-center justify-between gap-3">
			<p class="text-sm text-gray-500">The detail behind Home's two event-type cards{{ data ? `, ${periodText}` : '' }}.</p>
			<div class="inline-flex rounded-xl bg-gray-100 p-1" role="tablist" aria-label="Period">
				<button v-for="r in ranges" :key="r" type="button" role="tab" :aria-selected="range === r"
					class="min-h-9 rounded-lg px-3 text-xs font-bold transition" :class="range === r ? 'bg-white text-primary-800 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
					@click="range = r">{{ r.replace('m', ' mo') }}</button>
			</div>
		</div>

		<p v-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700" role="alert">{{ error }}</p>
		<div v-else-if="loading && !data" class="h-64 animate-pulse rounded-2xl bg-white ring-1 ring-gray-200" />

		<template v-else-if="data">
			<!-- ================= Yours ================= -->
			<section class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
				<h2 class="text-base font-bold text-gray-950">Your events by type</h2>
				<p class="mt-0.5 text-sm text-gray-500">Per event type: the requests you received, your offers and wins (same rules as Bid Performance), and the events you booked.</p>

				<dl class="stat-grid mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
					<div v-for="tile in myTiles" :key="tile.label" class="rounded-xl bg-gray-50 px-4 py-3">
						<dt class="text-xs font-semibold text-gray-500">{{ tile.label }}</dt>
						<dd class="mt-1 break-words text-xl font-extrabold tabular-nums text-gray-950">{{ tile.value }}</dd>
						<p v-if="tile.hint" class="mt-0.5 text-xs text-gray-500">{{ tile.hint }}</p>
					</div>
				</dl>

				<div v-if="data.can.events" class="mt-6">
					<div class="flex flex-wrap items-center justify-between gap-3">
						<p class="text-sm font-semibold text-gray-900">Events booked per month</p>
						<div class="w-48"><FormsSelect v-model="myType" :options="data.mine.filter(t => t.events).map(t => t.name)" placeholder="All event types" /></div>
					</div>
					<ClientOnly>
						<DeferredChart type="bar" :height="220" :options="myChartOptions" :series="myChartSeries" class="mt-2" />
					</ClientOnly>
				</div>

				<div class="mt-6">
					<table v-table-cards class="w-full text-left text-sm">
						<thead>
							<tr class="border-b border-gray-200 text-xs font-bold uppercase tracking-wide text-gray-400">
								<th class="py-2.5 pr-3">Event type</th>
								<template v-if="data.can.bids">
									<th class="py-2.5 pr-3 text-right">Requests</th>
									<th class="py-2.5 pr-3 text-right">Offers sent</th>
									<th class="py-2.5 pr-3 text-right">Won</th>
									<th class="py-2.5 pr-3 text-right">Win rate</th>
									<th class="py-2.5 pr-3 text-right">Won value</th>
								</template>
								<th v-if="data.can.events" class="py-2.5 pr-3 text-right">Events</th>
								<th class="py-2.5 text-right">Share of NaSeRy requests</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="t in data.mine" :key="t.name">
								<td data-card-title class="py-3 pr-3">
									<span class="font-semibold text-gray-900">{{ t.name }}</span>
									<span v-if="!t.on_profile && t.name !== 'Other' && marketOf(t.name)?.requests" class="ml-2 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Not on your profile</span>
								</td>
								<template v-if="data.can.bids">
									<td class="py-3 pr-3 text-right tabular-nums">{{ t.requests }}</td>
									<td class="py-3 pr-3 text-right tabular-nums">{{ t.offers }}</td>
									<td class="py-3 pr-3 text-right tabular-nums">{{ t.won }}<span v-if="t.lost" class="text-gray-400"> / {{ t.lost }} lost</span></td>
									<td class="py-3 pr-3 text-right tabular-nums">{{ t.win_rate === null || t.win_rate === undefined ? '—' : `${t.win_rate}%` }}</td>
									<td class="py-3 pr-3 text-right tabular-nums">{{ Number(t.won_value) ? peso(t.won_value ?? 0) : '—' }}</td>
								</template>
								<td v-if="data.can.events" class="py-3 pr-3 text-right font-semibold tabular-nums text-gray-950">{{ t.events }}</td>
								<td class="py-3 text-right tabular-nums text-gray-600">{{ marketOf(t.name) ? `${marketOf(t.name)!.share}%` : '—' }}</td>
							</tr>
							<tr v-if="!data.mine.length"><td :colspan="columns" class="py-8 text-center text-gray-400">No requests or events in this period yet.</td></tr>
						</tbody>
					</table>
					<p class="mt-3 text-xs leading-5 text-gray-500">Win rate counts decided offers only (won ÷ won + lost). Won value uses the final agreed price where you set one. "Not on your profile": clients ask NaSeRy for it, but it isn't one of your event types. Requests sent straight to you still arrive; matched requests for it go to other organizers. <NuxtLink to="/organizer/business-profile#event-types" class="font-semibold text-primary-700 hover:underline">Edit your event types</NuxtLink></p>
				</div>
			</section>

			<!-- ================= NaSeRy-wide ================= -->
			<section class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
				<h2 class="text-base font-bold text-gray-950">Event trends on NaSeRy</h2>
				<p class="mt-0.5 text-sm text-gray-500">What clients across NaSeRy asked for and booked, per event type. Counts only, never who.</p>

				<dl class="stat-grid mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
					<div class="rounded-xl bg-gray-50 px-4 py-3">
						<dt class="text-xs font-semibold text-gray-500">Requests</dt>
						<dd class="mt-1 text-xl font-extrabold tabular-nums text-gray-950">{{ data.totals.requests }}</dd>
						<p class="mt-0.5 text-xs text-gray-500">{{ changeText(data.totals.requests, data.totals.previous_requests) }}</p>
					</div>
					<div class="rounded-xl bg-gray-50 px-4 py-3">
						<dt class="text-xs font-semibold text-gray-500">Booked events</dt>
						<dd class="mt-1 text-xl font-extrabold tabular-nums text-gray-950">{{ data.totals.booked }}</dd>
					</div>
					<div class="rounded-xl bg-gray-50 px-4 py-3">
						<dt class="text-xs font-semibold text-gray-500">Most requested</dt>
						<dd class="mt-1 break-words text-xl font-extrabold text-gray-950">{{ topMarket?.name ?? '—' }}</dd>
						<p v-if="topMarket" class="mt-0.5 text-xs text-gray-500">{{ topMarket.share }}% of requests</p>
					</div>
				</dl>

				<div class="mt-6">
					<div class="flex flex-wrap items-center justify-between gap-3">
						<dl class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
							<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: CHART.blue }" /><dt class="text-gray-500">Requests</dt><dd class="font-bold tabular-nums text-gray-950">{{ sum(marketChart.requests) }}</dd></div>
							<div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: CHART.orange }" /><dt class="text-gray-500">Booked events</dt><dd class="font-bold tabular-nums text-gray-950">{{ sum(marketChart.booked) }}</dd></div>
						</dl>
						<div class="w-48"><FormsSelect v-model="marketType" :options="data.market.filter(t => t.requests || t.booked).map(t => t.name)" placeholder="All event types" /></div>
					</div>
					<ClientOnly>
						<DeferredChart type="line" :height="220" :options="marketChartOptions" :series="marketChartSeries" class="mt-2" />
					</ClientOnly>
				</div>

				<div class="mt-6">
					<table v-table-cards class="w-full text-left text-sm">
						<thead>
							<tr class="border-b border-gray-200 text-xs font-bold uppercase tracking-wide text-gray-400">
								<th class="py-2.5 pr-3">Event type</th>
								<th class="py-2.5 pr-3 text-right">Requests</th>
								<th class="py-2.5 pr-3 text-right">Share</th>
								<th class="py-2.5 pr-3 text-right">vs. period before</th>
								<th class="py-2.5 pr-3 text-right">Booked</th>
								<th class="py-2.5 pr-3 text-right">Organizers offering</th>
								<th class="py-2.5 text-right">Requests per organizer</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="t in data.market" :key="t.name">
								<td data-card-title class="py-3 pr-3">
									<span class="font-semibold text-gray-900">{{ t.name }}</span>
									<span v-if="isMine(t.name)" class="ml-2 inline-block rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-semibold text-primary-700">Yours</span>
								</td>
								<td class="py-3 pr-3 text-right font-semibold tabular-nums text-gray-950">{{ t.requests }}</td>
								<td class="py-3 pr-3 text-right tabular-nums">{{ t.share }}%</td>
								<td class="py-3 pr-3 text-right tabular-nums" :class="t.change === null ? 'text-gray-400' : t.change > 0 ? 'text-green-700' : t.change < 0 ? 'text-rose-700' : 'text-gray-600'">
									{{ t.change === null ? (t.requests ? 'New' : '—') : `${t.change > 0 ? '▲' : t.change < 0 ? '▼' : ''} ${Math.abs(t.change)}%` }}
								</td>
								<td class="py-3 pr-3 text-right tabular-nums">{{ t.booked }}</td>
								<td class="py-3 pr-3 text-right tabular-nums">{{ t.organizers ?? '—' }}</td>
								<td class="py-3 text-right tabular-nums">{{ t.requests_per_organizer ?? '—' }}</td>
							</tr>
						</tbody>
					</table>
					<p class="mt-3 text-xs leading-5 text-gray-500">"vs. period before" compares requests with the same number of months just before. "Requests per organizer" is requests ÷ listed organizers offering the type: a high number means lots of demand for few organizers. Organizers' own custom types count as "Other".</p>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'
import { CHART } from '~/utils/chartColors'

definePageMeta({ layout: 'dashboard' })

interface MineRow {
	name: string
	on_profile: boolean
	requests?: number
	offers?: number
	won?: number
	lost?: number
	win_rate?: number | null
	won_value?: string
	events?: number
	events_by_month?: number[]
}
interface MarketRow {
	name: string
	requests_by_month: number[]
	booked_by_month: number[]
	requests: number
	booked: number
	share: number
	previous_requests: number
	change: number | null
	organizers: number | null
	requests_per_organizer: number | null
}
interface Breakdown {
	period: { from: string, to: string, keys: string[], labels: string[] }
	ranges: string[]
	can: { bids: boolean, events: boolean }
	mine: MineRow[]
	market: MarketRow[]
	totals: { requests: number, booked: number, previous_requests: number }
}

const data = ref<Breakdown | null>(null)
const range = ref('6m')
const ranges = ref<string[]>(['3m', '6m', '12m'])
const loading = ref(false)
const error = ref('')
const myType = ref('')
const marketType = ref('')

const sum = (list: number[]) => list.reduce((a, b) => a + b, 0)
const peso = (v: string | number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(Number(v))
const marketOf = (name: string) => data.value?.market.find(m => m.name === name) ?? null
// "Yours": on your profile's event types.
const isMine = (name: string) => !!data.value?.mine.find(m => m.name === name && m.on_profile)
const columns = computed(() => 2 + (data.value?.can.bids ? 5 : 0) + (data.value?.can.events ? 1 : 0))
const periodText = computed(() => {
	const l = data.value?.period.labels ?? []
	return l.length ? `${l[0]} to ${l[l.length - 1]}` : ''
})
const shortMonths = computed(() => (data.value?.period.keys ?? []).map(k => new Date(`${k}-01T00:00:00`).toLocaleDateString('en-PH', { month: 'short', year: range.value === '12m' ? '2-digit' : undefined })))

function changeText(now: number, before: number) {
	if (!before) return now ? 'No requests in the period before' : ''
	const pct = Math.round(((now - before) / before) * 1000) / 10
	return `${pct > 0 ? '▲' : pct < 0 ? '▼' : ''} ${Math.abs(pct)}% vs. the period before (${before})`
}

const myTiles = computed(() => {
	const d = data.value
	if (!d) return []
	const mine = d.mine
	const top = [...mine].filter(t => (t.events ?? 0) > 0).sort((a, b) => (b.events ?? 0) - (a.events ?? 0))[0]
	const won = sum(mine.map(t => t.won ?? 0))
	const lost = sum(mine.map(t => t.lost ?? 0))
	const tiles: { label: string, value: string, hint?: string }[] = []
	if (d.can.events) tiles.push({ label: 'Events booked', value: String(sum(mine.map(t => t.events ?? 0))) })
	if (d.can.bids) {
		tiles.push({ label: 'Requests received', value: String(sum(mine.map(t => t.requests ?? 0))) })
		tiles.push({ label: 'Win rate', value: won + lost ? `${Math.round((won / (won + lost)) * 1000) / 10}%` : '—', hint: `${won} won, ${lost} lost` })
	}
	tiles.push({ label: 'Most handled', value: top?.name ?? '—', hint: top && d.can.events ? `${top.events} of your events` : undefined })
	return tiles
})

// Single series in brand teal (one hue: it's one measure, optionally filtered by type).
const myChartSeries = computed(() => {
	const rows = data.value?.mine ?? []
	const pick = myType.value ? rows.filter(r => r.name === myType.value) : rows
	const months = data.value?.period.keys.length ?? 0
	const values = Array.from({ length: months }, (_, i) => sum(pick.map(r => r.events_by_month?.[i] ?? 0)))
	return [{ name: myType.value || 'Events', data: values }]
})
const myChartOptions = computed<ApexOptions>(() => ({
	chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
	colors: [CHART.brand],
	plotOptions: { bar: { columnWidth: '45%', borderRadius: 4, borderRadiusApplication: 'end' } },
	dataLabels: { enabled: false },
	legend: { show: false },
	grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
	xaxis: { categories: shortMonths.value, labels: { style: { colors: '#9ca3af', fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
	yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: '#9ca3af', fontSize: '11px' }, formatter: (v: number) => String(Math.round(v)) } },
	tooltip: { y: { formatter: (v: number) => `${Math.round(v)} ${Math.round(v) === 1 ? 'event' : 'events'}` } },
}))

// Two series in fixed colours (requests blue, booked orange), as on Home.
const marketChart = computed(() => {
	const rows = data.value?.market ?? []
	const pick = marketType.value ? rows.filter(r => r.name === marketType.value) : rows
	const months = data.value?.period.keys.length ?? 0
	return {
		requests: Array.from({ length: months }, (_, i) => sum(pick.map(r => r.requests_by_month[i] ?? 0))),
		booked: Array.from({ length: months }, (_, i) => sum(pick.map(r => r.booked_by_month[i] ?? 0))),
	}
})
const marketChartSeries = computed(() => [
	{ name: 'Requests', data: marketChart.value.requests },
	{ name: 'Booked events', data: marketChart.value.booked },
])
const marketChartOptions = computed<ApexOptions>(() => ({
	chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false }, fontFamily: 'inherit' },
	colors: [CHART.blue, CHART.orange],
	stroke: { width: 2, curve: 'smooth' },
	markers: { size: 4, strokeWidth: 2, strokeColors: '#fff', hover: { size: 6 } },
	dataLabels: { enabled: false },
	legend: { show: false },
	grid: { borderColor: '#f1f5f9', strokeDashArray: 4, padding: { left: 4, right: 8 } },
	xaxis: { categories: shortMonths.value, labels: { style: { colors: '#9ca3af', fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false }, tooltip: { enabled: false } },
	yaxis: { min: 0, forceNiceScale: true, labels: { style: { colors: '#9ca3af', fontSize: '11px' }, formatter: (v: number) => String(Math.round(v)) } },
	tooltip: { shared: true, intersect: false, y: { formatter: (v: number) => String(Math.round(v)) } },
}))

const topMarket = computed(() => (data.value?.market ?? []).filter(t => t.name !== 'Other' && t.requests > 0)[0] ?? null)

async function load() {
	loading.value = true
	error.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) return
		const d = (await useApi().get<Breakdown>(`/organizations/${org.id}/event-type-breakdown`, { query: { range: range.value } })).data
		data.value = d
		ranges.value = d.ranges
		if (myType.value && !d.mine.some(t => t.name === myType.value)) myType.value = ''
		if (marketType.value && !d.market.some(t => t.name === marketType.value)) marketType.value = ''
	} catch (e) {
		if (e instanceof ApiError && e.status === 403) return navigateTo('/organizer/analyticsdashboard/live-events', { replace: true })
		error.value = authMessage(e) || 'Unable to load event types.'
	} finally {
		loading.value = false
	}
}

watch(range, load)
onMounted(load)
</script>
