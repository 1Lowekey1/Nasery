<template>
	<div class="space-y-6">
		<header class="overflow-hidden rounded-3xl border border-primary-100 bg-white shadow-sm">
			<div class="flex flex-col gap-4 px-5 py-6 sm:px-7 lg:flex-row lg:items-center lg:justify-between">
				<div class="max-w-2xl">
					<div class="mb-3 inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-primary-700">
						<IconBase name="cash" class="h-3.5 w-3.5" />
						Earnings
					</div>
					<h1 class="text-2xl font-extrabold tracking-tight text-gray-950 sm:text-3xl">Where your ticket money goes</h1>
					<p class="mt-2 text-sm leading-6 text-gray-500 sm:text-base">
						Track your online ticket sales, what NaSeRy keeps, and what has been paid out to you.
					</p>
				</div>

				<button v-if="earnings?.csv_exports" type="button"
					class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
					:disabled="exporting" @click="downloadCsv">
					<IconBase name="download" class="h-4 w-4" />
					{{ exporting ? 'Preparing…' : 'Download CSV' }}
				</button>
			</div>
		</header>

		<div v-if="isLoading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
			Loading your earnings...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else-if="earnings">
			<div class="grid grid-cols-1 gap-5 lg:grid-cols-5">
				<div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm lg:col-span-2">
					<h2 class="text-base font-bold text-gray-900">Money flow</h2>
					<p class="text-sm text-gray-500">How each peso of ticket sales is split, all events, all time</p>

					<template v-if="earnings.totals.ticket_sales > 0">
						<ClientOnly>
							<apexchart type="donut" height="300" :options="flowOptions" :series="flowSeries" />
						</ClientOnly>
					</template>
					<p v-else class="py-16 text-center text-sm text-gray-400">No online ticket sales yet.</p>
				</div>

				<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-3">
					<div v-for="card in statCards" :key="card.label"
						class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
						<div class="flex items-start justify-between gap-3">
							<span class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">{{ card.label }}</span>
							<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" :class="card.iconBg">
								<IconBase :name="card.icon" class="h-5 w-5" :class="card.iconColor" />
							</span>
						</div>
						<div class="mt-3 text-3xl font-extrabold tracking-tight" :class="card.valueClass">{{ card.value }}</div>
						<p class="mt-2 text-xs leading-5 text-gray-500">{{ card.hint }}</p>
					</div>
				</div>
			</div>

			<p class="px-1 text-xs text-gray-400">
				Money paid outside NaSeRy (like a client paying you for a booked event) isn't here — NaSeRy only handles online ticket sales.
			</p>

			<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
				<div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
					<p class="text-sm font-semibold text-gray-500">Paid out to you</p>
					<p class="mt-2 text-3xl font-extrabold tracking-tight text-gray-950">{{ formatMoney(earnings.paid_out) }}</p>
					<p class="mt-2 text-xs text-gray-500">
						<template v-if="earnings.last_payout">
							Last payout {{ formatDate(earnings.last_payout.paid_at) }} by {{ earnings.last_payout.method }}.
						</template>
						<template v-else>No payouts yet.</template>
					</p>
				</div>

				<div class="rounded-2xl border border-primary-100 bg-primary-50/60 p-6 shadow-sm">
					<p class="text-sm font-semibold text-primary-700">Still to be paid to you</p>
					<p class="mt-2 text-3xl font-extrabold tracking-tight text-primary-700">{{ formatMoney(earnings.outstanding) }}</p>
					<p class="mt-2 text-xs leading-5 text-primary-700/80">
						NaSeRy transfers your share by bank, GCash or Maya. You get a notification with the reference number.
					</p>
				</div>
			</div>

			<div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
				<h2 class="text-base font-bold text-gray-900">Your share by month</h2>
				<p class="mb-2 text-sm text-gray-500">Ticket sales vs. what you keep, last 6 months</p>

				<div v-if="!earnings.csv_exports" class="rounded-xl border border-dashed border-primary-200 bg-primary-50/40 p-5 text-sm">
					<p class="font-semibold text-gray-900">Available on Starter and Pro</p>
					<p class="mt-1 text-gray-600">See the split for each event, month by month, and download every payment with its fee and refunds.</p>
					<NuxtLink to="/organizer/billing" class="mt-3 inline-flex rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white">See plans</NuxtLink>
				</div>
				<template v-else-if="hasMonthlyActivity">
					<ClientOnly>
						<apexchart type="bar" height="300" :options="monthlyOptions" :series="monthlySeries" />
					</ClientOnly>
				</template>
				<p v-else class="py-14 text-center text-sm text-gray-400">No sales in the last 6 months yet.</p>
			</div>

			<div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
				<h2 class="text-base font-bold text-gray-900">Payout history</h2>
				<p class="mb-4 text-sm text-gray-500">Every transfer NaSeRy has sent you</p>

				<div v-if="earnings.payouts.length" class="overflow-x-auto">
					<table class="w-full text-left text-sm">
						<thead>
							<tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
								<th class="pb-3 pr-4">Date</th>
								<th class="pb-3 pr-4">Method</th>
								<th class="pb-3 pr-4">Reference</th>
								<th class="pb-3 pr-4 text-right">Amount</th>
								<th class="pb-3 pl-4">Note</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="payout in earnings.payouts" :key="payout.id">
								<td class="py-3.5 pr-4 text-gray-900">{{ formatDate(payout.paid_at) }}</td>
								<td class="py-3.5 pr-4 text-gray-700">{{ payout.method }}</td>
								<td class="py-3.5 pr-4 font-mono text-xs text-gray-500">{{ payout.reference || '—' }}</td>
								<td class="py-3.5 pr-4 text-right font-bold text-gray-900">{{ formatMoney(payout.amount) }}</td>
								<td class="py-3.5 pl-4 text-gray-500">{{ payout.note || '—' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="rounded-xl border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400">
					No payouts yet. Your first one will appear here.
				</p>
			</div>

			<div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
				<h2 class="text-base font-bold text-gray-900">Detailed breakdown</h2>
				<p class="mb-4 text-sm text-gray-500">
					Per event. NaSeRy keeps {{ earnings.fee_percent }}% of each sale after refunds; buyers never pay extra.
				</p>

				<div v-if="!earnings.csv_exports" class="rounded-xl border border-dashed border-primary-200 bg-primary-50/40 p-5 text-sm">
					<p class="font-semibold text-gray-900">Available on Starter and Pro</p>
					<p class="mt-1 text-gray-600">See the split for each event, month by month, and download every payment with its fee and refunds.</p>
					<NuxtLink to="/organizer/billing" class="mt-3 inline-flex rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white">See plans</NuxtLink>
				</div>
				<div v-else-if="earnings.events.length" class="overflow-x-auto">
					<table class="w-full text-left text-sm">
						<thead>
							<tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
								<th class="pb-3 pr-4">Event</th>
								<th class="pb-3 pr-4 text-right">Ticket sales</th>
								<th class="pb-3 pr-4 text-right">Refunded</th>
								<th class="pb-3 pr-4 text-right">NaSeRy fee</th>
								<th class="pb-3 text-right">Your share</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="event in earnings.events" :key="event.event_id">
								<td class="py-3.5 pr-4">
									<div class="font-semibold text-gray-900">{{ event.name }}</div>
									<div class="text-xs text-gray-400">{{ event.payments_count }} payment{{ event.payments_count === 1 ? '' : 's' }}</div>
								</td>
								<td class="py-3.5 pr-4 text-right text-gray-700">{{ formatMoney(event.ticket_sales) }}</td>
								<td class="py-3.5 pr-4 text-right text-gray-500">−{{ formatMoney(event.refunded) }}</td>
								<td class="py-3.5 pr-4 text-right text-amber-600">−{{ formatMoney(event.fee) }}</td>
								<td class="py-3.5 text-right font-bold text-primary-700">{{ formatMoney(event.share) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p v-else class="rounded-xl border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400">
					No online ticket payments yet.
				</p>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'

definePageMeta({ layout: 'dashboard', middleware: ['organizer'] })

// Our earnings (0043) mapped onto the redesign's shape. The monthly chart and
// per-event table come from the details endpoint (Starter / Pro plans).
const earningsApi = useEarnings()
const exporting = ref(false)
let orgId = ''

interface EarningsPayment {
	date: string
	reference: string
	event: string
	ticket_sales: number
	refunded: number
	fee: number
	share: number
}

interface EarningsResponse {
	data: {
		fee_percent: number
		csv_exports: boolean
		totals: { ticket_sales: number, refunded: number, fee: number, share: number }
		paid_out: number
		outstanding: number
		last_payout: { paid_at: string, method: string } | null
		payouts: Array<{ id: number, paid_at: string, method: string, reference: string | null, amount: number, note: string | null }>
		events: Array<{ event_id: number, name: string, payments_count: number, ticket_sales: number, refunded: number, fee: number, share: number }>
		monthly: Array<{ label: string, ticket_sales: number, share: number }>
		payments: EarningsPayment[]
	}
}

type Earnings = EarningsResponse['data']

const earnings = ref<Earnings | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')

const COLORS = { share: '#285F6b', fee: '#d97706', refunded: '#9ca3af', sales: '#a9c9cd' }

function formatMoney(value: number) {
	return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2 }).format(value)
}

function formatDate(value: string) {
	return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

const statCards = computed(() => {
	const totals = earnings.value?.totals
	if (!totals) return []

	return [
		{
			label: 'Ticket sales', value: formatMoney(totals.ticket_sales), icon: 'cash',
			iconBg: 'bg-primary-50', iconColor: 'text-primary-700', valueClass: 'text-gray-950',
			hint: 'What buyers paid online for your tickets.',
		},
		{
			label: 'Refunded to buyers', value: `−${formatMoney(totals.refunded)}`, icon: 'refresh-cw',
			iconBg: 'bg-gray-100', iconColor: 'text-gray-600', valueClass: 'text-gray-700',
			hint: 'Already sent back to buyers. Nobody holds it.',
		},
		{
			label: 'NaSeRy fee', value: `−${formatMoney(totals.fee)}`, icon: 'tag',
			iconBg: 'bg-amber-50', iconColor: 'text-amber-600', valueClass: 'text-amber-600',
			hint: `The platform fee on each sale (${earnings.value?.fee_percent ?? 0}% after refunds).`,
		},
		{
			label: 'Your share', value: formatMoney(totals.share), icon: 'check-circle',
			iconBg: 'bg-primary-50', iconColor: 'text-primary-700', valueClass: 'text-primary-700',
			hint: 'Yours. NaSeRy pays it out to you (see below).',
		},
	]
})

const flowSeries = computed(() => {
	const totals = earnings.value?.totals
	return totals ? [totals.share, totals.fee, totals.refunded] : []
})

const flowOptions = computed<ApexOptions>(() => ({
	chart: { type: 'donut', fontFamily: 'inherit' },
	labels: ['Your share', 'NaSeRy fee', 'Refunded'],
	colors: [COLORS.share, COLORS.fee, COLORS.refunded],
	dataLabels: { enabled: false },
	stroke: { width: 2, colors: ['#fff'] },
	legend: { position: 'bottom', fontSize: '13px', fontWeight: 600, markers: { size: 8 } },
	plotOptions: {
		pie: {
			donut: {
				size: '70%',
				labels: {
					show: true,
					name: { fontSize: '12px', fontWeight: 700, color: '#6b7280' },
					value: { fontSize: '20px', fontWeight: 800, color: '#111827', formatter: (value: string) => formatMoney(Number(value)) },
					total: {
						show: true,
						label: 'Ticket sales',
						color: '#6b7280',
						fontSize: '12px',
						fontWeight: 700,
						formatter: () => formatMoney(earnings.value?.totals.ticket_sales ?? 0),
					},
				},
			},
		},
	},
	tooltip: { y: { formatter: (value: number) => formatMoney(value) } },
}))

const hasMonthlyActivity = computed(() => (earnings.value?.monthly ?? []).some(row => row.ticket_sales > 0))

const monthlySeries = computed(() => [
	{ name: 'Ticket sales', data: (earnings.value?.monthly ?? []).map(row => row.ticket_sales) },
	{ name: 'Your share', data: (earnings.value?.monthly ?? []).map(row => row.share) },
])

const monthlyOptions = computed<ApexOptions>(() => ({
	chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
	colors: [COLORS.sales, COLORS.share],
	plotOptions: { bar: { columnWidth: '50%', borderRadius: 4, borderRadiusApplication: 'end' } },
	dataLabels: { enabled: false },
	grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
	legend: { position: 'bottom', fontSize: '13px', fontWeight: 600, markers: { size: 8 } },
	xaxis: {
		categories: (earnings.value?.monthly ?? []).map(row => row.label),
		axisBorder: { show: false },
		axisTicks: { show: false },
		labels: { style: { colors: '#6b7280', fontSize: '13px', fontWeight: 600 } },
	},
	yaxis: {
		labels: {
			style: { colors: '#9ca3af', fontSize: '11px' },
			formatter: (value: number) => (value >= 1000 ? `₱${(value / 1000).toFixed(value >= 10000 ? 0 : 1)}K` : `₱${Math.round(value)}`),
		},
	},
	tooltip: { shared: true, intersect: false, y: { formatter: (value: number) => formatMoney(value) } },
}))

async function downloadCsv() {
	exporting.value = true
	try {
		const blob = await earningsApi.exportCsv(orgId)
		const url = URL.createObjectURL(blob)
		const a = Object.assign(document.createElement('a'), { href: url, download: `earnings-${new Date().toISOString().slice(0, 10)}.csv` })
		a.click()
		URL.revokeObjectURL(url)
	} catch (error: unknown) {
		errorMessage.value = authMessage(error)
	} finally {
		exporting.value = false
	}
}

async function loadEarnings() {
	isLoading.value = true
	errorMessage.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId = org.id
		const summary = await earningsApi.summary(orgId)
		const details = summary.plan.details ? await earningsApi.details(orgId, 6) : null
		const payouts = summary.payouts.filter(p => !p.voided)
		const net = summary.gross - summary.refunded
		earnings.value = {
			fee_percent: net > 0 ? Math.round((summary.platform_fee / net) * 10000) / 100 : 1,
			csv_exports: summary.plan.details,
			totals: { ticket_sales: summary.gross, refunded: summary.refunded, fee: summary.platform_fee, share: summary.share },
			paid_out: summary.paid_out,
			outstanding: summary.owed,
			last_payout: payouts[0] ? { paid_at: payouts[0].paid_at, method: payouts[0].method_label } : null,
			payouts: payouts.map(p => ({ id: p.id as unknown as number, paid_at: p.paid_at, method: p.method_label, reference: p.reference, amount: p.amount, note: p.note })),
			events: (details?.events ?? []).map(e => ({ event_id: e.id as unknown as number, name: e.name, payments_count: e.payments, ticket_sales: e.gross, refunded: e.refunded, fee: e.platform_fee, share: e.share })),
			monthly: (details?.months ?? []).map(m => ({ label: new Date(`${m.month}-01T00:00:00`).toLocaleDateString('en-PH', { month: 'short' }), ticket_sales: m.gross, share: m.share })),
			payments: [],
		}
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load your earnings.'
	} finally {
		isLoading.value = false
	}
}

onMounted(() => {
	loadEarnings()
})
</script>
