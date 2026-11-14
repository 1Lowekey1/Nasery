<template>
	<!-- This organizer's own picture: the event types they handle most, with the requests they got for each. -->
	<section v-if="!forbidden" class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div class="min-w-0">
				<h2 class="text-base font-bold text-gray-950">Your events by type</h2>
				<p class="mt-0.5 text-sm text-gray-500">The kinds of events you handle most, from your booked events.</p>
				<NuxtLink to="/organizer/analyticsdashboard/event-types" class="mt-1 inline-flex min-h-8 items-center gap-1 text-xs font-semibold text-primary-700 hover:underline">Full breakdown <IconBase name="arrow-right" class="h-3 w-3" /></NuxtLink>
			</div>
			<div class="inline-flex rounded-xl bg-gray-100 p-1" role="tablist" aria-label="Period">
				<button v-for="m in PERIODS" :key="m" type="button" role="tab" :aria-selected="months === m"
					class="min-h-8 rounded-lg px-3 text-xs font-bold transition" :class="months === m ? 'bg-white text-primary-800 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
					@click="months = m">{{ m }} mo</button>
			</div>
		</div>

		<p v-if="error" class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>
		<div v-else-if="loading && !data" class="mt-4 h-40 animate-pulse rounded-xl bg-gray-50" />
		<p v-else-if="data && !data.totals.events && !data.totals.requests" class="mt-4 rounded-xl bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">No events or requests in this period yet.</p>

		<template v-else-if="data">
			<dl class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-sm">
				<div class="flex items-center gap-2"><dt class="text-gray-500">Events</dt><dd class="font-bold tabular-nums text-gray-950">{{ data.totals.events }}</dd></div>
				<div class="flex items-center gap-2"><dt class="text-gray-500">Requests received</dt><dd class="font-bold tabular-nums text-gray-950">{{ data.totals.requests }}</dd></div>
				<div v-if="top" class="flex min-w-0 items-center gap-2"><dt class="text-gray-500">Most handled</dt><dd class="truncate font-bold text-gray-950">{{ top.name }} ({{ top.share }}%)</dd></div>
			</dl>

			<!-- One hue, sorted, numbers written on each row (never colour alone) -->
			<ol class="mt-4 space-y-3">
				<li v-for="(t, i) in data.types" :key="t.name">
					<div class="flex items-baseline justify-between gap-3 text-sm">
						<span class="flex min-w-0 items-baseline gap-2">
							<span class="w-4 shrink-0 text-right text-xs font-bold tabular-nums text-gray-400">{{ i + 1 }}</span>
							<span class="truncate font-medium text-gray-800">{{ t.name }}</span>
						</span>
						<span class="shrink-0 tabular-nums text-gray-500">
							<strong class="font-semibold text-gray-950">{{ t.events }}</strong> {{ t.events === 1 ? 'event' : 'events' }}
							<span class="text-gray-300">·</span> {{ t.requests }} {{ t.requests === 1 ? 'request' : 'requests' }}
						</span>
					</div>
					<div class="ml-6 mt-1 h-2 overflow-hidden rounded-full" :style="{ background: CHART.track }" role="img" :aria-label="`${t.name}: ${t.share}% of your events`">
						<div class="h-full rounded-full" :style="{ width: `${t.share}%`, background: CHART.brand }" />
					</div>
				</li>
			</ol>
		</template>
	</section>
</template>

<script setup lang="ts">
import { CHART } from '~/utils/chartColors'

interface MyEventTypes {
	from: string
	types: { name: string, events: number, requests: number, share: number }[]
	totals: { events: number, requests: number }
}

const PERIODS = [3, 6, 12] as const
const months = ref<(typeof PERIODS)[number]>(6)
const data = ref<MyEventTypes | null>(null)
const loading = ref(false)
const error = ref('')
// Members who don't handle bids or events don't get this card.
const forbidden = ref(false)

const top = computed(() => data.value?.types.find(t => t.events > 0) ?? null)

async function load() {
	loading.value = true
	error.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) return
		data.value = (await useApi().get<MyEventTypes>(`/organizations/${org.id}/top-event-types`, { query: { months: months.value } })).data
	} catch (e) {
		if (e instanceof ApiError && e.status === 403) forbidden.value = true
		else error.value = authMessage(e) || 'Unable to load your event types.'
	} finally {
		loading.value = false
	}
}

watch(months, load)
onMounted(load)
</script>
