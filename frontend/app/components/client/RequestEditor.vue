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
					<FormsLabel :text="dayDates.length > 1 && dayDates.length <= MAX_EVENT_DAYS && form.per_day ? 'Day 1 start' : 'Starts'" />
					<FormsTimeField v-model="form.start_time" />
				</div>
				<div>
					<FormsLabel :text="dayDates.length > 1 && dayDates.length <= MAX_EVENT_DAYS && form.per_day ? 'Day 1 end' : 'Ends'" />
					<FormsTimeField v-model="form.end_time" />
				</div>
			</div>
			<div class="max-w-xs">
				<FormsLabel text="Ends on (optional)" />
				<FormsDateField v-model="form.end_date" :min-date="form.requested_date || today" placeholder="Same day" />
				<p class="mt-1 text-xs text-gray-400">For events over several days. An end time earlier than the start means the next day.</p>
			</div>
			<!-- Members' follow-up (Nov 2026): over several days, the same hours every day or each day its own. -->
			<div v-if="dayDates.length > 1" class="rounded-xl border border-gray-200 p-3">
				<div class="flex flex-wrap items-center justify-between gap-2">
					<p class="text-sm font-semibold text-gray-900">{{ dayDates.length }} days · hours on each day</p>
					<div class="flex rounded-lg bg-gray-100 p-0.5 text-xs font-semibold" role="group" aria-label="Hours on each day">
						<button type="button" class="rounded-md px-2.5 py-1.5" :class="!form.per_day || dayDates.length > MAX_EVENT_DAYS ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" :aria-pressed="!form.per_day || dayDates.length > MAX_EVENT_DAYS" @click="form.per_day = false">Same every day</button>
						<button type="button" class="rounded-md px-2.5 py-1.5 disabled:cursor-not-allowed disabled:opacity-40" :class="form.per_day && dayDates.length <= MAX_EVENT_DAYS ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" :aria-pressed="form.per_day && dayDates.length <= MAX_EVENT_DAYS" :disabled="dayDates.length > MAX_EVENT_DAYS" @click="form.per_day = true">Different each day</button>
					</div>
				</div>
				<p v-if="dayDates.length > MAX_EVENT_DAYS" class="mt-2 text-xs font-medium text-amber-700">Different hours each day is for events up to {{ MAX_EVENT_DAYS }} days. Use the same hours, and mention any day that differs in your notes.</p>
				<div v-else-if="form.per_day" class="mt-3">
					<div class="flex flex-wrap items-center justify-between gap-2">
						<p class="text-xs text-gray-500">Day 1 uses Starts and Ends above.</p>
						<button type="button" class="text-xs font-semibold text-primary-700 hover:underline disabled:opacity-40" :disabled="!form.start_time || !form.end_time" @click="form.day_hours = form.day_hours.map(() => ({ start: form.start_time, end: form.end_time }))">Copy Day 1 hours to all days</button>
					</div>
					<div class="mt-2 grid max-h-80 gap-x-4 gap-y-1.5 overflow-y-auto pr-1 2xl:grid-cols-2">
						<div v-for="(date, i) in dayDates.slice(1)" :key="date" class="grid grid-cols-[5.25rem_1fr_1fr] items-center gap-1.5">
							<span class="text-xs font-semibold leading-4 text-gray-900">Day {{ i + 2 }}<span class="block font-normal text-gray-500">{{ new Date(`${date}T00:00:00`).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) }}</span></span>
							<FormsTimeField v-if="form.day_hours[i]" v-model="form.day_hours[i]!.start" placeholder="Start" />
							<FormsTimeField v-if="form.day_hours[i]" v-model="form.day_hours[i]!.end" placeholder="End" />
						</div>
					</div>
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
// Overnight / multi-day requests (Advisor's fix, Nov 2026): "Ends on: YYYY-MM-DD", rebuilt on save.
const ENDS = /^Ends on: (\d{4}-\d{2}-\d{2})$/
const lines = (props.inquiry.additional_requirements ?? '').split('\n')
const time = lines.map(l => l.match(TIME)).find(Boolean)
/** The saved "Ends on" date, unless it only says "the next day" (that follows from the times). */
function multiDayEnd() {
	const ends = lines.map(l => l.match(ENDS)).find(Boolean)?.[1] ?? ''
	const date = props.inquiry.requested_date ?? ''
	if (!ends || !date || !time) return ends
	return scheduleEnd(date, time[1]!, '', time[2]!).date === ends ? '' : ends
}
const kept = lines.filter(l => KEPT.test(l))
// Per-day hours (Members' follow-up, Nov 2026), "Day 2 · 2027-01-07: 13:00 – 22:00"; days 2.. by position.
const savedDays = lines.map(l => l.match(DAY_LINE)).filter(Boolean).map(m => ({ start: m![2]!, end: m![3]! }))

const form = reactive({
	requested_date: props.inquiry.requested_date ?? '',
	start_time: time?.[1] ?? '',
	end_time: time?.[2] ?? '',
	end_date: multiDayEnd(),
	per_day: savedDays.length > 1,
	day_hours: savedDays.slice(1),
	guest_count: props.inquiry.guest_count ? String(props.inquiry.guest_count) : '',
	budget_min: props.inquiry.budget_min ? String(Number(props.inquiry.budget_min)) : '',
	budget_max: props.inquiry.budget_max ? String(Number(props.inquiry.budget_max)) : '',
	services: (props.inquiry.services ?? []).map(s => ({
		service_id: s.service_id, name: s.custom_service_name || 'Service', custom: s.service_id ? null : s.custom_service_name, notes: s.notes ?? '',
	})),
	notes: lines.filter(l => !TIME.test(l) && !KEPT.test(l) && !ENDS.test(l) && !DAY_LINE.test(l)).join('\n').trim(),
})
const dayDates = computed(() => daysBetween(form.requested_date, form.end_date))
watch(dayDates, (dates) => {
	while (dates.length <= MAX_EVENT_DAYS && form.day_hours.length < dates.length - 1) form.day_hours.push({ start: form.start_time, end: form.end_time })
}, { immediate: true })
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
	const endDay = form.requested_date && form.start_time && form.end_time ? scheduleEnd(form.requested_date, form.start_time, form.end_date, form.end_time).date : ''
	const endsLine = endDay && endDay !== form.requested_date ? `Ends on: ${endDay}` : ''
	const days = form.per_day && dayDates.value.length > 1 && dayDates.value.length <= MAX_EVENT_DAYS && form.start_time && form.end_time
		? dayLines(dayDates.value, Object.fromEntries(dayDates.value.map((d, i) => [d, i === 0 ? { start: form.start_time, end: form.end_time } : form.day_hours[i - 1]!])))
		: []
	try {
		const saved = await useInquiries().update(props.inquiry.id, {
			requested_date: form.requested_date || null,
			guest_count: form.guest_count === '' ? null : Number(form.guest_count),
			budget_min: amount(form.budget_min),
			budget_max: amount(form.budget_max),
			additional_requirements: [kept.find(l => l.startsWith('Event: ')), timeLine, endsLine, ...days, kept.find(l => l.startsWith('Venue: ')), form.notes.trim()]
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
