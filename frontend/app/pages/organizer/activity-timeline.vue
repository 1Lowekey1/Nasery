<template>
	<div class="mx-auto max-w-3xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Activity Timeline</h1>
		<p class="mt-1 mb-6 text-gray-500">Everything that happened on an event, from the client's inquiry to completion, and who did it.</p>

		<OrganizerEventPicker :model-value="eventId" />

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="eventId && loading" class="py-16 text-center text-sm text-gray-500">Loading timeline…</div>

		<section v-else-if="eventId" class="rounded-2xl border border-gray-200 bg-white p-6">
			<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
				<h2 class="text-base font-bold text-gray-900">Activity</h2>
				<label class="flex items-center gap-2 text-sm text-gray-600">
					<input v-model="milestonesOnly" type="checkbox"> Show only what the client sees
				</label>
			</div>
			<EventTimeline :entries="shown" newest-first />
		</section>
	</div>
</template>

<script setup lang="ts">
import type { TimelineEntry } from '~/composables/useEvents'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerEvents()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const entries = ref<TimelineEntry[]>([])
const loading = ref(false)
const error = ref('')
const milestonesOnly = ref(false)
const shown = computed(() => milestonesOnly.value ? entries.value.filter(e => e.milestone) : entries.value)

async function load() {
	if (!eventId.value) return
	loading.value = true
	error.value = ''
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) entries.value = await api.timeline(org.id, eventId.value)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

watch(eventId, load)
onMounted(load)
</script>
