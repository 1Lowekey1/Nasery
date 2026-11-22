<template>
	<!-- Up to 4 photos side by side. With more, the row slides along like a carousel (one photo at a time,
	     looping, paused while hovered). Clicking a photo opens it. -->
	<div class="relative overflow-hidden" :class="compact ? 'max-w-md' : 'max-w-2xl'" @mouseenter="paused = true" @mouseleave="paused = false" @focusin="paused = true" @focusout="paused = false">
		<ul class="flex gap-2" :class="sliding ? 'transition-transform duration-[600ms] ease-in-out' : ''"
			:style="{ transform: `translateX(calc(${-position} * (100% + 0.5rem) / 4))` }" @transitionend.self="settle">
			<li v-for="(slot, k) in track" :key="`${slot.img.id}-${k}`" class="shrink-0 basis-[calc((100%-1.5rem)/4)]">
				<button type="button" class="group relative block aspect-[4/3] w-full overflow-hidden rounded-xl bg-gray-100 ring-1 ring-black/5 transition hover:ring-2 hover:ring-primary-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600"
					:tabindex="k < shownImages.length ? 0 : -1" :aria-hidden="k >= shownImages.length"
					:aria-label="`Open picture ${slot.index + 1} of ${shownImages.length}`" @click="emit('open', slot.index)">
					<img :src="slot.img.url ?? ''" :alt="slot.img.caption || ''" class="h-full w-full object-contain transition duration-300 group-hover:scale-[1.04] motion-reduce:transition-none" loading="lazy" draggable="false">
					<span v-if="slot.index === 0 && firstLabel" class="absolute left-1.5 top-1.5 rounded-full bg-primary-700 px-2 py-0.5 text-[10px] font-bold text-white">{{ firstLabel }}</span>
					<span v-if="slot.img.caption && !compact" class="absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/60 to-transparent px-2 pb-1 pt-4 text-left text-[11px] font-semibold text-white">{{ slot.img.caption }}</span>
				</button>
			</li>
		</ul>
		<span v-if="moving" class="pointer-events-none absolute bottom-1.5 right-1.5 rounded-full bg-gray-900/75 px-2 py-0.5 text-[11px] font-bold text-white">
			{{ shownImages.length }} photos
		</span>
	</div>
</template>

<script setup lang="ts">
type Photo = { id: string, url: string | null, caption?: string | null }

const props = withDefaults(defineProps<{ images: Photo[], firstLabel?: string | null, compact?: boolean, interval?: number }>(),
	{ firstLabel: null, compact: false, interval: 2200 })
const emit = defineEmits<{ open: [index: number] }>()

const shownImages = computed(() => props.images.filter(i => i.url))
// More than 4: the photos, then the first 4 again, so the loop back to the start is seamless.
const moving = computed(() => shownImages.value.length > 4)
const track = computed(() => {
	const list = shownImages.value.map((img, index) => ({ img, index }))
	return moving.value ? [...list, ...list.slice(0, 4)] : list
})

const position = ref(0)
const sliding = ref(true)
const paused = ref(false)
let timer: ReturnType<typeof setInterval> | null = null
const reducedMotion = () => import.meta.client && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

function step() {
	if (paused.value || !moving.value) return
	sliding.value = true
	position.value += 1
}
// After sliding onto the copies at the end, jump back to the real start without animating.
function settle() {
	if (position.value < shownImages.value.length) return
	sliding.value = false
	position.value = 0
	requestAnimationFrame(() => requestAnimationFrame(() => { sliding.value = true }))
}
watch(() => [moving.value, props.interval] as const, ([on]) => {
	if (timer) clearInterval(timer)
	timer = null
	position.value = 0
	if (on && !reducedMotion()) timer = setInterval(step, props.interval)
}, { immediate: true })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>
