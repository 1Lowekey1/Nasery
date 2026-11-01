<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />
		<div v-if="!eventId" class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-10 text-center text-sm text-gray-500">Choose an event above.</div>
		<div v-else-if="!data && !error" class="py-12 text-center text-sm text-gray-500">Loading payments…</div>

		<template v-if="data">
			<div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
				<div v-for="card in totals" :key="card.label" class="rounded-2xl border border-gray-200 bg-white p-5">
					<p class="text-sm text-gray-500">{{ card.label }}</p>
					<p class="mt-1 text-2xl font-extrabold text-gray-900">{{ formatPeso(card.value) || '₱0' }}</p>
					<p class="mt-1 text-xs text-gray-400">{{ card.hint }}</p>
				</div>
			</div>

			<div class="rounded-2xl border border-gray-200 bg-white p-6">
				<h3 class="mb-3 text-base font-bold text-gray-900">Online payments</h3>
				<div class="overflow-x-auto">
					<table class="w-full min-w-[720px] text-left text-sm">
						<thead class="text-xs uppercase text-gray-400">
							<tr><th class="py-2">Buyer</th><th>Tickets</th><th>Amount</th><th>Fee</th><th>Refunded</th><th>Status</th><th class="text-right" /></tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="p in data.payments" :key="p.id">
								<td class="py-2.5"><p class="font-semibold text-gray-900">{{ p.buyer }}</p><p class="text-xs text-gray-500">{{ p.email }}</p></td>
								<td>{{ p.status === 'paid' ? p.tickets : '—' }}</td>
								<td>{{ formatPeso(p.amount) }}</td>
								<td class="text-gray-500">{{ p.fee ? formatPeso(p.fee) : '—' }}</td>
								<td class="text-gray-500">{{ Number(p.refunded) ? formatPeso(p.refunded) : '—' }}</td>
								<td>
									<span class="rounded-full px-2 py-0.5 text-xs font-semibold"
										:class="p.status === 'paid' ? 'bg-green-50 text-green-700' : p.status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-gray-100 text-gray-500'">
										{{ statusLabel(p) }}
									</span>
									<span v-if="p.failure_reason && p.status === 'failed'" class="block text-xs text-gray-400">{{ p.failure_reason }}</span>
								</td>
								<td class="text-right">
									<button v-if="p.status === 'paid' && Number(p.refunded) < Number(p.amount)" type="button" class="text-xs font-semibold text-rose-700"
										@click="startRefund(p)">Refund</button>
								</td>
							</tr>
							<tr v-if="!data.payments.length"><td colspan="7" class="py-6 text-center text-gray-400">No online payments yet.</td></tr>
						</tbody>
					</table>
				</div>
				<p class="mt-3 text-xs text-gray-400">NaSeRy's platform fee is deducted from each payment (buyers pay the ticket price only). Refunds are recorded separately; the original payment stays on record. The fee is returned only for force majeure or payment errors, not for refunds caused by the organizer or requested by the buyer.</p>
			</div>
		</template>

		<div v-if="refunding" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="refunding = null">
			<form class="w-full max-w-md rounded-2xl bg-white p-6" @submit.prevent="refund">
				<h2 class="text-lg font-bold text-gray-900">Refund {{ refunding.buyer }}</h2>
				<p class="mt-1 text-sm text-gray-500">Paid {{ formatPeso(refunding.amount) }}<template v-if="Number(refunding.refunded)">, already refunded {{ formatPeso(refunding.refunded) }}</template>.</p>
				<Alert v-if="formError" type="danger" :text="formError" class="mt-3" />
				<div class="mt-4 space-y-3">
					<div>
						<FormsLabel text="Amount (₱)" />
						<FormsTextField v-model="refundForm.amount" type="number" min="1" step="0.01" />
					</div>
					<div>
						<FormsLabel text="Why is this refunded?" />
						<select v-model="refundForm.category" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
							<option value="" disabled>Choose a reason</option>
							<option v-for="c in refundCategories" :key="c.value" :value="c.value">{{ c.label }}</option>
						</select>
						<p v-if="chosenCategory" class="mt-1 text-xs text-gray-500">{{ chosenCategory.hint }}.</p>
						<p v-if="chosenCategory" class="mt-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold"
							:class="chosenCategory.returnsFee ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-800'">
							{{ chosenCategory.returnsFee ? 'NaSeRy returns its platform fee for this share of the payment.' : 'NaSeRy keeps its platform fee; the refund comes from your share.' }}
						</p>
					</div>
					<div>
						<FormsLabel text="Details" />
						<FormsTextField v-model="refundForm.reason" placeholder="e.g. Guest can't attend" />
					</div>
					<label class="flex items-center gap-2 text-sm"><input v-model="refundForm.cancel_tickets" type="checkbox"> Cancel this buyer's tickets (their QR codes stop working)</label>
				</div>
				<div class="mt-5 flex justify-end gap-2">
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="refunding = null">Close</button>
					<button type="submit" :disabled="busy || !refundForm.category || refundForm.reason.trim().length < 3" class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Refund</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script setup lang="ts">
import { refundCategories, type EventPayment, type RefundCategory } from '~/composables/useTicketing'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = usePayments()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const data = ref<{ payments: EventPayment[], totals: { gross: string, fees: string, fee_returned: string, refunds: string, net: string } } | null>(null)
const error = ref('')
const notice = ref('')
const formError = ref('')
const busy = ref(false)
const refunding = ref<EventPayment | null>(null)
const refundForm = reactive({ amount: '' as string | number, category: '' as RefundCategory | '', reason: '', cancel_tickets: true })
const chosenCategory = computed(() => refundCategories.find(c => c.value === refundForm.category))

const totals = computed(() => {
	const t = data.value!.totals
	return [
		{ label: 'Gross sales', value: t.gross, hint: 'Paid online' },
		{ label: 'Platform fee', value: t.fees, hint: Number(t.fee_returned) ? `Deducted by NaSeRy (${formatPeso(t.fee_returned)} returned)` : 'Deducted by NaSeRy' },
		{ label: 'Refunds', value: t.refunds, hint: 'Returned to buyers' },
		{ label: 'Net to you', value: t.net, hint: 'Gross − fee − refunds' },
	]
})

/** quiet: a background refresh (keeps what's on screen). */
async function load(quiet = false) {
	if (!eventId.value) return
	error.value = ''
	if (!quiet) data.value = null
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) data.value = await api.eventPayments(org.id, eventId.value)
	} catch (e) {
		error.value = authMessage(e)
	}
}

function statusLabel(p: EventPayment) {
	if (p.status === 'paid' && Number(p.refunded) >= Number(p.amount)) return 'Refunded'
	if (p.status === 'paid' && Number(p.refunded) > 0) return 'Partly refunded'
	return { paid: 'Paid', pending: 'Awaiting payment', failed: 'Failed' }[p.status]
}

function startRefund(p: EventPayment) {
	formError.value = ''
	refunding.value = p
	Object.assign(refundForm, { amount: (Number(p.amount) - Number(p.refunded)).toFixed(2), category: '', reason: '', cancel_tickets: true })
}

async function refund() {
	formError.value = ''
	busy.value = true
	try {
		await api.refund(organization.value!.id, eventId.value!, refunding.value!.id, {
			amount: Number(refundForm.amount), category: refundForm.category as RefundCategory, reason: refundForm.reason.trim(), cancel_tickets: refundForm.cancel_tickets,
		})
		refunding.value = null
		notice.value = 'Refund recorded.'
		await load()
	} catch (e) {
		formError.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

watch(eventId, () => load())
onMounted(() => load())
// New payments come in while tickets sell; paused while a refund is being filled in.
useLiveRefresh(() => load(true), { interval: 30000, paused: () => !!refunding.value || busy.value })
</script>
