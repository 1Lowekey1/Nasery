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

		<div v-else class="space-y-2">
			<!-- Field test follow-up (Nov 2026): up to 4 at a glance; more fade through the 4th spot. A click opens the viewer. -->
			<PhotoStrip :images="images" :compact="compact" @open="openAt" />
			<div class="flex flex-wrap items-center gap-2">
				<label v-if="editable && images.length < limit" class="inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-xl border border-primary-200 bg-primary-50 px-3.5 text-sm font-bold text-primary-800 transition hover:bg-primary-100"
					:class="busy ? 'pointer-events-none opacity-60' : ''">
					<IconBase :name="busy ? 'refresh-cw' : 'camera'" class="h-4 w-4" :class="busy ? 'animate-spin' : ''" />{{ busy ? 'Uploading…' : 'Add pictures' }}
					<input type="file" class="sr-only" :accept="imageRules.accept" multiple :disabled="busy" @change="pick">
				</label>
				<button type="button" class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="openAt(0)">
					<IconBase name="grid" class="h-4 w-4" />View all ({{ images.length }}<template v-if="editable"> of {{ limit }}</template>)
				</button>
			</div>
		</div>
		<p v-if="editable && !viewing" class="mt-1.5 text-xs" :class="problem ? 'font-semibold text-rose-600' : 'text-gray-400'">
			{{ problem || hint }}
		</p>

		<MoodBoardViewer v-if="viewing" :images="images" :start="viewStart" :title="title" :editable="editable" :busy="busy" :limit="limit" :problem="problem"
			:can-remove="canRemove" :commentable="commentable" @close="viewing = false" @pick="pick" @remove="emit('remove', $event)"
			@comment="(img, i) => emit('comment', img, i)" />
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
	/** Show "Comment on this picture" in the viewer (emits comment). */
	commentable?: boolean
}>(), { commentable: false, editable: false, busy: false, compact: false, limit: 20, title: null, canRemove: () => true,
	hint: 'Inspiration pictures, colour palettes or a mood board: up to 20. Organizers who get your request see them.' })
const emit = defineEmits<{ add: [files: File[]], remove: [id: string], comment: [img: MoodImage, index: number] }>()

const problem = ref('')
const viewing = ref(false)
const viewStart = ref(0)
function openAt(i: number) {
	viewStart.value = i
	viewing.value = true
}


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
