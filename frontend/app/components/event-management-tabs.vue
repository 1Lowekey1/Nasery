<template>
	<div>
		<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
			<div>
				<h1 class="text-2xl font-extrabold text-gray-900">Event Creation &amp; Management</h1>
				<p class="mt-1 text-gray-500">Configure events and manage ticket tiers</p>
			</div>
			<button v-if="canCreate" type="button"
				class="flex shrink-0 items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1f4a54]"
				@click="showCreateEvent = true">
				<IconBase name="plus" class="h-4 w-4" /> Create New Event
			</button>
		</div>

		<div class="mb-6 inline-flex max-w-full overflow-x-auto rounded-xl bg-gray-100 p-1">
			<NuxtLink v-for="tab in tabs" :key="tab.label" :to="{ path: tab.to, query: tab.keepEvent && route.query.event ? { event: route.query.event } : {} }"
				class="shrink-0 rounded-lg px-4 py-2 text-sm font-semibold"
				:class="route.path === tab.to ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
				{{ tab.label }}
			</NuxtLink>
		</div>

		<OrganizerEventForm v-if="showCreateEvent && organization" :organization-id="organization.id"
			@close="showCreateEvent = false" @saved="onCreated" />
	</div>
</template>

<script setup lang="ts">
import type { OrganizerEvent } from '~/composables/useEvents'

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()

const tabs = [
	{ label: 'All Events', to: '/organizer/eventmanagement/all-events', keepEvent: false },
	{ label: 'Event Details', to: '/organizer/eventmanagement/event-details', keepEvent: true },
	{ label: 'Ticket Tiers', to: '/organizer/eventmanagement/ticket-tiers', keepEvent: true },
	{ label: 'Preparation Tracking', to: '/organizer/eventmanagement/preparation-tracking', keepEvent: true },
]

const showCreateEvent = ref(false)
const canCreate = computed(() => {
	const m = organization.value?.my_membership
	return Boolean(m && (m.is_owner || m.permissions.includes('event.create')))
})

async function onCreated(event: OrganizerEvent) {
	showCreateEvent.value = false
	await navigateTo(`/organizer/eventmanagement/event-details?event=${event.id}`)
}

onMounted(() => {
	if (!organization.value) fetchOrganization()
})
</script>
