<template>
	<div>
		<div class="fixed inset-0 z-40 bg-gray-900/40" @click="$emit('close')" />
		<div class="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" :aria-label="title">
			<div class="flex shrink-0 items-start justify-between border-b border-gray-200 px-6 py-5">
				<div>
					<h2 class="text-lg font-bold text-gray-900">{{ title }}</h2>
					<p class="mt-0.5 text-sm text-gray-500">{{ subtitle }}</p>
				</div>
				<button type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100" aria-label="Close" @click="$emit('close')">
					<IconBase name="x" class="h-5 w-5" />
				</button>
			</div>

			<form novalidate class="flex-1 space-y-6 overflow-y-auto px-6 py-5" @submit.prevent="save">
				<FormsDraftNotice :draft="draft" />
				<Alert v-if="error" type="danger" :text="error" />

				<section class="space-y-3">
					<h3 class="text-sm font-bold uppercase tracking-wide text-gray-400">Basics</h3>
					<div>
						<FormsLabel text="Event Name" required />
						<FormsTextField v-model="form.name" maxlength="200" placeholder="e.g. Santos-Reyes Wedding Reception" />
						<p v-if="fields.name" class="mt-1 text-xs text-red-600">{{ fields.name }}</p>
					</div>
					<div>
						<FormsLabel text="Event Type" />
						<select v-model="form.event_type_id" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm" aria-label="Event type">
							<option :value="null">Choose a type</option>
							<option v-for="t in eventTypes" :key="t.id" :value="t.id">{{ t.name }}</option>
						</select>
					</div>
					<div>
						<FormsLabel text="Description" />
						<textarea v-model="form.description" rows="3" maxlength="5000"
							class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
					</div>
				</section>

				<section v-if="mode !== 'edit'" class="space-y-3">
					<h3 class="text-sm font-bold uppercase tracking-wide text-gray-400">Schedule</h3>
					<div v-for="(s, i) in sessions" :key="i" class="rounded-xl border border-gray-200 p-3">
						<div class="flex items-center gap-2">
							<FormsTextField v-model="s.name" :aria-label="`Session ${i + 1} name`" placeholder="Session name (optional)" class="flex-1" />
							<button v-if="sessions.length > 1" type="button" class="px-1 text-rose-700" :aria-label="`Remove session ${i + 1}`" @click="sessions.splice(i, 1)">×</button>
						</div>
						<div class="mt-2 grid grid-cols-2 gap-2">
							<label class="text-xs text-gray-500">Starts
								<input v-model="s.starts_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 text-sm">
							</label>
							<label class="text-xs text-gray-500">Ends
								<input v-model="s.ends_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-gray-200 bg-gray-50 px-2 py-2 text-sm">
							</label>
						</div>
					</div>
					<button type="button" class="text-sm font-semibold text-primary-700" @click="sessions.push(blankSession())">+ Add another session</button>
					<p class="text-xs text-gray-400">Times are in Philippine time. Multi-day events can have one session per day.</p>
					<p v-if="fields.starts_at || fields.ends_at || fields.sessions" class="text-xs text-red-600">{{ fields.starts_at || fields.ends_at || fields.sessions }}</p>
				</section>

				<section class="space-y-3">
					<h3 class="text-sm font-bold uppercase tracking-wide text-gray-400">Venue</h3>
					<div>
						<FormsLabel text="Venue Name" />
						<FormsTextField v-model="form.venue_name" maxlength="200" placeholder="e.g. Pearl Garden Events Place" />
					</div>
					<div>
						<FormsLabel text="Address" />
						<FormsTextField v-model="form.venue_address" maxlength="500" placeholder="Street, barangay, city" />
					</div>
					<div class="grid grid-cols-3 gap-2">
						<div>
							<FormsLabel text="Capacity" />
							<FormsTextField v-model="form.overall_capacity" type="number" min="1" placeholder="150" />
						</div>
						<div>
							<FormsLabel text="Latitude" />
							<FormsTextField v-model="coords.latitude" inputmode="decimal" placeholder="7.0731" />
						</div>
						<div>
							<FormsLabel text="Longitude" />
							<FormsTextField v-model="coords.longitude" inputmode="decimal" placeholder="125.6128" />
						</div>
					</div>
					<p v-if="fields.latitude || fields.longitude || fields.overall_capacity" class="text-xs text-red-600">{{ fields.latitude || fields.longitude || fields.overall_capacity }}</p>
				</section>

				<section class="space-y-3">
					<h3 class="text-sm font-bold uppercase tracking-wide text-gray-400">Who can attend</h3>
					<label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm" :class="form.visibility === 'private' ? 'border-primary-600 bg-primary-50' : 'border-gray-200'">
						<input v-model="form.visibility" type="radio" value="private" class="mt-1">
						<span><span class="font-semibold">Private</span><span class="block text-xs text-gray-500">Invited guests only (e.g. a wedding guest list).</span></span>
					</label>
					<label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm" :class="form.visibility === 'public' ? 'border-primary-600 bg-primary-50' : 'border-gray-200'">
						<input v-model="form.visibility" type="radio" value="public" class="mt-1">
						<span><span class="font-semibold">Public</span><span class="block text-xs text-gray-500">Anyone can register or buy tickets once ticketing is set up.</span></span>
					</label>
				</section>

				<section class="space-y-3">
					<h3 class="text-sm font-bold uppercase tracking-wide text-gray-400">Event point person</h3>
					<p class="text-xs text-gray-500">Who guests and staff contact about this event (shown to the client).</p>
					<FormsTextField v-model="form.contact_name" maxlength="120" placeholder="Contact person" aria-label="Contact person" />
					<div class="grid grid-cols-2 gap-2">
						<FormsTextField v-model="form.contact_email" type="email" placeholder="Email" aria-label="Contact email" />
						<FormsTextField v-model="form.contact_phone" type="tel" placeholder="Phone" aria-label="Contact phone" />
					</div>
					<p v-if="fields.contact_email" class="text-xs text-red-600">{{ fields.contact_email }}</p>
				</section>
			</form>

			<div class="flex shrink-0 justify-end gap-2 border-t border-gray-200 px-6 py-4">
				<button type="button" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="$emit('close')">Cancel</button>
				<button type="button" :disabled="busy" class="rounded-xl bg-primary-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-900 disabled:opacity-50" @click="save">
					{{ busy ? 'Saving…' : mode === 'edit' ? 'Save changes' : 'Create event' }}
				</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { EventFields, OrganizerEvent, SessionInput } from '~/composables/useEvents'
import type { OrganizerQuotation } from '~/composables/useQuotations'

const props = defineProps<{
	organizationId: string
	/** Edit an existing event. */
	event?: OrganizerEvent | null
	/** Create the event for this accepted quotation (prefilled from its inquiry). */
	quotation?: OrganizerQuotation | null
}>()
const emit = defineEmits<{ close: [], saved: [event: OrganizerEvent] }>()

const api = useApi()
const eventsApi = useOrganizerEvents()

const mode = computed(() => props.event ? 'edit' : props.quotation ? 'convert' : 'create')
const title = computed(() => ({ edit: 'Edit event', convert: 'Create the event', create: 'Create new event' })[mode.value])
const subtitle = computed(() => mode.value === 'convert'
	? `From your accepted quotation for ${props.quotation?.client?.name ?? 'the client'}. The client will see this event.`
	: mode.value === 'edit' ? 'Update the event details. Manage the schedule on the event page.' : 'Start as a draft; publish when ready.')

const eventTypes = ref<{ id: string, name: string }[]>([])
const busy = ref(false)
const error = ref('')
const fields = ref<Record<string, string>>({})

function blankSession(date = ''): SessionInput {
	return { name: null, starts_at: date ? `${date}T17:00` : '', ends_at: date ? `${date}T22:00` : '' }
}

const inquiry = props.quotation?.inquiry
const source = props.event
const form = reactive({
	name: source?.name ?? (inquiry ? `${inquiry.event_type ?? 'Event'} for ${props.quotation?.client?.name ?? 'client'}` : ''),
	event_type_id: source?.event_type_id ?? inquiry?.event_type_id ?? null,
	description: source?.description ?? '',
	overall_capacity: (source?.overall_capacity ?? inquiry?.guest_count ?? '') as number | string,
	venue_name: source?.venue_name ?? '',
	venue_address: source?.venue_address ?? inquiry?.location_text ?? '',
	visibility: source?.visibility ?? 'private',
	contact_name: source?.contact_name ?? props.quotation?.client?.name ?? '',
	contact_email: source?.contact_email ?? props.quotation?.client?.email ?? '',
	contact_phone: source?.contact_phone ?? props.quotation?.client?.phone ?? '',
})
const coords = reactive({ latitude: source?.latitude?.toString() ?? '', longitude: source?.longitude?.toString() ?? '' })
// Unfinished new event (from scratch or from a won quotation); edits aren't drafted.
const draft = useDraft(() => (props.event ? null : props.quotation ? `event:from:${props.quotation.id}` : 'event:new'), form)
const sessions = reactive<SessionInput[]>([blankSession(inquiry?.requested_date ?? '')])

function payload(): EventFields & { sessions?: SessionInput[] } {
	const text = (v: string | null | undefined) => (v ?? '').toString().trim() || null
	const fields: EventFields = {
		name: form.name.trim(),
		event_type_id: form.event_type_id,
		description: text(form.description),
		overall_capacity: form.overall_capacity === '' ? null : Number(form.overall_capacity),
		venue_name: text(form.venue_name),
		venue_address: text(form.venue_address),
		latitude: (coords.latitude.trim() || null) as number | null,
		longitude: (coords.longitude.trim() || null) as number | null,
		visibility: form.visibility,
		contact_name: text(form.contact_name),
		contact_email: text(form.contact_email),
		contact_phone: text(form.contact_phone),
	}
	if (mode.value === 'edit') return fields
	return { ...fields, sessions: sessions.filter(s => s.starts_at || s.ends_at).map(s => ({ name: text(s.name), starts_at: s.starts_at, ends_at: s.ends_at })) }
}

async function save() {
	error.value = ''
	fields.value = {}
	busy.value = true
	try {
		const data = payload()
		const saved = mode.value === 'edit'
			? await eventsApi.update(props.organizationId, props.event!.id, data)
			: mode.value === 'convert'
				? await eventsApi.convert(props.organizationId, props.quotation!.id, data)
				: await eventsApi.create(props.organizationId, data)
		draft.clear()
		emit('saved', saved)
	} catch (e) {
		// Session errors come back as sessions.0.starts_at; show them under Schedule.
		fields.value = Object.fromEntries(Object.entries(fieldErrors(e)).map(([k, v]) => [k.replace(/^sessions\.\d+\./, ''), v]))
		error.value = Object.values(fields.value)[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		eventTypes.value = (await api.get<{ id: string, name: string }[]>(`/organizations/${props.organizationId}/event-types`)).data
	} catch {
		// Type list is optional; the event can be saved without one.
	}
})
</script>
