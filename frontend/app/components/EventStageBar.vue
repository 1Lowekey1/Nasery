<template>
	<!-- Where the event is, at a glance (like a parcel tracker's top bar). Same view for client and organizer. -->
	<section class="rounded-2xl border border-gray-200 bg-white px-4 py-4 sm:px-6 sm:py-5" aria-label="Event stage">
		<div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
			<h3 class="text-base font-extrabold tracking-tight text-gray-950">{{ headline }}</h3>
			<p v-if="readiness !== null && readiness !== undefined" class="text-xs font-semibold text-gray-500">
				Preparation <span class="tabular-nums text-gray-900">{{ Math.round(readiness) }}%</span> ready
			</p>
		</div>

		<ol class="mt-4 grid gap-1" :style="{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }">
			<li v-for="(step, i) in steps" :key="step" class="relative flex flex-col items-center text-center">
				<span v-if="i > 0" class="absolute right-1/2 top-3 h-0.5 w-full -translate-y-1/2" :class="i <= current ? 'bg-[#285F6b]' : 'bg-gray-200'" aria-hidden="true" />
				<span class="relative z-10 flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold ring-4 ring-white"
					:class="i < current || (i === current && finished) ? 'bg-[#285F6b] text-white' : i === current ? 'bg-white text-[#285F6b] ring-[#285F6b]/20 outline outline-2 outline-[#285F6b]' : 'bg-gray-100 text-gray-400'">
					<IconBase v-if="i < current || (i === current && finished)" name="check" class="h-3.5 w-3.5" />
					<template v-else>{{ i + 1 }}</template>
				</span>
				<span class="mt-1.5 text-[11px] font-semibold leading-tight sm:text-xs" :class="i === current ? 'text-gray-950' : i < current ? 'text-gray-600' : 'text-gray-400'">{{ step }}</span>
			</li>
		</ol>
	</section>
</template>

<script setup lang="ts">
const props = defineProps<{ stages: string[], stage: string | null, eventStatus?: string | null, readiness?: number | null }>()

const steps = computed(() => (props.stages.length ? props.stages : ['Planning', 'Confirmed', 'Ready', 'Event day', 'Completed']))
const finished = computed(() => props.eventStatus === 'completed')
const current = computed(() => {
	if (finished.value) return steps.value.length - 1
	const i = props.stage ? steps.value.indexOf(props.stage) : -1
	return i < 0 ? 0 : i
})
const headline = computed(() => ({
	cancelled: 'Event cancelled',
	completed: 'Event completed',
	ongoing: 'Happening now',
} as Record<string, string>)[String(props.eventStatus ?? '')] ?? `Now: ${steps.value[current.value]}`)
</script>
