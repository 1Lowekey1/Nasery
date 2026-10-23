<template>
	<div class="space-y-6">
		<div class="at-rise">
			<h1 class="text-2xl font-extrabold tracking-tight text-gray-900">Activity Timeline</h1>
			<p class="mt-1 text-gray-500">Your event program and the milestones your organizers have reached.</p>
		</div>

		<div v-if="loading" class="rounded-3xl border border-gray-200 bg-white p-12 text-center text-sm text-gray-500">
			Loading your activity...
		</div>

		<div v-else-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">{{ error }}</div>

		<div v-else-if="!entries.length && !programs.length" class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
			<span class="at-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="activity" class="h-7 w-7" />
			</span>
			<p class="mt-4 text-sm font-bold text-gray-900">Nothing here yet</p>
			<p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-gray-500">
				Once an organizer sets up your event, its milestones show here as they happen. Offers and messages on your inquiries are in My Events.
			</p>
			<div class="mt-5 flex flex-wrap justify-center gap-2">
				<NuxtLink to="/client/my-events" class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-xs font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700">Go to My Events</NuxtLink>
				<NuxtLink to="/client/post-event" class="inline-flex items-center gap-2 rounded-xl bg-[#285F6b] px-5 py-2.5 text-xs font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#1f4a54]">
					Post an event inquiry
					<IconBase name="arrow-right" class="h-3.5 w-3.5" />
				</NuxtLink>
			</div>
		</div>

		<template v-else>
			<!-- 0049: program items the organizer shared with the client -->
			<section v-for="(p, pIndex) in programs" :key="p.eventId" class="at-rise rounded-3xl border border-gray-200 bg-white p-6" :style="{ '--d': 40 + pIndex * 60 + 'ms' }">
				<p class="text-xs font-bold uppercase tracking-[0.14em] text-primary-700">Event program</p>
				<h2 class="mt-1 text-lg font-extrabold tracking-tight text-gray-950">{{ p.event }}</h2>
				<p class="mt-0.5 text-xs text-gray-400">Shared by your organizer. Times can still change; they will update it here.</p>
				<ol class="mt-4 space-y-2">
					<li v-for="item in p.items" :key="item.id" class="flex gap-4 rounded-2xl px-3 py-3 transition hover:bg-gray-50">
						<div class="w-24 shrink-0 text-sm">
							<p class="font-bold text-gray-900">{{ item.activity_at ? formatTime(item.activity_at) : 'Time TBA' }}</p>
							<p v-if="item.activity_at" class="text-xs text-gray-400">{{ formatDay(item.activity_at) }}</p>
						</div>
						<div class="min-w-0 flex-1">
							<div class="flex flex-wrap items-center gap-2">
								<p class="font-semibold text-gray-900">{{ item.title }}</p>
								<span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusClass[item.status]">{{ statusLabel[item.status] }}</span>
							</div>
							<p v-if="item.category" class="text-xs text-gray-400">{{ item.category }}</p>
							<p v-if="item.description" class="mt-0.5 text-sm text-gray-500">{{ item.description }}</p>
						</div>
					</li>
				</ol>
			</section>

			<!-- Milestones, grouped by day -->
			<div v-if="days.length" class="space-y-8">
				<section v-for="(day, dIndex) in days" :key="day.key" class="at-rise" :style="{ '--d': 80 + dIndex * 60 + 'ms' }">
					<div class="mb-3 flex items-center gap-3">
						<h2 class="text-sm font-extrabold text-gray-950">{{ day.label }}</h2>
						<span class="h-px flex-1 bg-gray-200" />
						<span class="text-xs font-semibold text-gray-400">{{ day.entries.length }} milestone{{ day.entries.length === 1 ? '' : 's' }}</span>
					</div>

					<ol class="relative space-y-3 pl-12">
						<span class="absolute bottom-3 left-[19px] top-3 w-px bg-gray-200" />
						<li v-for="entry in day.entries" :key="entry.id" class="at-item relative">
							<span class="absolute -left-12 top-3 flex h-10 w-10 items-center justify-center rounded-full bg-white ring-4 ring-gray-50">
								<span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-50 text-primary-700">
									<IconBase name="check-circle" class="h-[18px] w-[18px]" />
								</span>
							</span>
							<div class="at-card flex w-full items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white px-4 py-3.5">
								<div class="min-w-0">
									<p class="text-sm font-bold text-gray-900">{{ entry.message }}</p>
									<p class="mt-0.5 truncate text-xs text-gray-500">{{ entry.event }}</p>
								</div>
								<span class="shrink-0 text-xs font-semibold text-gray-400">{{ formatTime(entry.at) }}</span>
							</div>
						</li>
					</ol>
				</section>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
// Every milestone across the client's events, newest first (client-safe entries only),
// plus the program items each organizer chose to share (0049).
import type { ClientProgramItem } from '~/composables/useEvents'

definePageMeta({ layout: 'client', middleware: ['client'] })

const entries = ref<{ id: string, at: string, message: string, event: string }[]>([])
const programs = ref<{ eventId: string, event: string, items: ClientProgramItem[] }[]>([])
const statusLabel: Record<ClientProgramItem['status'], string> = { pending: 'Upcoming', in_progress: 'Happening now', completed: 'Done' }
const statusClass: Record<ClientProgramItem['status'], string> = {
	pending: 'bg-gray-100 text-gray-600', in_progress: 'bg-amber-50 text-amber-700', completed: 'bg-emerald-50 text-emerald-700',
}
const loading = ref(true)
const error = ref('')

function formatTime(at: string) {
	return new Date(at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
function formatDay(at: string) {
	return new Date(at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
function formatDate(at: string) {
	return new Date(at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

onMounted(async () => {
	try {
		const events = await useClientEvents().list()
		const progress = await Promise.all(events.map(async e => ({ e, p: await useClientEvents().progress(e.id).catch(() => null) })))
		entries.value = progress.flatMap(({ e, p }) => (p?.milestones ?? []).map(m => ({ id: `${e.id}-${m.id}`, at: m.at, message: m.message, event: e.name })))
			.sort((a, b) => b.at.localeCompare(a.at))
		programs.value = progress.filter(({ p }) => p?.program?.length).map(({ e, p }) => ({ eventId: e.id, event: e.name, items: p!.program }))
	} catch (e: unknown) {
		error.value = authMessage(e) || 'Unable to load your timeline.'
	} finally {
		loading.value = false
	}
})

// Milestones grouped by day, newest first (the redesign's timeline).
const days = computed(() => {
	const groups = new Map<string, { key: string, label: string, entries: typeof entries.value }>()
	for (const entry of [...entries.value].sort((a, b) => b.at.localeCompare(a.at))) {
		const d = new Date(entry.at)
		const key = d.toDateString()
		if (!groups.has(key)) groups.set(key, { key, label: d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }), entries: [] })
		groups.get(key)!.entries.push(entry)
	}
	return [...groups.values()]
})
</script>

<style scoped>
.at-rise {
	animation: at-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.at-item {
	animation: at-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.at-card {
	transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
}

button.at-card:hover {
	transform: translateX(3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 12px 28px -22px rgba(17, 24, 39, 0.35);
}

.at-float {
	animation: at-float 3.2s ease-in-out infinite;
}

@keyframes at-rise {
	from { opacity: 0; transform: translateY(12px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes at-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
	.at-rise, .at-item, .at-float { animation: none; }
	.at-card { transition: none; }
}
</style>
