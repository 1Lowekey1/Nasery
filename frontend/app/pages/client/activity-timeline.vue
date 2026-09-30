<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold text-gray-900">Activity Timeline</h1>
			<p class="mt-1 text-gray-500">Milestones your organizers have reached on your events.</p>
		</div>

		<div v-if="loading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">Loading…</div>
		<div v-else-if="error" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">{{ error }}</div>

		<div v-else-if="!entries.length"
			class="flex flex-col items-center justify-center rounded-2xl border border-gray-200 bg-white px-6 py-24 text-center">
			<div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="activity" class="h-7 w-7" />
			</div>
			<h2 class="mt-4 text-lg font-bold text-gray-900">Nothing here yet</h2>
			<p class="mt-1 max-w-sm text-sm text-gray-500">Once an organizer sets up your event, its milestones show here as
				they happen. Offers and messages on your inquiries are in My Events.</p>
		</div>

		<ol v-else class="relative space-y-4 border-l-2 border-primary-100 pl-6">
			<li v-for="entry in entries" :key="entry.id" class="relative rounded-2xl border border-gray-200 bg-white p-4">
				<span class="absolute -left-[33px] top-5 h-3 w-3 rounded-full border-2 border-white bg-[#285F6b]" />
				<div class="flex flex-wrap items-center justify-between gap-2">
					<span class="text-xs font-bold uppercase tracking-wide text-primary-700">{{ entry.event }}</span>
					<span class="text-xs text-gray-400">{{ formatDate(entry.at) }}</span>
				</div>
				<p class="mt-1 text-sm font-medium text-gray-900">{{ entry.message }}</p>
			</li>
		</ol>
	</div>
</template>

<script setup lang="ts">
// Every milestone across the client's events, newest first (client-safe entries only).
definePageMeta({ layout: 'client', middleware: ['client'] })

const entries = ref<{ id: string, at: string, message: string, event: string }[]>([])
const loading = ref(true)
const error = ref('')

function formatDate(at: string) {
	return new Date(at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

onMounted(async () => {
	try {
		const events = await useClientEvents().list()
		const all = await Promise.all(events.map(async e => (await useClientEvents().progress(e.id).catch(() => null))?.milestones
			.map(m => ({ id: `${e.id}-${m.id}`, at: m.at, message: m.message, event: e.name })) ?? []))
		entries.value = all.flat().sort((a, b) => b.at.localeCompare(a.at))
	} catch (e: unknown) {
		error.value = authMessage(e) || 'Unable to load your timeline.'
	} finally {
		loading.value = false
	}
})
</script>
