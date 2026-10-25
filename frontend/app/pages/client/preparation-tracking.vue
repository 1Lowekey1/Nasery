<template>
	<div class="space-y-6">
		<div class="pt-rise">
			<h1 class="text-2xl font-extrabold tracking-tight text-gray-900">Event journey</h1>
			<p class="mt-1 text-gray-500">Where your event is, what's coming up, and what has happened, straight from your organizer.</p>
		</div>

		<!-- Event rail -->
		<div v-if="events.length > 1" class="pt-rail pt-rise -mx-1 flex gap-3 overflow-x-auto px-1 pb-2" style="--d: 40ms">
			<button v-for="e in events" :key="e.id" type="button"
				class="group w-56 shrink-0 rounded-2xl border p-3.5 text-left transition duration-200"
				:class="selectedId === e.id ? 'border-[#285F6b] bg-[#285F6b] text-white shadow-md' : 'border-gray-200 bg-white text-gray-900 hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-sm'"
				@click="selectedId = e.id">
				<div class="truncate text-sm font-bold">{{ e.name }}</div>
				<div class="mt-0.5 text-xs" :class="selectedId === e.id ? 'text-white/70' : 'text-gray-400'">
					{{ e.starts_at ? formatShort(e.starts_at) : 'Date to be set' }} · {{ e.organizer?.display_name ?? 'Organizer' }}
				</div>
			</button>
		</div>

		<div v-if="loading" class="rounded-3xl border border-gray-200 bg-white p-12 text-center text-sm text-gray-500">
			Loading your event…
		</div>

		<div v-else-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">{{ error }}</div>

		<div v-else-if="!event" class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
			<span class="pt-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="clipboard-list" class="h-7 w-7" />
			</span>
			<p class="mt-4 text-sm font-bold text-gray-900">No event to track yet</p>
			<p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-gray-500">
				Once you accept an organizer's quotation and they set up your event, its readiness shows here.
			</p>
			<NuxtLink to="/client/my-events" class="mt-5 inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-xs font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700">
				Go to My Events
				<IconBase name="arrow-right" class="h-3.5 w-3.5" />
			</NuxtLink>
		</div>

		<template v-else>
			<!-- Hero -->
			<section class="pt-hero pt-rise relative overflow-hidden rounded-[2rem] px-6 py-7 text-white sm:px-9 sm:py-8" style="--d: 60ms">
				<span class="pt-orb" />
				<span class="pt-dots" />
				<div class="relative flex flex-wrap items-start justify-between gap-6">
					<div class="min-w-0">
						<p class="text-xs font-bold uppercase tracking-[0.16em] text-white/60">Your event</p>
						<h2 class="mt-2 line-clamp-2 break-words text-2xl font-extrabold tracking-tight sm:text-3xl">{{ event.name }}</h2>
						<div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm text-white/75">
							<span class="flex items-center gap-1.5"><IconBase name="calendar" class="h-4 w-4 shrink-0" />{{ eventDate }}</span>
							<span v-if="venue" class="flex min-w-0 items-center gap-1.5"><IconBase name="map-pin" class="h-4 w-4 shrink-0" /><span class="truncate">{{ venue }}</span></span>
							<span v-if="event.overall_capacity" class="flex items-center gap-1.5"><IconBase name="users" class="h-4 w-4 shrink-0" />{{ event.overall_capacity }} guests</span>
						</div>
					</div>
					<div class="flex flex-wrap items-center gap-x-6 gap-y-3">
						<div v-if="countdown" class="text-center">
							<div class="text-3xl font-extrabold tabular-nums">{{ countdown.value }}</div>
							<div class="text-xs text-white/70">{{ countdown.label }}</div>
						</div>
						<div class="border-white/20 text-left sm:border-l sm:pl-6 sm:text-right">
							<div class="text-xs text-white/70">Organizer</div>
							<div class="text-sm font-semibold">{{ event.organizer?.display_name ?? '—' }}</div>
						</div>
					</div>
				</div>
			</section>

			<!-- Where it's at -->
			<EventStageBar :stages="progress?.stages ?? []" :stage="progress?.stage ?? null" :event-status="event.status"
				:readiness="progress?.readiness_percent ?? null" />

			<!-- Coming up: the program the organizer shared -->
			<section v-if="progress?.program?.length" class="rounded-2xl border border-gray-200 bg-white px-4 py-4 sm:px-6 sm:py-5">
				<h3 class="text-base font-extrabold tracking-tight text-gray-950">{{ event.status === 'completed' ? 'Program' : 'Coming up' }}</h3>
				<p class="mt-0.5 text-xs text-gray-400">The event program from your organizer. Times can still change; they update it here.</p>
				<ol class="mt-3 divide-y divide-gray-100">
					<li v-for="item in progress.program" :key="item.id" class="flex gap-3 py-2.5">
						<div class="w-20 shrink-0 text-sm">
							<p class="font-bold text-gray-900">{{ item.activity_at ? formatTime(item.activity_at) : 'Time TBA' }}</p>
							<p v-if="item.activity_at" class="text-xs text-gray-400">{{ formatShort(item.activity_at) }}</p>
						</div>
						<div class="min-w-0 flex-1">
							<div class="flex flex-wrap items-center gap-2">
								<p class="text-sm font-semibold text-gray-900">{{ item.title }}</p>
								<span v-if="!(event.status === 'completed' && item.status === 'pending')" class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="programStatus[item.status].cls">{{ programStatus[item.status].label }}</span>
							</div>
							<p v-if="item.description" class="mt-0.5 text-sm text-gray-500">{{ item.description }}</p>
						</div>
					</li>
				</ol>
			</section>

			<!-- What's happened -->
			<EventJourneyLog :entries="progress?.milestones ?? []" empty="Milestones appear here as your organizer reaches them." />
		</template>
	</div>
</template>

<script setup lang="ts">
// The client's "Event journey" (review #14): where the event is (stage bar), what's
// coming up (the program the organizer shared) and what has happened (milestones).
// The organizer's task list stays private to their team.
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
// Calendar days, not 24-hour blocks: an event later today is "Today", not "1 day away".
const countdown = computed(() => {
	const e = event.value
	if (!e?.starts_at || ['completed', 'cancelled'].includes(e.status)) return null
	if (e.status === 'ongoing') return { value: 'Live', label: 'Happening now' }
	const start = new Date(e.starts_at)
	const midnight = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
	const days = Math.round((midnight(start) - midnight(new Date())) / 86400000)
	if (days < 0) return null
	if (days === 0) return { value: 'Today', label: `at ${start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}` }
	return { value: String(days), label: days === 1 ? 'Day away' : 'Days away' }
})

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

const programStatus = {
	pending: { label: 'Upcoming', cls: 'bg-gray-100 text-gray-600' },
	in_progress: { label: 'Happening now', cls: 'bg-amber-50 text-amber-700' },
	completed: { label: 'Done', cls: 'bg-green-50 text-green-700' },
} as const
function formatTime(at: string) {
	return new Date(at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
</script>

<style scoped>
.pt-hero {
	background: #285f6b;
}

.pt-rail {
	scrollbar-width: none;
}

.pt-rail::-webkit-scrollbar {
	display: none;
}

.pt-orb {
	position: absolute;
	top: -120px;
	right: 6%;
	height: 300px;
	width: 300px;
	border-radius: 9999px;
	background: rgba(255, 255, 255, 0.09);
	filter: blur(60px);
	pointer-events: none;
	animation: pt-drift 16s ease-in-out infinite;
}

.pt-dots {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
	-webkit-mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
}

.pt-rise {
	animation: pt-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.pt-row {
	animation: pt-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.pt-card {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.pt-card:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.pt-line {
	transform: scaleX(0);
	transform-origin: left;
	transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.pt-card:hover .pt-line {
	transform: scaleX(1);
}

.pt-bar {
	animation: pt-grow 1s cubic-bezier(0.22, 1, 0.36, 1) both;
	transform-origin: left;
}

.pt-float {
	animation: pt-float 3.2s ease-in-out infinite;
}

@keyframes pt-rise {
	from { opacity: 0; transform: translateY(14px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes pt-drift {
	0%, 100% { transform: translate(0, 0) scale(1); }
	50% { transform: translate(-22px, 16px) scale(1.08); }
}

@keyframes pt-grow {
	from { transform: scaleX(0); }
	to { transform: scaleX(1); }
}

@keyframes pt-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
	.pt-orb, .pt-rise, .pt-row, .pt-bar, .pt-float { animation: none; }
	.pt-card, .pt-line { transition: none; }
}
</style>
