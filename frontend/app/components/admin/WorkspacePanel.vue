<template>
	<section class="rounded-2xl border border-gray-200 bg-white p-6">
		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<h2 class="text-base font-bold text-gray-900">Workspace</h2>
				<p class="mt-1 text-sm text-gray-500">Events, clients, team, payments and reviews. Visible only with {{ organizationName }}'s consent, read-only.</p>
			</div>
			<div v-if="access?.grant" class="text-right">
				<span class="inline-flex rounded-full bg-green-50 px-3 py-1 text-xs font-semibold text-green-700">Access until {{ dateTime(access.grant.expires_at) }}</span>
				<button type="button" class="mt-1 block w-full text-right text-xs font-semibold text-rose-700" :disabled="busy" @click="end">End access now</button>
			</div>
		</div>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />

		<!-- No access: ask -->
		<form v-if="access && !access.grant && !access.pending_request_id" class="mt-4 space-y-3 rounded-xl bg-gray-50 p-4" @submit.prevent="request">
			<p class="text-sm text-gray-700">The organizer is notified and can approve or refuse. Approved access is read-only and ends automatically.</p>
			<div>
				<label for="access-reason" class="text-xs font-semibold text-gray-600">Reason (shown to the organizer)</label>
				<textarea id="access-reason" v-model="reason" rows="2" maxlength="1000" placeholder="e.g. Looking into a buyer's payment complaint"
					class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm" />
			</div>
			<div class="flex flex-wrap items-end gap-3">
				<div>
					<label for="access-hours" class="text-xs font-semibold text-gray-600">For</label>
					<select id="access-hours" v-model.number="hours" class="mt-1 block rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
						<option v-for="h in hourOptions" :key="h" :value="h">{{ h }} hour{{ h === 1 ? '' : 's' }}</option>
					</select>
				</div>
				<button type="submit" :disabled="busy || reason.trim().length < 5" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Request access</button>
			</div>
		</form>

		<div v-else-if="access?.pending_request_id" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
			<span>Waiting for {{ organizationName }} to answer your access request.</span>
			<button type="button" class="font-semibold text-amber-900 underline" :disabled="busy" @click="withdraw">Withdraw</button>
		</div>

		<!-- Granted: the read-only view -->
		<template v-if="access?.grant">
			<div v-if="!ws && !error" class="py-8 text-center text-sm text-gray-400">Loading workspace…</div>
			<template v-if="ws">
				<p class="mt-3 text-xs text-gray-500">Reason on record: {{ ws.grant.reason }}. Each opening is logged for the organizer.</p>
				<div class="mt-4 flex flex-wrap gap-1 border-b border-gray-100" role="tablist">
					<button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
						class="-mb-px border-b-2 px-3 py-2 text-sm font-semibold"
						:class="tab === t.key ? 'border-primary-700 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
						@click="tab = t.key">{{ t.label }} <span class="text-gray-400">{{ t.count }}</span></button>
				</div>

				<div class="mt-4 overflow-x-auto">
					<table v-if="tab === 'events'" class="w-full text-left text-sm">
						<thead><tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
							<th class="pb-2 pr-4">Event</th><th class="pb-2 pr-4">Client</th><th class="pb-2 pr-4">Date</th><th class="pb-2 pr-4">Tickets</th><th class="pb-2 pr-4 text-right">Sales</th><th class="pb-2">Status</th>
						</tr></thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="e in ws.events" :key="e.id">
								<td class="py-2.5 pr-4 font-semibold text-gray-900">{{ e.name }} <span class="text-xs font-normal text-gray-400">{{ e.visibility }}</span></td>
								<td class="py-2.5 pr-4 text-gray-600">{{ e.client ?? '—' }}</td>
								<td class="py-2.5 pr-4 text-gray-600">{{ shortDate(e.starts_at) }}</td>
								<td class="py-2.5 pr-4 text-gray-700">{{ e.tickets_sold }}<template v-if="e.capacity">/{{ e.capacity }}</template></td>
								<td class="py-2.5 pr-4 text-right text-gray-700">{{ peso(e.sales) }}</td>
								<td class="py-2.5 capitalize text-gray-600">{{ e.status }}</td>
							</tr>
							<tr v-if="!ws.events.length"><td colspan="6" class="py-6 text-center text-gray-400">No events.</td></tr>
						</tbody>
					</table>

					<ul v-else-if="tab === 'clients'" class="flex flex-wrap gap-2">
						<li v-for="c in ws.clients" :key="c.name" class="rounded-full bg-gray-100 px-3 py-1.5 text-sm text-gray-700">{{ c.name }} <span class="text-gray-400">· {{ c.bookings }} booking{{ c.bookings === 1 ? '' : 's' }}</span></li>
						<li v-if="!ws.clients.length" class="text-sm text-gray-400">No clients yet.</li>
					</ul>

					<table v-else-if="tab === 'team'" class="w-full text-left text-sm">
						<thead><tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
							<th class="pb-2 pr-4">Name</th><th class="pb-2 pr-4">E-mail</th><th class="pb-2 pr-4">Role</th><th class="pb-2">Status</th>
						</tr></thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="m in ws.team" :key="m.email">
								<td class="py-2.5 pr-4 font-semibold text-gray-900">{{ m.name }}</td>
								<td class="py-2.5 pr-4 text-gray-600">{{ m.email }}</td>
								<td class="py-2.5 pr-4 text-gray-600">{{ m.role }}</td>
								<td class="py-2.5 capitalize text-gray-600">{{ m.status }}</td>
							</tr>
						</tbody>
					</table>

					<div v-else-if="tab === 'payments'">
						<dl class="grid grid-cols-2 gap-3 xl:grid-cols-4">
							<div class="rounded-xl bg-gray-50 p-3"><dt class="text-xs text-gray-500">Paid orders</dt><dd class="text-base font-bold">{{ ws.payments.paid_count }}</dd></div>
							<div class="rounded-xl bg-gray-50 p-3"><dt class="text-xs text-gray-500">Sales</dt><dd class="text-base font-bold">{{ peso(ws.payments.sales) }}</dd></div>
							<div class="rounded-xl bg-gray-50 p-3"><dt class="text-xs text-gray-500">Refunded</dt><dd class="text-base font-bold">{{ peso(ws.payments.refunded) }}</dd></div>
							<div class="rounded-xl bg-gray-50 p-3"><dt class="text-xs text-gray-500">Fee charged</dt><dd class="text-base font-bold">{{ peso(ws.payments.fee) }}</dd></div>
						</dl>
						<h3 class="mt-4 text-sm font-bold text-gray-900">Recent refunds</h3>
						<ul class="mt-2 divide-y divide-gray-100 text-sm">
							<li v-for="(r, i) in ws.payments.recent_refunds" :key="i" class="flex flex-wrap justify-between gap-2 py-2">
								<span class="text-gray-700">{{ r.event }} · {{ r.reason }}</span>
								<span class="text-gray-900">{{ peso(r.amount) }} <span class="text-xs text-gray-400">fee back {{ r.fee_returned !== null ? peso(r.fee_returned) : '—' }} · {{ shortDate(r.at) }}</span></span>
							</li>
							<li v-if="!ws.payments.recent_refunds.length" class="py-2 text-gray-400">No refunds.</li>
						</ul>
					</div>

					<ul v-else class="space-y-3">
						<li v-for="r in ws.reviews" :key="r.id" class="rounded-xl border border-gray-100 p-3 text-sm">
							<div class="flex flex-wrap items-center justify-between gap-2">
								<StarRating :model-value="r.rating" readonly />
								<span class="text-xs text-gray-500">{{ r.reviewer }} · {{ r.event }} · {{ shortDate(r.at) }}<template v-if="r.status !== 'published'"> · <b>{{ r.status }}</b></template></span>
							</div>
							<p v-if="r.text" class="mt-1 text-gray-700">{{ r.text }}</p>
						</li>
						<li v-if="!ws.reviews.length" class="text-sm text-gray-400">No reviews.</li>
					</ul>
				</div>
			</template>
		</template>
	</section>
</template>

<script setup lang="ts">
import type { Workspace } from '~/composables/useAdmin'

const props = defineProps<{
	organizationId: string
	organizationName: string
	access?: { grant: { id: string, expires_at: string } | null, pending_request_id: string | null, default_hours: number }
}>()
const emit = defineEmits<{ changed: [] }>()

const admin = useAdmin()
const ws = ref<Workspace | null>(null)
const error = ref('')
const busy = ref(false)
const reason = ref('')
const hours = ref(props.access?.default_hours ?? 24)
const tab = ref<'events' | 'clients' | 'team' | 'payments' | 'reviews'>('events')

const hourOptions = computed(() => [...new Set([1, 4, 8, props.access?.default_hours ?? 24])].sort((a, b) => a - b))
const tabs = computed(() => ws.value ? [
	{ key: 'events' as const, label: 'Events', count: ws.value.events.length },
	{ key: 'clients' as const, label: 'Clients', count: ws.value.clients.length },
	{ key: 'team' as const, label: 'Team', count: ws.value.team.length },
	{ key: 'payments' as const, label: 'Payments', count: ws.value.payments.paid_count },
	{ key: 'reviews' as const, label: 'Reviews', count: ws.value.reviews.length },
] : [])

async function act(fn: () => Promise<unknown>) {
	busy.value = true
	error.value = ''
	try {
		await fn()
		emit('changed')
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

const request = () => act(async () => {
	await admin.requestAccess(props.organizationId, reason.value.trim(), hours.value)
	reason.value = ''
})
const withdraw = () => act(() => admin.cancelRequest(props.access!.pending_request_id!))
const end = () => confirm('End your access to this workspace now?') && act(() => admin.endGrant(props.access!.grant!.id))

watch(() => props.access?.grant?.id, async (id) => {
	ws.value = null
	if (!id) return
	try {
		ws.value = await admin.workspace(props.organizationId)
	} catch (e) {
		error.value = authMessage(e)
	}
}, { immediate: true })
</script>
