<template>
	<div class="space-y-6">
		<div class="pt-rise">
			<h1 class="text-2xl font-extrabold tracking-tight text-gray-900">Preparation Tracking</h1>
			<p class="mt-1 text-gray-500">Live readiness overview for your event, straight from your organizer.</p>
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
						<div v-if="daysAway !== null" class="text-center">
							<div class="text-3xl font-extrabold tabular-nums">{{ daysAway }}</div>
							<div class="text-xs text-white/70">{{ daysAway === 1 ? 'Day away' : 'Days away' }}</div>
						</div>
						<div class="border-white/20 text-left sm:border-l sm:pl-6 sm:text-right">
							<div class="text-xs text-white/70">Organizer</div>
							<div class="text-sm font-semibold">{{ event.organizer?.display_name ?? '—' }}</div>
						</div>
					</div>
				</div>
			</section>

			<div class="grid grid-cols-1 gap-5 lg:grid-cols-12">
				<!-- Readiness -->
				<section class="pt-rise rounded-3xl border border-gray-200 bg-white p-6 lg:col-span-5" style="--d: 100ms">
					<h3 class="text-lg font-extrabold tracking-tight text-gray-950">Overall Readiness</h3>
					<p class="mt-0.5 text-sm text-gray-500">
						{{ progress?.readiness_percent === null ? 'Your organizer hasn\'t started the checklist yet.' : 'Based on the preparation work your organizer has verified.' }}
					</p>
					<ClientOnly>
						<apexchart type="radialBar" height="240" :options="gaugeOptions" :series="[readiness]" />
					</ClientOnly>
				</section>

				<!-- Stage -->
				<section class="pt-rise rounded-3xl border border-gray-200 bg-white p-6 lg:col-span-7" style="--d: 140ms">
					<h3 class="text-lg font-extrabold tracking-tight text-gray-950">Planning Stage</h3>
					<p class="mt-0.5 text-sm text-gray-500">Where your organizer is in the plan.</p>
					<ol v-if="progress?.stages.length" class="mt-5 space-y-2">
						<li v-for="(stage, i) in progress.stages" :key="stage" class="flex items-center gap-3 rounded-2xl px-3 py-3"
							:class="i === stageIndex ? 'bg-primary-50 ring-1 ring-primary-100' : ''">
							<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold"
								:class="i < stageIndex ? 'bg-[#285F6b] text-white' : i === stageIndex ? 'bg-white text-primary-700 ring-2 ring-[#285F6b]' : 'bg-gray-100 text-gray-400'">
								<IconBase v-if="i < stageIndex" name="check" class="h-3.5 w-3.5" />
								<template v-else>{{ i + 1 }}</template>
							</span>
							<span class="text-sm" :class="i === stageIndex ? 'font-bold text-gray-950' : i < stageIndex ? 'font-medium text-gray-700' : 'font-medium text-gray-400'">{{ stage }}</span>
							<span v-if="i === stageIndex" class="ml-auto rounded-full bg-white px-2.5 py-1 text-[11px] font-bold text-primary-700">Now</span>
						</li>
					</ol>
					<p v-else class="mt-5 text-sm text-gray-500">The stage shows here once your organizer starts planning.</p>
				</section>
			</div>

			<!-- Milestones -->
			<section class="pt-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 180ms">
				<h3 class="text-lg font-extrabold tracking-tight text-gray-950">Milestones</h3>
				<p v-if="!progress?.milestones.length" class="mt-2 text-sm text-gray-500">Milestones appear here as your organizer reaches them.</p>
				<ol v-else class="mt-4 space-y-1">
					<li v-for="m in progress.milestones" :key="m.id" class="pt-row flex flex-wrap items-center justify-between gap-2 rounded-2xl px-3 py-3 transition hover:bg-gray-50">
						<div class="flex min-w-0 items-center gap-3">
							<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700"><IconBase name="check-circle" class="h-4 w-4" /></span>
							<span class="text-sm font-medium text-gray-900">{{ m.message }}</span>
						</div>
						<span class="shrink-0 text-xs font-semibold text-gray-500">{{ formatShort(m.at) }}</span>
					</li>
				</ol>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
// The client's view of their booked event: readiness, stage and milestones only
// (the organizer's task list stays private to their team).
import type { ApexOptions } from 'apexcharts'
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

// Readiness gauge (same look as the organizer analytics).
const gaugeOptions = computed<ApexOptions>(() => ({
	chart: { type: 'radialBar', fontFamily: 'inherit', animations: { speed: 900 } },
	colors: ['#285F6b'],
	plotOptions: {
		radialBar: {
			startAngle: -135,
			endAngle: 135,
			hollow: { size: '64%' },
			track: { background: '#eef2f3', strokeWidth: '100%' },
			dataLabels: {
				name: { show: true, offsetY: 24, color: '#6b7280', fontSize: '12px', fontWeight: 700 },
				value: { offsetY: -14, color: '#111827', fontSize: '38px', fontWeight: 800, formatter: (value: number) => `${value}%` },
			},
		},
	},
	stroke: { lineCap: 'round' },
	labels: ['ready'],
}))
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
