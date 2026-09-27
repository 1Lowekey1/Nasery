<template>
	<div class="mx-auto max-w-3xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Your events</h1>
		<p class="mt-1 mb-6 text-gray-500">Events your organizers created from quotations you accepted.</p>

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading your events…</div>

		<div v-else-if="!events.length" class="flex flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white px-6 py-20 text-center">
			<div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="calendar" class="h-7 w-7" />
			</div>
			<h2 class="mt-4 text-lg font-bold text-gray-900">No events yet</h2>
			<p class="mt-1 max-w-sm text-sm text-gray-500">When you accept a quotation, your organizer sets up the event here and you can follow it.</p>
		</div>

		<div v-else class="space-y-4">
			<article v-for="e in events" :key="e.id" class="rounded-2xl border bg-white p-6"
				:class="selected === e.id ? 'border-primary-300' : 'border-gray-200'">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div>
						<h2 class="text-lg font-bold text-gray-900">{{ e.name }}</h2>
						<p class="text-sm text-gray-500">{{ e.event_type ?? 'Event' }}<template v-if="e.organizer"> · by
							<NuxtLink v-if="e.organizer.slug" :to="`/organizers/${e.organizer.slug}`" class="font-semibold text-primary-700">{{ e.organizer.display_name }}</NuxtLink>
							<template v-else>{{ e.organizer.display_name }}</template></template>
						</p>
					</div>
					<span class="rounded-full px-3 py-1 text-xs font-semibold" :class="eventStatusClasses[e.status]">{{ clientStatus[e.status] }}</span>
				</div>

				<p v-if="e.status === 'cancelled'" class="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">Cancelled by the organizer: {{ e.cancelled_reason }}</p>

				<div class="mt-4 grid gap-4 sm:grid-cols-2">
					<div>
						<div class="text-xs text-gray-500">When</div>
						<ul class="mt-1 space-y-1 text-sm font-semibold text-gray-900">
							<li v-for="s in e.sessions" :key="s.id" :class="s.status === 'cancelled' ? 'text-gray-400 line-through' : ''">
								<span v-if="s.name">{{ s.name }}: </span>{{ sessionLabel(s, e.timezone) }}
							</li>
							<li v-if="!e.sessions.length" class="font-normal text-gray-500">Date to be confirmed</li>
						</ul>
					</div>
					<div>
						<div class="text-xs text-gray-500">Where</div>
						<div class="mt-1 text-sm font-semibold text-gray-900">{{ e.venue_name || 'Venue to be confirmed' }}</div>
						<div v-if="e.venue_address" class="text-sm text-gray-500">{{ e.venue_address }}</div>
						<a v-if="e.latitude !== null" :href="`https://www.openstreetmap.org/?mlat=${e.latitude}&mlon=${e.longitude}#map=16/${e.latitude}/${e.longitude}`"
							target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-primary-700 underline">View on map ↗</a>
					</div>
					<div>
						<div class="text-xs text-gray-500">Guests</div>
						<div class="mt-1 text-sm font-semibold text-gray-900">{{ e.overall_capacity ?? '—' }}</div>
					</div>
					<div>
						<div class="text-xs text-gray-500">Organizer</div>
						<div class="mt-1 text-sm text-gray-900">
							{{ e.organizer?.display_name ?? '—' }}
							<span class="block text-gray-500">{{ [e.organizer?.contact_email, e.organizer?.contact_phone].filter(Boolean).join(' · ') }}</span>
						</div>
					</div>
					<div v-if="e.contact_name || e.contact_email || e.contact_phone">
						<div class="text-xs text-gray-500">Event point person</div>
						<div class="mt-1 text-sm text-gray-900">{{ [e.contact_name, e.contact_email, e.contact_phone].filter(Boolean).join(' · ') }}</div>
					</div>
				</div>
				<ClientLiveAttendance v-if="['published', 'ongoing', 'completed'].includes(e.status)" :event-id="e.id" />
				<NuxtLink v-if="e.status === 'completed'" :to="`/client/event-report?event=${e.id}`" class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-primary-700">
					<IconBase name="file-text" class="h-4 w-4" /> Post-event report
				</NuxtLink>
				<ClientReviewPanel v-if="e.status === 'completed' && e.organizer" :event-id="e.id" :organizer="e.organizer.display_name" />
				<p v-if="e.description" class="mt-4 whitespace-pre-line text-sm text-gray-700">{{ e.description }}</p>
			</article>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { ClientEvent, EventStatus } from '~/composables/useEvents'

definePageMeta({ layout: 'client' })

const route = useRoute()
const { list } = useClientEvents()

const events = ref<ClientEvent[]>([])
const loading = ref(true)
const error = ref('')
const selected = computed(() => typeof route.query.event === 'string' ? route.query.event : null)

const clientStatus: Record<EventStatus, string> = {
	draft: 'Being planned', published: 'Confirmed', ongoing: 'Happening now', completed: 'Completed', cancelled: 'Cancelled',
}

onMounted(async () => {
	try {
		events.value = await list()
		// Show the requested event first.
		if (selected.value) events.value.sort((a, b) => Number(b.id === selected.value) - Number(a.id === selected.value))
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
})
</script>
