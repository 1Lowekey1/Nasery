<template>
	<div class="mx-auto max-w-7xl px-5 pt-10 sm:px-8">
		<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Organizer directory</p>
		<h1 class="mt-2 text-2xl font-extrabold tracking-tight sm:text-4xl">Find the right team for your event</h1>
		<p class="mt-3 max-w-2xl text-gray-600">
			Every organizer here is reviewed by NaSeRy. Compare services and reviews, then post your event to receive their quotations.
		</p>

		<form class="mt-8 grid gap-3 rounded-2xl border border-gray-200 bg-white p-4 sm:grid-cols-[1fr_auto_auto]" role="search" @submit.prevent="apply">
			<label class="sr-only" for="directory-search">Search organizers</label>
			<input id="directory-search" v-model="filters.search" type="search" placeholder="Search by name, service or city…"
				class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm focus:border-primary-500 focus:outline-none">
			<button type="button" class="rounded-xl border border-gray-200 px-4 py-3 text-sm font-semibold hover:bg-gray-50"
				:class="near ? 'border-primary-500 bg-primary-50 text-primary-800' : ''" :disabled="locating" @click="toggleNear">
				{{ locating ? 'Locating…' : near ? `Within ${filters.radius_km} km ✓` : 'Near me' }}
			</button>
			<button type="submit" class="rounded-xl bg-primary-700 px-6 py-3 text-sm font-bold text-white hover:bg-primary-900">Search</button>
		</form>

		<p v-if="geoError" class="mt-2 text-sm text-rose-700">{{ geoError }}</p>

		<div v-if="services.length" class="mt-4 flex flex-wrap gap-2" aria-label="Filter by service">
			<button v-for="s in services" :key="s.name" type="button"
				class="rounded-full border px-3 py-1.5 text-xs font-semibold"
				:class="filters.service === s.name ? 'border-primary-600 bg-primary-600 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'"
				@click="toggleService(s.name)">
				{{ s.name }} <span class="opacity-70">· {{ s.organizers }}</span>
			</button>
		</div>

		<div class="mt-6 flex items-center justify-between text-sm text-gray-500">
			<span aria-live="polite">{{ loading ? 'Loading…' : `${pagination?.total ?? 0} organizer${pagination?.total === 1 ? '' : 's'}` }}</span>
			<label class="flex items-center gap-2">
				Sort
				<select v-model="filters.sort" class="rounded-lg border border-gray-200 bg-white px-2 py-1.5" @change="apply">
					<option value="rating">Top rated</option>
					<option value="name">Name</option>
					<option v-if="near" value="distance">Nearest</option>
				</select>
			</label>
		</div>

		<p v-if="error" class="mt-6 rounded-xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-800">{{ error }}</p>

		<div v-if="!loading && !items.length && !error" class="mt-10 rounded-2xl border border-dashed border-gray-300 p-10 text-center text-gray-500">
			No organizers match yet. Try another search{{ near ? ' or a wider area' : '' }}.
		</div>

		<div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
			<NuxtLink v-for="org in items" :key="org.id" :to="`/organizers/${org.slug}`"
				class="group overflow-hidden rounded-2xl border border-gray-200 bg-white transition hover:-translate-y-0.5 hover:shadow-md">
				<div class="h-28 bg-gradient-to-br from-primary-600 to-primary-900"
					:style="(org.cover_url || org.cover_image_url) ? { backgroundImage: `url(${JSON.stringify(org.cover_url || org.cover_image_url)})`, backgroundSize: 'cover', backgroundPosition: 'center' } : {}" />
				<div class="p-5">
					<div class="-mt-12 mb-3 flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl border-4 border-white text-lg font-bold text-white"
						:class="org.logo_url ? 'bg-white' : 'bg-gray-900'">
						<img v-if="org.logo_url" :src="org.logo_url" :alt="`${org.display_name} logo`" class="h-full w-full object-contain">
						<template v-else>{{ initialsOf(org.display_name) }}</template>
					</div>
					<h2 class="text-lg font-bold group-hover:text-primary-800">{{ org.display_name }}</h2>
					<p class="mt-1 text-sm text-gray-500">
						{{ org.location_text || 'Location not listed' }}<span v-if="org.distance_km !== null"> · {{ org.distance_km }} km away</span>
					</p>
					<p class="mt-2 text-sm font-semibold" :class="org.rating_avg ? 'text-amber-700' : 'text-gray-400'">
						{{ org.rating_avg ? `★ ${org.rating_avg.toFixed(1)} · ${org.reviews_count} review${org.reviews_count === 1 ? '' : 's'}` : 'No reviews yet' }}
					</p>
					<p v-if="org.excerpt" class="mt-3 line-clamp-3 text-sm text-gray-600">{{ org.excerpt }}</p>
					<div v-if="org.services.length" class="mt-4 flex flex-wrap gap-1.5">
						<span v-for="s in org.services" :key="s" class="rounded-md bg-primary-50 px-2 py-1 text-xs font-semibold text-primary-700">{{ s }}</span>
					</div>
				</div>
			</NuxtLink>
		</div>

		<div v-if="pagination && pagination.last_page > 1" class="mt-8 flex items-center justify-center gap-3 text-sm">
			<button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-2 disabled:opacity-40"
				:disabled="pagination.current_page <= 1" @click="go(pagination.current_page - 1)">Previous</button>
			<span class="text-gray-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
			<button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-2 disabled:opacity-40"
				:disabled="pagination.current_page >= pagination.last_page" @click="go(pagination.current_page + 1)">Next</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { DirectoryFilters, OrganizerCard } from '~/composables/useMarketplace'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Browse event organizers | NaSeRy', description: 'Find reviewed event organizers near you on NaSeRy.' })

const { organizers, serviceNames } = useMarketplace()

const filters = reactive<DirectoryFilters>({ search: '', service: '', sort: 'rating', radius_km: 25 })
const near = ref(false)
const locating = ref(false)
const geoError = ref('')
const items = ref<OrganizerCard[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const services = ref<{ name: string, organizers: number }[]>([])
const loading = ref(false)
const error = ref('')

async function load(page = 1) {
	loading.value = true
	error.value = ''
	try {
		const result = await organizers({ ...filters, search: filters.search?.trim(), page, ...(near.value ? {} : { lat: undefined, lng: undefined, radius_km: undefined }) })
		items.value = result.items
		pagination.value = result.pagination
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

const apply = () => load(1)
const go = (page: number) => {
	load(page)
	window.scrollTo({ top: 0, behavior: 'smooth' })
}

function toggleService(name: string) {
	filters.service = filters.service === name ? '' : name
	apply()
}

function toggleNear() {
	geoError.value = ''
	if (near.value) {
		near.value = false
		if (filters.sort === 'distance') filters.sort = 'rating'
		apply()
		return
	}
	if (!navigator.geolocation) {
		geoError.value = 'Your browser cannot share your location.'
		return
	}
	locating.value = true
	navigator.geolocation.getCurrentPosition((pos) => {
		filters.lat = Number(pos.coords.latitude.toFixed(5))
		filters.lng = Number(pos.coords.longitude.toFixed(5))
		near.value = true
		filters.sort = 'distance'
		locating.value = false
		apply()
	}, () => {
		locating.value = false
		geoError.value = 'Location permission was denied. You can still search by city.'
	}, { timeout: 10000, maximumAge: 300000 })
}

onMounted(async () => {
	load()
	try {
		services.value = await serviceNames()
	} catch {
		services.value = []
	}
})
</script>
