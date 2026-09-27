<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />

		<div v-if="!eventId" class="rounded-3xl border-2 border-dashed border-gray-200 bg-gray-50/60 px-6 py-16 text-center">
			<h2 class="text-lg font-bold text-gray-900">Choose an event</h2>
			<p class="mt-1 text-sm text-gray-500">Open one from <NuxtLink to="/organizer/eventmanagement/all-events" class="font-semibold text-primary-700">All Events</NuxtLink>.</p>
		</div>

		<div v-else-if="!event && !error" class="py-16 text-center text-sm text-gray-500">Loading event…</div>

		<template v-if="event">
			<div class="rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div>
						<h2 class="text-lg font-bold text-gray-900">{{ event.name }}</h2>
						<p class="text-sm text-gray-500">{{ event.event_type ?? 'Event' }} · {{ event.visibility === 'public' ? 'Public' : 'Private (invited guests)' }}</p>
					</div>
					<span class="rounded-full px-3 py-1 text-xs font-semibold" :class="eventStatusClasses[event.status]">{{ eventStatusLabels[event.status] }}</span>
				</div>

				<p v-if="event.status === 'cancelled'" class="mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">Cancelled: {{ event.cancelled_reason }}</p>
				<div v-if="event.client" class="mt-4 rounded-lg bg-primary-50 p-3 text-sm text-primary-900">
					Client: <strong>{{ event.client.name }}</strong>
					<template v-if="event.client.email"> · <a :href="`mailto:${event.client.email}`" class="underline">{{ event.client.email }}</a></template>
					<template v-if="event.client.phone"> · {{ event.client.phone }}</template>
					<span class="block text-xs text-primary-700">The client can see this event, its schedule and status in their portal.</span>
				</div>

				<div class="mt-6 grid grid-cols-1 gap-x-12 gap-y-5 sm:grid-cols-2">
					<div>
						<div class="text-sm text-gray-500">Venue</div>
						<div class="mt-1 flex items-center gap-1.5 text-base font-bold text-gray-900">
							<IconBase name="map-pin" class="h-4 w-4 text-gray-400" /> {{ event.venue_name || 'Not set' }}
						</div>
						<div v-if="event.venue_address" class="text-sm text-gray-500">{{ event.venue_address }}</div>
					</div>
					<div>
						<div class="text-sm text-gray-500">Capacity</div>
						<div class="mt-1 flex items-center gap-1.5 text-base font-bold text-gray-900">
							<IconBase name="users" class="h-4 w-4 text-gray-400" /> {{ event.overall_capacity ?? 'Not set' }}
						</div>
					</div>
					<div>
						<div class="text-sm text-gray-500">Event contact</div>
						<div class="mt-1 text-sm text-gray-900">{{ [event.contact_name, event.contact_email, event.contact_phone].filter(Boolean).join(' · ') || '—' }}</div>
					</div>
					<div v-if="event.description" class="sm:col-span-2">
						<div class="text-sm text-gray-500">Description</div>
						<p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ event.description }}</p>
					</div>
				</div>

				<div class="mt-6 flex flex-wrap gap-3">
					<button v-if="editable" type="button"
						class="flex items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1f4a54]"
						@click="editing = true">
						<IconBase name="edit" class="h-4 w-4" /> Edit details
					</button>
					<button v-for="a in availableActions" :key="a.action" type="button" :disabled="busy"
						class="rounded-xl border px-4 py-2.5 text-sm font-semibold disabled:opacity-50"
						:class="a.action === 'cancel' ? 'border-gray-200 text-rose-700 hover:bg-rose-50' : 'border-gray-200 text-gray-700 hover:bg-gray-50'"
						@click="a.action === 'cancel' ? (cancelling = true, cancelReason = '') : run(a.action)">{{ a.label }}</button>
					<button v-if="event.status === 'draft' && !event.inquiry_id && event.can?.update !== false" type="button" :disabled="busy"
						class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50"
						@click="removeDraft">Delete draft</button>
				</div>
			</div>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h3 class="text-base font-bold text-gray-900">Schedule</h3>
				<p class="text-xs text-gray-500">Times in {{ event.timezone }}.</p>
				<ul class="mt-3 divide-y divide-gray-100">
					<li v-for="s in event.sessions" :key="s.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
						<div :class="s.status === 'cancelled' ? 'text-gray-400 line-through' : ''">
							<span class="font-semibold">{{ s.name || 'Session' }}</span>
							<span class="block text-gray-500">{{ sessionLabel(s, event.timezone) }}</span>
						</div>
						<span v-if="editable && s.status !== 'cancelled'" class="flex gap-3">
							<button type="button" class="text-xs font-semibold text-gray-600" @click="editSession(s)">Edit</button>
							<button type="button" class="text-xs font-semibold text-rose-700" @click="removeSession(s.id)">Remove</button>
						</span>
					</li>
					<li v-if="!event.sessions.length" class="py-3 text-sm text-gray-400">No date yet. Add a session before publishing.</li>
				</ul>

				<form v-if="editable" class="mt-4 grid grid-cols-1 gap-2 rounded-xl bg-gray-50 p-3 sm:grid-cols-[1fr_1fr_1fr_auto]" @submit.prevent="saveSession">
					<FormsTextField v-model="sessionForm.name" placeholder="Session name (optional)" aria-label="Session name" />
					<input v-model="sessionForm.starts_at" type="datetime-local" aria-label="Session start" class="rounded-lg border border-gray-200 px-2 py-2 text-sm">
					<input v-model="sessionForm.ends_at" type="datetime-local" aria-label="Session end" class="rounded-lg border border-gray-200 px-2 py-2 text-sm">
					<div class="flex gap-2">
						<button type="submit" :disabled="busy" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">{{ sessionForm.id ? 'Save' : 'Add' }}</button>
						<button v-if="sessionForm.id" type="button" class="text-sm text-gray-500" @click="resetSessionForm">Cancel</button>
					</div>
				</form>
			</section>

			<OrganizerEventTeam :organization-id="event.organization_id" :event-id="event.id" :editable="!['completed', 'cancelled'].includes(event.status)" />
		</template>

		<OrganizerEventForm v-if="editing && event" :organization-id="event.organization_id" :event="event"
			@close="editing = false" @saved="(e) => { event = e; editing = false; notice = 'Event saved.' }" />

		<div v-if="cancelling" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="cancelling = false">
			<form class="w-full max-w-md rounded-2xl bg-white p-6" @submit.prevent="confirmCancel">
				<h2 class="text-lg font-bold text-gray-900">Cancel this event?</h2>
				<p class="mt-1 text-sm text-gray-500">This can't be undone. {{ event?.client ? 'The client will see the reason.' : '' }}</p>
				<textarea v-model="cancelReason" rows="3" maxlength="500" placeholder="Reason"
					class="mt-4 w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
				<div class="mt-4 flex justify-end gap-2">
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="cancelling = false">Keep event</button>
					<button type="submit" :disabled="busy || cancelReason.trim().length < 3"
						class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Cancel event</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { EventAction, EventSession, OrganizerEvent } from '~/composables/useEvents'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerEvents()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const event = ref<OrganizerEvent | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const editing = ref(false)
const cancelling = ref(false)
const cancelReason = ref('')
const sessionForm = reactive({ id: null as string | null, name: '', starts_at: '', ends_at: '' })

const editable = computed(() => event.value !== null && !['completed', 'cancelled'].includes(event.value.status) && event.value.can?.update !== false)

const actionLabels: Record<EventAction, string> = {
	publish: 'Publish event', unpublish: 'Back to draft', start: 'Start event', complete: 'Mark completed', cancel: 'Cancel event',
}
const allowed: Record<string, EventAction[]> = {
	draft: ['publish', 'cancel'],
	published: ['start', 'complete', 'unpublish', 'cancel'],
	ongoing: ['complete', 'cancel'],
}
// Start / complete only make sense once the first session has begun (the server enforces this too).
const hasStarted = computed(() => {
	const first = event.value?.sessions.find(s => s.status !== 'cancelled')
	return first !== undefined && new Date(first.starts_at).getTime() <= Date.now()
})
const actionPermission: Record<EventAction, 'update' | 'publish' | 'complete' | 'cancel'> = {
	publish: 'publish', unpublish: 'publish', start: 'update', complete: 'complete', cancel: 'cancel',
}
const availableActions = computed(() => (allowed[event.value?.status ?? ''] ?? [])
	.filter(action => (action !== 'start' && action !== 'complete') || hasStarted.value)
	.filter(action => event.value?.can?.[actionPermission[action]] !== false)
	.map(action => ({ action, label: actionLabels[action] })))

async function load() {
	if (!eventId.value) return
	error.value = ''
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) event.value = await api.show(org.id, eventId.value)
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function act(fn: () => Promise<OrganizerEvent | void>, success: string) {
	error.value = ''
	notice.value = ''
	busy.value = true
	try {
		const result = await fn()
		if (result) event.value = result
		notice.value = success
	} catch (e) {
		const fields = fieldErrors(e)
		error.value = Object.values(fields)[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

const actionDone: Record<EventAction, string> = {
	publish: 'Event published.', unpublish: 'Event moved back to draft.', start: 'Event started.', complete: 'Event marked completed.', cancel: 'Event cancelled.',
}
const run = (action: EventAction) => act(() => api.transition(event.value!.organization_id, event.value!.id, action), actionDone[action])

async function confirmCancel() {
	await act(() => api.transition(event.value!.organization_id, event.value!.id, 'cancel', cancelReason.value.trim()), 'Event cancelled.')
	cancelling.value = false
}

function editSession(s: EventSession) {
	Object.assign(sessionForm, { id: s.id, name: s.name ?? '', starts_at: s.starts_local, ends_at: s.ends_local })
}

function resetSessionForm() {
	Object.assign(sessionForm, { id: null, name: '', starts_at: '', ends_at: '' })
}

async function saveSession() {
	const e = event.value!
	const input = { name: sessionForm.name.trim() || null, starts_at: sessionForm.starts_at, ends_at: sessionForm.ends_at }
	await act(() => sessionForm.id
		? api.updateSession(e.organization_id, e.id, sessionForm.id, input)
		: api.addSession(e.organization_id, e.id, input), sessionForm.id ? 'Session saved.' : 'Session added.')
	if (!error.value) resetSessionForm()
}

const removeSession = (id: string) => act(() => api.removeSession(event.value!.organization_id, event.value!.id, id), 'Session removed.')

async function removeDraft() {
	if (!confirm('Delete this draft event?')) return
	await act(() => api.remove(event.value!.organization_id, event.value!.id).then(() => {}), 'Draft deleted.')
	if (!error.value) await navigateTo('/organizer/eventmanagement/all-events')
}

watch(eventId, () => { event.value = null; load() })
onMounted(load)
</script>
