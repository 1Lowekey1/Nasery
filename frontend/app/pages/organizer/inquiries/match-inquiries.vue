<template>
	<div>
		<div class="mb-6 flex items-start gap-3 rounded-xl bg-primary-50 p-4 text-sm text-gray-700">
			<IconBase name="bell" class="mt-0.5 h-5 w-5 shrink-0 text-primary-700" />
			<p>Inquiries arrive when a client picks you from the directory, or posts an event near your
				<strong>business location</strong> that needs a <strong>service you offer</strong>.</p>
		</div>

		<div class="mb-4 flex flex-wrap gap-2">
			<button v-for="f in filters" :key="f.label" type="button"
				class="rounded-full border px-3 py-1.5 text-xs font-semibold"
				:class="activeFilter === f.label ? 'border-primary-600 bg-primary-50 text-primary-800' : 'border-gray-200 text-gray-600'"
				@click="activeFilter = f.label; load(1)">{{ f.label }}</button>
		</div>

		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading inquiries…</div>

		<div v-else-if="inquiries.length" class="space-y-5">
			<div v-for="inquiry in inquiries" :key="inquiry.inquiry_id" class="rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-start justify-between gap-2">
					<div>
						<div class="flex flex-wrap items-center gap-2">
							<h3 class="text-lg font-bold text-gray-900">{{ (inquiry.event_type ?? 'Event') + ' inquiry' }}</h3>
							<span class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
								:class="inquiry.via === 'direct' ? 'bg-primary-50 text-primary-700' : 'bg-green-50 text-green-700'">
								{{ inquiry.via === 'direct' ? 'Picked you' : 'Matched nearby' }}
							</span>
						</div>
						<div class="mt-1 text-sm text-gray-400">
							From <span class="font-semibold text-gray-600">{{ inquiry.client }}</span> &bull; received {{ formatDate(inquiry.invited_at) }}
						</div>
					</div>
					<span class="shrink-0 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-500">
						{{ inquiry.is_open ? invitationLabels[inquiry.invitation_status] : inquiryStatusLabels[inquiry.inquiry_status] }}
					</span>
				</div>

				<div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="flex items-center gap-1.5 text-xs text-gray-500"><IconBase name="calendar" class="h-3.5 w-3.5" /> Event Date</div>
						<div class="mt-1 text-sm font-bold text-gray-900">{{ formatDate(inquiry.requested_date) || '—' }}</div>
					</div>
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="flex items-center gap-1.5 text-xs text-gray-500"><IconBase name="map-pin" class="h-3.5 w-3.5" /> Location</div>
						<div class="mt-1 text-sm font-bold text-gray-900">{{ inquiry.location_text || '—' }}</div>
					</div>
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="flex items-center gap-1.5 text-xs text-gray-500"><IconBase name="users" class="h-3.5 w-3.5" /> Guests</div>
						<div class="mt-1 text-sm font-bold text-gray-900">{{ inquiry.guest_count ?? '—' }}</div>
					</div>
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="flex items-center gap-1.5 text-xs text-gray-500"><IconBase name="cash" class="h-3.5 w-3.5" /> Budget</div>
						<div class="mt-1 text-sm font-bold text-gray-900">{{ budgetLabel(inquiry.budget_min, inquiry.budget_max) }}</div>
					</div>
				</div>

				<div v-if="inquiry.services.length" class="mt-4">
					<div class="mb-2 text-xs font-semibold tracking-wide text-gray-400">SERVICES NEEDED</div>
					<div class="flex flex-wrap gap-2">
						<span v-for="(s, i) in inquiry.services" :key="i" class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600"
							:title="s.notes ?? undefined">{{ s.name }}</span>
					</div>
				</div>

				<p v-if="expanded.has(inquiry.inquiry_id) && inquiry.additional_requirements" class="mt-4 whitespace-pre-line text-sm text-gray-600">
					{{ inquiry.additional_requirements }}
				</p>
				<p v-if="inquiry.decline_reason" class="mt-4 text-sm text-gray-500">You declined: “{{ inquiry.decline_reason }}”</p>

				<div class="mt-5 flex flex-wrap gap-3">
					<button v-if="!expanded.has(inquiry.inquiry_id)" type="button"
						class="flex items-center gap-2 rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
						@click="view(inquiry)">
						<IconBase name="file-text" class="h-4 w-4" /> View details
					</button>
					<template v-if="inquiry.quotation && inquiry.quotation.status !== 'withdrawn' && inquiry.quotation.status !== 'cancelled'">
						<span class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold" :class="quotationStatusClasses[inquiry.quotation.status]">
							{{ quotationStatusLabels[inquiry.quotation.status] }} · {{ formatPeso(inquiry.quotation.total_amount) }}
						</span>
						<button v-if="inquiry.is_open && (inquiry.quotation.status === 'draft' || inquiry.quotation.status === 'submitted')" type="button"
							class="flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900"
							@click="openQuotation(inquiry)">
							<IconBase name="send" class="h-4 w-4" /> {{ inquiry.quotation.status === 'draft' ? 'Continue draft' : 'Revise quotation' }}
						</button>
					</template>
					<button v-else-if="canQuote(inquiry)" type="button"
						class="flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900"
						@click="openQuotation(inquiry)">
						<IconBase name="send" class="h-4 w-4" /> Send Quotation
					</button>
					<button v-if="canDecline(inquiry)" type="button"
						class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50"
						@click="declining = inquiry; declineReason = ''">
						Decline
					</button>
					<button type="button" class="relative flex items-center gap-2 rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
						:aria-label="messages.unread.value[inquiry.inquiry_id] ? `Messages, ${messages.unread.value[inquiry.inquiry_id]} unread` : 'Messages'"
						@click="messages.openFor(organization!.id, inquiry.inquiry_id, `${inquiry.event_type ?? 'Inquiry'} · ${formatDate(inquiry.requested_date)}`)">
						<IconBase name="message" class="h-4 w-4" /> Messages
						<span v-if="messages.unread.value[inquiry.inquiry_id]" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-bold text-white">{{ messages.unread.value[inquiry.inquiry_id] }}</span>
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
			<h2 class="text-lg font-bold text-gray-900">No inquiries here yet</h2>
			<p class="mt-1 text-sm text-gray-500">Keep your services and business location up to date so matching clients can find you.</p>
		</div>

		<OrganizerQuotationForm v-if="quoting" :organization-id="organization!.id" :inquiry-id="quoting.inquiry.inquiry_id"
			:event-label="`${quoting.inquiry.event_type ?? 'Event'} for ${quoting.inquiry.client}`" :guest-count="quoting.inquiry.guest_count"
			:quotation="quoting.quotation" @close="quoting = null" @saved="onQuotationSaved" />

		<div v-if="declining" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="declining = null">
			<form class="w-full max-w-md rounded-2xl bg-white p-6" @submit.prevent="confirmDecline">
				<h2 class="text-lg font-bold text-gray-900">Decline this inquiry?</h2>
				<p class="mt-1 text-sm text-gray-500">The client will see your reason.</p>
				<textarea v-model="declineReason" rows="3" maxlength="500" required placeholder="e.g. We're fully booked on that date."
					class="mt-4 w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
				<div class="mt-4 flex justify-end gap-2">
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="declining = null">Keep it</button>
					<button type="submit" :disabled="busy || declineReason.trim().length < 3"
						class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Decline</button>
				</div>
			</form>
		</div>

		<OrganizerThreadDrawer :thread-id="messages.open.value?.id ?? null" :title="messages.open.value?.title ?? ''"
			@close="messages.open.value = null" @read="messages.markRead()" />
	</div>
</template>

<script setup lang="ts">
import type { InvitationStatus, OrganizerInquiry } from '~/composables/useInquiries'
import type { OrganizerQuotation } from '~/composables/useQuotations'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerInquiries()

const filters: { label: string, status: InvitationStatus[] }[] = [
	{ label: 'Needs a response', status: ['pending', 'viewed'] },
	{ label: 'Quoted', status: ['quoted', 'accepted', 'rejected', 'withdrawn'] },
	{ label: 'Declined', status: ['declined'] },
	{ label: 'All', status: [] },
]
const activeFilter = ref('Needs a response')
const inquiries = ref<OrganizerInquiry[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const page = ref(1)
const loading = ref(true)
const error = ref('')
const notice = ref('')
const expanded = reactive(new Set<string>())
const declining = ref<OrganizerInquiry | null>(null)
const quoting = ref<{ inquiry: OrganizerInquiry, quotation: OrganizerQuotation | null } | null>(null)
const quotationsApi = useOrganizerQuotations()
const declineReason = ref('')
const busy = ref(false)
const messages = useOrganizerThreads()

function formatDate(value: string | null) {
	if (!value) return ''
	return new Date(value.length === 10 ? `${value}T00:00:00` : value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function canQuote(i: OrganizerInquiry) {
	return i.is_open && ['pending', 'viewed', 'quoted', 'withdrawn'].includes(i.invitation_status)
}

async function openQuotation(inquiry: OrganizerInquiry) {
	try {
		const current = inquiry.quotation && ['draft', 'submitted'].includes(inquiry.quotation.status)
			? await quotationsApi.show(organization.value!.id, inquiry.quotation.id)
			: null
		quoting.value = { inquiry, quotation: current }
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function onQuotationSaved(q: OrganizerQuotation) {
	quoting.value = null
	notice.value = q.status === 'draft' ? 'Draft saved.' : 'Quotation sent to the client.'
	await load(page.value)
}

function canDecline(i: OrganizerInquiry) {
	return i.is_open && (i.invitation_status === 'pending' || i.invitation_status === 'viewed')
}

async function load(p = 1) {
	loading.value = true
	error.value = ''
	try {
		const org = organization.value ?? await fetchOrganization()
		if (!org) return
		messages.loadUnread(org.id) // badges for client messages; not awaited
		const status = filters.find(f => f.label === activeFilter.value)!.status
		const r = await api.list(org.id, status, p)
		inquiries.value = r.items
		pagination.value = r.pagination
		page.value = p
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

// Opening an inquiry marks it viewed for the client.
async function view(inquiry: OrganizerInquiry) {
	try {
		const fresh = await api.show(organization.value!.id, inquiry.inquiry_id)
		Object.assign(inquiry, fresh)
		expanded.add(inquiry.inquiry_id)
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function confirmDecline() {
	if (!declining.value) return
	busy.value = true
	try {
		await api.decline(organization.value!.id, declining.value.inquiry_id, declineReason.value.trim())
		declining.value = null
		await load(page.value)
	} catch (e) {
		error.value = authMessage(e)
		declining.value = null
	} finally {
		busy.value = false
	}
}

onMounted(() => load())
</script>
