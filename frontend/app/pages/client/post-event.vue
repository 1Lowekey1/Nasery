<template>
	<div class="mx-auto max-w-3xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Post an event</h1>
		<p class="mt-1 mb-6 text-gray-500">
			Describe your event. {{ target ? `We'll send it to ${target.display_name}.` : 'Choose organizers yourself, or let NaSeRy send it to every matching organizer near you.' }}
		</p>
		<p v-if="estimate !== null" class="-mt-4 mb-6 rounded-xl bg-primary-50 p-3 text-sm text-primary-900">
			Your calculator picks are filled in below (estimate {{ formatPeso(estimate) }}). {{ target?.display_name }} will confirm the price in their quotation.
		</p>

		<Alert v-if="error" type="danger" :text="error" />

		<form novalidate class="space-y-6" @submit.prevent="send">
			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-4 text-base font-bold text-gray-900">Event</h2>
				<FormsLabel text="Event Type" required />
				<div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
					<button v-for="t in eventTypes" :key="t.id" type="button"
						class="rounded-xl border px-3 py-2.5 text-sm font-semibold"
						:class="form.event_type_id === t.id ? 'border-primary-600 bg-primary-50 text-primary-800' : 'border-gray-200 text-gray-600 hover:border-primary-300'"
						@click="form.event_type_id = t.id">{{ t.name }}</button>
				</div>
				<p v-if="fields.event_type_id" class="mt-1 text-xs text-red-600">{{ fields.event_type_id }}</p>

				<div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
					<div>
						<FormsLabel text="Event Date" required />
						<FormsTextField v-model="form.requested_date" type="date" :min="today" />
						<p v-if="fields.requested_date" class="mt-1 text-xs text-red-600">{{ fields.requested_date }}</p>
					</div>
					<div>
						<FormsLabel text="Expected Guests" required />
						<FormsTextField v-model="form.guest_count" type="number" min="1" placeholder="150" />
						<p v-if="fields.guest_count" class="mt-1 text-xs text-red-600">{{ fields.guest_count }}</p>
					</div>
					<div>
						<FormsLabel text="Budget From (₱)" />
						<FormsTextField v-model="form.budget_min" type="number" min="0" placeholder="100000" />
					</div>
					<div>
						<FormsLabel text="Budget Up To (₱)" />
						<FormsTextField v-model="form.budget_max" type="number" min="0" placeholder="250000" />
						<p v-if="fields.budget_max" class="mt-1 text-xs text-red-600">{{ fields.budget_max }}</p>
					</div>
				</div>
			</section>

			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-4 text-base font-bold text-gray-900">Location</h2>
				<FormsLabel text="Venue or Area" required />
				<FormsTextField v-model="form.location_text" placeholder="Matina, Davao City" />
				<p v-if="fields.location_text" class="mt-1 text-xs text-red-600">{{ fields.location_text }}</p>
				<div class="mt-3 grid grid-cols-2 gap-3">
					<div>
						<FormsLabel text="Latitude" />
						<FormsTextField v-model="coords.latitude" inputmode="decimal" placeholder="7.0731" />
					</div>
					<div>
						<FormsLabel text="Longitude" />
						<FormsTextField v-model="coords.longitude" inputmode="decimal" placeholder="125.6128" />
					</div>
				</div>
				<div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-2 font-semibold hover:bg-gray-50" @click="pinHere">
						Use my current location
					</button>
					<span class="text-gray-400">Needed to find organizers near the venue. Copy coordinates from any map app if you're not at the venue.</span>
				</div>
				<p v-if="fields.latitude || fields.longitude" class="mt-1 text-xs text-red-600">{{ fields.latitude || fields.longitude }}</p>
			</section>

			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-1 text-base font-bold text-gray-900">Services you need</h2>
				<p class="mb-4 text-sm text-gray-500">Organizers are matched on these.</p>
				<div v-for="(s, i) in form.services" :key="i" class="mb-2 grid grid-cols-[1fr_1fr_auto] gap-2">
					<FormsTextField v-model="s.custom_service_name" placeholder="e.g. Wedding coordination" />
					<FormsTextField v-model="s.notes" placeholder="Notes (optional)" />
					<button type="button" class="px-2 text-sm text-rose-700" :aria-label="`Remove service ${i + 1}`" @click="form.services.splice(i, 1)">×</button>
				</div>
				<button type="button" class="text-sm font-semibold text-primary-700" @click="form.services.push({ custom_service_name: '', notes: '' })">+ Add a service</button>
				<p v-if="fields.services" class="mt-1 text-xs text-red-600">{{ fields.services }}</p>

				<div class="mt-4">
					<FormsLabel text="Other Details" />
					<textarea v-model="form.additional_requirements" rows="4" maxlength="3000"
						placeholder="Theme, schedule, must-haves…"
						class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
				</div>
			</section>

			<section v-if="!target" class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-4 text-base font-bold text-gray-900">Who should receive it?</h2>
				<div class="grid gap-3 sm:grid-cols-2">
					<label class="cursor-pointer rounded-xl border p-4" :class="mode === 'open' ? 'border-primary-600 bg-primary-50' : 'border-gray-200'">
						<input v-model="mode" type="radio" value="open" class="sr-only">
						<span class="block text-sm font-bold">Matching organizers near me</span>
						<span class="mt-1 block text-xs text-gray-500">Every approved organizer offering your services within the distance you choose.</span>
					</label>
					<label class="cursor-pointer rounded-xl border p-4" :class="mode === 'direct' ? 'border-primary-600 bg-primary-50' : 'border-gray-200'">
						<input v-model="mode" type="radio" value="direct" class="sr-only">
						<span class="block text-sm font-bold">Organizers I choose</span>
						<span class="mt-1 block text-xs text-gray-500">Pick up to 10 from the directory.</span>
					</label>
				</div>

				<div v-if="mode === 'open'" class="mt-4">
					<FormsLabel :text="`Within ${radius} km of my pin`" />
					<input v-model.number="radius" type="range" min="5" max="200" step="5" class="w-full" aria-label="Matching distance in kilometres">
				</div>

				<div v-else class="mt-4">
					<FormsTextField v-model="organizerSearch" placeholder="Search organizers by name or service…" @keyup.enter.prevent="searchOrganizers" />
					<ul class="mt-2 divide-y divide-gray-100 text-sm">
						<li v-for="o in organizerResults" :key="o.id" class="flex items-center justify-between py-2">
							<span>{{ o.display_name }} <span class="text-gray-400">· {{ o.location_text }}</span></span>
							<button type="button" class="font-semibold" :class="chosen.has(o.id) ? 'text-rose-700' : 'text-primary-700'" @click="toggleChosen(o.id, o.display_name)">
								{{ chosen.has(o.id) ? 'Remove' : 'Add' }}
							</button>
						</li>
					</ul>
					<p class="mt-2 text-xs text-gray-500">Selected: {{ [...chosen.values()].join(', ') || 'none yet' }}</p>
					<p v-if="fields.organization_ids" class="mt-1 text-xs text-red-600">{{ fields.organization_ids }}</p>
				</div>
			</section>

			<button type="submit" :disabled="sending"
				class="w-full rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white hover:bg-primary-900 disabled:cursor-wait disabled:opacity-70">
				{{ sending ? 'Sending…' : 'Send inquiry' }}
			</button>
		</form>
	</div>
</template>

<script setup lang="ts">
import type { InquiryPayload, InquiryServiceInput } from '~/composables/useInquiries'
import type { OrganizerCard, OrganizerPublicProfile } from '~/composables/useMarketplace'

definePageMeta({ layout: 'client' })

const route = useRoute()
const { create, update, submit } = useInquiries()
const { eventTypes: loadEventTypes, organizer: loadOrganizer, organizers: searchDirectory } = useMarketplace()
const estimate = ref<number | null>(null)

const today = new Date().toISOString().slice(0, 10)
const eventTypes = ref<{ id: string, name: string }[]>([])
const target = ref<{ id: string, display_name: string } | null>(null)
const mode = ref<'open' | 'direct'>('open')
const radius = ref(30)
const chosen = reactive(new Map<string, string>())
const organizerSearch = ref('')
const organizerResults = ref<OrganizerCard[]>([])
const draftId = ref<string | null>(null)
const sending = ref(false)
const error = ref('')
const fields = ref<Record<string, string>>({})

const coords = reactive({ latitude: '', longitude: '' })

const form = reactive({
	event_type_id: null as string | null,
	requested_date: '',
	guest_count: '' as string | number,
	budget_min: '' as string | number,
	budget_max: '' as string | number,
	location_text: '',
	additional_requirements: '',
	services: [{ custom_service_name: '', notes: '' }] as InquiryServiceInput[],
})

function num(v: string | number) {
	return v === '' || v === null ? null : Number(v)
}

function payload(): InquiryPayload {
	return {
		event_type_id: form.event_type_id,
		requested_date: form.requested_date || null,
		guest_count: num(form.guest_count),
		budget_min: num(form.budget_min),
		budget_max: num(form.budget_max),
		location_text: form.location_text || null,
		// Sent as typed so the server rejects malformed coordinates instead of dropping them.
		latitude: (coords.latitude.trim() || null) as number | null,
		longitude: (coords.longitude.trim() || null) as number | null,
		additional_requirements: form.additional_requirements || null,
		services: form.services.filter(s => s.custom_service_name?.trim()).map(s => ({ custom_service_name: s.custom_service_name!.trim(), notes: s.notes || null })),
	}
}

function pinHere() {
	navigator.geolocation?.getCurrentPosition((p) => {
		coords.latitude = p.coords.latitude.toFixed(6)
		coords.longitude = p.coords.longitude.toFixed(6)
	}, () => { error.value = 'Location permission was denied. Enter the coordinates instead, or choose organizers yourself.' })
}

async function searchOrganizers() {
	organizerResults.value = (await searchDirectory({ search: organizerSearch.value.trim(), page: 1 })).items
}

function toggleChosen(id: string, name: string) {
	if (chosen.has(id)) chosen.delete(id)
	else if (chosen.size < 10) chosen.set(id, name)
}

async function send() {
	error.value = ''
	fields.value = {}
	sending.value = true
	try {
		// Save (or re-save) the draft, then send it.
		const draft = draftId.value ? await update(draftId.value, payload()) : await create(payload())
		draftId.value = draft.id
		const sent = target.value
			? await submit(draft.id, { mode: 'direct', organization_ids: [target.value.id] })
			: mode.value === 'open'
				? await submit(draft.id, { mode: 'open', match_radius_km: radius.value })
				: await submit(draft.id, { mode: 'direct', organization_ids: [...chosen.keys()] })
		await navigateTo(`/client/inquiries/${sent.id}`)
	} catch (e) {
		fields.value = fieldErrors(e)
		// Show the first problem at the top too (covers nested keys like services.0.*).
		error.value = Object.values(fields.value)[0] ?? authMessage(e)
	} finally {
		sending.value = false
	}
}

// Picks from the organizer's quotation calculator (?guests=&options=id,id).
function prefillFromCalculator(org: OrganizerPublicProfile) {
	const guests = Number(route.query.guests)
	if (guests > 0) form.guest_count = guests
	const ids = typeof route.query.options === 'string' ? route.query.options.split(',') : []
	const picks = org.price_options.filter(o => ids.includes(o.id))
	if (!picks.length) return

	form.services = picks.map((o) => {
		const service = org.services.find(s => s.id === o.organization_service_id)?.name
		const price = `${formatPeso(o.price)}${o.pricing_unit === 'per_guest' ? ' per guest' : ''}`
		return { custom_service_name: service ?? o.name, notes: service ? `${o.name} (${price})` : price }
	})
	estimate.value = linesTotal(picks.map(o => optionAsLine(o, guests > 0 ? guests : null)))
	form.additional_requirements = `Estimate from ${org.display_name}'s quotation calculator: ${formatPeso(estimate.value)} for ${guests || 'the listed'} guests.`
}

onMounted(async () => {
	try {
		eventTypes.value = await loadEventTypes()
		if (typeof route.query.organizer === 'string') {
			const org = await loadOrganizer(route.query.organizer)
			target.value = { id: org.id, display_name: org.display_name }
			prefillFromCalculator(org)
		}
	} catch (e) {
		error.value = authMessage(e)
	}
})
</script>
