<template>
	<div class="sa-card group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5">
		<span class="sa-line absolute inset-x-0 top-0 h-[2px] bg-primary-700" />

		<div class="relative flex items-start justify-between">
			<div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 transition duration-200 group-hover:scale-105">
				<IconBase :name="icon" class="h-5 w-5" />
			</div>

			<span v-if="trend" class="flex items-center gap-1 rounded-full bg-primary-50 px-2 py-1 text-[11px] font-bold text-primary-700">
				<IconBase name="trending-up" class="h-3 w-3" />
				{{ trend }}
			</span>
		</div>

		<div class="relative mt-4 text-xs font-bold uppercase tracking-[0.1em] text-gray-400">{{ label }}</div>
		<div class="relative mt-1.5 text-3xl font-extrabold tracking-tight text-gray-950">
			{{ displayValue }}
		</div>

		<div v-if="hint" class="relative mt-1.5 text-xs text-gray-400">{{ hint }}</div>
	</div>
</template>

<script setup lang="ts">
const props = defineProps<{
	label: string
	value: string | number
	icon: string
	hint?: string
	trend?: string
}>()

// Whole numbers count up to their value in the browser; formatted values (currency, percentages) show as they are.
// The server render shows the final value, so hydration always matches.
const shown = ref(typeof props.value === 'number' ? props.value : 0)
let frame = 0

function animate(target: number) {
	// no browser (server render) or a hidden tab (animation frames are paused): show the real value straight away
	if (!import.meta.client || document.visibilityState === 'hidden') {
		shown.value = target
		return
	}

	cancelAnimationFrame(frame)

	const from = shown.value
	const start = performance.now()

	const step = (now: number) => {
		const t = Math.min((now - start) / 700, 1)
		shown.value = Math.round(from + (target - from) * (1 - Math.pow(1 - t, 3)))
		if (t < 1) frame = requestAnimationFrame(step)
	}

	frame = requestAnimationFrame(step)
}

const displayValue = computed(() => (typeof props.value === 'number' ? shown.value.toLocaleString() : props.value))

watch(() => props.value, (value) => {
	if (typeof value === 'number') animate(value)
})

onMounted(() => {
	if (typeof props.value === 'number' && props.value > 0) {
		shown.value = 0
		animate(props.value)
	}
})

onBeforeUnmount(() => {
	if (import.meta.client) cancelAnimationFrame(frame)
})
</script>

<style scoped>
.sa-card {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.sa-card:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.sa-line {
	transform: scaleX(0);
	transform-origin: left;
	transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.sa-card:hover .sa-line {
	transform: scaleX(1);
}

@media (prefers-reduced-motion: reduce) {
	.sa-card, .sa-line { transition: none; }
}
</style>
