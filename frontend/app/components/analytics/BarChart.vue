<template>
	<figure class="m-0">
		<div class="mb-3 flex flex-wrap items-center justify-between gap-2">
			<ul v-if="series.length > 1" class="flex flex-wrap gap-4 text-sm text-gray-700" aria-label="Legend">
				<li v-for="s in series" :key="s.name" class="flex items-center gap-1.5">
					<span class="h-2.5 w-2.5 rounded-sm" :style="{ background: s.color }" aria-hidden="true" />{{ s.name }}
				</li>
			</ul>
			<button type="button" class="ml-auto text-xs font-semibold text-primary-700" @click="asTable = !asTable">{{ asTable ? 'Show chart' : 'Show as table' }}</button>
		</div>

		<table v-if="asTable" class="w-full text-left text-sm">
			<thead class="text-xs uppercase text-gray-400"><tr><th class="py-1.5">Period</th><th v-for="s in series" :key="s.name" class="text-right">{{ s.name }}</th></tr></thead>
			<tbody class="divide-y divide-gray-100">
				<tr v-for="(label, i) in labels" :key="label"><td class="py-1.5">{{ label }}</td><td v-for="s in series" :key="s.name" class="text-right">{{ s.values[i] }}</td></tr>
			</tbody>
		</table>

		<div v-else class="flex" @mouseleave="hover = null">
			<div class="mr-2 flex flex-col-reverse justify-between text-right text-xs text-gray-400" :style="{ height: `${height}px` }" aria-hidden="true">
				<span v-for="t in ticks" :key="t" class="-mb-1.5 leading-3">{{ t }}</span>
			</div>
			<div class="relative min-w-0 flex-1">
				<div class="absolute inset-0 flex flex-col-reverse justify-between" aria-hidden="true">
					<div v-for="t in ticks" :key="t" class="border-t" :class="t === 0 ? 'border-gray-300' : 'border-dashed border-gray-200'" />
				</div>
				<div class="relative flex items-end" :style="{ height: `${height}px` }" role="img" :aria-label="ariaLabel">
					<div v-for="(label, i) in labels" :key="label" class="relative flex h-full flex-1 cursor-default items-end justify-center" style="gap: 2px"
						@mouseenter="hover = i" @focus="hover = i">
						<div v-for="s in series" :key="s.name" class="w-2.5 rounded-t sm:w-3.5" :style="{ height: `${barHeight(s.values[i] ?? 0)}px`, background: s.color, minHeight: (s.values[i] ?? 0) > 0 ? '2px' : '0' }" />
						<div v-if="hover === i" class="pointer-events-none absolute bottom-full z-10 mb-1 whitespace-nowrap rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs shadow-lg"
							:class="i > labels.length / 2 ? 'right-0' : 'left-0'">
							<p class="font-semibold text-gray-900">{{ label }}</p>
							<p v-for="s in series" :key="s.name" class="mt-0.5 flex items-center gap-1.5 text-gray-600">
								<span class="h-2 w-2 rounded-sm" :style="{ background: s.color }" />{{ s.name }}: <span class="font-semibold text-gray-900">{{ s.values[i] }}</span>
							</p>
						</div>
					</div>
				</div>
				<div class="mt-2 flex" aria-hidden="true">
					<div v-for="(label, i) in labels" :key="label" class="flex-1 truncate text-center text-xs text-gray-500">{{ showLabel(i) ? label : '' }}</div>
				</div>
			</div>
		</div>
		<figcaption v-if="caption" class="mt-2 text-xs text-gray-500">{{ caption }}</figcaption>
	</figure>
</template>

<script setup lang="ts">
// Grouped bars for counts over time (one axis, counts only). Hover a period
// for its values; "Show as table" gives the same data without the chart.
const props = withDefaults(defineProps<{
	labels: string[]
	series: { name: string, color: string, values: number[] }[]
	height?: number
	caption?: string
}>(), { height: 220, caption: '' })

const hover = ref<number | null>(null)
const asTable = ref(false)

const max = computed(() => Math.max(1, ...props.series.flatMap(s => s.values)))
// 4 nice steps: 1, 2 or 5 × 10^n.
const step = computed(() => {
	const raw = max.value / 4
	const mag = 10 ** Math.floor(Math.log10(raw))
	// Counts: never a fractional step.
	return Math.max(1, [1, 2, 5, 10].map(m => m * mag).find(s => s >= raw) ?? raw)
})
const ticks = computed(() => Array.from({ length: Math.ceil(max.value / step.value) + 1 }, (_, i) => Math.round(i * step.value)))
const top = computed(() => ticks.value[ticks.value.length - 1] || 1)
const barHeight = (v: number) => (v / top.value) * props.height
// Thin out x labels when there are many periods.
const showLabel = (i: number) => props.labels.length <= 12 || i % Math.ceil(props.labels.length / 12) === 0
const ariaLabel = computed(() => props.series.map(s => `${s.name}: ${s.values.reduce((a, b) => a + b, 0)} in total`).join('; '))
</script>
