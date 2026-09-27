<template>
	<ol class="relative border-l-2 border-gray-100 pl-6">
		<li v-for="entry in ordered" :key="entry.id" class="relative pb-6 last:pb-0">
			<span class="absolute -left-[33px] top-1 flex h-4 w-4 items-center justify-center rounded-full border-2 border-white"
				:class="entry.milestone ? 'bg-[#285F6b]' : 'bg-gray-300'" aria-hidden="true" />
			<p class="text-sm font-semibold text-gray-900">{{ entry.message }}</p>
			<p v-if="entry.reason" class="mt-0.5 text-sm text-gray-600">“{{ entry.reason }}”</p>
			<p class="mt-0.5 text-xs text-gray-400">
				<time :datetime="entry.at">{{ when(entry.at) }}</time><template v-if="entry.actor"> · {{ entry.actor }}</template>
			</p>
		</li>
		<li v-if="!entries.length" class="text-sm text-gray-400">Nothing yet.</li>
	</ol>
</template>

<script setup lang="ts">
import type { TimelineEntry } from '~/composables/useEvents'

const props = defineProps<{ entries: TimelineEntry[], newestFirst?: boolean }>()

const ordered = computed(() => props.newestFirst ? [...props.entries].reverse() : props.entries)

function when(at: string) {
	return new Date(at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' })
}
</script>
