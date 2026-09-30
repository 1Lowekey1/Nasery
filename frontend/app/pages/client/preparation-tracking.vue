<template>
	<div>
		<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
			<div>
				<h1 class="text-2xl font-extrabold text-gray-900">Preparation Tracking</h1>
				<p class="mt-1 text-gray-500">Live readiness overview for your upcoming event.</p>
			</div>
			<select v-if="events.length > 1" v-model="selectedId" aria-label="Choose an event"
				class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700">
				<option v-for="e in events" :key="e.id" :value="e.id">{{ e.name }}</option>
			</select>
		</div>

		<div v-if="loading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
			Loading your event…
		</div>

		<div v-else-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">{{ error }}</div>

		<div v-else-if="!event"
			class="flex flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white px-6 py-20 text-center">
			<div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="clipboard-list" class="h-7 w-7" />
			</div>
			<h2 class="mt-4 text-lg font-bold text-gray-900">No event to track yet</h2>
			<p class="mt-1 max-w-sm text-sm text-gray-500">Once you accept an organizer's quotation and they set up your
				event, its readiness shows here.</p>
			<NuxtLink to="/client/my-events" class="mt-5 rounded-xl bg-[#285F6b] px-4 py-2 text-sm font-semibold text-white">Go to My Events</NuxtLink>
		</div>

		<template v-else>
			<div class="mb-6 rounded-2xl bg-[#285F6b] p-5 text-white sm:p-6">
				<div class="flex flex-wrap items-start justify-between gap-6">
					<div class="min-w-0">
						<h2 class="text-lg font-bold">{{ event.name }}</h2>
						<div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/80">
							<span class="flex items-center gap-1.5">
								<IconBase name="calendar" class="h-4 w-4 shrink-0" />{{ eventDate }}
							</span>
							<span v-if="venue" class="flex items-center gap-1.5">
								<IconBase name="map-pin" class="h-4 w-4 shrink-0" />{{ venue }}
							</span>
							<span v-if="event.overall_capacity" class="flex items-center gap-1.5">
								<IconBase name="users" class="h-4 w-4 shrink-0" />{{ event.overall_capacity }} guests
							</span>
						</div>
					</div>
					<div class="flex flex-wrap items-center gap-x-6 gap-y-3">
						<div v-if="daysAway !== null" class="text-center">
							<div class="text-3xl font-extrabold">{{ daysAway }}</div>
							<div class="text-xs text-white/70">{{ daysAway === 1 ? 'Day away' : 'Days away' }}</div>
						</div>
						<div class="border-white/20 pl-0 text-left sm:border-l sm:pl-6 sm:text-right">
							<div class="text-xs text-white/70">Organizer</div>
							<div class="text-sm font-semibold">{{ event.organizer?.display_name ?? '—' }}</div>
						</div>
					</div>
				</div>
			</div>

			<div class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
				<div class="flex flex-wrap items-end justify-between gap-3">
					<div>
						<h3 class="text-base font-bold text-gray-900">Overall Readiness</h3>
						<p class="mt-0.5 text-sm text-gray-500">
							{{ progress?.readiness_percent === null ? 'Your organizer hasn\'t started the checklist yet.' : 'Based on the preparation work your organizer has verified.' }}
						</p>
					</div>
					<div class="text-3xl font-extrabold text-[#285F6b]">{{ progress ? `${readiness}%` : '…' }}</div>
				</div>
				<div class="mt-4 h-2.5 w-full overflow-hidden rounded-full bg-gray-100">
					<div class="h-full rounded-full bg-[#285F6b] transition-all" :style="{ width: readiness + '%' }" />
				</div>
			</div>

			<div v-if="progress?.stages.length" class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
				<h3 class="mb-4 text-base font-bold text-gray-900">Planning Stage</h3>
				<ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
					<li v-for="(stage, i) in progress.stages" :key="stage" class="flex items-center gap-3 rounded-xl px-3 py-2.5"
						:class="i === stageIndex ? 'bg-primary-50' : ''">
						<IconBase :name="i < stageIndex ? 'check-circle' : i === stageIndex ? 'clock' : 'circle'" class="h-4 w-4 shrink-0"
							:class="i < stageIndex ? 'text-green-600' : i === stageIndex ? 'text-amber-600' : 'text-gray-400'" />
						<span class="text-sm" :class="i === stageIndex ? 'font-bold text-gray-900' : 'font-medium text-gray-600'">{{ stage }}</span>
					</li>
				</ol>
			</div>

			<div class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
				<h3 class="mb-4 text-base font-bold text-gray-900">Milestones</h3>
				<p v-if="!progress?.milestones.length" class="text-sm text-gray-500">Milestones appear here as your organizer reaches them.</p>
				<div v-else class="space-y-3">
					<div v-for="m in progress.milestones" :key="m.id" class="flex flex-wrap items-center justify-between gap-2">
						<div class="flex min-w-0 items-center gap-3">
							<span class="h-2 w-2 shrink-0 rounded-full bg-[#285F6b]" />
							<span class="text-sm font-medium text-gray-900">{{ m.message }}</span>
						</div>
						<span class="shrink-0 text-xs font-semibold text-gray-500">{{ formatShort(m.at) }}</span>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
// The client's view of their booked event: readiness, stage and milestones only
// (the organizer's task list stays private to their team).
import type { ClientEvent, ClientProgress } from '~/composables/useEvents'

definePageMeta({ layout: 'client', middleware: ['client'] })

const route = useRoute()
const events = ref<ClientEvent[]>([])
const selectedId = ref<string | null>(null)
const progress = ref<ClientProgress | null>(null)
const loading = ref(true)
const error = ref('')

const event = computed(() => events.value.find(e => e.id === selectedId.value) ?? null)
const venue = computed(() => [event.value?.venue_name, event.value?.venue_address].filter(Boolean).join(', '))
const eventDate = computed(() => event.value?.starts_at
	? new Date(event.value.starts_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', timeZone: event.value.timezone })
	: 'Date to be set')
const daysAway = computed(() => {
	if (!event.value?.starts_at) return null
	const days = Math.ceil((new Date(event.value.starts_at).getTime() - Date.now()) / 86400000)
	return days >= 0 ? days : null
})
const readiness = computed(() => Math.round(progress.value?.readiness_percent ?? 0))
const stageIndex = computed(() => (progress.value?.stage ? progress.value.stages.indexOf(progress.value.stage) : -1))

function formatShort(at: string) {
	return new Date(at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

async function loadProgress() {
	progress.value = null
	if (!selectedId.value) return
	try {
		progress.value = await useClientEvents().progress(selectedId.value)
	} catch (e: unknown) {
		error.value = authMessage(e) || 'Unable to load the readiness of this event.'
	}
}

watch(selectedId, loadProgress)

onMounted(async () => {
	try {
		const all = await useClientEvents().list()
		// Upcoming and live events first, soonest first; then finished ones.
		const active = (e: ClientEvent) => ['draft', 'published', 'ongoing'].includes(e.status)
		events.value = [...all].sort((a, b) => Number(active(b)) - Number(active(a))
			|| (a.starts_at ?? '').localeCompare(b.starts_at ?? ''))
		const wanted = typeof route.query.event === 'string' ? route.query.event : null
		selectedId.value = events.value.find(e => e.id === wanted)?.id ?? events.value[0]?.id ?? null
	} catch (e: unknown) {
		error.value = authMessage(e) || 'Unable to load your events.'
	} finally {
		loading.value = false
	}
})
</script>
