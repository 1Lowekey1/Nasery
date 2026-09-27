<template>
	<div>
		<h1 class="text-2xl font-extrabold text-gray-900">Organizer Analytics Dashboard</h1>
		<p class="mt-1 text-gray-500">Track your performance, bids, and event metrics</p>

		<div class="mt-6 mb-6 flex flex-wrap items-center justify-between gap-3">
			<div class="inline-flex flex-wrap rounded-xl bg-gray-100 p-1">
				<NuxtLink v-for="tab in tabs" :key="tab.label" :to="{ path: tab.to, query: tab.live ? {} : filterQuery }"
					class="rounded-lg px-4 py-2 text-sm font-semibold transition-colors duration-200"
					:class="route.path === tab.to ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
					{{ tab.label }}
				</NuxtLink>
			</div>

			<!-- One filter row for every chart on the page (not on Live) -->
			<div v-if="!onLive" class="flex flex-wrap items-center gap-2">
				<label class="sr-only" for="analytics-range">Date range</label>
				<select id="analytics-range" :value="range ?? defaultRange" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm" @change="setQuery({ range: ($event.target as HTMLSelectElement).value })">
					<option v-for="o in rangeOptions" :key="o.value" :value="o.value" :disabled="!allowed(o.value)">{{ o.label }}{{ allowed(o.value) ? '' : ' (Starter/Pro)' }}</option>
				</select>
				<div class="inline-flex rounded-xl border border-gray-200 bg-white p-0.5" role="group" aria-label="Group by">
					<button v-for="g in (['month', 'quarter'] as const)" :key="g" type="button" :aria-pressed="group === g"
						class="rounded-lg px-3 py-1.5 text-sm font-semibold" :class="group === g ? 'bg-gray-900 text-white' : 'text-gray-600'"
						:disabled="g === 'quarter' && plan && !plan.quarterly" :title="g === 'quarter' && plan && !plan.quarterly ? 'Quarterly view is on the Starter and Pro plans' : undefined"
						@click="setQuery({ group: g })">{{ g === 'month' ? 'Monthly' : 'Quarterly' }}<span v-if="g === 'quarter' && plan && !plan.quarterly" aria-hidden="true"> 🔒</span></button>
				</div>
				<NuxtLink v-if="plan && (plan.history_months !== null && plan.history_months < 24 || !plan.quarterly)" to="/organizer/billing" class="text-xs font-semibold text-primary-700">More history on Starter/Pro</NuxtLink>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { AnalyticsRange } from '~/composables/useAnalytics'
import { rangeOptions } from '~/composables/useAnalytics'

const route = useRoute()
const router = useRouter()
const { range, group } = useAnalyticsFilters()

const tabs = [
	{ label: 'Overview', to: '/organizer/analyticsdashboard/overview' },
	{ label: 'Bid Performance', to: '/organizer/analyticsdashboard/bid-performance' },
	{ label: 'Event Performance', to: '/organizer/analyticsdashboard/event-performance' },
	{ label: 'Live Events', to: '/organizer/analyticsdashboard/live-events', live: true },
]

const plan = useAnalyticsPlan()
const onLive = computed(() => route.path.endsWith('/live-events'))
const filterQuery = computed(() => (range.value ? { range: range.value, group: group.value } : { group: group.value }))
// B18: what the plan allows (unknown until the first response: allow all, the server decides).
const allowed = (r: AnalyticsRange) => !plan.value || plan.value.ranges.includes(r)
const defaultRange = computed<AnalyticsRange>(() => plan.value && !plan.value.ranges.includes('12m') ? plan.value.ranges[plan.value.ranges.length - 1]! : '12m')

function setQuery(patch: Record<string, string>) {
	router.replace({ query: { ...route.query, ...patch } })
}
</script>
