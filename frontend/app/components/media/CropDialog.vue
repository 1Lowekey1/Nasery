<template>
	<Teleport to="body">
		<Transition name="crop-fade" appear>
			<div class="fixed inset-0 z-[200] flex items-center justify-center bg-gray-900/40 p-4 backdrop-blur-[2px]" @click.self="emit('cancel')">
				<div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="crop-title">
					<div class="flex items-start gap-3">
						<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
							<IconBase name="camera" class="h-5 w-5" />
						</span>
						<div class="min-w-0">
							<h2 id="crop-title" class="text-base font-bold text-gray-900">Adjust photo</h2>
							<p class="mt-1 text-sm leading-6 text-gray-600">Drag to move it and zoom to fit. This is how it will look.</p>
						</div>
					</div>

					<!-- Calm stage around the frame; the frame has the shape of the final picture -->
					<div class="mt-5 rounded-xl border border-primary-100 bg-primary-25 p-3 sm:p-4">
						<div ref="frame" tabindex="0" aria-label="Photo position. Use the arrow keys to move it and plus or minus to zoom."
							class="relative mx-auto w-full touch-none select-none overflow-hidden bg-white shadow-sm ring-1 ring-primary-100 outline-none transition-shadow focus-visible:ring-2 focus-visible:ring-primary-500"
							:class="[round ? 'max-w-[18rem] rounded-full' : aspect === 1 ? 'max-w-[18rem] rounded-2xl' : 'rounded-lg', dragging ? 'cursor-grabbing' : 'cursor-grab', transparent && 'checker']"
							:style="{ aspectRatio: String(aspect) }"
							@pointerdown="startDrag" @pointermove="drag" @pointerup="endDrag" @pointercancel="endDrag"
							@wheel.prevent="onWheel" @keydown="onKey">
							<img v-if="src" ref="picture" :src="src" alt="" draggable="false" class="pointer-events-none absolute left-0 top-0 max-w-none origin-top-left"
								:style="{ width: `${natural.w * scale}px`, height: `${natural.h * scale}px`, transform: `translate(${pos.x}px, ${pos.y}px)` }"
								@load="onLoad">
							<!-- Thirds grid while moving, to help line things up -->
							<div aria-hidden="true" class="pointer-events-none absolute inset-0 transition-opacity duration-150" :class="dragging ? 'opacity-100' : 'opacity-0'">
								<div class="absolute inset-y-0 left-1/3 w-px bg-white/60" /><div class="absolute inset-y-0 left-2/3 w-px bg-white/60" />
								<div class="absolute inset-x-0 top-1/3 h-px bg-white/60" /><div class="absolute inset-x-0 top-2/3 h-px bg-white/60" />
							</div>
							<div v-if="!ready" class="absolute inset-0 flex items-center justify-center">
								<IconBase name="refresh-cw" class="h-5 w-5 animate-spin text-primary-400" />
							</div>
						</div>
					</div>

					<!-- Zoom -->
					<div class="mt-4 flex items-center gap-2">
						<button type="button" class="zoom-btn" aria-label="Zoom out" :disabled="zoom <= 1" @click="setZoom(zoom - 0.25)">−</button>
						<input v-model.number="zoom" type="range" min="1" max="4" step="0.01" aria-label="Zoom" class="zoom-range"
							:style="{ '--fill': `${((zoom - 1) / 3) * 100}%` }">
						<button type="button" class="zoom-btn" aria-label="Zoom in" :disabled="zoom >= 4" @click="setZoom(zoom + 0.25)">+</button>
						<span class="w-11 shrink-0 text-right text-xs font-semibold tabular-nums text-gray-500">{{ Math.round(zoom * 100) }}%</span>
					</div>

					<div class="mt-6 flex items-center gap-2">
						<button type="button" class="mr-auto rounded-xl px-2 py-2 text-sm font-semibold text-primary-700 hover:text-primary-900 disabled:opacity-40" :disabled="!ready" @click="reset">Reset</button>
						<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50" @click="emit('cancel')">Cancel</button>
						<button type="button" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-bold text-white hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-50" :disabled="!ready || working" @click="finish">
							{{ working ? 'Preparing…' : 'Use photo' }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</Teleport>
</template>

<script setup lang="ts">
// Move and zoom a picked picture inside a frame shaped like the final picture,
// then hand back the cropped file (the server still checks and re-encodes it).
const props = withDefaults(defineProps<{
	file: File
	/** Width ÷ height of the final picture. */
	aspect: number
	/** Output size in pixels (width; height follows the aspect). */
	outputWidth: number
	round?: boolean
	/** Logos: the whole picture may fit inside the frame; transparency is kept. */
	transparent?: boolean
}>(), { round: false, transparent: false })
const emit = defineEmits<{ done: [file: File], cancel: [] }>()

const frame = ref<HTMLDivElement | null>(null)
const picture = ref<HTMLImageElement | null>(null)
const src = ref('')
const natural = reactive({ w: 0, h: 0 })
const box = reactive({ w: 0, h: 0 })
const pos = reactive({ x: 0, y: 0 })
const zoom = ref(1)
const ready = ref(false)
const working = ref(false)
const dragging = ref(false)
let last = { x: 0, y: 0 }

/** Smallest scale: the picture covers the frame (logos: fits inside it). */
const baseScale = computed(() => {
	if (!natural.w || !box.w) return 1
	const sx = box.w / natural.w
	const sy = box.h / natural.h
	return props.transparent ? Math.min(sx, sy) : Math.max(sx, sy)
})
const scale = computed(() => baseScale.value * zoom.value)

function clamp() {
	const w = natural.w * scale.value
	const h = natural.h * scale.value
	pos.x = Math.min(Math.max(pos.x, Math.min(0, box.w - w)), Math.max(0, box.w - w))
	pos.y = Math.min(Math.max(pos.y, Math.min(0, box.h - h)), Math.max(0, box.h - h))
}

function measure() {
	if (!frame.value) return
	box.w = frame.value.clientWidth
	box.h = frame.value.clientHeight
}

function onLoad(e: Event) {
	const img = e.target as HTMLImageElement
	natural.w = img.naturalWidth
	natural.h = img.naturalHeight
	measure()
	// Start centred.
	pos.x = (box.w - natural.w * scale.value) / 2
	pos.y = (box.h - natural.h * scale.value) / 2
	ready.value = true
	frame.value?.focus()
}

// Zooming keeps the point in the middle of the frame where it is.
watch(zoom, (z, zBefore) => {
	const now = baseScale.value * z
	const before = baseScale.value * zBefore
	const cx = (box.w / 2 - pos.x) / before
	const cy = (box.h / 2 - pos.y) / before
	pos.x = box.w / 2 - cx * now
	pos.y = box.h / 2 - cy * now
	clamp()
})

function startDrag(e: PointerEvent) {
	dragging.value = true
	last = { x: e.clientX, y: e.clientY }
	;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
}
function drag(e: PointerEvent) {
	if (!dragging.value) return
	pos.x += e.clientX - last.x
	pos.y += e.clientY - last.y
	last = { x: e.clientX, y: e.clientY }
	clamp()
}
function endDrag() { dragging.value = false }

const setZoom = (z: number) => { zoom.value = Math.min(4, Math.max(1, z)) }
function reset() {
	zoom.value = 1
	nextTick(() => {
		pos.x = (box.w - natural.w * scale.value) / 2
		pos.y = (box.h - natural.h * scale.value) / 2
	})
}
function onWheel(e: WheelEvent) { setZoom(zoom.value - e.deltaY * 0.002) }
function onKey(e: KeyboardEvent) {
	const step = e.shiftKey ? 40 : 10
	const moves: Record<string, [number, number]> = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] }
	if (moves[e.key]) {
		pos.x += moves[e.key]![0]
		pos.y += moves[e.key]![1]
		clamp()
	} else if (e.key === '+' || e.key === '=') setZoom(zoom.value + 0.1)
	else if (e.key === '-') setZoom(zoom.value - 0.1)
	else if (e.key === 'Escape') emit('cancel')
	else if (e.key === 'Enter') finish()
	else return
	e.preventDefault()
}

async function finish() {
	if (!ready.value || working.value) return
	working.value = true
	try {
		// The picture on screen is already decoded.
		const img = picture.value!
		const width = props.outputWidth
		const height = Math.round(width / props.aspect)
		const canvas = document.createElement('canvas')
		canvas.width = width
		canvas.height = height
		const ctx = canvas.getContext('2d')!
		ctx.imageSmoothingQuality = 'high'
		if (!props.transparent) {
			ctx.fillStyle = '#ffffff'
			ctx.fillRect(0, 0, width, height)
		}
		const r = width / box.w
		ctx.drawImage(img, pos.x * r, pos.y * r, natural.w * scale.value * r, natural.h * scale.value * r)
		const type = props.transparent ? 'image/png' : 'image/jpeg'
		const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, type, 0.92))
		if (!blob) throw new Error('crop failed')
		const name = props.file.name.replace(/\.[^.]+$/, '') + (props.transparent ? '.png' : '.jpg')
		emit('done', new File([blob], name, { type }))
	} catch {
		// The picture couldn't be redrawn here; send it as picked (the server crops it).
		emit('done', props.file)
	} finally {
		working.value = false
	}
}

const onResize = () => { measure(); clamp() }
onMounted(() => {
	src.value = URL.createObjectURL(props.file)
	window.addEventListener('resize', onResize)
})
onBeforeUnmount(() => {
	URL.revokeObjectURL(src.value)
	window.removeEventListener('resize', onResize)
})
</script>

<style scoped>
.crop-fade-enter-active, .crop-fade-leave-active { transition: opacity 150ms ease; }
.crop-fade-enter-from, .crop-fade-leave-to { opacity: 0; }

/* Transparent logos: a soft teal checkerboard instead of harsh grey */
.checker {
	background-color: #fff;
	background-image: conic-gradient(#eef4f5 25%, transparent 0 50%, #eef4f5 0 75%, transparent 0);
	background-size: 16px 16px;
}

.zoom-btn {
	@apply flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-base font-semibold leading-none text-gray-600 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-gray-200 disabled:hover:bg-white disabled:hover:text-gray-600;
}

/* Teal slider: filled part primary-700, rest primary-100 */
.zoom-range {
	@apply h-1.5 w-full cursor-pointer appearance-none rounded-full outline-none;
	background: linear-gradient(to right, #285F6b var(--fill), #d7e6e8 var(--fill));
}
.zoom-range:focus-visible { box-shadow: 0 0 0 3px rgba(65, 127, 136, 0.25); }
.zoom-range::-webkit-slider-thumb {
	@apply h-4 w-4 appearance-none rounded-full border-2 border-white bg-primary-700 shadow;
}
.zoom-range::-moz-range-thumb {
	@apply h-4 w-4 rounded-full border-2 border-white bg-primary-700 shadow;
}
</style>
