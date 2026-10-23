<template>
	<div ref="host" :style="{ minHeight: typeof height === 'number' ? `${height}px` : undefined }">
		<apexchart v-if="ready" :type="type" :height="height" :options="options" :series="series" />
	</div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'

defineProps<{
	type: 'bar' | 'donut' | 'radialBar' | 'line' | 'area'
	height: number | string
	options: ApexOptions
	series: unknown[]
}>()

// Mounts the chart only once its host element is attached and has been laid out, so ApexCharts
// never tries to render into a node that is still being swapped in during client-side navigation.
const host = ref<HTMLElement | null>(null)
const ready = ref(false)

let frame = 0
let attempts = 0

function check() {
	if (host.value?.isConnected && host.value.getBoundingClientRect().width > 0) {
		ready.value = true
		return
	}

	if (attempts++ < 60) frame = window.setTimeout(check, 50)
}

onMounted(() => {
	frame = window.setTimeout(check, 30)
})

onBeforeUnmount(() => window.clearTimeout(frame))
</script>
