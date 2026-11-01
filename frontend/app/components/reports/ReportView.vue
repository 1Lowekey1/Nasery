<template>
	<div class="space-y-5">
		<!-- Headline numbers -->
		<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
			<div v-for="c in cards" :key="c.label" class="rounded-2xl border border-gray-200 bg-white p-5">
				<p class="text-sm text-gray-500">{{ c.label }}</p>
				<p class="mt-1 text-3xl font-extrabold text-gray-900">{{ c.value }}<span v-if="c.of" class="text-base font-semibold text-gray-400"> / {{ c.of }}</span></p>
				<p class="mt-1 text-xs text-gray-500">{{ c.hint }}</p>
			</div>
		</div>
		<p class="text-xs text-gray-500">
			{{ t.cancelled_tickets }} cancelled ticket{{ t.cancelled_tickets === 1 ? '' : 's' }} not counted · {{ t.manual_check_ins }} manual check-in{{ t.manual_check_ins === 1 ? '' : 's' }} · {{ t.repeat_scans }} repeat scan{{ t.repeat_scans === 1 ? '' : 's' }} caught at the door
		</p>

		<div class="grid gap-5 xl:grid-cols-2">
			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h3 class="text-base font-bold text-gray-900">Attendance by ticket tier</h3>
				<p v-if="!report.tiers.length" class="mt-3 text-sm text-gray-400">No tickets were issued.</p>
				<div v-for="tier in report.tiers" :key="tier.name" class="mt-4">
					<div class="flex justify-between text-sm">
						<span class="font-medium text-gray-800">{{ tier.name }}<span v-if="tier.zone" class="text-gray-400"> · {{ tier.zone }}</span></span>
						<span class="text-gray-500">{{ tier.attended }} / {{ tier.expected }} ({{ pct(tier.rate) }}) · {{ tier.no_shows }} no-show{{ tier.no_shows === 1 ? '' : 's' }}</span>
					</div>
					<div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-primary-700" :style="{ width: tier.rate + '%' }" /></div>
				</div>

				<template v-if="report.sources.length > 1">
					<h4 class="mt-6 text-sm font-bold text-gray-900">How guests got their tickets</h4>
					<ul class="mt-2 space-y-1 text-sm">
						<li v-for="s in report.sources" :key="s.source" class="flex justify-between"><span class="text-gray-600">{{ s.label }}</span><span class="font-semibold">{{ s.attended }} / {{ s.expected }}</span></li>
					</ul>
				</template>
				<template v-if="report.sessions.length > 1">
					<h4 class="mt-6 text-sm font-bold text-gray-900">By session</h4>
					<ul class="mt-2 space-y-1 text-sm">
						<li v-for="s in report.sessions" :key="s.label" class="flex justify-between"><span class="text-gray-600">{{ s.label }}</span><span class="font-semibold">{{ s.checked_in }} checked in · {{ s.walk_ins }} walk-ins</span></li>
					</ul>
				</template>
			</section>

			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h3 class="text-base font-bold text-gray-900">Check-in timeline</h3>
				<p v-if="!report.timeline.buckets.length" class="mt-3 text-sm text-gray-400">No one has checked in yet.</p>
				<template v-else>
					<p class="mt-1 text-xs text-gray-500">
						Arrivals per {{ report.timeline.bucket_minutes }} minutes.
						<span class="inline-block h-2 w-2 rounded-sm bg-primary-700 align-middle" /> ticket check-ins
						<span class="ml-2 inline-block h-2 w-2 rounded-sm bg-primary-200 align-middle" /> walk-ins
						<template v-if="report.timeline.peak"> · busiest {{ report.timeline.peak.label }} ({{ report.timeline.peak.check_ins + report.timeline.peak.walk_ins }})</template>
					</p>
					<div class="mt-3 max-h-80 space-y-1 overflow-y-auto pr-1">
						<div v-for="b in report.timeline.buckets" :key="b.starts_at" class="flex items-center gap-2 text-xs">
							<span class="w-24 shrink-0 text-right text-gray-500">{{ b.label }}</span>
							<div class="flex h-3 flex-1 overflow-hidden rounded-sm bg-gray-50" :title="`${b.check_ins} check-ins, ${b.walk_ins} walk-ins`">
								<div class="h-full bg-primary-700" :style="{ width: b.check_ins / maxBucket * 100 + '%' }" />
								<div class="h-full bg-primary-200" :style="{ width: b.walk_ins / maxBucket * 100 + '%' }" />
							</div>
							<span class="w-6 shrink-0 text-gray-600">{{ b.check_ins + b.walk_ins }}</span>
						</div>
					</div>
				</template>
			</section>
		</div>

		<section v-if="report.revenue" class="rounded-2xl border border-gray-200 bg-white p-6">
			<h3 class="text-base font-bold text-gray-900">Revenue</h3>
			<dl class="mt-3 grid max-w-md grid-cols-2 gap-y-1.5 text-sm">
				<dt class="text-gray-600">Online ticket sales</dt><dd class="text-right">{{ money(report.revenue.online_gross) }}</dd>
				<dt class="text-gray-600">NaSeRy platform fee</dt><dd class="text-right">−{{ money(report.revenue.platform_fee) }}</dd>
				<dt class="text-gray-600">Refunds</dt><dd class="text-right">−{{ money(report.revenue.refunds) }}</dd>
				<dt class="text-gray-600">Cash at the door</dt><dd class="text-right">{{ money(report.revenue.door_cash) }}</dd>
				<dt class="border-t border-gray-100 pt-1.5 font-bold">Total</dt><dd class="border-t border-gray-100 pt-1.5 text-right font-bold">{{ money(report.revenue.total) }}</dd>
			</dl>
		</section>

		<section v-if="report.attendees" class="rounded-2xl border border-gray-200 bg-white p-6">
			<div class="flex flex-wrap items-center gap-2">
				<h3 class="mr-auto text-base font-bold text-gray-900">Guest list</h3>
				<input v-model="q" placeholder="Search name" aria-label="Search guests" class="w-48 rounded-lg border border-gray-200 px-3 py-2 text-sm">
				<select v-model="status" aria-label="Status" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm">
					<option value="">Everyone</option>
					<option value="attended">Attended</option>
					<option value="no_show">No-shows</option>
					<option value="cancelled">Cancelled</option>
					<option value="walk_in">Walk-ins (names)</option>
				</select>
			</div>
			<div class="mt-3 overflow-x-auto">
				<table v-table-cards class="w-full min-w-[560px] text-left text-sm">
					<thead class="text-xs uppercase text-gray-400"><tr><th class="py-2">Name</th><th>Tier</th><th>Status</th><th>First check-in</th></tr></thead>
					<tbody class="divide-y divide-gray-100">
						<tr v-for="(a, i) in rows" :key="i">
							<td data-card-title class="py-2 font-medium text-gray-900">{{ a.name }}</td>
							<td class="text-gray-600">{{ a.tier ?? '—' }}</td>
							<td><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusTone[a.status]">{{ a.label }}</span></td>
							<td class="text-gray-600">{{ a.at ?? '—' }}</td>
						</tr>
						<tr v-if="!rows.length"><td colspan="4" class="py-5 text-center text-gray-400">No one matches.</td></tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</template>

<script setup lang="ts">
import { attendeeStatusLabels, type AttendanceReport } from '~/composables/useReports'

// Read-only presentation of an attendance report, shared by the organizer's
// live report and the client's shared copy.
const props = defineProps<{ report: AttendanceReport }>()

const q = ref('')
const status = ref('')
const t = computed(() => props.report.totals)
const pct = (v: number | null) => (v === null ? '—' : `${Math.round(v * 10) / 10}%`)
const money = (v: string) => formatPeso(v) || '₱0'
const maxBucket = computed(() => Math.max(1, ...props.report.timeline.buckets.map(b => b.check_ins + b.walk_ins)))
const statusTone: Record<string, string> = {
	attended: 'bg-green-50 text-green-700', no_show: 'bg-amber-50 text-amber-800', cancelled: 'bg-gray-100 text-gray-500', walk_in: 'bg-primary-50 text-primary-700',
}

const cards = computed(() => [
	{ label: 'Attended', value: t.value.attended, of: t.value.expected, hint: `${pct(t.value.attendance_rate)} of ticket holders` },
	{ label: 'No-shows', value: t.value.no_shows, of: null, hint: 'never checked in' },
	{ label: 'Walk-ins', value: t.value.walk_ins, of: null, hint: `without a ticket · ${t.value.door_sales} door sale${t.value.door_sales === 1 ? '' : 's'}` },
	{ label: 'People in total', value: t.value.people, of: null, hint: t.value.capacity ? `${pct(t.value.capacity_used)} of ${t.value.capacity} capacity` : 'no capacity set' },
])

const rows = computed(() => {
	const tickets = (props.report.attendees ?? []).map(a => ({ name: a.name, tier: a.tier as string | null, status: a.status as string, label: attendeeStatusLabels[a.status], at: a.first_check_in }))
	const walkIns = (props.report.walk_in_names ?? []).map(w => ({ name: w.name, tier: null, status: 'walk_in', label: 'Walk-in', at: w.at }))
	const term = q.value.trim().toLowerCase()
	return [...tickets, ...walkIns].filter(r => (!status.value || r.status === status.value) && (!term || r.name.toLowerCase().includes(term)))
})
</script>
