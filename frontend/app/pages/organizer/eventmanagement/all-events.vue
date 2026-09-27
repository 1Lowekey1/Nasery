<template>
	<div>
		<div class="mb-4 flex flex-wrap gap-2">
			<button v-for="f in filters" :key="f.label" type="button"
				class="rounded-full border px-3 py-1.5 text-xs font-semibold"
				:class="activeFilter === f.label ? 'border-primary-600 bg-primary-50 text-primary-800' : 'border-gray-200 text-gray-600'"
				@click="activeFilter = f.label; load(1)">{{ f.label }}</button>
		</div>

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading events…</div>

		<div v-else-if="events.length" class="space-y-5">
			<div v-for="event in events" :key="event.id" class="rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div class="min-w-0">
						<h3 class="text-lg font-bold text-gray-900">{{ event.name }}</h3>
						<p class="mt-1 text-sm text-gray-500">
							{{ event.sessions.length ? sessionLabel(firstSession(event)!, event.timezone) : 'No date yet' }}
							&bull; {{ event.venue_name || event.venue_address || 'Venue not set' }}
						</p>
						<p v-if="event.client" class="mt-1 text-xs text-gray-400">For {{ event.client.name }} (from an accepted quotation)</p>
					</div>
					<span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold" :class="eventStatusClasses[event.status]">
						{{ eventStatusLabels[event.status] }}
					</span>
				</div>

				<div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
					<div>
						<div class="text-sm text-gray-500">Capacity</div>
						<div class="mt-1 text-2xl font-extrabold text-gray-900">{{ event.overall_capacity ?? '—' }}</div>
					</div>
					<div>
						<div class="text-sm text-gray-500">Sessions</div>
						<div class="mt-1 text-2xl font-extrabold text-gray-900">{{ event.sessions.filter(s => s.status !== 'cancelled').length }}</div>
					</div>
					<div>
						<div class="text-sm text-gray-500">Type</div>
						<div class="mt-1 truncate text-base font-bold text-gray-900">{{ event.event_type ?? '—' }}</div>
					</div>
					<div>
						<div class="text-sm text-gray-500">Attendance</div>
						<div class="mt-1 text-base font-bold text-gray-900">{{ event.visibility === 'public' ? 'Public' : 'Private' }}</div>
					</div>
				</div>

				<div class="mt-5 mb-1 flex items-center justify-between text-sm">
					<span class="font-semibold text-gray-900">Event Readiness<span v-if="event.stage" class="font-normal text-gray-500"> · {{ event.stage }}</span></span>
					<span class="text-gray-500">{{ event.readiness_percent === null ? 'No tasks yet' : `${Math.round(event.readiness_percent)}%` }}</span>
				</div>
				<div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100">
					<div class="h-full rounded-full bg-[#285F6b]" :style="{ width: `${event.readiness_percent ?? 0}%` }" />
				</div>

				<div class="mt-5 flex flex-wrap gap-3">
					<NuxtLink :to="`/organizer/eventmanagement/preparation-tracking?event=${event.id}`"
						class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
						Preparation
					</NuxtLink>
					<NuxtLink :to="`/organizer/eventmanagement/event-details?event=${event.id}`"
						class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
						View details
					</NuxtLink>
				</div>
			</div>

			<div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2 pt-2">
				<button type="button" :disabled="page <= 1" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page - 1)">Previous</button>
				<span class="px-2 py-1.5 text-sm text-gray-500">Page {{ page }} of {{ pagination.last_page }}</span>
				<button type="button" :disabled="page >= pagination.last_page" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page + 1)">Next</button>
			</div>
		</div>

		<div v-else class="rounded-3xl border-2 border-dashed border-gray-200 bg-gray-50/60 px-6 py-16 text-center">
			<h2 class="text-lg font-bold text-gray-900">No events here yet</h2>
			<p v-if="organization?.my_membership?.sees_all_events" class="mt-1 text-sm text-gray-500">Create one with “Create New Event”, or from a quotation a client accepted in My Offers.</p>
			<p v-else class="mt-1 text-sm text-gray-500">Events appear here once your team adds you to them.</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { EventStatus, OrganizerEvent } from '~/composables/useEvents'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerEvents()

const filters: { label: string, status: EventStatus[] }[] = [
	{ label: 'Upcoming', status: ['draft', 'published', 'ongoing'] },
	{ label: 'Completed', status: ['completed'] },
	{ label: 'Cancelled', status: ['cancelled'] },
	{ label: 'All', status: [] },
]
const activeFilter = ref('Upcoming')
const events = ref<OrganizerEvent[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const page = ref(1)
const loading = ref(true)
const error = ref('')

function firstSession(event: OrganizerEvent) {
	return event.sessions.find(s => s.status !== 'cancelled') ?? event.sessions[0]
}

async function load(p = 1) {
	loading.value = true
	error.value = ''
	try {
		const org = organization.value ?? await fetchOrganization()
		if (!org) return
		const r = await api.list(org.id, filters.find(f => f.label === activeFilter.value)!.status, p)
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
