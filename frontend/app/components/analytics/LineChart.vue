<template>
	<figure class="m-0">
		<div class="mb-3 flex flex-wrap items-center justify-between gap-2">
			<ul v-if="series.length > 1" class="flex flex-wrap gap-4 text-sm text-gray-700" aria-label="Legend">
				<li v-for="s in series" :key="s.name" class="flex items-center gap-1.5">
					<span class="h-2.5 w-2.5 rounded-full" :style="{ background: s.color }" aria-hidden="true" />{{ s.name }}
				</li>
			</ul>
			<button type="button" class="ml-auto text-xs font-semibold text-primary-700" @click="asTable = !asTable">{{ asTable ? 'Show chart' : 'Show as table' }}</button>
		</div>

		<table v-if="asTable" class="w-full text-left text-sm">
			<thead class="text-xs uppercase text-gray-400"><tr><th class="py-1.5">Period</th><th v-for="s in series" :key="s.name" class="text-right">{{ s.name }}</th></tr></thead>
			<tbody class="divide-y divide-gray-100">
				<tr v-for="(label, i) in labels" :key="label"><td class="py-1.5">{{ label }}</td><td v-for="s in series" :key="s.name" class="text-right">{{ fmt(s.values[i]) }}</td></tr>
			</tbody>
		</table>

		<div v-else ref="box" class="relative" @mouseleave="hover = null">
			<svg :width="width" :height="height + 24" class="block overflow-visible" role="img" :aria-label="ariaLabel">
				<!-- recessive grid and y labels -->
				<g v-for="t in ticks" :key="t">
					<line :x1="padL" :x2="width" :y1="y(t)" :y2="y(t)" :stroke="t === 0 ? '#d1d5db' : '#e5e7eb'" :stroke-dasharray="t === 0 ? '' : '4 4'" />
					<text :x="padL - 6" :y="y(t) + 4" text-anchor="end" class="fill-gray-400 text-[11px]">{{ t }}{{ suffix }}</text>
				</g>
				<!-- crosshair -->
				<line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="0" :y2="height" stroke="#9ca3af" stroke-width="1" />
				<!-- series: 2px lines broken at gaps, 8px markers with a 2px surface ring -->
				<g v-for="s in series" :key="s.name">
					<path v-for="(seg, k) in segments(s.values)" :key="k" :d="seg" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
					<circle v-for="(v, i) in s.values" v-show="v !== null && (hover === i || labels.length <= 12)" :key="i" :cx="x(i)" :cy="y(v ?? 0)" r="4" :fill="s.color" stroke="#ffffff" stroke-width="2" />
				</g>
				<!-- x labels -->
				<text v-for="(label, i) in labels" v-show="showLabel(i)" :key="label" :x="x(i)" :y="height + 18" text-anchor="middle" class="fill-gray-500 text-[11px]">{{ label }}</text>
				<!-- hit targets wider than the marks -->
				<rect v-for="(label, i) in labels" :key="`hit-${label}`" :x="x(i) - slot / 2" y="0" :width="slot" :height="height" fill="transparent" @mouseenter="hover = i" />
			</svg>
			<div v-if="hover !== null" class="pointer-events-none absolute top-0 z-10 whitespace-nowrap rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs shadow-lg"
				:style="{ left: `${Math.min(x(hover) + 10, width - 170)}px` }">
				<p class="font-semibold text-gray-900">{{ labels[hover] }}</p>
				<p v-for="s in series" :key="s.name" class="mt-0.5 flex items-center gap-1.5 text-gray-600">
					<span class="h-2 w-2 rounded-full" :style="{ background: s.color }" />{{ s.name }}: <span class="font-semibold text-gray-900">{{ fmt(s.values[hover]) }}</span>
				</p>
			</div>
		</div>
		<figcaption v-if="caption" class="mt-2 text-xs text-gray-500">{{ caption }}</figcaption>
	</figure>
</template>

<script setup lang="ts">
// Rates over time on one 0–100% axis. Gaps (no data that period) break the
// line instead of pretending to be zero. Crosshair + tooltip on hover.
const props = withDefaults(defineProps<{
	labels: string[]
	series: { name: string, color: string, values: (number | null)[] }[]
	height?: number
	suffix?: string
	caption?: string
}>(), { height: 220, suffix: '%', caption: '' })

const box = ref<HTMLElement | null>(null)
const width = ref(600)
const hover = ref<number | null>(null)
const asTable = ref(false)
const padL = 40
const ticks = [0, 25, 50, 75, 100]

const slot = computed(() => (width.value - padL) / Math.max(1, props.labels.length))
const x = (i: number) => padL + slot.value * (i + 0.5)
const y = (v: number) => props.height - (Math.max(0, Math.min(100, v)) / 100) * props.height
const fmt = (v: number | null | undefined) => (v === null || v === undefined ? 'no events' : `${Math.round(v * 10) / 10}${props.suffix}`)
const showLabel = (i: number) => props.labels.length <= 12 || i % Math.ceil(props.labels.length / 12) === 0
const ariaLabel = computed(() => props.series.map(s => `${s.name}: ${s.values.map((v, i) => `${props.labels[i]} ${fmt(v)}`).join(', ')}`).join('. '))

function segments(values: (number | null)[]) {
	const out: string[] = []
	let d = ''
	values.forEach((v, i) => {
		if (v === null) {
			if (d) out.push(d)
			d = ''
			return
		}
		d += `${d ? 'L' : 'M'}${x(i)},${y(v)}`
	})
	if (d) out.push(d)
	return out
}

let observer: ResizeObserver | undefined
onMounted(() => {
	observer = new ResizeObserver(([entry]) => { width.value = Math.max(280, entry!.contentRect.width) })
	if (box.value) observer.observe(box.value)
})
watch(asTable, async (t) => {
	if (!t) {
		await nextTick()
		if (box.value) observer?.observe(box.value)
	}
})
onBeforeUnmount(() => observer?.disconnect())
</script>
