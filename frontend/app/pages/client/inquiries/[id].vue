<template>
	<div>
		<NuxtLink to="/client/my-events" class="inline-flex items-center gap-2 text-sm font-semibold text-gray-500 transition hover:text-[#285F6b]">
			<IconBase name="arrow-left" class="h-4 w-4" />
			My Events
		</NuxtLink>
		<p class="mt-5 text-xs font-bold uppercase tracking-[0.16em] text-[#285F6b]">Client Portal</p>
		<h1 class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl">Your request</h1>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />
		<div v-if="!inquiry && !error" class="mt-6 rounded-2xl border border-gray-200 bg-white p-10 text-center">
			<IconBase name="refresh-cw" class="mx-auto h-5 w-5 animate-spin text-gray-400" />
			<p class="mt-2 text-sm text-gray-500">Loading your request…</p>
		</div>

		<template v-if="inquiry">
			<section class="mt-6 overflow-hidden rounded-3xl border border-[#285F6b]/15 bg-white shadow-sm">
				<div class="bg-[#285F6b] px-5 py-6 text-white sm:px-7 sm:py-7">
					<div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
						<div class="min-w-0">
							<div class="flex flex-wrap items-center gap-2">
								<span class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-[0.14em] text-white/90">{{ inquiry.event_type ?? 'Event' }}</span>
								<span class="rounded-full bg-white px-3 py-1 text-[11px] font-bold text-[#285F6b]">{{ inquiryStatusLabels[inquiry.status] }}</span>
							</div>
							<h2 class="mt-4 break-words text-2xl font-black tracking-tight sm:text-3xl">{{ inquiry.event_name || `${inquiry.event_type ?? 'Event'} request` }}</h2>
							<p class="mt-2 max-w-2xl text-sm leading-6 text-white/70">
								<template v-if="inquiry.via === 'open_posting'">Posted to organizers within {{ inquiry.match_radius_km }} km.</template>
								<template v-else-if="inquiry.via === 'direct'">Sent to organizers you chose.</template>
								<template v-if="inquiry.revised_at"> Changed after sending, {{ formatDateTime(inquiry.revised_at) }}.</template>
							</p>
						</div>
						<div class="flex shrink-0 flex-wrap gap-2">
							<button v-if="isOpen && !editing && (inquiry.revisions_left ?? 0) > 0" type="button" :disabled="busy"
								class="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-[#285F6b] transition hover:bg-white/90" @click="editing = true">
								<IconBase name="edit" class="h-4 w-4" /> Edit request
							</button>
							<button v-if="inquiry.status === 'draft'" type="button" :disabled="busy"
								class="rounded-xl border border-white/30 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-white/10" @click="removeDraft">
								Delete draft
							</button>
							<button v-else-if="cancellable" type="button" :disabled="busy"
								class="rounded-xl border border-white/30 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-white/10" @click="cancelInquiry">
								Cancel request
							</button>
						</div>
					</div>
				</div>
				<dl v-if="!editing" class="grid grid-cols-2 gap-4 px-5 py-5 sm:grid-cols-4 sm:px-7">
					<div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">Date</dt><dd class="mt-1 text-sm font-bold text-gray-900">{{ inquiry.requested_date || '—' }}</dd></div>
					<div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">Guests</dt><dd class="mt-1 text-sm font-bold text-gray-900">{{ inquiry.guest_count ?? '—' }}</dd></div>
					<div class="col-span-2"><dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">Budget</dt><dd class="mt-1 text-sm font-bold text-gray-900">{{ budgetLabel(inquiry.budget_min, inquiry.budget_max) }}</dd></div>
					<div class="col-span-2 sm:col-span-4"><dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">Location</dt><dd class="mt-1 break-words text-sm font-bold text-gray-900">{{ inquiry.location_text || '—' }}</dd></div>
				</dl>
			</section>

			<div v-if="inquiry.status === 'converted_to_event'" class="mt-4 rounded-xl bg-green-50 p-4 text-sm text-green-900">
				Your organizer has set up the event. <NuxtLink :to="inquiry.event_id ? `/client/event-details?event=${inquiry.event_id}` : '/client/event-details'" class="font-semibold underline">See your event</NuxtLink>
			</div>

			<BookingCancellation v-if="winner" class="mt-4" :inquiry-id="inquiry.id" :other-name="winner.organizer.display_name" @changed="load" />

			<ClientRequestEditor v-if="editing" :inquiry="inquiry" @saved="onRequestSaved" @cancel="editing = false" />
			<Alert v-if="requestNotice && !editing" type="success" :text="requestNotice" class="mt-4" />

			<!-- Wide screens: what was asked for and who it went to, side by side. -->
			<div class="mt-6 grid items-start gap-6 xl:grid-cols-2">
			<section v-if="!editing" class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-7">
				<h2 class="text-lg font-extrabold tracking-tight text-gray-900">Services requested</h2>
				<ul v-if="inquiry.services?.length" class="mt-2 list-disc pl-5 text-sm text-gray-700">
					<li v-for="s in inquiry.services" :key="s.id">{{ s.custom_service_name }}<span v-if="s.notes" class="text-gray-500"> — {{ s.notes }}</span></li>
				</ul>
				<p v-else class="mt-2 text-sm text-gray-500">None listed.</p>
				<div v-if="inquiry.additional_requirements" class="mt-4 rounded-2xl bg-gray-50 px-4 py-3"><p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Your notes</p><p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ inquiry.additional_requirements }}</p></div>
			</section>

			<section v-if="inquiry.status !== 'draft'" class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm sm:p-7">
				<h2 class="text-lg font-extrabold tracking-tight text-gray-900">Organizers</h2>
				<ul class="mt-3 divide-y divide-gray-100">
					<li v-for="inv in inquiry.invitations" :key="inv.organization_id" class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
						<NuxtLink v-if="inv.slug" :to="`/organizers/${inv.slug}`" class="font-semibold text-gray-900 hover:text-primary-700">{{ inv.display_name }}</NuxtLink>
						<span v-else class="font-semibold text-gray-500">{{ inv.display_name }}</span>
						<span class="text-gray-500">
							{{ invitationLabels[inv.status] }}<template v-if="inv.decline_reason">: “{{ inv.decline_reason }}”</template>
						</span>
					</li>
				</ul>

				<div v-if="isOpen" id="invite" class="mt-4 scroll-mt-6 border-t border-gray-100 pt-4">
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

			</div>

			<!-- Offers and conversations live on their own pages (mock defense): offers as cards on My Events,
			     messages on the Messages page. This page keeps the request itself. -->
			<section v-if="inquiry.status !== 'draft'" class="mt-6 grid gap-3 sm:grid-cols-2">
				<NuxtLink :to="`/client/my-events?offers=${inquiry.id}`" class="flex items-center justify-between gap-3 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-200 hover:shadow-md">
					<span><span class="block text-base font-extrabold text-gray-900">Offers</span>
						<span class="block text-sm text-gray-500">{{ quotations.length ? `${quotations.length} received. Compare and choose.` : 'None yet. They show up here as organizers reply.' }}</span></span>
					<IconBase name="arrow-right" class="h-5 w-5 shrink-0 text-gray-400" />
				</NuxtLink>
				<NuxtLink :to="`/client/messages?inquiry=${inquiry.id}`" class="flex items-center justify-between gap-3 rounded-3xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-200 hover:shadow-md">
					<span><span class="block text-base font-extrabold text-gray-900">Messages</span>
						<span class="block text-sm text-gray-500">{{ unreadMessages ? `${unreadMessages} unread from organizers.` : 'Talk privately with each organizer.' }}</span></span>
					<IconBase name="arrow-right" class="h-5 w-5 shrink-0 text-gray-400" />
				</NuxtLink>
			</section>

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
// Once the client picks an organizer, that offer leads and the rest fold into compact rows.
const winner = computed(() => quotations.value.find(q => q.status === 'accepted') ?? null)
const notice = ref('')
const isOpen = computed(() => inquiry.value !== null && ['submitted', 'receiving_bids'].includes(inquiry.value.status))

const inquiry = ref<ClientInquiry | null>(null)
const error = ref('')
const threadsApi = useThreads()
const threads = ref<ThreadSummary[]>([])
const selectedThread = ref<string | null>(typeof route.query.thread === 'string' ? route.query.thread : null)
const markThreadRead = (id: string) => { const t = threads.value.find(x => x.id === id); if (t) t.unread = 0 }
const busy = ref(false)

// Editing a sent request (0051).
const editing = ref(false)
const requestNotice = ref('')
async function onRequestSaved(updated: ClientInquiry) {
	editing.value = false
	requestNotice.value = 'Request updated. The organizers you invited were told what changed.'
	inquiry.value = updated
	quotations.value = await quotationsApi.forInquiry(updated.id).catch(() => quotations.value)
}
const formatDateTime = (iso: string) => new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })

const cancellable = computed(() => inquiry.value && ['submitted', 'receiving_bids'].includes(inquiry.value.status))

// Deep-link jumps (#invite) happen on the first load only, not on refreshes.
let jumped = false
// Old links to the messages here (#messages, ?thread=) now open the Messages page.
if (import.meta.client && (route.query.thread || route.hash === '#messages')) {
	navigateTo({ path: '/client/messages', query: typeof route.query.thread === 'string' ? { thread: route.query.thread } : { inquiry: String(route.params.id) } }, { replace: true })
}
const unreadMessages = computed(() => threads.value.reduce((n, t) => n + (t.unread || 0), 0))
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
			// Once: later reloads (after accepting, declining…) must not jump back to the messages.
			if (!jumped && (route.query.thread || route.hash === '#messages')) {
				jumped = true
				setTimeout(() => scrollToElement(document.getElementById('messages'), { behavior: 'auto' }), 150)
				navigateTo({ query: { ...route.query, thread: undefined }, hash: route.hash }, { replace: true })
			}
		}
		// From My Events ("Invite organizers"): go to the invite search, ready to type.
		if (!jumped && route.hash === '#invite') {
			jumped = true
			nextTick(() => {
				const box = document.getElementById('invite')
				scrollToElement(box, { behavior: 'auto' })
				box?.querySelector('input')?.focus({ preventScroll: true })
			})
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
// Close to live (review #12): new quotes and offer changes appear on their own.
useLiveRefresh(() => load(), { paused: () => busy.value })
</script>
