<template>
	<label class="mb-5 flex max-w-md flex-col gap-1 text-sm">
		<span class="font-semibold text-gray-700">Event</span>
		<select :value="modelValue ?? ''" class="rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm"
			@change="choose(($event.target as HTMLSelectElement).value)">
			<option value="" disabled>{{ loading ? 'Loading events…' : events.length ? 'Choose an event' : 'No events yet' }}</option>
			<option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }} · {{ eventStatusLabels[e.status] }}</option>
		</select>
	</label>
</template>

<script setup lang="ts">
import type { OrganizerEvent } from '~/composables/useEvents'

/** Chooses the event for per-event organizer pages; keeps it in ?event=. */
defineProps<{ modelValue: string | null }>()

const route = useRoute()
const router = useRouter()
const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerEvents()

const events = ref<OrganizerEvent[]>([])
const loading = ref(true)

function choose(id: string) {
	router.replace({ query: { ...route.query, event: id } })
}

onMounted(async () => {
	try {
		const org = organization.value ?? await fetchOrganization()
		if (!org) return
		events.value = (await api.list(org.id, ['draft', 'published', 'ongoing', 'completed'])).items
		// Default to the first event so the page isn't empty.
		if (!route.query.event && events.value.length) choose(events.value[0].id)
	} finally {
		loading.value = false
	}
})
</script>
