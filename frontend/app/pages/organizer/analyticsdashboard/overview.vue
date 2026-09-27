<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading && !data" class="py-12 text-center text-sm text-gray-500">Crunching your numbers…</div>

		<template v-if="data">
			<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
				<div v-for="tile in tiles" :key="tile.label" class="rounded-2xl border border-gray-200 bg-white p-5">
					<p class="text-sm text-gray-500">{{ tile.label }}</p>
					<p class="mt-1 text-3xl font-extrabold text-gray-900">{{ tile.value }}</p>
					<p class="mt-1 text-xs text-gray-500">{{ tile.hint }}</p>
				</div>
			</div>

			<div class="mt-5 grid gap-5 xl:grid-cols-2">
				<section v-if="data.bids" class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">From inquiry to booking</h2>
					<p class="text-sm text-gray-500">{{ periodText }}</p>
					<div class="mt-4 space-y-3">
						<div v-for="step in funnel" :key="step.label">
							<div class="flex justify-between text-sm"><span class="text-gray-700">{{ step.label }}</span><span class="font-semibold text-gray-900">{{ step.value }}<span v-if="step.of !== null" class="font-normal text-gray-400"> · {{ pctLabel(step.of) }}</span></span></div>
							<div class="mt-1 h-2.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full" :style="{ width: `${step.width}%`, background: '#2a78d6' }" /></div>
						</div>
					</div>
					<p class="mt-4 text-xs text-gray-500">{{ data.bids.totals.missed }} missed (the inquiry closed before you answered) · {{ data.bids.totals.declined }} declined by you · {{ data.bids.totals.open }} waiting for your answer</p>
				</section>

				<section v-if="data.bids" class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Wins by event type</h2>
					<p v-if="!data.bids.by_event_type.length" class="mt-4 text-sm text-gray-400">No inquiries in this period yet.</p>
					<table v-else class="mt-3 w-full text-left text-sm">
						<thead class="text-xs uppercase text-gray-400"><tr><th class="py-1.5">Type</th><th class="text-right">Inquiries</th><th class="text-right">Bids</th><th class="text-right">Won</th><th class="text-right">Win rate</th></tr></thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="t in data.bids.by_event_type.slice(0, 6)" :key="t.event_type">
								<td class="py-2 text-gray-800">{{ t.event_type }}</td><td class="text-right">{{ t.inquiries }}</td><td class="text-right">{{ t.bids }}</td><td class="text-right">{{ t.won }}</td><td class="text-right font-semibold">{{ pctLabel(t.win_rate) }}</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section v-if="data.events" class="rounded-2xl border border-gray-200 bg-white p-6 xl:col-span-2">
					<div class="flex flex-wrap items-baseline justify-between gap-2">
						<h2 class="text-base font-bold text-gray-900">Recent events</h2>
						<NuxtLink :to="{ path: '/organizer/analyticsdashboard/event-performance', query: route.query }" class="text-sm font-semibold text-primary-700">All event results →</NuxtLink>
					</div>
					<p v-if="!data.events.events.length" class="mt-4 text-sm text-gray-400">No events in this period yet.</p>
					<ul v-else class="mt-3 divide-y divide-gray-100">
						<li v-for="e in data.events.events.slice(0, 5)" :key="e.id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
							<span class="min-w-0"><span class="font-semibold text-gray-900">{{ e.name }}</span> <span class="text-gray-500">· {{ new Date(e.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }}</span></span>
							<span class="text-gray-600">{{ e.status === 'completed' ? `${e.attended}/${e.expected} attended (${pctLabel(e.attendance_rate)}) · ${e.walk_ins} walk-in${e.walk_ins === 1 ? '' : 's'}` : 'Upcoming' }}</span>
						</li>
					</ul>
				</section>
			</div>

			<p v-if="!data.bids || !data.events" class="mt-5 text-xs text-gray-500">Some sections are hidden: they depend on your role (bids need inquiry or quotation access; event results need access to every event).</p>
			<p class="mt-2 text-xs text-gray-400">Figures cover what happened in NaSeRy only; events managed outside it aren't counted. New organizers will see more as activity builds up.</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import { pctLabel } from '~/composables/useAnalytics'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { data, error, loading } = useAnalyticsOverview()

const periodText = computed(() => data.value
	? `${new Date(data.value.period.from).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })} – ${new Date(data.value.period.to).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
	: '')

const tiles = computed(() => {
	const b = data.value?.bids?.totals
	const e = data.value?.events?.totals
	return [
		b && { label: 'Win rate', value: pctLabel(b.win_rate), hint: `${b.won} won · ${b.lost} lost` },
		b && { label: 'Bids submitted', value: b.bids, hint: `${pctLabel(b.response_rate)} of ${b.inquiries} inquiries answered` },
		e && { label: 'Avg attendance rate', value: pctLabel(e.average_attendance_rate), hint: `${e.completed} completed event${e.completed === 1 ? '' : 's'}` },
		b ? { label: 'Active inquiries', value: b.open + b.pending, hint: `${b.open} to answer · ${b.pending} awaiting clients` }
			: e && { label: 'Guests welcomed', value: e.attendees, hint: 'checked in + walk-ins' },
	].filter(Boolean) as { label: string, value: string | number, hint: string }[]
})

const funnel = computed(() => {
	const t = data.value!.bids!.totals
	const top = Math.max(1, t.inquiries)
	return [
		{ label: 'Inquiries received', value: t.inquiries, of: null, width: 100 },
		{ label: 'Quotations sent', value: t.bids, of: t.response_rate, width: (t.bids / top) * 100 },
		{ label: 'Won', value: t.won, of: t.inquiries ? (t.won * 100) / t.inquiries : null, width: (t.won / top) * 100 },
	]
})
</script>
