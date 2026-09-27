<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading && !data" class="py-12 text-center text-sm text-gray-500">Loading bid performance…</div>
		<div v-else-if="data && !data.bids" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">Bid performance is for team members who handle inquiries or quotations.</div>

		<template v-if="data?.bids">
			<div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
				<div v-for="tile in tiles" :key="tile.label" class="rounded-2xl border border-gray-200 bg-white p-5">
					<p class="text-sm text-gray-500">{{ tile.label }}</p>
					<p class="mt-1 text-2xl font-extrabold text-gray-900">{{ tile.value }}</p>
					<p class="mt-1 text-xs text-gray-500">{{ tile.hint }}</p>
				</div>
			</div>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Bids won and lost</h2>
				<p class="mb-4 text-sm text-gray-500">{{ data.period.group === 'quarter' ? 'Quarterly' : 'Monthly' }}, by the date the client decided</p>
				<AnalyticsBarChart :labels="labels" :series="[
					{ name: 'Won', color: SERIES[0], values: data.bids.trend.map(t => t.won) },
					{ name: 'Lost', color: SERIES[1], values: data.bids.trend.map(t => t.lost) },
				]" />
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Inquiries received and quotations sent</h2>
				<p class="mb-4 text-sm text-gray-500">How much demand reached you, and how much of it you bid on</p>
				<AnalyticsBarChart :labels="labels" :series="[
					{ name: 'Inquiries received', color: SERIES[0], values: data.bids.trend.map(t => t.inquiries) },
					{ name: 'Quotations sent', color: SERIES[1], values: data.bids.trend.map(t => t.bids) },
				]" />
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Bid history</h2>
				<p v-if="!data.bids.history.length" class="mt-3 text-sm text-gray-400">No inquiries in this period.</p>
				<div v-else class="mt-3 overflow-x-auto">
					<table class="w-full min-w-[620px] text-left text-sm">
						<thead class="text-xs uppercase text-gray-400"><tr><th class="py-2">Inquiry</th><th>Received</th><th>Your quote</th><th>Answered in</th><th>Outcome</th></tr></thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="h in data.bids.history" :key="h.inquiry_id">
								<td class="py-2.5"><p class="font-medium text-gray-900">{{ h.event_type }}</p><p class="text-xs text-gray-500">{{ h.guest_count ?? '?' }} guests<template v-if="h.requested_date"> · {{ new Date(h.requested_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }}</template></p></td>
								<td class="text-gray-600">{{ new Date(h.invited_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) }}</td>
								<td class="text-gray-900">{{ h.amount ? formatPeso(h.amount) : '—' }}</td>
								<td class="text-gray-600">{{ h.first_sent_at ? duration(h.invited_at, h.first_sent_at) : '—' }}</td>
								<td><span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold" :class="outcomeTone[h.outcome]"><span aria-hidden="true">{{ outcomeIcon[h.outcome] }}</span>{{ outcomeLabels[h.outcome] }}</span></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import { outcomeLabels, pctLabel, type BidOutcome } from '~/composables/useAnalytics'

definePageMeta({ layout: 'dashboard' })

// Categorical slots 1–2 of the validated reference palette (colorblind-safe pair).
const SERIES = ['#2a78d6', '#eb6834']
const { data, error, loading } = useAnalyticsOverview()
const labels = computed(() => data.value?.bids?.trend.map(t => t.label) ?? [])

const tiles = computed(() => {
	const t = data.value!.bids!.totals
	return [
		{ label: 'Bids submitted', value: t.bids, hint: `of ${t.inquiries} inquiries` },
		{ label: 'Win rate', value: pctLabel(t.win_rate), hint: `${t.won} won · ${t.lost} lost · ${t.pending} pending` },
		{ label: 'Typical response', value: responseTime(t.median_response_hours), hint: 'median time to send a quote' },
		{ label: 'Average quote', value: t.average_bid ? formatPeso(t.average_bid) : '—', hint: 'final quote per inquiry' },
		{ label: 'Value won', value: formatPeso(t.won_value) || '₱0', hint: 'accepted quotations' },
	]
})

// Status meaning ships with an icon + label, never color alone.
const outcomeTone: Record<BidOutcome, string> = {
	won: 'bg-green-50 text-green-800', lost: 'bg-rose-50 text-rose-800', pending: 'bg-amber-50 text-amber-800', open: 'bg-blue-50 text-blue-800',
	declined: 'bg-gray-100 text-gray-600', missed: 'bg-gray-100 text-gray-600', withdrawn: 'bg-gray-100 text-gray-600', cancelled: 'bg-gray-100 text-gray-600',
}
const outcomeIcon: Record<BidOutcome, string> = { won: '✓', lost: '✕', pending: '…', open: '!', declined: '–', missed: '–', withdrawn: '–', cancelled: '–' }

function responseTime(h: number | null) {
	if (h === null) return '—'
	if (h < 1) return `${Math.max(1, Math.round(h * 60))} min`
	return h < 48 ? `${Math.round(h)} h` : `${Math.round(h / 24)} days`
}

function duration(from: string, to: string) {
	const h = (new Date(to).getTime() - new Date(from).getTime()) / 3600000
	return h < 1 ? `${Math.max(1, Math.round(h * 60))} min` : h < 48 ? `${Math.round(h)} h` : `${Math.round(h / 24)} days`
}
</script>
