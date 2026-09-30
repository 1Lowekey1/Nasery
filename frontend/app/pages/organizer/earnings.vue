<template>
	<div class="mx-auto max-w-6xl">
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold tracking-tight text-gray-950">Earnings</h1>
			<p class="mt-1 text-gray-500">Where your online ticket money goes, and what NaSeRy has paid out to you.</p>
		</div>

		<div v-if="error" class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ error }}</div>
		<div v-if="!data && !error" class="rounded-2xl border border-gray-100 bg-white p-10 text-center text-sm text-gray-500">Loading your earnings…</div>

		<template v-if="data">
			<!-- At a glance: where every peso of ticket sales went. -->
			<section class="mb-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
				<div class="flex flex-wrap items-baseline justify-between gap-2">
					<h2 class="text-base font-bold text-gray-900">Where your ticket money goes</h2>
					<p class="text-sm text-gray-500">All events, all time</p>
				</div>

				<div v-if="data.gross > 0" class="mt-5 flex h-4 w-full overflow-hidden rounded-full bg-gray-100" aria-hidden="true">
					<div class="h-full bg-emerald-600" :style="{ width: pct(data.share) }" />
					<div class="h-full bg-slate-400" :style="{ width: pct(data.platform_fee) }" />
					<div class="h-full bg-rose-400" :style="{ width: pct(data.refunded) }" />
				</div>

				<dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
					<div class="rounded-xl bg-gray-50 p-4">
						<dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ticket sales</dt>
						<dd class="mt-1 text-xl font-extrabold text-gray-900">{{ pesoExact(data.gross) }}</dd>
						<p class="mt-1 text-xs text-gray-500">What buyers paid online for your tickets.</p>
					</div>
					<div class="rounded-xl bg-rose-50 p-4">
						<dt class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-rose-700"><span class="h-2.5 w-2.5 rounded-full bg-rose-400" />Refunded to buyers</dt>
						<dd class="mt-1 text-xl font-extrabold text-rose-700">−{{ pesoExact(data.refunded) }}</dd>
						<p class="mt-1 text-xs text-rose-700/80">Already sent back to the buyers' cards or e-wallets. Nobody holds it.</p>
					</div>
					<div class="rounded-xl bg-slate-50 p-4">
						<dt class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-600"><span class="h-2.5 w-2.5 rounded-full bg-slate-400" />NaSeRy fee</dt>
						<dd class="mt-1 text-xl font-extrabold text-slate-700">−{{ pesoExact(data.platform_fee) }}</dd>
						<p class="mt-1 text-xs text-slate-500">The platform fee on each sale (buyers never pay extra).</p>
					</div>
					<div class="rounded-xl bg-emerald-50 p-4">
						<dt class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-emerald-700"><span class="h-2.5 w-2.5 rounded-full bg-emerald-600" />Your share</dt>
						<dd class="mt-1 text-xl font-extrabold text-emerald-700">{{ pesoExact(data.share) }}</dd>
						<p class="mt-1 text-xs text-emerald-700/80">Yours. NaSeRy pays it out to you (see below).</p>
					</div>
				</dl>
				<p class="mt-4 text-xs text-gray-400">Money paid outside NaSeRy (like a client paying you for a booked event) isn't here: NaSeRy only handles online ticket sales.</p>
			</section>

			<!-- Payout status -->
			<section class="mb-6 grid gap-5 md:grid-cols-2">
				<div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
					<p class="text-sm font-semibold text-gray-500">Paid out to you</p>
					<p class="mt-1 text-3xl font-extrabold text-gray-900">{{ pesoExact(data.paid_out) }}</p>
					<p class="mt-2 text-xs text-gray-500">{{ lastPayout ? `Last payout ${dateLabel(lastPayout.paid_at)} by ${lastPayout.method_label}.` : 'No payouts yet.' }}</p>
				</div>
				<div class="rounded-2xl border p-6 shadow-sm" :class="data.owed < 0 ? 'border-amber-200 bg-amber-50' : 'border-emerald-200 bg-emerald-50'">
					<p class="text-sm font-semibold" :class="data.owed < 0 ? 'text-amber-800' : 'text-emerald-800'">
						{{ data.owed < 0 ? 'To be taken off your next payout' : 'Still to be paid to you' }}
					</p>
					<p class="mt-1 text-3xl font-extrabold" :class="data.owed < 0 ? 'text-amber-800' : 'text-emerald-800'">{{ pesoExact(Math.abs(data.owed)) }}</p>
					<p class="mt-2 text-xs" :class="data.owed < 0 ? 'text-amber-800/80' : 'text-emerald-800/80'">
						{{ data.owed < 0
							? 'A refund came after we paid you out; NaSeRy deducts it from your next payout.'
							: 'NaSeRy transfers your share by bank, GCash or Maya. You get a notification with the reference number.' }}
					</p>
				</div>
			</section>

			<section class="mb-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
				<h2 class="text-base font-bold text-gray-900">Payout history</h2>
				<p v-if="!data.payouts.length" class="mt-3 text-sm text-gray-400">No payouts yet.</p>
				<div v-else class="mt-4 overflow-x-auto">
					<table class="w-full text-left text-sm">
						<thead>
							<tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
								<th class="pb-3 pr-4">Date</th><th class="pb-3 pr-4">Method</th><th class="pb-3 pr-4">Reference</th><th class="pb-3 pr-4">Amount</th><th class="pb-3">Note</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="p in data.payouts" :key="p.id" :class="p.voided ? 'text-gray-400' : ''">
								<td class="py-3 pr-4">{{ dateLabel(p.paid_at) }}</td>
								<td class="py-3 pr-4">{{ p.method_label }}</td>
								<td class="py-3 pr-4 font-mono text-xs">{{ p.reference }}</td>
								<td class="py-3 pr-4 font-semibold" :class="p.voided ? 'line-through' : 'text-gray-900'">{{ pesoExact(p.amount) }}</td>
								<td class="py-3 text-xs">
									<span v-if="p.voided" class="rounded-full bg-gray-100 px-2 py-0.5 font-semibold text-gray-600">Voided: {{ p.void_reason }}</span>
									<span v-else>{{ p.note || '—' }}</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<!-- Paid plans: the detailed view. -->
			<section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<div>
						<h2 class="text-base font-bold text-gray-900">Detailed breakdown</h2>
						<p class="mt-1 text-sm text-gray-500">Per event and per month, with a CSV of every payment.</p>
					</div>
					<button v-if="data.plan.details" type="button" :disabled="exporting"
						class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" @click="exportCsv">
						{{ exporting ? 'Preparing…' : 'Download CSV' }}
					</button>
				</div>

				<div v-if="!data.plan.details" class="mt-5 rounded-xl border border-dashed border-primary-200 bg-primary-50/40 p-5 text-sm">
					<p class="font-semibold text-gray-900">Available on Starter and Pro</p>
					<p class="mt-1 text-gray-600">See the split for each event, month by month, and download every payment with its fee and refunds.</p>
					<NuxtLink to="/organizer/billing" class="mt-3 inline-flex rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white">See plans</NuxtLink>
				</div>

				<template v-else-if="details">
					<div class="mt-5 overflow-x-auto">
						<table class="w-full text-left text-sm">
							<thead>
								<tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
									<th class="pb-3 pr-4">Event</th><th class="pb-3 pr-4">Ticket sales</th><th class="pb-3 pr-4">Refunded</th><th class="pb-3 pr-4">NaSeRy fee</th><th class="pb-3">Your share</th>
								</tr>
							</thead>
							<tbody class="divide-y divide-gray-100">
								<tr v-for="e in details.events" :key="e.id">
									<td class="py-3 pr-4 font-semibold text-gray-900">{{ e.name }} <span class="text-xs font-normal text-gray-400">· {{ e.payments }} payments</span></td>
									<td class="py-3 pr-4">{{ pesoExact(e.gross) }}</td>
									<td class="py-3 pr-4 text-rose-700">−{{ pesoExact(e.refunded) }}</td>
									<td class="py-3 pr-4 text-slate-600">−{{ pesoExact(e.platform_fee) }}</td>
									<td class="py-3 font-semibold text-emerald-700">{{ pesoExact(e.share) }}</td>
								</tr>
								<tr v-if="!details.events.length"><td colspan="5" class="py-4 text-center text-gray-400">No ticket sales yet.</td></tr>
							</tbody>
						</table>
					</div>

					<h3 class="mt-8 text-sm font-bold text-gray-900">Your share by month</h3>
					<div class="mt-4 flex h-44 items-end gap-3">
						<div v-for="m in details.months" :key="m.month" class="flex flex-1 flex-col items-center gap-2">
							<span class="text-[11px] font-semibold text-gray-600">{{ m.share ? pesoShort(m.share) : '' }}</span>
							<div class="w-full rounded-t-md bg-emerald-500" :style="{ height: `${barHeight(m.share)}px` }" :title="`${monthLabel(m.month)}: ${pesoExact(m.share)}`" />
							<span class="text-[11px] text-gray-400">{{ monthLabel(m.month) }}</span>
						</div>
					</div>
				</template>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { EarningsDetails, EarningsSummary } from '~/composables/useEarnings'
import { pesoExact } from '~/composables/useEarnings'

definePageMeta({ layout: 'dashboard' })
useSeoMeta({ title: 'Earnings | NaSeRy' })

const earnings = useEarnings()
const { fetchOrganization } = useOrganization()
const data = ref<EarningsSummary | null>(null)
const details = ref<EarningsDetails | null>(null)
const error = ref('')
const exporting = ref(false)
let orgId = ''

const lastPayout = computed(() => data.value?.payouts.find(p => !p.voided) ?? null)
const pct = (n: number) => `${data.value?.gross ? Math.max(0, (n / data.value.gross) * 100) : 0}%`
const dateLabel = (at: string) => new Date(at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
const monthLabel = (ym: string) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('en-PH', { month: 'short' })
const pesoShort = (n: number) => (Math.abs(n) >= 1000 ? `₱${(n / 1000).toFixed(1)}K` : `₱${Math.round(n)}`)
const barHeight = (n: number) => {
	const max = Math.max(1, ...(details.value?.months.map(m => m.share) ?? [1]))
	return Math.max(2, Math.round((Math.max(0, n) / max) * 120))
}

async function load() {
	error.value = ''
	try {
		const org = await fetchOrganization()
		if (!org) return
		orgId = org.id
		data.value = await earnings.summary(orgId)
		if (data.value.plan.details) details.value = await earnings.details(orgId, 6)
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function exportCsv() {
	exporting.value = true
	try {
		const blob = await earnings.exportCsv(orgId)
		const url = URL.createObjectURL(blob)
		const a = Object.assign(document.createElement('a'), { href: url, download: `earnings-${new Date().toISOString().slice(0, 10)}.csv` })
		a.click()
		URL.revokeObjectURL(url)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		exporting.value = false
	}
}

onMounted(load)
</script>
