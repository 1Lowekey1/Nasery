<template>
	<div class="mx-auto max-w-3xl">
		<NuxtLink to="/client/my-events" class="text-sm font-semibold text-primary-700">← My Events</NuxtLink>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<div v-if="!inquiry && !error" class="py-16 text-center text-sm text-gray-500">Loading…</div>

		<template v-if="inquiry">
			<div class="mt-3 flex flex-wrap items-start justify-between gap-3">
				<div>
					<h1 class="text-2xl font-extrabold text-gray-900">{{ (inquiry.event_type ?? 'Event') + ' inquiry' }}</h1>
					<p class="mt-1 text-gray-500">
						{{ inquiryStatusLabels[inquiry.status] }}
						<template v-if="inquiry.via === 'open_posting'"> · posted to organizers within {{ inquiry.match_radius_km }} km</template>
						<template v-else-if="inquiry.via === 'direct'"> · sent to organizers you chose</template>
					</p>
				</div>
				<div class="flex gap-2">
					<button v-if="inquiry.status === 'draft'" type="button" :disabled="busy"
						class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" @click="removeDraft">
						Delete draft
					</button>
					<button v-else-if="cancellable" type="button" :disabled="busy"
						class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50" @click="cancelInquiry">
						Cancel inquiry
					</button>
				</div>
			</div>

			<div v-if="inquiry.status === 'converted_to_event'" class="mt-4 rounded-xl bg-green-50 p-4 text-sm text-green-900">
				Your organizer has set up the event. <NuxtLink :to="inquiry.event_id ? `/client/event-details?event=${inquiry.event_id}` : '/client/event-details'" class="font-semibold underline">See your event</NuxtLink>
			</div>

			<section class="mt-6 grid grid-cols-2 gap-3 rounded-2xl border border-gray-200 bg-white p-6 sm:grid-cols-4">
				<div><div class="text-xs text-gray-500">Date</div><div class="mt-1 text-sm font-bold">{{ inquiry.requested_date || '—' }}</div></div>
				<div><div class="text-xs text-gray-500">Guests</div><div class="mt-1 text-sm font-bold">{{ inquiry.guest_count ?? '—' }}</div></div>
				<div class="col-span-2"><div class="text-xs text-gray-500">Budget</div><div class="mt-1 text-sm font-bold">{{ budgetLabel(inquiry.budget_min, inquiry.budget_max) }}</div></div>
				<div class="col-span-2 sm:col-span-4"><div class="text-xs text-gray-500">Location</div><div class="mt-1 text-sm font-bold">{{ inquiry.location_text || '—' }}</div></div>
			</section>

			<section class="mt-4 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Services requested</h2>
				<ul v-if="inquiry.services?.length" class="mt-2 list-disc pl-5 text-sm text-gray-700">
					<li v-for="s in inquiry.services" :key="s.id">{{ s.custom_service_name }}<span v-if="s.notes" class="text-gray-500"> — {{ s.notes }}</span></li>
				</ul>
				<p v-else class="mt-2 text-sm text-gray-500">None listed.</p>
				<p v-if="inquiry.additional_requirements" class="mt-4 whitespace-pre-line text-sm text-gray-700">{{ inquiry.additional_requirements }}</p>
			</section>

			<section v-if="inquiry.status !== 'draft'" class="mt-4 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Organizers</h2>
				<ul class="mt-3 divide-y divide-gray-100">
					<li v-for="inv in inquiry.invitations" :key="inv.organization_id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
						<NuxtLink v-if="inv.slug" :to="`/organizers/${inv.slug}`" class="font-semibold text-gray-900 hover:text-primary-700">{{ inv.display_name }}</NuxtLink>
						<span v-else class="font-semibold text-gray-500">{{ inv.display_name }}</span>
						<span class="text-gray-500">
							{{ invitationLabels[inv.status] }}<template v-if="inv.decline_reason">: “{{ inv.decline_reason }}”</template>
						</span>
					</li>
				</ul>

				<div v-if="isOpen" class="mt-4 border-t border-gray-100 pt-4">
					<h3 class="text-sm font-bold text-gray-900">Invite more organizers</h3>
					<form class="mt-2 flex gap-2" @submit.prevent="searchOrganizers">
						<FormsTextField v-model="organizerSearch" placeholder="Search organizers by name or service…" class="flex-1" />
						<button type="submit" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold hover:bg-gray-50">Search</button>
					</form>
					<ul class="mt-2 divide-y divide-gray-100 text-sm">
						<li v-for="o in inviteCandidates" :key="o.id" class="flex items-center justify-between gap-3 py-2">
							<span>{{ o.display_name }} <span class="text-gray-400">· {{ o.location_text }}</span></span>
							<button type="button" :disabled="busy" class="font-semibold text-primary-700" @click="inviteOrganizer(o.id)">Invite</button>
						</li>
					</ul>
				</div>
			</section>

			<section v-if="inquiry.status !== 'draft' && threads.length" id="messages" class="mt-4 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Messages</h2>
				<p class="mt-1 text-sm text-gray-500">Talk privately with each organizer before you choose. Each conversation is only between you and that organizer.</p>
				<div class="mt-4 grid gap-4 md:grid-cols-[220px_1fr]">
					<ul class="space-y-1" aria-label="Organizers">
						<li v-for="t in threads" :key="t.id">
							<button type="button" class="flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left text-sm"
								:class="selectedThread === t.id ? 'bg-primary-50 font-semibold text-primary-800' : 'hover:bg-gray-50'" :aria-current="selectedThread === t.id"
								@click="selectedThread = t.id">
								<span class="min-w-0">
									<span class="block truncate">{{ t.organization.name }}</span>
									<span class="block truncate text-xs font-normal text-gray-500">{{ t.last ? t.last.body : (t.can_send ? 'No messages yet' : 'Closed') }}</span>
								</span>
								<span v-if="t.unread" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-bold text-white" :aria-label="`${t.unread} unread`">{{ t.unread }}</span>
							</button>
						</li>
					</ul>
					<div class="h-[26rem]">
						<InquiryThread v-if="selectedThread" :thread-id="selectedThread" @read="markThreadRead" />
					</div>
				</div>
			</section>

			<section v-if="inquiry.status !== 'draft'" class="mt-4 rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-baseline justify-between gap-2">
					<h2 class="text-base font-bold text-gray-900">Quotations</h2>
					<span v-if="quotations.length" class="text-xs text-gray-500">Cheapest open offers first</span>
				</div>
				<p class="mt-1 text-xs text-gray-500">Quotations are proposals, not contracts. You can contact organizers before choosing; final terms are agreed with them directly.</p>
				<Alert v-if="notice" type="success" :text="notice" class="mt-3" />

				<p v-if="!quotations.length" class="mt-4 text-sm text-gray-500">No quotations yet. Organizers you invited will send theirs here.</p>

				<div v-for="q in quotations" :key="q.id" class="mt-4 rounded-xl border p-4"
					:class="q.status === 'accepted' ? 'border-green-300 bg-green-50/40' : 'border-gray-200'">
					<div class="flex flex-wrap items-start justify-between gap-2">
						<div>
							<NuxtLink v-if="q.organizer.slug" :to="`/organizers/${q.organizer.slug}`" class="font-bold text-gray-900 hover:text-primary-700">{{ q.organizer.display_name }}</NuxtLink>
							<span v-else class="font-bold text-gray-500">{{ q.organizer.display_name }}</span>
							<p v-if="q.package_name" class="text-sm text-gray-600">{{ q.package_name }}</p>
						</div>
						<div class="text-right">
							<div class="text-lg font-extrabold text-primary-700">{{ formatPeso(q.total_amount) }}</div>
							<span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="quotationStatusClasses[q.status]">
								{{ clientLabels[q.status] }}
							</span>
							<p v-if="q.earlier_versions" class="mt-1 text-xs text-gray-400">Revised {{ q.earlier_versions }}×</p>
						</div>
					</div>

					<div class="mt-3 divide-y divide-gray-100 text-sm">
						<div v-for="line in q.items" :key="line.id" class="flex justify-between gap-3 py-1.5">
							<span class="text-gray-600">{{ line.description }}<span v-if="Number(line.quantity) !== 1" class="text-gray-400"> × {{ Number(line.quantity) }}</span></span>
							<span class="font-semibold">{{ formatPeso(line.subtotal) }}</span>
						</div>
					</div>
					<p v-if="q.proposal" class="mt-3 whitespace-pre-line text-sm text-gray-700">{{ q.proposal }}</p>
					<p v-if="q.terms" class="mt-2 whitespace-pre-line text-xs text-gray-500">Terms: {{ q.terms }}</p>
					<p v-if="q.organizer.contact_email || q.organizer.contact_phone" class="mt-2 text-xs text-gray-500">
						Contact: <a v-if="q.organizer.contact_email" :href="`mailto:${q.organizer.contact_email}`" class="text-primary-700">{{ q.organizer.contact_email }}</a>
						<template v-if="q.organizer.contact_phone"> · {{ q.organizer.contact_phone }}</template>
					</p>

					<div v-if="q.status === 'submitted' && isOpen" class="mt-4 flex flex-wrap gap-2">
						<button type="button" :disabled="busy"
							class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900 disabled:opacity-50"
							@click="acceptQuotation(q)">Accept this quotation</button>
						<button type="button" :disabled="busy"
							class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50"
							@click="declining = q; declineReason = ''">Decline</button>
					</div>
				</div>
			</section>

			<div v-if="declining" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="declining = null">
				<form class="w-full max-w-md rounded-2xl bg-white p-6" @submit.prevent="confirmDecline">
					<h2 class="text-lg font-bold text-gray-900">Decline {{ declining.organizer.display_name }}'s quotation?</h2>
					<p class="mt-1 text-sm text-gray-500">The organizer will see your reason and can't send another one for this inquiry.</p>
					<textarea v-model="declineReason" rows="3" maxlength="500" placeholder="e.g. Over our budget"
						class="mt-4 w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
					<div class="mt-4 flex justify-end gap-2">
						<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="declining = null">Keep it</button>
						<button type="submit" :disabled="busy || declineReason.trim().length < 3"
							class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Decline</button>
					</div>
				</form>
			</div>

			<p v-if="inquiry.status === 'draft'" class="mt-4 text-sm text-gray-500">
				This draft has not been sent. <NuxtLink to="/client/post-event" class="font-semibold text-primary-700">Post a new event</NuxtLink> to send one.
			</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ThreadSummary } from '~/composables/useThreads'
import type { ClientInquiry } from '~/composables/useInquiries'
import type { ClientQuotation } from '~/composables/useQuotations'
import type { OrganizerCard } from '~/composables/useMarketplace'

definePageMeta({ layout: 'client' })

const route = useRoute()
const { show, cancel, remove, invite } = useInquiries()
const { organizers: searchDirectory } = useMarketplace()
const organizerSearch = ref('')
const organizerResults = ref<OrganizerCard[]>([])
const inviteCandidates = computed(() => organizerResults.value
	.filter(o => !inquiry.value?.invitations?.some(i => i.organization_id === o.id)))
const quotationsApi = useClientQuotations()
const clientLabels: Record<string, string> = {
	submitted: 'Awaiting your decision', accepted: 'Accepted', rejected: 'Not selected',
	withdrawn: 'Withdrawn by organizer', cancelled: 'Closed', draft: 'Draft',
}
const quotations = ref<ClientQuotation[]>([])
const declining = ref<ClientQuotation | null>(null)
const declineReason = ref('')
const notice = ref('')
const isOpen = computed(() => inquiry.value !== null && ['submitted', 'receiving_bids'].includes(inquiry.value.status))

const inquiry = ref<ClientInquiry | null>(null)
const error = ref('')
const threadsApi = useThreads()
const threads = ref<ThreadSummary[]>([])
const selectedThread = ref<string | null>(typeof route.query.thread === 'string' ? route.query.thread : null)
const markThreadRead = (id: string) => { const t = threads.value.find(x => x.id === id); if (t) t.unread = 0 }
const busy = ref(false)

const cancellable = computed(() => inquiry.value && ['submitted', 'receiving_bids'].includes(inquiry.value.status))

async function load() {
	try {
		inquiry.value = await show(String(route.params.id))
		quotations.value = inquiry.value.status === 'draft' ? [] : await quotationsApi.forInquiry(inquiry.value.id)
		if (inquiry.value.status !== 'draft') {
			threads.value = await threadsApi.forInquiry(inquiry.value.id)
			if (!selectedThread.value || !threads.value.some(t => t.id === selectedThread.value)) {
				selectedThread.value = (threads.value.find(t => t.unread) ?? threads.value.find(t => t.can_send) ?? threads.value[0])?.id ?? null
			}
			// Opened from a message notification: bring the conversation into view.
			if (route.query.thread) nextTick(() => document.getElementById('messages')?.scrollIntoView({ behavior: 'smooth' }))
		}
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function cancelInquiry() {
	if (!(await confirmDialog('Cancel this inquiry? Organizers will no longer be able to respond.', { confirmLabel: 'Cancel inquiry', cancelLabel: 'Keep it' }))) return
	busy.value = true
	try {
		await cancel(inquiry.value!.id)
		await load()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function searchOrganizers() {
	try {
		organizerResults.value = (await searchDirectory({ search: organizerSearch.value.trim(), page: 1 })).items
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function inviteOrganizer(id: string) {
	busy.value = true
	try {
		inquiry.value = await invite(inquiry.value!.id, [id])
		notice.value = 'Organizer invited.'
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function acceptQuotation(q: ClientQuotation) {
	if (!(await confirmDialog(`Accept ${q.organizer.display_name}'s quotation for ${formatPeso(q.total_amount)}? The other offers will be declined and ${q.organizer.display_name} will receive your contact details.`, { title: 'Accept this quotation?', confirmLabel: 'Yes, accept', danger: false }))) return
	busy.value = true
	try {
		await quotationsApi.accept(q.id)
		notice.value = `You accepted ${q.organizer.display_name}'s quotation. They'll contact you to agree the final terms.`
		await load()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function confirmDecline() {
	if (!declining.value) return
	busy.value = true
	try {
		await quotationsApi.decline(declining.value.id, declineReason.value.trim())
		declining.value = null
		await load()
	} catch (e) {
		error.value = authMessage(e)
		declining.value = null
	} finally {
		busy.value = false
	}
}

async function removeDraft() {
	if (!(await confirmDialog('Delete this draft?', { confirmLabel: 'Delete draft' }))) return
	busy.value = true
	try {
		await remove(inquiry.value!.id)
		await navigateTo('/client/my-events')
	} catch (e) {
		error.value = authMessage(e)
		busy.value = false
	}
}

onMounted(load)
</script>
