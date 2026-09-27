<template>
	<div class="mx-auto max-w-7xl px-5 pt-8 pb-16 sm:px-8">
		<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Events</p>
		<h1 class="mt-2 text-3xl font-bold tracking-tight">Public events on NaSeRy</h1>
		<p class="mt-2 max-w-2xl text-gray-500">Concerts, conferences and gatherings by organizers reviewed by NaSeRy. Register to get your QR ticket.</p>

		<form class="mt-6 flex max-w-xl gap-2" @submit.prevent="load(1)">
			<FormsTextField v-model="search" placeholder="Search events, organizers or places" class="flex-1" aria-label="Search events" />
			<button type="submit" class="rounded-lg bg-primary-700 px-5 py-2.5 text-sm font-bold text-white">Search</button>
		</form>

		<p v-if="error" class="mt-6 rounded-xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-800">{{ error }}</p>
		<div v-if="loading" class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3" aria-label="Loading events">
			<div v-for="n in 3" :key="n" class="h-48 animate-pulse rounded-2xl bg-gray-100" />
		</div>

		<div v-else-if="events.length" class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
			<NuxtLink v-for="e in events" :key="e.id" :to="`/events/${e.id}`" class="rounded-2xl border border-gray-200 bg-white p-6 hover:border-primary-300 hover:shadow-sm">
				<p class="text-xs font-semibold uppercase tracking-wide text-primary-700">{{ e.event_type ?? 'Event' }}<span v-if="e.status === 'ongoing'" class="text-green-700"> · Happening now</span></p>
				<h2 class="mt-2 text-lg font-bold text-gray-900">{{ e.name }}</h2>
				<p class="mt-1 text-sm text-gray-500">by {{ e.organizer.name }}</p>
				<p class="mt-3 text-sm text-gray-700">{{ e.starts_at ? dateLabel(e.starts_at, e.timezone) : 'Date to be announced' }}</p>
				<p class="text-sm text-gray-500">{{ e.venue_name || e.venue_address || 'Venue to be announced' }}</p>
				<p class="mt-4 text-sm font-bold text-gray-900">{{ e.tiers ? (Number(e.from_price) === 0 ? 'Free entry available' : `From ${formatPeso(e.from_price)}`) : 'Registration opening soon' }}</p>
			</NuxtLink>
		</div>
		<div v-else class="mt-10 rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center text-gray-500">No public events right now. Check back soon.</div>

		<div v-if="pagination && pagination.last_page > 1" class="mt-8 flex justify-center gap-2">
			<button type="button" :disabled="page <= 1" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page - 1)">Previous</button>
			<span class="px-2 py-1.5 text-sm text-gray-500">Page {{ page }} of {{ pagination.last_page }}</span>
			<button type="button" :disabled="page >= pagination.last_page" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page + 1)">Next</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { PublicEventCard } from '~/composables/useTicketing'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Events | NaSeRy', description: 'Public events by NaSeRy organizers. Register and get your QR ticket.' })

const api = usePublicEvents()
const events = ref<PublicEventCard[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const page = ref(1)
const search = ref('')
const loading = ref(true)
const error = ref('')

function dateLabel(at: string, timeZone: string) {
	return new Date(at).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone })
}

async function load(p = 1) {
	loading.value = true
	error.value = ''
	try {
		const r = await api.list({ search: search.value.trim(), page: p })
		events.value = r.items
		pagination.value = r.pagination
		page.value = p
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

onMounted(() => load())
</script>
