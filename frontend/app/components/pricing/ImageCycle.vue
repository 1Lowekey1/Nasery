<template>
	<!-- Pictures stacked and cross-faded: autoplay steps through them; v-model:index lets arrows/thumbnails drive it too. -->
	<div class="relative h-full w-full overflow-hidden">
		<img v-for="(img, i) in shownImages" :key="img.id" :src="img.url ?? ''" :alt="i === current ? alt : ''" :aria-hidden="i !== current"
			class="absolute inset-0 h-full w-full transition-opacity duration-700 ease-in-out motion-reduce:transition-none"
			:class="[fit === 'contain' ? 'object-contain' : 'object-cover', i === current ? 'opacity-100' : 'opacity-0']"
			:loading="i === 0 ? 'eager' : 'lazy'" draggable="false">
	</div>
</template>

<script setup lang="ts">
type Picture = { id: string, url: string | null }

const props = withDefaults(defineProps<{
	images: Picture[]
	/** Step through the pictures on its own (e.g. while hovered). */
	autoplay?: boolean
	interval?: number
	fit?: 'cover' | 'contain'
	alt?: string
	/** Controlled position (v-model:index); left out, it keeps its own and goes back to the first when autoplay stops. */
	index?: number
}>(), { autoplay: false, interval: 1400, fit: 'cover', alt: '', index: undefined })
const emit = defineEmits<{ 'update:index': [value: number] }>()

const shownImages = computed(() => props.images.filter(i => i.url))
const own = ref(0)
const current = computed(() => Math.min(props.index ?? own.value, Math.max(0, shownImages.value.length - 1)))

function go(next: number) {
	if (props.index === undefined) own.value = next
	emit('update:index', next)
}

let timer: ReturnType<typeof setInterval> | null = null
const reducedMotion = () => import.meta.client && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
function stop(reset: boolean) {
	if (timer) clearInterval(timer)
	timer = null
	if (reset && props.index === undefined) own.value = 0
}
watch(() => [props.autoplay, shownImages.value.length] as const, ([on, n]) => {
	stop(!on)
	if (on && n > 1 && !reducedMotion()) timer = setInterval(() => go((current.value + 1) % shownImages.value.length), props.interval)
}, { immediate: true })
onBeforeUnmount(() => stop(false))
</script>
