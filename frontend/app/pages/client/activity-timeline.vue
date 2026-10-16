<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold text-gray-900">Activity Timeline</h1>
			<p class="mt-1 text-gray-500">Your event program and the milestones your organizers have reached.</p>
		</div>

		<div v-if="loading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">Loading…</div>
		<div v-else-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">{{ error }}</div>

		<div v-else-if="!entries.length && !programs.length"
			class="flex flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white px-6 py-24 text-center">
			<div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="activity" class="h-7 w-7" />
			</div>
			<h2 class="mt-4 text-lg font-bold text-gray-900">Nothing here yet</h2>
			<p class="mt-1 max-w-sm text-sm text-gray-500">Once an organizer sets up your event, its milestones show here as
				they happen. Offers and messages on your inquiries are in My Events.</p>
		</div>

		<template v-else>
		<!-- 0049: program items the organizer shared with the client. -->
		<section v-for="p in programs" :key="p.eventId" class="mb-8 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6">
			<p class="text-xs font-bold uppercase tracking-wide text-primary-700">Event program</p>
			<h2 class="mt-0.5 text-lg font-bold text-gray-900">{{ p.event }}</h2>
			<p class="mt-0.5 text-xs text-gray-400">Shared by your organizer. Times can still change; they will update it here.</p>
			<ul class="mt-4 divide-y divide-gray-100">
				<li v-for="item in p.items" :key="item.id" class="flex gap-4 py-3">
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
			</ul>
		</section>

		<h2 v-if="programs.length && entries.length" class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-500">Milestones</h2>
		<ol v-if="entries.length" class="relative space-y-4 border-l-2 border-primary-100 pl-6">
			<li v-for="entry in entries" :key="entry.id" class="relative rounded-2xl border border-gray-200 bg-white p-4">
				<span class="absolute -left-[33px] top-5 h-3 w-3 rounded-full border-2 border-white bg-[#285F6b]" />
				<div class="flex flex-wrap items-center justify-between gap-2">
					<span class="text-xs font-bold uppercase tracking-wide text-primary-700">{{ entry.event }}</span>
					<span class="text-xs text-gray-400">{{ formatDate(entry.at) }}</span>
				</div>
				<p class="mt-1 text-sm font-medium text-gray-900">{{ entry.message }}</p>
			</li>
		</ol>
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
</script>
