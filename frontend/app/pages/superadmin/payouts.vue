<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold tracking-tight text-gray-950">Payouts</h1>
			<p class="mt-1 max-w-3xl text-gray-500">Online ticket payments land in NaSeRy's PayMongo account. Each organizer's share (ticket sales − refunds to buyers − NaSeRy's fee) is paid out by NaSeRy and recorded here.</p>
		</div>

		<div v-if="error" class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ error }}</div>
		<div v-if="notice" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">{{ notice }}</div>

		<div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<SuperadminStatCard label="Organizers' Share" :value="pesoExact(totals.share)" icon="users" tone="primary" hint="Ticket sales − refunds − NaSeRy fee" />
			<SuperadminStatCard label="Paid Out" :value="pesoExact(totals.paid_out)" icon="check-circle" tone="green" value-class="text-green-600" hint="Recorded transfers" />
			<SuperadminStatCard label="Still to Pay" :value="pesoExact(totals.owed)" icon="clock" tone="amber" value-class="text-amber-600" hint="What NaSeRy owes organizers now" />
		</div>

		<div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
			<h2 class="text-base font-bold text-gray-900">Organizers</h2>
			<div v-if="!rows" class="py-8 text-center text-sm text-gray-400">Loading…</div>
			<p v-else-if="!rows.length" class="py-8 text-center text-sm text-gray-400">No ticket sales yet.</p>
			<div v-else class="mt-4 overflow-x-auto">
				<table class="w-full text-left text-sm">
					<thead>
						<tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
							<th class="pb-3 pr-4">Organizer</th><th class="pb-3 pr-4">Ticket sales</th><th class="pb-3 pr-4">Refunded</th><th class="pb-3 pr-4">NaSeRy fee</th>
							<th class="pb-3 pr-4">Their share</th><th class="pb-3 pr-4">Paid out</th><th class="pb-3 pr-4">Still to pay</th><th class="pb-3" />
						</tr>
					</thead>
					<tbody class="divide-y divide-gray-100">
						<template v-for="r in rows" :key="r.organization.id">
							<tr>
								<td class="py-3.5 pr-4">
									<p class="font-semibold text-gray-900">{{ r.organization.name }}</p>
									<p class="text-xs text-gray-400">{{ r.last_payout_at ? `Last payout ${dateLabel(r.last_payout_at)}` : 'Never paid out' }}</p>
								</td>
								<td class="py-3.5 pr-4">{{ pesoExact(r.gross) }}</td>
								<td class="py-3.5 pr-4 text-rose-700">−{{ pesoExact(r.refunded) }}</td>
								<td class="py-3.5 pr-4 text-slate-600">−{{ pesoExact(r.platform_fee) }}</td>
								<td class="py-3.5 pr-4 font-semibold text-gray-900">{{ pesoExact(r.share) }}</td>
								<td class="py-3.5 pr-4">{{ pesoExact(r.paid_out) }}</td>
								<td class="py-3.5 pr-4 font-bold" :class="r.owed > 0 ? 'text-amber-600' : r.owed < 0 ? 'text-rose-600' : 'text-gray-400'">{{ pesoExact(r.owed) }}</td>
								<td class="py-3.5 text-right">
									<button type="button" class="text-xs font-semibold text-primary-700 hover:text-primary-900" @click="toggle(r.organization.id)">
										{{ open === r.organization.id ? 'Close' : r.owed > 0 ? 'Record payout' : 'History' }}
									</button>
								</td>
							</tr>
							<tr v-if="open === r.organization.id">
								<td colspan="8" class="bg-gray-50 p-5">
									<form v-if="r.owed > 0" class="grid gap-3 sm:grid-cols-6" @submit.prevent="record(r.organization.id)">
										<label class="text-xs font-semibold text-gray-600 sm:col-span-1">Amount (₱)
											<input v-model.number="form.amount" type="number" min="1" step="0.01" :max="r.owed" required class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
										</label>
										<label class="text-xs font-semibold text-gray-600 sm:col-span-1">Method
											<select v-model="form.method" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
												<option v-for="(label, key) in methods" :key="key" :value="key">{{ label }}</option>
											</select>
										</label>
										<label class="text-xs font-semibold text-gray-600 sm:col-span-2">Reference no.
											<input v-model="form.reference" required minlength="3" maxlength="100" placeholder="e.g. GCash ref 1234 5678" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
										</label>
										<label class="text-xs font-semibold text-gray-600 sm:col-span-2">Note (optional)
											<input v-model="form.note" maxlength="500" placeholder="e.g. Payout for September" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
										</label>
										<div class="sm:col-span-6">
											<button type="submit" :disabled="busy" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
												{{ busy ? 'Saving…' : `Record ${pesoExact(Number(form.amount) || 0)} payout` }}
											</button>
											<span class="ml-2 text-xs text-gray-500">Make the transfer first, then record it here. The organizer is notified with the reference.</span>
										</div>
									</form>

									<h3 class="mt-5 text-xs font-bold uppercase tracking-wide text-gray-500">Payout history</h3>
									<p v-if="!history.length" class="mt-2 text-sm text-gray-400">No payouts yet.</p>
									<ul v-else class="mt-2 divide-y divide-gray-200">
										<li v-for="p in history" :key="p.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm">
											<div :class="p.voided ? 'text-gray-400' : ''">
												<span class="font-semibold" :class="p.voided ? 'line-through' : 'text-gray-900'">{{ pesoExact(p.amount) }}</span>
												· {{ p.method_label }} · <span class="font-mono text-xs">{{ p.reference }}</span> · {{ dateLabel(p.paid_at) }}
												<span v-if="p.voided" class="ml-1 rounded-full bg-gray-200 px-2 py-0.5 text-xs">Voided: {{ p.void_reason }}</span>
												<span v-else-if="p.note" class="text-xs text-gray-500"> · {{ p.note }}</span>
											</div>
											<form v-if="!p.voided && voiding === p.id" class="flex gap-2" @submit.prevent="voidPayout(p.id)">
												<input v-model="voidReason" required minlength="5" placeholder="Why? (logged, sent to the organizer)" class="w-64 rounded-xl border border-gray-200 px-3 py-1.5 text-xs">
												<button type="submit" :disabled="busy" class="rounded-xl bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white">Void</button>
												<button type="button" class="text-xs text-gray-500" @click="voiding = null">Cancel</button>
											</form>
											<button v-else-if="!p.voided" type="button" class="text-xs font-semibold text-rose-600" @click="voiding = p.id; voidReason = ''">Void…</button>
										</li>
									</ul>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { Payout, PayoutBalance } from '~/composables/useEarnings'
import { pesoExact } from '~/composables/useEarnings'

definePageMeta({ layout: 'superadmin', middleware: ['superadmin'] })
useSeoMeta({ title: 'Payouts | NaSeRy Admin' })

const api = useEarnings()
const rows = ref<PayoutBalance[] | null>(null)
const methods = ref<Record<string, string>>({ bank_transfer: 'Bank transfer', gcash: 'GCash', maya: 'Maya', other: 'Other' })
const open = ref<string | null>(null)
const history = ref<Payout[]>([])
const form = reactive({ amount: 0, method: 'gcash', reference: '', note: '' })
const busy = ref(false)
const error = ref('')
const notice = ref('')
const voiding = ref<string | null>(null)
const voidReason = ref('')

const totals = computed(() => (rows.value ?? []).reduce((t, r) => ({
	share: t.share + r.share, paid_out: t.paid_out + r.paid_out, owed: t.owed + Math.max(0, r.owed),
}), { share: 0, paid_out: 0, owed: 0 }))

const dateLabel = (at: string) => new Date(at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })

async function load() {
	try {
		const data = await api.balances()
		rows.value = data.organizers
		methods.value = data.methods
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function toggle(orgId: string) {
	if (open.value === orgId) { open.value = null; return }
	open.value = orgId
	voiding.value = null
	const row = rows.value?.find(r => r.organization.id === orgId)
	Object.assign(form, { amount: Math.max(0, Math.round((row?.owed ?? 0) * 100) / 100), method: 'gcash', reference: '', note: '' })
	history.value = await api.payouts(orgId)
}

async function run(fn: () => Promise<unknown>, message: string) {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		await fn()
		notice.value = message
		await load()
		if (open.value) history.value = await api.payouts(open.value)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

const record = (orgId: string) => run(
	() => api.record(orgId, { amount: Number(form.amount), method: form.method, reference: form.reference.trim(), note: form.note.trim() || null }),
	'Payout recorded. The organizer was notified with the reference.',
)

const voidPayout = (id: string) => run(async () => { await api.voidPayout(id, voidReason.value.trim()); voiding.value = null }, 'Payout voided. It counts as still to be paid again.')

onMounted(load)
</script>
