<template>
	<div>
		<div class="flex items-baseline justify-between gap-3">
			<span class="text-sm font-semibold text-gray-900">Event readiness</span>
			<span class="text-sm text-gray-500">
				{{ readiness === null ? 'No tasks yet' : `${formatPercent(readiness)}%` }}<template v-if="manual"> (set manually)</template>
			</span>
		</div>
		<div class="mt-1.5 h-2.5 w-full overflow-hidden rounded-full bg-gray-100" role="progressbar"
			:aria-valuenow="readiness ?? 0" aria-valuemin="0" aria-valuemax="100" aria-label="Event readiness">
			<div class="h-full rounded-full bg-[#285F6b] transition-all" :style="{ width: `${readiness ?? 0}%` }" />
		</div>

		<ol class="mt-5 grid gap-2" :style="{ gridTemplateColumns: `repeat(${stages.length}, minmax(0, 1fr))` }" aria-label="Event stages">
			<li v-for="(s, i) in stages" :key="s" class="text-center">
				<div class="mx-auto h-2 rounded-full" :class="i <= currentIndex ? (cancelled ? 'bg-gray-300' : 'bg-[#285F6b]') : 'bg-gray-100'" />
				<span class="mt-1.5 block text-xs" :class="i === currentIndex ? 'font-bold text-gray-900' : 'text-gray-400'"
					:aria-current="i === currentIndex ? 'step' : undefined">{{ s }}</span>
			</li>
		</ol>
		<p v-if="cancelled" class="mt-2 text-xs text-rose-700">This event was cancelled.</p>
	</div>
</template>

<script setup lang="ts">
const props = defineProps<{
	readiness: number | null
	stage: string | null
	stages: string[]
	manual?: boolean
	cancelled?: boolean
}>()

const currentIndex = computed(() => props.stage ? props.stages.indexOf(props.stage) : -1)

function formatPercent(value: number) {
	return Number.isInteger(value) ? String(value) : value.toFixed(1)
}
</script>
