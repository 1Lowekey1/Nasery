<template>
	<!-- What has happened, newest first (like a parcel tracker's list). Same view for client and organizer. -->
	<section class="rounded-2xl border border-gray-200 bg-white px-4 py-4 sm:px-6 sm:py-5">
		<h3 class="text-base font-extrabold tracking-tight text-gray-950">{{ title }}</h3>
		<p v-if="!entries.length" class="mt-2 text-sm text-gray-500">{{ empty }}</p>
		<ol v-else class="mt-3">
			<li v-for="(entry, i) in shown" :key="entry.id" class="relative flex gap-3 pb-4 last:pb-0">
				<span v-if="i < shown.length - 1" class="absolute left-[5px] top-4 h-full w-px bg-gray-200" aria-hidden="true" />
				<span class="relative mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full" :class="i === 0 ? 'bg-[#285F6b] ring-4 ring-primary-50' : 'bg-gray-300'" />
				<div class="min-w-0 flex-1">
					<p class="text-sm leading-5" :class="i === 0 ? 'font-semibold text-gray-950' : 'text-gray-600'">{{ entry.message }}</p>
					<p class="mt-0.5 text-xs text-gray-400">{{ stamp(entry.at) }}<template v-if="entry.actor"> · {{ entry.actor }}</template></p>
				</div>
			</li>
		</ol>
		<button v-if="entries.length > shown.length" type="button" class="mt-2 inline-flex min-h-9 items-center text-sm font-semibold text-primary-700" @click="limit += 10">
			Show {{ Math.min(10, entries.length - shown.length) }} more
		</button>
	</section>
</template>

<script setup lang="ts">
const props = withDefaults(defineProps<{
	entries: { id: string, at: string, message: string, actor?: string | null }[]
	title?: string
	empty?: string
}>(), { title: 'What\'s happened', empty: 'Updates appear here as the event moves along.' })

const limit = ref(8)
const shown = computed(() => [...props.entries].sort((a, b) => b.at.localeCompare(a.at)).slice(0, limit.value))
const stamp = (at: string) => new Date(at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
</script>
