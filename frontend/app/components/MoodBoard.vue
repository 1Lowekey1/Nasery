<template>
	<div>
		<!-- 0062 (organizer field test): the client's mood board / colour palette pictures.
		     Field test follow-up (Nov 2026): one preview that grows on hover and cycles through the
		     pictures; a click opens the full viewer. Empty and editable: an "Add" button. -->
		<template v-if="!images.length">
			<label v-if="editable" class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-primary-200 bg-primary-50 px-4 text-sm font-bold text-primary-800 transition hover:bg-primary-100"
				:class="busy ? 'pointer-events-none opacity-60' : ''">
				<IconBase :name="busy ? 'refresh-cw' : 'camera'" class="h-4 w-4" :class="busy ? 'animate-spin' : ''" />{{ busy ? 'Uploading…' : 'Add mood board pictures' }}
				<input type="file" class="sr-only" :accept="imageRules.accept" multiple :disabled="busy" @change="pick">
			</label>
			<p v-else class="text-sm text-gray-500">No mood board pictures yet.</p>
		</template>

		<div v-else class="flex flex-wrap items-end gap-3">
			<button type="button" class="group relative block aspect-[4/3] w-full overflow-hidden rounded-2xl bg-gray-100 shadow-sm ring-1 ring-black/5 transition duration-300 ease-out hover:z-10 hover:scale-[1.04] hover:shadow-xl focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600 motion-reduce:transition-none motion-reduce:hover:scale-100"
				:class="compact ? 'max-w-[13rem]' : 'max-w-[18rem]'" :aria-label="`Open the mood board (${images.length} picture${images.length === 1 ? '' : 's'})`"
				@mouseenter="startCycle" @mouseleave="stopCycle" @focus="startCycle" @blur="stopCycle" @click="openAt(shown)">
				<img v-for="(img, i) in images" :key="img.id" :src="img.url ?? ''" :alt="i === shown ? (img.caption || 'Mood board picture') : ''"
					class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500 motion-reduce:transition-none" :class="i === shown ? 'opacity-100' : 'opacity-0'" loading="lazy">
				<span class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-2 bg-gradient-to-t from-black/60 to-transparent px-3 pb-2 pt-8 text-left text-xs font-semibold text-white">
					<span class="min-w-0 truncate">{{ images[shown]?.caption || 'Mood board' }}</span>
					<span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-black/40 px-2 py-0.5"><IconBase name="camera" class="h-3 w-3" />{{ images.length }}</span>
				</span>
				<span v-if="images.length > 1" class="absolute right-2 top-2 flex gap-1" aria-hidden="true">
					<span v-for="(img, i) in images" :key="img.id" class="h-1.5 w-1.5 rounded-full transition" :class="i === shown ? 'bg-white' : 'bg-white/50'" />
				</span>
			</button>
			<label v-if="editable && images.length < limit" class="inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 text-xs font-bold text-gray-700 hover:border-primary-300 hover:text-primary-700"
				:class="busy ? 'pointer-events-none opacity-60' : ''">
				<IconBase :name="busy ? 'refresh-cw' : 'plus'" class="h-3.5 w-3.5" :class="busy ? 'animate-spin' : ''" />{{ busy ? 'Uploading…' : `Add pictures (${images.length} of ${limit})` }}
				<input type="file" class="sr-only" :accept="imageRules.accept" multiple :disabled="busy" @change="pick">
			</label>
		</div>
		<p v-if="editable && !viewing" class="mt-1.5 text-xs" :class="problem ? 'font-semibold text-rose-600' : 'text-gray-400'">
			{{ problem || hint }}
		</p>

		<MoodBoardViewer v-if="viewing" :images="images" :start="viewStart" :title="title" :editable="editable" :busy="busy" :limit="limit" :problem="problem"
			:can-remove="canRemove" @close="viewing = false" @pick="pick" @remove="emit('remove', $event)" />
	</div>
</template>

<script setup lang="ts">
export type MoodImage = { id: string, url: string | null, caption: string | null, added_by?: string | null, by_organization?: string | null }

const props = withDefaults(defineProps<{
	images: MoodImage[]
	editable?: boolean
	busy?: boolean
	compact?: boolean
	limit?: number
	title?: string | null
	/** Which pictures this person may remove (organizers: only their own). */
	canRemove?: (img: MoodImage) => boolean
	hint?: string
}>(), { editable: false, busy: false, compact: false, limit: 8, title: null, canRemove: () => true,
	hint: 'Inspiration pictures, colour palettes or a mood board: up to 8. Organizers who get your request see them.' })
const emit = defineEmits<{ add: [files: File[]], remove: [id: string] }>()

const problem = ref('')
const viewing = ref(false)
const viewStart = ref(0)
function openAt(i: number) {
	stopCycle()
	viewStart.value = i
	viewing.value = true
}

// Hover (or keyboard focus): cycle through the pictures; leaving goes back to the first.
const shown = ref(0)
let timer: ReturnType<typeof setInterval> | null = null
const reducedMotion = () => import.meta.client && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
function startCycle() {
	if (timer || props.images.length < 2 || reducedMotion()) return
	timer = setInterval(() => { shown.value = (shown.value + 1) % props.images.length }, 1300)
}
function stopCycle() {
	if (timer) clearInterval(timer)
	timer = null
	shown.value = 0
}
watch(() => props.images.length, n => { if (shown.value > n - 1) shown.value = 0 })
onBeforeUnmount(stopCycle)

function pick(e: Event) {
	const input = e.target as HTMLInputElement
	const files = [...(input.files ?? [])]
	input.value = ''
	problem.value = ''
	const room = props.limit - props.images.length
	const bad = files.map(f => imageProblem(f) || (f.size > imageRules.maxBytes ? 'Choose images under 5 MB each.' : '')).find(Boolean)
	if (bad) { problem.value = bad; return }
	if (files.length > room) problem.value = `Only ${room} more picture${room === 1 ? '' : 's'} fit; the first ${room} were added.`
	if (files.length) emit('add', files.slice(0, room))
}
</script>
