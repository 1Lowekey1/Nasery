<template>
	<div class="mx-auto max-w-4xl">
		<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
			<div>
				<h1 class="text-2xl font-extrabold text-gray-900">Business profile</h1>
				<p class="mt-1 text-gray-500">What clients see in the NaSeRy organizer directory.</p>
			</div>
			<NuxtLink v-if="organization" :to="`/organizers/${organization.slug}`" target="_blank"
				class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
				View public page ↗
			</NuxtLink>
		</div>

		<Alert v-if="error" type="danger" :text="error" />
		<p v-if="notice" class="mb-4 rounded-xl border border-green-100 bg-green-50 px-3 py-2 text-sm text-green-800">{{ notice }}</p>

		<section class="mb-6 rounded-2xl border border-gray-200 bg-white p-6">
			<div class="flex flex-wrap items-center justify-between gap-3">
				<div>
					<h2 class="text-base font-bold text-gray-900">Directory listing</h2>
					<p class="mt-1 text-sm text-gray-500">
						{{ profile.is_published ? 'Your profile is published.' : 'Your profile is hidden.' }}
						<template v-if="profile.is_published && !organization?.is_operational">It appears once NaSeRy approves your organization.</template>
					</p>
				</div>
				<button type="button" :disabled="!isOwner || saving"
					class="rounded-lg px-4 py-2 text-sm font-semibold disabled:opacity-50"
					:class="profile.is_published ? 'border border-gray-200 text-gray-700 hover:bg-gray-50' : 'bg-primary-700 text-white hover:bg-primary-900'"
					@click="togglePublished">
					{{ profile.is_published ? 'Hide from directory' : 'Publish to directory' }}
				</button>
			</div>
		</section>

		<form class="mb-6 rounded-2xl border border-gray-200 bg-white p-6" novalidate @submit.prevent="saveProfile">
			<h2 class="mb-4 text-base font-bold text-gray-900">Details</h2>
			<fieldset :disabled="!isOwner" class="space-y-4">
				<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
					<div>
						<FormsLabel text="Brand / Trade Name" required />
						<FormsTextField v-model="profile.business_name" />
						<p v-if="fields.business_name" class="mt-1 text-xs text-red-600">{{ fields.business_name }}</p>
					</div>
					<div>
						<FormsLabel text="Business Address" required />
						<FormsTextField v-model="profile.location_text" />
						<p v-if="fields.location_text" class="mt-1 text-xs text-red-600">{{ fields.location_text }}</p>
					</div>
				</div>
				<div>
					<FormsLabel text="About" />
					<textarea v-model="profile.description" rows="5" maxlength="2000"
						class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
				</div>
				<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
					<div>
						<FormsLabel text="Contact Email" required />
						<FormsTextField v-model="profile.contact_email" type="email" />
						<p v-if="fields.contact_email" class="mt-1 text-xs text-red-600">{{ fields.contact_email }}</p>
					</div>
					<div>
						<FormsLabel text="Contact Mobile" />
						<FormsTextField v-model="profile.contact_phone" type="tel" placeholder="0917 123 4567" />
						<p v-if="fields.contact_phone" class="mt-1 text-xs text-red-600">{{ fields.contact_phone }}</p>
					</div>
				</div>
				<div class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
					<div>
						<FormsLabel text="Latitude" />
						<FormsTextField v-model="coords.latitude" inputmode="decimal" placeholder="7.0731" />
					</div>
					<div>
						<FormsLabel text="Longitude" />
						<FormsTextField v-model="coords.longitude" inputmode="decimal" placeholder="125.6128" />
					</div>
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-semibold hover:bg-gray-50" @click="useMyLocation">
						Use my location
					</button>
				</div>
				<p class="text-xs text-gray-400">Coordinates let clients find you with "Near me". Leave empty to show your address only.</p>
				<p v-if="fields.latitude || fields.longitude" class="text-xs text-red-600">{{ fields.latitude || fields.longitude }}</p>
				<div>
					<FormsLabel text="Cover Image URL (https)" />
					<FormsTextField v-model="profile.cover_image_url" placeholder="https://…" />
					<p v-if="fields.cover_image_url" class="mt-1 text-xs text-red-600">{{ fields.cover_image_url }}</p>
				</div>
			</fieldset>
			<button v-if="isOwner" type="submit" :disabled="saving"
				class="mt-5 rounded-lg bg-primary-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-primary-900 disabled:opacity-60">
				{{ saving ? 'Saving…' : 'Save details' }}
			</button>
		</form>

		<section class="mb-6 rounded-2xl border border-gray-200 bg-white p-6">
			<h2 class="mb-1 text-base font-bold text-gray-900">Services</h2>
			<p class="mb-4 text-sm text-gray-500">Shown on your public page with an optional starting price.</p>
			<ul class="divide-y divide-gray-100">
				<li v-for="s in services" :key="s.id" class="flex items-center justify-between gap-3 py-2.5 text-sm">
					<span class="font-medium text-gray-900">{{ s.name }}<span v-if="s.base_price" class="font-normal text-gray-500"> · from {{ formatPeso(s.base_price) }}</span></span>
					<button v-if="isOwner" type="button" class="text-xs font-semibold text-rose-700" @click="removeServiceNow(s.id)">Remove</button>
				</li>
				<li v-if="!services.length" class="py-2 text-sm text-gray-400">No services yet.</li>
			</ul>
			<form v-if="isOwner" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_160px_auto]" @submit.prevent="addServiceNow">
				<FormsTextField v-model="newService.custom_name" placeholder="e.g. Wedding coordination" />
				<FormsTextField v-model="newService.base_price" inputmode="decimal" placeholder="Starting price" />
				<button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Add</button>
			</form>
		</section>

		<section class="mb-6 rounded-2xl border border-gray-200 bg-white p-6">
			<h2 class="mb-1 text-base font-bold text-gray-900">Quotation calculator</h2>
			<p class="mb-4 text-sm text-gray-500">
				Your price list. Clients pick options on your public page to get an estimate before sending an inquiry, and you can
				add these as lines when you write a quotation. Options under the same service are alternatives (the client picks one).
			</p>
			<ul class="divide-y divide-gray-100">
				<li v-for="o in priceOptions" :key="o.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm">
					<span :class="o.is_active ? 'text-gray-900' : 'text-gray-400 line-through'">
						<span v-if="serviceName(o.organization_service_id)" class="text-gray-500">{{ serviceName(o.organization_service_id) }} · </span>
						<span class="font-medium">{{ o.name }}</span>
						<span class="text-gray-500"> · {{ formatPeso(o.price) }}{{ o.pricing_unit === 'per_guest' ? ' per guest' : '' }}</span>
						<span v-if="o.min_guests" class="text-gray-400"> (min {{ o.min_guests }})</span>
					</span>
					<span v-if="isOwner" class="flex gap-3">
						<button type="button" class="text-xs font-semibold text-gray-600" @click="toggleOption(o)">{{ o.is_active ? 'Hide' : 'Show' }}</button>
						<button type="button" class="text-xs font-semibold text-rose-700" @click="removeOption(o.id)">Remove</button>
					</span>
				</li>
				<li v-if="!priceOptions.length" class="py-2 text-sm text-gray-400">No price options yet. Your public page won't show a calculator until you add some. <NuxtLink to="/organizer/price-list" class="font-semibold text-primary-700 hover:underline">Open the Price List</NuxtLink></li>
			</ul>
			<form v-if="isOwner" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2" @submit.prevent="addOption">
				<select v-model="newOption.organization_service_id" aria-label="Service"
					class="rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm">
					<option :value="null">No service (add-on)</option>
					<option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }}</option>
				</select>
				<FormsTextField v-model="newOption.name" placeholder="Option name, e.g. Partner A buffet" />
				<select v-model="newOption.pricing_unit" aria-label="Pricing unit"
					class="rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm">
					<option value="flat">Flat price</option>
					<option value="per_guest">Per guest</option>
				</select>
				<FormsTextField v-model="newOption.price" inputmode="decimal" placeholder="Price (₱)" />
				<FormsTextField v-model="newOption.min_guests" inputmode="numeric" placeholder="Minimum guests (optional)" />
				<button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Add option</button>
			</form>
		</section>

		<section class="rounded-2xl border border-gray-200 bg-white p-6">
			<h2 class="mb-1 text-base font-bold text-gray-900">Event types</h2>
			<p class="mb-4 text-sm text-gray-500">NaSeRy's standard types are always available. Add your own for anything else you organize.</p>
			<div class="flex flex-wrap gap-2">
				<span v-for="t in eventTypes" :key="t.id" class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs"
					:class="t.is_platform_default ? 'border-gray-200 text-gray-500' : 'border-primary-200 bg-primary-50 text-primary-800'">
					{{ t.name }}
					<button v-if="!t.is_platform_default && isOwner" type="button" :aria-label="`Remove ${t.name}`" class="font-bold" @click="removeEventType(t.id)">×</button>
				</span>
			</div>
			<form v-if="isOwner" class="mt-4 flex gap-2" @submit.prevent="addEventType">
				<FormsTextField v-model="newEventType" placeholder="e.g. Product launch" class="flex-1" />
				<button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white">Add</button>
			</form>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { OfferedService, OrganizationProfile } from '~/composables/useOrganization'
import type { PriceOption, PricingUnit } from '~/composables/useQuotations'

definePageMeta({ layout: 'dashboard' })

const api = useApi()
const { organization, fetchOrganization, updateProfile, services: fetchServices, addService, removeService } = useOrganization()

const profile = reactive<Partial<OrganizationProfile>>({})
const coords = reactive({ latitude: '', longitude: '' })
const services = ref<OfferedService[]>([])
const eventTypes = ref<{ id: string, name: string, is_platform_default: boolean }[]>([])
const newService = reactive({ custom_name: '', base_price: '' })
const newEventType = ref('')
const priceApi = usePriceOptions()
const priceOptions = ref<PriceOption[]>([])
const newOption = reactive({ organization_service_id: null as string | null, name: '', pricing_unit: 'flat' as PricingUnit, price: '', min_guests: '' })
const saving = ref(false)
const error = ref('')
const notice = ref('')
const fields = ref<Record<string, string>>({})

const isOwner = computed(() => Boolean(organization.value?.my_membership?.is_owner))
const orgPath = computed(() => `/organizations/${organization.value!.id}`)

async function run(action: () => Promise<unknown>, success?: string) {
	error.value = ''
	notice.value = ''
	fields.value = {}
	saving.value = true
	try {
		await action()
		if (success) notice.value = success
	} catch (e) {
		fields.value = fieldErrors(e)
		error.value = Object.keys(fields.value).length ? 'Please check the highlighted fields.' : authMessage(e)
	} finally {
		saving.value = false
	}
}

function numberOrNull(value: string) {
	return value.trim() === '' ? null : Number(value)
}

const saveProfile = () => run(async () => {
	const saved = await updateProfile({
		business_name: profile.business_name,
		location_text: profile.location_text,
		description: profile.description || null,
		contact_email: profile.contact_email,
		contact_phone: profile.contact_phone || null,
		cover_image_url: profile.cover_image_url || null,
		latitude: numberOrNull(coords.latitude),
		longitude: numberOrNull(coords.longitude),
	})
	Object.assign(profile, saved)
}, 'Profile saved.')

const togglePublished = () => run(async () => {
	Object.assign(profile, await updateProfile({ is_published: !profile.is_published }))
}, profile.is_published ? 'Hidden from the directory.' : 'Published.')

function useMyLocation() {
	navigator.geolocation?.getCurrentPosition((pos) => {
		coords.latitude = pos.coords.latitude.toFixed(6)
		coords.longitude = pos.coords.longitude.toFixed(6)
	}, () => { error.value = 'Location permission was denied.' })
}

const addServiceNow = () => run(async () => {
	services.value.push(await addService({ custom_name: newService.custom_name, base_price: newService.base_price || null } as never))
	newService.custom_name = ''
	newService.base_price = ''
})

const removeServiceNow = (id: string) => run(async () => {
	await removeService(id)
	services.value = services.value.filter(s => s.id !== id)
})

function serviceName(id: string | null) {
	return id ? services.value.find(s => s.id === id)?.name ?? null : null
}

const addOption = () => run(async () => {
	priceOptions.value.push(await priceApi.create(organization.value!.id, {
		organization_service_id: newOption.organization_service_id,
		name: newOption.name,
		pricing_unit: newOption.pricing_unit,
		price: newOption.price as never,
		min_guests: newOption.min_guests === '' ? null : Number(newOption.min_guests),
	}))
	Object.assign(newOption, { name: '', price: '', min_guests: '' })
}, 'Price option added.')

const toggleOption = (o: PriceOption) => run(async () => {
	Object.assign(o, await priceApi.update(organization.value!.id, o.id, { is_active: !o.is_active }))
})

const removeOption = (id: string) => run(async () => {
	await priceApi.remove(organization.value!.id, id)
	priceOptions.value = priceOptions.value.filter(o => o.id !== id)
})

const addEventType = () => run(async () => {
	const { data } = await api.post<{ id: string, name: string, is_platform_default: boolean }>(`${orgPath.value}/event-types`, { name: newEventType.value })
	eventTypes.value.push(data)
	newEventType.value = ''
})

const removeEventType = (id: string) => run(async () => {
	await api.delete(`${orgPath.value}/event-types/${id}`)
	eventTypes.value = eventTypes.value.filter(t => t.id !== id)
})

onMounted(() => run(async () => {
	await fetchOrganization()
	const [p, s, t, o] = await Promise.all([
		api.get<OrganizationProfile>(`${orgPath.value}/profile`),
		fetchServices(),
		api.get<{ id: string, name: string, is_platform_default: boolean }[]>(`${orgPath.value}/event-types`),
		priceApi.list(organization.value!.id),
	])
	priceOptions.value = o
	Object.assign(profile, p.data)
	coords.latitude = p.data.latitude?.toString() ?? ''
	coords.longitude = p.data.longitude?.toString() ?? ''
	services.value = s
	eventTypes.value = t.data
}))
</script>
