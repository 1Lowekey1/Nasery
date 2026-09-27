<template>
	<div class="mx-auto max-w-3xl">
		<h1 class="text-2xl font-extrabold text-gray-900">{{ title }}</h1>
		<p class="mt-1 mb-6 text-gray-500">{{ intro }}</p>

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading…</div>

		<div v-else-if="!events.length" class="rounded-2xl border border-gray-200 bg-white px-6 py-16 text-center">
			<h2 class="text-lg font-bold text-gray-900">No events yet</h2>
			<p class="mt-1 text-sm text-gray-500">Once you accept a quotation and your organizer sets up the event, you can follow it here.</p>
		</div>

		<template v-else>
			<label v-if="events.length > 1" class="mb-5 flex max-w-md flex-col gap-1 text-sm">
				<span class="font-semibold text-gray-700">Event</span>
				<select v-model="selected" class="rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm">
					<option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option>
				</select>
			</label>

			<section v-if="progress" class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-4 text-base font-bold text-gray-900">{{ current?.name }}</h2>
				<template v-if="mode === 'progress'">
					<EventStageTrack :readiness="progress.readiness_percent" :stage="progress.stage" :stages="progress.stages"
						:cancelled="progress.event_status === 'cancelled'" />
					<p class="mt-4 text-sm text-gray-500">
						{{ stageHint }} Your organizer, {{ current?.organizer?.display_name ?? 'the organizer' }}, updates this as preparation moves along.
					</p>
				</template>
				<EventTimeline v-else :entries="progress.milestones" newest-first />
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ClientEvent, ClientProgress } from '~/composables/useEvents'

/** Client's simplified view of an event: stage + readiness, or milestone timeline. */
const props = defineProps<{ mode: 'progress' | 'timeline' }>()

const { list, progress: fetchProgress } = useClientEvents()

const events = ref<ClientEvent[]>([])
const selected = ref<string | null>(null)
const progress = ref<ClientProgress | null>(null)
const loading = ref(true)
const error = ref('')

const current = computed(() => events.value.find(e => e.id === selected.value) ?? null)
const title = computed(() => props.mode === 'progress' ? 'Preparation Tracking' : 'Activity Timeline')
const intro = computed(() => props.mode === 'progress'
	? 'See how ready your event is and which stage it has reached.'
	: 'Key moments of your event, from your inquiry to the big day.')
const stageHint = computed(() => ({
	Planning: 'Your event is being planned.',
	Confirmed: 'Your event is confirmed and preparations are under way.',
	Ready: 'Everything on the preparation list is done.',
	'Event day': 'Your event is happening now.',
	Completed: 'Your event is complete.',
} as Record<string, string>)[progress.value?.stage ?? ''] ?? '')

watch(selected, async (id) => {
	if (!id) return
	progress.value = null
	try {
		progress.value = await fetchProgress(id)
	} catch (e) {
		error.value = authMessage(e)
	}
})

onMounted(async () => {
	try {
		events.value = await list()
		selected.value = events.value.find(e => e.status !== 'cancelled')?.id ?? events.value[0]?.id ?? null
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
})
</script>
