<template>
	<div>
		<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
			<div>
				<h1 class="text-2xl font-extrabold text-gray-900">Platform Analytics</h1>
				<p class="mt-1 text-gray-500">NaSeRy-wide growth and money, by calendar month (Philippine time).</p>
			</div>
			<div class="flex gap-1 rounded-xl border border-gray-200 bg-white p-1" role="group" aria-label="Period">
				<button v-for="m in [3, 6, 12, 24]" :key="m" type="button" class="rounded-lg px-3 py-1.5 text-sm font-semibold"
					:class="months === m ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100'" :aria-pressed="months === m" @click="setMonths(m)">{{ m }} mo</button>
			</div>
		</div>

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="!data && !error" class="py-16 text-center text-sm text-gray-400">Loading…</div>

		<template v-if="data">
			<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
				<div v-for="k in kpis" :key="k.label" class="rounded-2xl border border-gray-200 bg-white p-5">
					<p class="text-sm text-gray-500">{{ k.label }}</p>
					<p class="mt-1 text-2xl font-extrabold text-gray-900">{{ k.value }}</p>
					<p class="mt-1 text-xs text-gray-500">{{ k.sub }}</p>
				</div>
			</div>

			<div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Ticket sales (₱)</h2>
					<p class="mb-4 text-sm text-gray-500">Settled ticket payments per month</p>
					<AnalyticsBarChart :labels="labels" :series="[{ name: 'Ticket sales', color: BLUE, values: data.trends.sales }]" caption="Ticket sales in pesos per month" />
				</section>
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Platform fee earned (₱)</h2>
					<p class="mb-4 text-sm text-gray-500">Fee kept after fee returned on valid-reason refunds</p>
					<AnalyticsBarChart :labels="labels" :series="[{ name: 'Platform fee', color: BLUE, values: data.trends.platform_fee }]" caption="Platform fee in pesos per month" />
				</section>
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Plan payments (₱)</h2>
					<p class="mb-4 text-sm text-gray-500">Organizer subscriptions paid per month</p>
					<AnalyticsBarChart :labels="labels" :series="[{ name: 'Plan payments', color: BLUE, values: data.trends.subscriptions }]" caption="Subscription payments in pesos per month" />
				</section>
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">New accounts</h2>
					<p class="mb-4 text-sm text-gray-500">Sign-ups per month</p>
					<AnalyticsBarChart :labels="labels" :series="[{ name: 'Clients', color: BLUE, values: data.trends.new_clients }, { name: 'Organizers', color: ORANGE, values: data.trends.new_organizers }]" caption="New client and organizer accounts per month" />
				</section>
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Inquiries and bookings</h2>
					<p class="mb-4 text-sm text-gray-500">Inquiries sent vs. quotations accepted</p>
					<AnalyticsBarChart :labels="labels" :series="[{ name: 'Inquiries', color: BLUE, values: data.trends.inquiries }, { name: 'Accepted quotations', color: ORANGE, values: data.trends.quotations_accepted }]" caption="Inquiries and accepted quotations per month" />
				</section>
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Events created</h2>
					<p class="mb-4 text-sm text-gray-500">New events per month, all organizers</p>
					<AnalyticsBarChart :labels="labels" :series="[{ name: 'Events', color: BLUE, values: data.trends.events_created }]" caption="Events created per month" />
				</section>
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Tickets sold</h2>
					<p class="mb-4 text-sm text-gray-500">Paid, free and invited tickets issued per month</p>
					<AnalyticsBarChart :labels="labels" :series="[{ name: 'Tickets', color: BLUE, values: data.trends.tickets_sold }]" caption="Tickets issued per month" />
				</section>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { PlatformTotals, PlatformTrends } from '~/composables/useAdmin'

definePageMeta({ layout: 'admin' })

// Validated categorical pair (B15 palette).
const BLUE = '#2a78d6'
const ORANGE = '#eb6834'

const route = useRoute()
const admin = useAdmin()
const months = ref([3, 6, 12, 24].includes(Number(route.query.months)) ? Number(route.query.months) : 12)
const data = ref<{ totals: PlatformTotals, trends: PlatformTrends } | null>(null)
const error = ref('')

const labels = computed(() => data.value!.trends.months.map((m) => {
	const [y, mo] = m.split('-').map(Number)
	return new Date(y!, mo! - 1, 1).toLocaleDateString('en-PH', { month: 'short', year: '2-digit' })
}))
const sum = (xs: number[]) => xs.reduce((a, b) => a + b, 0)
const kpis = computed(() => {
	const t = data.value!.totals
	const tr = data.value!.trends
	return [
		{ label: 'Ticket sales', value: peso(sum(tr.sales)), sub: `${peso(t.sales)} all time` },
		{ label: 'Platform income', value: peso(sum(tr.platform_fee) + sum(tr.subscriptions)), sub: `${peso(sum(tr.platform_fee))} fees + ${peso(sum(tr.subscriptions))} plans` },
		{ label: 'New accounts', value: sum(tr.new_clients) + sum(tr.new_organizers), sub: `${t.users.client + t.users.business} accounts in total` },
		{ label: 'Bookings', value: sum(tr.quotations_accepted), sub: `from ${sum(tr.inquiries)} inquiries` },
	]
})

async function load() {
	error.value = ''
	try {
		data.value = await admin.analytics(months.value)
	} catch (e) {
		error.value = authMessage(e)
	}
}

function setMonths(m: number) {
	months.value = m
	navigateTo({ query: { months: m } }, { replace: true })
	load()
}

onMounted(load)
</script>
