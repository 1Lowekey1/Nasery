<template>
	<!-- The mood board in full: one picture at a time, its caption and who added it, and (when allowed) add / remove. -->
	<Teleport to="body">
		<div class="fixed inset-0 z-[210] flex items-stretch justify-center bg-gray-950/80 sm:items-center sm:p-6" role="dialog" aria-modal="true"
			:aria-label="title || 'Mood board'" @click.self="emit('close')" @keydown.esc="emit('close')" @keydown.left="step(-1)" @keydown.right="step(1)">
			<div ref="panel" tabindex="-1" class="flex max-h-[100dvh] w-full max-w-5xl flex-col overflow-hidden bg-gray-950 text-white shadow-2xl outline-none sm:max-h-[92dvh] sm:rounded-3xl">
				<div class="flex items-center justify-between gap-3 px-4 py-3 sm:px-5">
					<div class="min-w-0">
						<p class="text-[11px] font-bold uppercase tracking-widest text-primary-200">Mood board</p>
						<p class="truncate text-sm font-semibold">{{ title || 'Inspiration and colour palette' }}</p>
					</div>
					<button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/10 hover:bg-white/20" aria-label="Close" @click="emit('close')"><IconBase name="x" class="h-5 w-5" /></button>
				</div>

				<div class="relative flex min-h-0 flex-1 items-center justify-center bg-black/30 px-2" @touchstart.passive="touchX = $event.touches[0]?.clientX ?? null" @touchend="swipe">
					<img v-if="current?.url" :key="current.id" :src="current.url" :alt="current.caption || `Mood board picture ${index + 1}`" class="max-h-[62dvh] w-auto max-w-full object-contain">
					<div v-else class="flex h-64 flex-col items-center justify-center gap-2 text-sm text-white/60"><IconBase name="camera" class="h-10 w-10" />No pictures yet</div>
					<template v-if="images.length > 1">
						<button type="button" class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-gray-900 shadow hover:bg-white" aria-label="Previous picture" @click="step(-1)"><IconBase name="arrow-left" class="h-5 w-5" /></button>
						<button type="button" class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-gray-900 shadow hover:bg-white" aria-label="Next picture" @click="step(1)"><IconBase name="arrow-right" class="h-5 w-5" /></button>
					</template>
				</div>

				<div class="space-y-3 px-4 py-3 sm:px-5">
					<div class="flex flex-wrap items-center justify-between gap-2 text-sm">
						<p class="min-w-0">
							<span v-if="current?.caption" class="font-semibold">{{ current.caption }}</span>
							<span v-if="current" class="text-white/60">{{ current.caption ? ' · ' : '' }}{{ current.added_by ? `Added by ${current.added_by}` : 'From the client' }}</span>
						</p>
						<span v-if="images.length" class="rounded-full bg-white/10 px-2.5 py-0.5 text-xs font-semibold">{{ index + 1 }} / {{ images.length }}</span>
					</div>
					<div v-if="images.length > 1" class="flex gap-2 overflow-x-auto pb-1">
						<button v-for="(img, i) in images" :key="img.id" type="button" class="h-14 w-20 shrink-0 overflow-hidden rounded-lg ring-2 transition"
							:class="i === index ? 'ring-white' : 'opacity-60 ring-transparent hover:opacity-100'" :aria-label="`Picture ${i + 1}`" :aria-current="i === index" @click="index = i">
							<img :src="img.url ?? ''" alt="" class="h-full w-full object-cover" loading="lazy">
						</button>
					</div>
					<button v-if="commentable && current" type="button" class="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-white/10 px-3.5 text-sm font-semibold hover:bg-white/20" @click="emit('comment', current, index)">
						<IconBase name="message" class="h-4 w-4" />Comment on this picture
					</button>
					<div v-if="editable" class="flex flex-wrap items-center gap-2 border-t border-white/10 pt-3">
						<label v-if="images.length < limit" class="inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-lg bg-white px-3.5 text-sm font-bold text-gray-900 hover:bg-primary-50" :class="busy ? 'pointer-events-none opacity-60' : ''">
							<IconBase :name="busy ? 'refresh-cw' : 'plus'" class="h-4 w-4" :class="busy ? 'animate-spin' : ''" />{{ busy ? 'Uploading…' : 'Add pictures' }}
							<input type="file" class="sr-only" :accept="imageRules.accept" multiple :disabled="busy" @change="emit('pick', $event)">
						</label>
						<button v-if="current && canRemove(current)" type="button" class="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-white/10 px-3.5 text-sm font-semibold hover:bg-rose-600" :disabled="busy" @click="emit('remove', current.id)">
							<IconBase name="trash" class="h-4 w-4" />Remove this picture
						</button>
						<span class="text-xs text-white/60">{{ images.length }} of {{ limit }}</span>
						<p v-if="problem" class="w-full text-xs font-semibold text-rose-300">{{ problem }}</p>
					</div>
				</div>
			</div>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
import type { MoodImage } from './MoodBoard.vue'

const props = withDefaults(defineProps<{
	images: MoodImage[]
	start?: number
	title?: string | null
	editable?: boolean
	busy?: boolean
	limit?: number
	problem?: string
	canRemove?: (img: MoodImage) => boolean
	/** Show "Comment on this picture" (emits comment). */
	commentable?: boolean
}>(), { start: 0, title: null, editable: false, busy: false, limit: 20, problem: '', canRemove: () => true, commentable: false })
const emit = defineEmits<{ close: [], pick: [event: Event], remove: [id: string], comment: [img: MoodImage, index: number] }>()

const index = ref(Math.min(props.start, Math.max(0, props.images.length - 1)))
const current = computed(() => props.images[index.value] ?? null)
// A removed or added picture: stay in range.
watch(() => props.images.length, n => { if (index.value > n - 1) index.value = Math.max(0, n - 1) })

function step(dir: number) {
	if (props.images.length > 1) index.value = (index.value + dir + props.images.length) % props.images.length
}
const touchX = ref<number | null>(null)
function swipe(e: TouchEvent) {
	const end = e.changedTouches[0]?.clientX
	if (touchX.value !== null && end !== undefined && Math.abs(end - touchX.value) > 40) step(end < touchX.value ? 1 : -1)
	touchX.value = null
}
const panel = ref<HTMLElement | null>(null)
onMounted(() => panel.value?.focus())
</script>
