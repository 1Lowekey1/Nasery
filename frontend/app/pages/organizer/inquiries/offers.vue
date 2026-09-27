<template>
	<div>
		<div class="mb-4 flex flex-wrap gap-2">
			<button v-for="f in filters" :key="f.label" type="button"
				class="rounded-full border px-3 py-1.5 text-xs font-semibold"
				:class="activeFilter === f.label ? 'border-primary-600 bg-primary-50 text-primary-800' : 'border-gray-200 text-gray-600'"
				@click="activeFilter = f.label; load(1)">{{ f.label }}</button>
		</div>

		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading quotations…</div>

		<div v-else-if="offers.length" class="space-y-5">
			<div v-for="offer in offers" :key="offer.id" class="rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div class="min-w-0">
						<h3 class="text-lg font-bold text-gray-900">{{ offer.package_name || `${offer.inquiry?.event_type ?? 'Event'} quotation` }}</h3>
						<p class="mt-1 text-sm text-gray-500">
							{{ offer.inquiry?.event_type ?? 'Event' }} for {{ offer.client?.name ?? 'client' }}
							<template v-if="offer.inquiry?.requested_date"> · {{ formatDate(offer.inquiry.requested_date) }}</template>
							<template v-if="offer.inquiry?.guest_count"> · {{ offer.inquiry.guest_count }} guests</template>
						</p>
					</div>
					<span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold" :class="quotationStatusClasses[offer.status]">
						{{ quotationStatusLabels[offer.status] }}
					</span>
				</div>

				<div v-if="offer.status === 'accepted' && offer.client" class="mt-4 rounded-xl bg-green-50 p-4 text-sm text-green-900">
					<p class="font-bold">The client accepted your quotation.</p>
					<p class="mt-1">Reach {{ offer.client.name }} to agree the final terms:
						<a v-if="offer.client.email" :href="`mailto:${offer.client.email}`" class="font-semibold underline">{{ offer.client.email }}</a>
						<template v-if="offer.client.phone"> · {{ offer.client.phone }}</template>
					</p>
					<div class="mt-3">
						<NuxtLink v-if="offer.event_id" :to="`/organizer/eventmanagement/event-details?event=${offer.event_id}`"
							class="inline-block rounded-xl bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800">Open the event</NuxtLink>
						<button v-else type="button" class="rounded-xl bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-800"
							@click="converting = offer">Create the event</button>
					</div>
				</div>

				<div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="text-xs text-gray-500">Offer Amount</div>
						<div class="mt-1 text-lg font-bold text-primary-700">{{ formatPeso(offer.total_amount) }}</div>
					</div>
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="text-xs text-gray-500">{{ offer.submitted_at ? 'Sent' : 'Last saved' }}</div>
						<div class="mt-1 text-sm font-bold text-gray-900">{{ formatDate(offer.submitted_at ?? offer.updated_at) }}</div>
					</div>
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="text-xs text-gray-500">Client budget</div>
						<div class="mt-1 text-sm font-bold text-gray-900">{{ offer.inquiry ? budgetLabel(offer.inquiry.budget_min, offer.inquiry.budget_max) : '—' }}</div>
					</div>
				</div>

				<div class="mt-4">
					<div class="mb-2 text-xs font-semibold tracking-wide text-gray-400">COST BREAKDOWN</div>
					<div class="divide-y divide-gray-100">
						<div v-for="line in offer.items" :key="line.id" class="flex items-center justify-between gap-3 py-2 text-sm">
							<span class="text-gray-600">{{ line.description }}<span v-if="Number(line.quantity) !== 1" class="text-gray-400"> × {{ Number(line.quantity) }}</span></span>
							<span class="font-semibold text-gray-900">{{ formatPeso(line.subtotal) }}</span>
						</div>
					</div>
					<div class="mt-1 flex items-center justify-between border-t border-gray-200 pt-2 text-sm font-bold text-gray-900">
						<span>Total</span><span>{{ formatPeso(offer.total_amount) }}</span>
					</div>
				</div>

				<p v-if="offer.proposal && expanded.has(offer.id)" class="mt-4 whitespace-pre-line text-sm text-gray-600">{{ offer.proposal }}</p>
				<p v-if="offer.terms && expanded.has(offer.id)" class="mt-2 whitespace-pre-line text-xs text-gray-500">Terms: {{ offer.terms }}</p>

				<div class="mt-5 flex flex-wrap gap-3">
					<button v-if="(offer.proposal || offer.terms) && !expanded.has(offer.id)" type="button"
						class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
						@click="expanded.add(offer.id)">View proposal</button>
					<template v-if="isOpen(offer)">
						<button v-if="offer.status === 'draft' || offer.status === 'submitted'" type="button"
							class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900"
							@click="editing = offer">{{ offer.status === 'draft' ? 'Edit & send' : 'Edit offer' }}</button>
						<button v-if="offer.status === 'submitted'" type="button"
							class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50"
							@click="withdrawing = offer; withdrawReason = ''">Withdraw</button>
					</template>
					<button v-if="offer.status === 'draft'" type="button"
						class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50"
						@click="removeDraft(offer)">Delete draft</button>
					<button type="button" class="relative flex items-center gap-2 rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
						:aria-label="messages.unread.value[offer.inquiry_id] ? `Messages, ${messages.unread.value[offer.inquiry_id]} unread` : 'Messages'"
						@click="messages.openFor(organization!.id, offer.inquiry_id, offer.client?.name ?? 'Client')">
						<IconBase name="message" class="h-4 w-4" /> Messages
						<span v-if="messages.unread.value[offer.inquiry_id]" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-bold text-white">{{ messages.unread.value[offer.inquiry_id] }}</span>
					</button>
				</div>
			</div>

			<div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2 pt-2">
				<button type="button" :disabled="page <= 1" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page - 1)">Previous</button>
				<span class="px-2 py-1.5 text-sm text-gray-500">Page {{ page }} of {{ pagination.last_page }}</span>
				<button type="button" :disabled="page >= pagination.last_page" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page + 1)">Next</button>
			</div>
		</div>

		<div v-else class="rounded-3xl border-2 border-dashed border-gray-200 bg-gray-50/60 px-6 py-16 text-center">
			<h2 class="text-lg font-bold text-gray-900">No quotations here yet</h2>
			<p class="mt-1 text-sm text-gray-500">Send one from <NuxtLink to="/organizer/inquiries/match-inquiries" class="font-semibold text-primary-700">Matching Inquiries</NuxtLink>.</p>
		</div>

		<OrganizerQuotationForm v-if="editing" :organization-id="organization!.id" :inquiry-id="editing.inquiry_id"
			:event-label="`${editing.inquiry?.event_type ?? 'Event'} for ${editing.client?.name ?? 'client'}`"
			:guest-count="editing.inquiry?.guest_count ?? null" :quotation="editing"
			@close="editing = null" @saved="onSaved" />

		<OrganizerEventForm v-if="converting" :organization-id="organization!.id" :quotation="converting"
			@close="converting = null" @saved="onEventCreated" />

		<div v-if="withdrawing" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="withdrawing = null">
			<form class="w-full max-w-md rounded-2xl bg-white p-6" @submit.prevent="confirmWithdraw">
				<h2 class="text-lg font-bold text-gray-900">Withdraw this quotation?</h2>
				<p class="mt-1 text-sm text-gray-500">The client will no longer be able to accept it. You can send a new one while the inquiry is open.</p>
				<textarea v-model="withdrawReason" rows="3" maxlength="500" placeholder="Reason (the client sees this)"
					class="mt-4 w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
				<div class="mt-4 flex justify-end gap-2">
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="withdrawing = null">Keep it</button>
					<button type="submit" :disabled="busy || withdrawReason.trim().length < 3"
						class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Withdraw</button>
				</div>
			</form>
		</div>

		<OrganizerThreadDrawer :thread-id="messages.open.value?.id ?? null" :title="messages.open.value?.title ?? ''"
			@close="messages.open.value = null" @read="messages.markRead()" />
	</div>
</template>

<script setup lang="ts">
import type { OrganizerQuotation, QuotationStatus } from '~/composables/useQuotations'
import type { OrganizerEvent } from '~/composables/useEvents'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerQuotations()

const filters: { label: string, status: QuotationStatus[] }[] = [
	{ label: 'Active', status: ['draft', 'submitted'] },
	{ label: 'Won', status: ['accepted'] },
	{ label: 'Closed', status: ['rejected', 'withdrawn', 'cancelled'] },
	{ label: 'All', status: [] },
]
const activeFilter = ref('Active')
const offers = ref<OrganizerQuotation[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const page = ref(1)
const loading = ref(true)
const error = ref('')
const notice = ref('')
const expanded = reactive(new Set<string>())
const editing = ref<OrganizerQuotation | null>(null)
const converting = ref<OrganizerQuotation | null>(null)

async function onEventCreated(event: OrganizerEvent) {
	converting.value = null
	await navigateTo(`/organizer/eventmanagement/event-details?event=${event.id}`)
}
const withdrawing = ref<OrganizerQuotation | null>(null)
const withdrawReason = ref('')
const busy = ref(false)
const messages = useOrganizerThreads()

function formatDate(value: string | null | undefined) {
	if (!value) return ''
	return new Date(value.length === 10 ? `${value}T00:00:00` : value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function isOpen(offer: OrganizerQuotation) {
	return offer.inquiry !== null && ['submitted', 'receiving_bids'].includes(offer.inquiry.status)
}

async function load(p = 1) {
	loading.value = true
	error.value = ''
	try {
		const org = organization.value ?? await fetchOrganization()
		if (!org) return
		messages.loadUnread(org.id) // badges for client messages; not awaited
		const r = await api.list(org.id, filters.find(f => f.label === activeFilter.value)!.status, p)
		offers.value = r.items
		pagination.value = r.pagination
		page.value = p
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

async function onSaved(q: OrganizerQuotation) {
	editing.value = null
	notice.value = q.status === 'draft' ? 'Draft saved.' : 'Quotation sent to the client.'
	await load(page.value)
}

async function confirmWithdraw() {
	if (!withdrawing.value) return
	busy.value = true
	try {
		await api.withdraw(organization.value!.id, withdrawing.value.id, withdrawReason.value.trim())
		withdrawing.value = null
		notice.value = 'Quotation withdrawn.'
		await load(page.value)
	} catch (e) {
		error.value = authMessage(e)
		withdrawing.value = null
	} finally {
		busy.value = false
	}
}

async function removeDraft(offer: OrganizerQuotation) {
	if (!confirm('Delete this draft quotation?')) return
	try {
		await api.remove(organization.value!.id, offer.id)
		notice.value = 'Draft deleted.'
		await load(page.value)
	} catch (e) {
		error.value = authMessage(e)
	}
}

onMounted(() => load())
</script>
