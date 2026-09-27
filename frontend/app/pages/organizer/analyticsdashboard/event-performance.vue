<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading && !data" class="py-12 text-center text-sm text-gray-500">Loading event performance…</div>
		<div v-else-if="data && !data.events" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">Event results are for team members with access to every event.</div>

		<template v-if="data?.events">
			<div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
				<div v-for="tile in tiles" :key="tile.label" class="rounded-2xl border border-gray-200 bg-white p-5">
					<p class="text-sm text-gray-500">{{ tile.label }}</p>
					<p class="mt-1 text-2xl font-extrabold text-gray-900">{{ tile.value }}</p>
					<p class="mt-1 text-xs text-gray-500">{{ tile.hint }}</p>
				</div>
			</div>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Attendance vs no-shows</h2>
				<p class="mb-4 text-sm text-gray-500">Share of ticket holders who came or didn't, for completed events · {{ data.period.group === 'quarter' ? 'quarterly' : 'monthly' }}</p>
				<AnalyticsLineChart :labels="labels" :series="[
					{ name: 'Attendance rate', color: SERIES[0], values: data.events.trend.map(t => t.attendance_rate) },
					{ name: 'No-show rate', color: SERIES[1], values: data.events.trend.map(t => t.no_show_rate) },
				]" caption="Periods without a completed event are left blank." />
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Events and guests</h2>
				<p class="mb-4 text-sm text-gray-500">Completed events and everyone who came (checked in + walk-ins)</p>
				<AnalyticsBarChart :labels="labels" :series="[
					{ name: 'Events held', color: SERIES[0], values: data.events.trend.map(t => t.events) },
				]" :caption="`${data.events.totals.attendees} guests in total across ${data.events.totals.completed} events.`" />
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Event results</h2>
				<p v-if="!data.events.events.length" class="mt-3 text-sm text-gray-400">No events in this period.</p>
				<div v-else class="mt-3 overflow-x-auto">
					<table class="w-full min-w-[720px] text-left text-sm">
						<thead class="text-xs uppercase text-gray-400">
							<tr><th class="py-2">Event</th><th class="text-right">Attended</th><th class="text-right">Rate</th><th class="text-right">No-shows</th><th class="text-right">Walk-ins</th><th class="text-right">Capacity fill</th><th v-if="data.can.revenue" class="text-right">Revenue</th><th /></tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="e in data.events.events" :key="e.id">
								<td class="py-2.5"><p class="font-medium text-gray-900">{{ e.name }}</p><p class="text-xs text-gray-500">{{ new Date(e.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }} · {{ e.status === 'completed' ? 'completed' : 'upcoming' }}</p></td>
								<td class="text-right">{{ e.attended }} / {{ e.expected }}</td>
								<td class="text-right font-semibold">{{ pctLabel(e.attendance_rate) }}</td>
								<td class="text-right">{{ e.no_shows ?? '—' }}</td>
								<td class="text-right">{{ e.walk_ins }}</td>
								<td class="text-right">{{ pctLabel(e.capacity_fill) }}</td>
								<td v-if="data.can.revenue" class="text-right">{{ formatPeso(e.revenue ?? '0') || '₱0' }}</td>
								<td class="text-right"><NuxtLink :to="`/organizer/ticketattendance/attendance-report?event=${e.id}`" class="text-xs font-semibold text-primary-700">Report</NuxtLink></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import { pctLabel } from '~/composables/useAnalytics'

definePageMeta({ layout: 'dashboard' })

// Categorical slots 1–2 of the validated reference palette (colorblind-safe pair).
const SERIES = ['#2a78d6', '#eb6834']
const { data, error, loading } = useAnalyticsOverview()
const labels = computed(() => data.value?.events?.trend.map(t => t.label) ?? [])

const tiles = computed(() => {
	const t = data.value!.events!.totals
	return [
		{ label: 'Events held', value: t.completed, hint: `${t.upcoming} more in this period` },
		{ label: 'Avg attendance', value: pctLabel(t.average_attendance_rate), hint: 'of ticket holders came' },
		{ label: 'No-show rate', value: pctLabel(t.no_show_rate), hint: 'never checked in' },
		{ label: 'Walk-in share', value: pctLabel(t.walk_in_share), hint: `of ${t.attendees} guests welcomed` },
		t.revenue !== undefined
			? { label: 'Revenue', value: formatPeso(t.revenue) || '₱0', hint: 'online net + door cash' }
			: { label: 'Capacity fill', value: pctLabel(t.average_capacity_fill), hint: 'average, events with a limit' },
	]
})
</script>
