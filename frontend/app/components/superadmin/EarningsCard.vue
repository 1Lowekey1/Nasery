<template>
	<!-- What NaSeRy earns: recurring plan revenue and the ticket fee, at a glance. -->
	<section class="rounded-3xl border border-gray-200 bg-white p-6 sm:p-8">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div class="min-w-0">
				<h2 class="text-xl font-extrabold tracking-tight text-gray-950">What NaSeRy earns</h2>
				<p class="mt-1 text-sm text-gray-500">Two income streams: organizers' paid plans, and a {{ data?.fee_percent ?? '—' }}% fee on each online ticket sale.</p>
			</div>
			<NuxtLink to="/superadmin/settings?tab=plans" class="inline-flex min-h-9 items-center gap-1 text-xs font-semibold text-primary-700 hover:underline">Plans &amp; fee settings <IconBase name="arrow-right" class="h-3 w-3" /></NuxtLink>
		</div>

		<p v-if="error" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>
		<div v-else-if="!data" class="mt-5 h-40 animate-pulse rounded-2xl bg-gray-50" />

		<template v-else>
			<dl class="stat-grid mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
				<div class="rounded-2xl bg-gray-50 px-4 py-3.5">
					<dt class="text-xs font-semibold text-gray-500">Monthly recurring revenue</dt>
					<dd class="mt-1 break-words text-2xl font-extrabold tabular-nums text-gray-950">{{ peso(data.mrr) }}</dd>
					<p class="mt-0.5 text-xs text-gray-500">{{ peso(data.arr) }} a year at this rate</p>
				</div>
				<div class="rounded-2xl bg-gray-50 px-4 py-3.5">
					<dt class="text-xs font-semibold text-gray-500">Paying organizers</dt>
					<dd class="mt-1 text-2xl font-extrabold tabular-nums text-gray-950">{{ data.organizations.paying }} <span class="text-sm font-semibold text-gray-400">of {{ data.organizations.listed }}</span></dd>
					<p class="mt-0.5 text-xs text-gray-500">{{ payingShare }}% on a paid plan · {{ data.organizations.free }} on Free</p>
				</div>
				<div class="rounded-2xl bg-gray-50 px-4 py-3.5">
					<dt class="text-xs font-semibold text-gray-500">Earned, last 12 months</dt>
					<dd class="mt-1 break-words text-2xl font-extrabold tabular-nums text-gray-950">{{ peso(data.last_12_months.total) }}</dd>
					<p class="mt-0.5 text-xs text-gray-500">Since {{ since }}</p>
				</div>
				<div class="rounded-2xl bg-gray-50 px-4 py-3.5">
					<dt class="text-xs font-semibold text-gray-500">Ticket fee</dt>
					<dd class="mt-1 break-words text-2xl font-extrabold tabular-nums text-gray-950">{{ peso(data.last_12_months.ticket_fee) }}</dd>
					<p class="mt-0.5 text-xs text-gray-500">{{ data.fee_percent ?? 0 }}% of {{ peso(data.last_12_months.ticket_sales) }} in ticket sales</p>
				</div>
			</dl>

			<div class="mt-6 grid gap-6 lg:grid-cols-2">
				<!-- Where the money came from: two streams, fixed colours, amounts written on -->
				<div class="min-w-0">
					<p class="text-sm font-semibold text-gray-900">Where the last 12 months came from</p>
					<div class="mt-3 flex h-3 overflow-hidden rounded-full" :style="{ background: CHART.track }" role="img" :aria-label="`Plans ${planShare}%, ticket fee ${100 - planShare}%`">
						<div class="h-full" :style="{ width: `${planShare}%`, background: CHART.blue }" />
						<div class="h-full border-l-2" :style="{ width: `${100 - planShare}%`, background: CHART.orange, borderColor: CHART.gap }" />
					</div>
					<dl class="mt-3 space-y-1.5 text-sm">
						<div class="flex items-center justify-between gap-3"><dt class="flex items-center gap-2 text-gray-600"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: CHART.blue }" />Paid plans</dt><dd class="tabular-nums"><strong class="text-gray-950">{{ peso(data.last_12_months.plans) }}</strong> <span class="text-gray-400">({{ planShare }}%)</span></dd></div>
						<div class="flex items-center justify-between gap-3"><dt class="flex items-center gap-2 text-gray-600"><span class="h-2.5 w-2.5 rounded-full" :style="{ background: CHART.orange }" />Ticket fee</dt><dd class="tabular-nums"><strong class="text-gray-950">{{ peso(data.last_12_months.ticket_fee) }}</strong> <span class="text-gray-400">({{ 100 - planShare }}%)</span></dd></div>
					</dl>
				</div>

				<!-- Recurring revenue per plan, biggest first -->
				<div class="min-w-0">
					<p class="text-sm font-semibold text-gray-900">Recurring revenue by plan</p>
					<p v-if="!data.plans.length" class="mt-3 text-sm text-gray-500">No paid plans yet.</p>
					<ul v-else class="mt-3 space-y-3">
						<li v-for="p in data.plans" :key="p.plan">
							<div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
								<span class="font-medium text-gray-800">{{ p.plan }} <span class="text-xs text-gray-500">· {{ p.organizations }} organizer{{ p.organizations === 1 ? '' : 's' }} ({{ cycles(p) }})</span></span>
								<span class="tabular-nums text-gray-500"><strong class="font-semibold text-gray-950">{{ peso(p.mrr) }}</strong> / month</span>
							</div>
							<div class="mt-1 h-2 overflow-hidden rounded-full" :style="{ background: CHART.track }">
								<div class="h-full rounded-full" :style="{ width: `${data.mrr ? Math.round((p.mrr / data.mrr) * 100) : 0}%`, background: CHART.brand }" />
							</div>
							<p v-if="data.last_12_months.plans_by_plan[p.plan]" class="mt-1 text-xs text-gray-500">{{ peso(data.last_12_months.plans_by_plan[p.plan]!) }} paid in the last 12 months</p>
						</li>
					</ul>
				</div>
			</div>
			<p class="mt-5 text-xs leading-5 text-gray-500">Monthly recurring revenue counts active paid plans now (a yearly plan as a twelfth of its price each month). Ticket fees are what NaSeRy keeps after fees returned on valid refunds. Payments run in test mode for the demo; no real money moves.</p>
		</template>
	</section>
</template>

<script setup lang="ts">
import { CHART } from '~/utils/chartColors'

interface Earnings {
	mrr: number
	arr: number
	plans: { plan: string, organizations: number, monthly: number, yearly: number, mrr: number }[]
	organizations: { listed: number, paying: number, free: number }
	last_12_months: { from: string, plans: number, plans_by_plan: Record<string, number>, ticket_fee: number, ticket_sales: number, total: number }
	fee_percent: number | null
}

const data = ref<Earnings | null>(null)
const error = ref('')

const peso = (v: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(v)
const payingShare = computed(() => data.value?.organizations.listed ? Math.round((data.value.organizations.paying / data.value.organizations.listed) * 100) : 0)
const planShare = computed(() => {
	const t = data.value?.last_12_months
	return t && t.total > 0 ? Math.round((t.plans / t.total) * 100) : 0
})
const since = computed(() => data.value ? new Date(`${data.value.last_12_months.from}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' }) : '')
const cycles = (p: Earnings['plans'][number]) => [p.monthly ? `${p.monthly} monthly` : '', p.yearly ? `${p.yearly} yearly` : ''].filter(Boolean).join(', ')

async function load() {
	try {
		data.value = (await useApi().get<Earnings>('/admin/earnings')).data
		error.value = ''
	} catch (e) {
		error.value = authMessage(e) || 'Unable to load earnings.'
	}
}
onMounted(load)
useLiveRefresh(load, { interval: 60000 })
</script>
