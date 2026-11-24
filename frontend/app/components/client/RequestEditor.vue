<template>
	<section class="mt-6 rounded-2xl border border-primary-200 bg-white p-6 shadow-sm" aria-labelledby="edit-request-title">
		<div class="flex items-start gap-3">
			<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
				<IconBase name="edit" class="h-5 w-5" />
			</span>
			<div class="min-w-0">
				<h2 id="edit-request-title" class="text-base font-bold text-gray-900">Edit request</h2>
				<p class="mt-1 text-sm leading-6 text-gray-600">
					Organizers you invited are told what changed. Anyone who already sent a quotation can revise it.
				</p>
			</div>
		</div>

		<form class="mt-5 space-y-5" novalidate @submit.prevent="save">
			<FormsDraftNotice :draft="draft" />

			<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
				<div>
					<FormsLabel text="Event date" required />
					<FormsDateField v-model="form.requested_date" :min-date="today" />
					<p v-if="fields.requested_date" class="mt-1 text-xs text-rose-600">{{ fields.requested_date }}</p>
				</div>
				<div>
					<FormsLabel text="Starts" />
					<FormsTimeField v-model="form.start_time" />
				</div>
				<div>
					<FormsLabel text="Ends" />
					<FormsTimeField v-model="form.end_time" />
				</div>
			</div>

			<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
				<div>
					<FormsLabel text="Guests" required />
					<FormsTextField v-model="form.guest_count" type="number" min="1" />
					<p v-if="fields.guest_count" class="mt-1 text-xs text-rose-600">{{ fields.guest_count }}</p>
				</div>
				<div>
					<FormsLabel text="Budget from (₱)" />
					<FormsTextField v-model="form.budget_min" type="number" min="0" placeholder="No minimum" />
					<p v-if="fields.budget_min" class="mt-1 text-xs text-rose-600">{{ fields.budget_min }}</p>
				</div>
				<div>
					<FormsLabel text="Budget up to (₱)" />
					<FormsTextField v-model="form.budget_max" type="number" min="0" placeholder="No maximum" />
					<p v-if="fields.budget_max" class="mt-1 text-xs text-rose-600">{{ fields.budget_max }}</p>
				</div>
			</div>

			<div>
				<FormsLabel text="Services" />
				<!-- Client feedback (Nov 2026): the same service list as Post Event Inquiry, so clients can see what there is. -->
				<FormsTagPicker v-model="serviceNames" multiple :options="serviceTagOptions" placeholder="Choose the services you need" search-placeholder="Search services" />
				<p v-if="form.services.length" class="mb-2 mt-3 text-xs text-gray-500">Add a note to any service if you like.</p>
				<ul class="space-y-2">
					<li v-for="(s, i) in form.services" :key="i" class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 p-2.5 sm:flex-nowrap">
						<span class="w-full px-1 text-sm font-semibold text-gray-800 sm:w-44 sm:shrink-0 sm:truncate">{{ s.name }}</span>
						<input v-model="s.notes" type="text" maxlength="500" placeholder="Notes for this service (optional)" :aria-label="`Notes for ${s.name}`"
							class="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15">
						<button type="button" class="rounded-lg px-2.5 py-2 text-xs font-semibold text-gray-500 hover:bg-red-50 hover:text-red-600" :aria-label="`Remove ${s.name}`" @click="form.services.splice(i, 1)">
							Remove
						</button>
					</li>
				</ul>
				<p v-if="fields.services" class="mt-1 text-xs text-rose-600">{{ fields.services }}</p>
			</div>

			<div>
				<FormsLabel text="Notes for organizers" />
				<textarea v-model="form.notes" rows="4" maxlength="2500" placeholder="Anything organizers should know"
					class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15" />
				<p v-if="fields.additional_requirements" class="mt-1 text-xs text-rose-600">{{ fields.additional_requirements }}</p>
			</div>

			<p class="flex items-start gap-2 rounded-xl bg-gray-50 px-3.5 py-2.5 text-xs leading-5 text-gray-500">
				<IconBase name="map-pin" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
				<span>
					The event type and place can't change: organizers were invited for them. To change those, cancel this request and post a new one.
					{{ inquiry.revisions_left === 1 ? 'This is your last change for this request.' : `You can change this request ${inquiry.revisions_left} more times.` }}
				</span>
			</p>

			<p v-if="error" class="rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ error }}</p>

			<div class="flex flex-wrap justify-end gap-2">
				<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" :disabled="busy" @click="discard">
					Cancel
				</button>
				<button type="submit" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-bold text-white hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-50" :disabled="busy">
					{{ busy ? 'Saving…' : 'Save changes' }}
				</button>
			</div>
		</form>
	</section>
</template>

<script setup lang="ts">
// Change a sent request while it is open (0051). Event type and place are
// fixed; Post an Event keeps the event name, time and venue as lines in the
// notes, so those lines are kept (the time can be changed here).
import type { ClientInquiry } from '~/composables/useInquiries'

const props = defineProps<{ inquiry: ClientInquiry }>()
const emit = defineEmits<{ saved: [inquiry: ClientInquiry], cancel: [] }>()

const TIME = /^Time: (\d{2}:\d{2}) – (\d{2}:\d{2})$/
const KEPT = /^(Event|Venue): /
const lines = (props.inquiry.additional_requirements ?? '').split('\n')
const time = lines.map(l => l.match(TIME)).find(Boolean)
const kept = lines.filter(l => KEPT.test(l))

const form = reactive({
	requested_date: props.inquiry.requested_date ?? '',
	start_time: time?.[1] ?? '',
	end_time: time?.[2] ?? '',
	guest_count: props.inquiry.guest_count ? String(props.inquiry.guest_count) : '',
	budget_min: props.inquiry.budget_min ? String(Number(props.inquiry.budget_min)) : '',
	budget_max: props.inquiry.budget_max ? String(Number(props.inquiry.budget_max)) : '',
	services: (props.inquiry.services ?? []).map(s => ({
		service_id: s.service_id, name: s.custom_service_name || 'Service', custom: s.service_id ? null : s.custom_service_name, notes: s.notes ?? '',
	})),
	notes: lines.filter(l => !TIME.test(l) && !KEPT.test(l)).join('\n').trim(),
})
// Unsaved edits survive a reload; an untouched form keeps no draft.
const initial = JSON.stringify(form)
const draft = useDraft(() => `request-edit:${props.inquiry.id}`, form, { isEmpty: v => JSON.stringify(v) === initial })
const today = new Date().toISOString().slice(0, 10)
const busy = ref(false)
const error = ref('')
const fields = ref<Record<string, string>>({})

// The service picker (as on Post Event Inquiry): NaSeRy's catalog plus services organizers list,
// and anything already on this request. Picking adds a line; unpicking removes it (and its note).
const catalog = ref<{ name: string, category: string | null }[]>([])
const offered = ref<{ name: string, organizers: number }[]>([])
onMounted(async () => {
	const [list, names] = await Promise.all([
		useApi().get<{ name: string, category: string | null }[]>('/services').then(r => r.data).catch(() => []),
		useMarketplace().serviceNames().catch(() => []),
	])
	catalog.value = list
	offered.value = names.filter(s => s.organizers > 0)
})
const serviceTagOptions = computed(() => {
	const count = new Map(offered.value.map(s => [s.name.toLowerCase(), s.organizers]))
	const hint = (name: string) => {
		const n = count.get(name.toLowerCase()) ?? 0
		return n ? `${n} ${n === 1 ? 'organizer' : 'organizers'}` : 'No organizers yet'
	}
	// The request's own spelling wins (e.g. "Event coordination" vs "Event Coordination"), so it shows as picked.
	const group = new Map(catalog.value.map(s => [s.name.toLowerCase(), s.category || 'Other']))
	const all = [...form.services.map(s => ({ name: s.name, group: group.get(s.name.toLowerCase()) ?? 'Other' })),
		...catalog.value.map(s => ({ name: s.name, group: s.category || 'Other' })), ...offered.value.map(s => ({ name: s.name, group: 'Other' }))]
	const seen = new Set<string>()
	return all.filter(o => !seen.has(o.name.toLowerCase()) && seen.add(o.name.toLowerCase()))
		.map(o => ({ value: o.name, label: o.name, group: o.group, hint: hint(o.name) }))
		.sort((a, b) => Number(a.hint === 'No organizers yet') - Number(b.hint === 'No organizers yet') || a.label.localeCompare(b.label))
})
const serviceNames = computed({
	get: () => form.services.map(s => s.name),
	set: (names: string | string[]) => {
		const picked = Array.isArray(names) ? names : [names]
		const kept = form.services.filter(s => picked.includes(s.name))
		const added = picked.filter(n => !kept.some(s => s.name === n)).map(n => ({ service_id: null, name: n, custom: n, notes: '' }))
		form.services = [...kept, ...added]
	},
})

function discard() {
	draft.clear()
	emit('cancel')
}

const amount = (v: string) => (String(v).trim() === '' ? null : Number(v))

async function save() {
	busy.value = true
	error.value = ''
	fields.value = {}
	const timeLine = form.start_time && form.end_time ? `Time: ${form.start_time} – ${form.end_time}` : ''
	try {
		const saved = await useInquiries().update(props.inquiry.id, {
			requested_date: form.requested_date || null,
			guest_count: form.guest_count === '' ? null : Number(form.guest_count),
			budget_min: amount(form.budget_min),
			budget_max: amount(form.budget_max),
			additional_requirements: [kept.find(l => l.startsWith('Event: ')), timeLine, kept.find(l => l.startsWith('Venue: ')), form.notes.trim()]
				.filter(Boolean).join('\n') || null,
			services: form.services.map(s => ({ service_id: s.service_id, custom_service_name: s.custom, notes: s.notes.trim() || null })),
		})
		draft.clear()
		emit('saved', saved)
	} catch (e) {
		const f = fieldErrors(e)
		fields.value = f
		error.value = f.request ?? (Object.keys(f).length ? 'Please check the highlighted fields.' : authMessage(e))
	} finally {
		busy.value = false
	}
}
</script>
