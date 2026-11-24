<template>
	<div>
		<p v-if="label" class="mb-2 text-sm font-semibold text-gray-700">{{ label }}</p>
		<div class="flex flex-wrap items-center gap-4">
			<!-- Current picture (or the new one, previewed before it's saved) -->
			<div class="relative shrink-0 overflow-hidden transition"
				:class="[frameClass, shown ? 'border border-gray-200 bg-white' : 'border border-dashed border-primary-200 bg-primary-25', waiting && !waiting.error && 'ring-2 ring-primary-300 ring-offset-2', busy && 'opacity-60']">
				<img v-if="shown" :src="shown" :alt="alt" class="h-full w-full" :class="shape === 'logo' || shape === 'plan' ? 'object-contain p-1' : 'object-cover'">
				<div v-else class="flex h-full w-full items-center justify-center text-primary-400">
					<slot name="placeholder"><IconBase name="camera" class="h-6 w-6" /></slot>
				</div>
				<div v-if="busy" class="absolute inset-0 flex items-center justify-center bg-white/60">
					<IconBase name="refresh-cw" class="h-5 w-5 animate-spin text-primary-700" />
				</div>
			</div>

			<div class="min-w-0 flex-1 space-y-2">
				<div class="flex flex-wrap items-center gap-1.5">
					<label class="inline-flex items-center gap-1.5 rounded-xl border border-primary-200 bg-primary-50 px-3.5 py-2 text-xs font-semibold text-primary-700 transition focus-within:ring-2 focus-within:ring-primary-500/25"
						:class="busy ? 'cursor-wait opacity-60' : 'cursor-pointer hover:border-primary-300 hover:bg-primary-100'">
						<IconBase :name="current || waiting ? 'refresh-cw' : 'upload'" class="h-3.5 w-3.5" />
						{{ busy ? 'Uploading…' : current || waiting ? 'Change' : 'Upload' }}
						<input ref="input" type="file" :accept="imageRules.accept" class="sr-only" :disabled="busy" @change="pick">
					</label>
					<button v-if="waiting?.original" type="button" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold text-primary-700 hover:bg-primary-50" @click="cropping = waiting.original">
						<IconBase name="edit" class="h-3.5 w-3.5" /> Adjust
					</button>
					<button v-if="waiting" type="button" class="rounded-xl px-3 py-2 text-xs font-semibold text-gray-500 hover:bg-gray-50 hover:text-gray-700" @click="undo">Undo</button>
					<button v-else-if="current" type="button" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold text-gray-500 hover:bg-red-50 hover:text-red-600" :disabled="busy" @click="remove">
						<IconBase name="trash" class="h-3.5 w-3.5" /> Remove
					</button>
				</div>
				<p v-if="error || waiting?.error" class="flex items-start gap-1.5 rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700" role="alert">
					<IconBase name="x-circle" class="mt-px h-3.5 w-3.5 shrink-0" />{{ error || waiting?.error }}
				</p>
				<p v-else-if="waiting" class="flex items-start gap-1.5 rounded-lg bg-primary-50 px-2.5 py-1.5 text-xs font-medium text-primary-800" role="status">
					<IconBase name="clock" class="mt-px h-3.5 w-3.5 shrink-0" />New picture. It's saved when you save your profile.
				</p>
				<p v-else-if="saved" class="flex items-start gap-1.5 rounded-lg bg-green-50 px-2.5 py-1.5 text-xs font-medium text-green-700" role="status">
					<IconBase name="check-circle" class="mt-px h-3.5 w-3.5 shrink-0" />Picture saved.
				</p>
				<p class="text-xs leading-5 text-gray-400">{{ hint }}</p>
			</div>
		</div>
		<MediaCropDialog v-if="cropping" :file="cropping" v-bind="cropShape" @done="accept" @cancel="cropping = null" />
	</div>
</template>

<script setup lang="ts">
// One picture slot (profile photo, logo, cover, event cover). Inside a form that
// provides a media batch (profile panels), a picked file is previewed and waits
// for that form's Save button; elsewhere it uploads straight away. The server
// checks and re-encodes every image.
const props = withDefaults(defineProps<{
	current: string | null | undefined
	upload: (file: File) => Promise<string | null | void>
	remove?: () => Promise<unknown>
	/** plan (0066 floor plan): the whole picture, no cropping. */
	shape?: 'avatar' | 'logo' | 'cover' | 'event' | 'plan'
	label?: string
	alt?: string
	/** Key in the form's media batch (defaults to the shape). */
	slotKey?: string
}>(), { shape: 'avatar', label: '', alt: '', remove: undefined, slotKey: undefined })
const emit = defineEmits<{ changed: [url: string | null] }>()

const input = ref<HTMLInputElement | null>(null)
const pending = ref<File | null>(null)
const preview = ref<string | null>(null)
const busy = ref(false)
const error = ref('')
const saved = ref(false)
const batch = useMediaBatch()
const key = computed(() => props.slotKey ?? props.shape)
const waiting = computed(() => batch?.pending.value[key.value] ?? null)

const frameClass = computed(() => ({
	avatar: 'h-20 w-20 rounded-full',
	logo: 'h-20 w-20 rounded-2xl',
	cover: 'h-24 w-full max-w-sm rounded-xl',
	event: 'h-28 w-full max-w-sm rounded-xl',
	plan: 'h-36 w-full max-w-sm rounded-xl',
})[props.shape])
const hint = computed(() => ({
	avatar: 'JPG, PNG or WebP, up to 25 MB. Shown as a circle.',
	logo: 'JPG, PNG or WebP, up to 25 MB. Square works best; transparent PNG is kept.',
	cover: 'JPG, PNG or WebP, up to 25 MB. A wide picture (about 1600×600) works best.',
	event: 'JPG, PNG or WebP, up to 25 MB. A wide picture (about 16:9) works best.',
	plan: 'JPG, PNG or WebP, up to 5 MB. The whole picture is kept (no cropping), so the numbers stay readable.',
})[props.shape])
// The crop frame matches what the server makes of each kind (ImageStore::KINDS).
const cropping = ref<File | null>(null)
const cropShape = computed(() => ({
	avatar: { aspect: 1, outputWidth: 1024, round: true },
	logo: { aspect: 1, outputWidth: 1024, transparent: true },
	cover: { aspect: 1600 / 600, outputWidth: 1600 },
	event: { aspect: 16 / 9, outputWidth: 1600 },
	plan: { aspect: 16 / 9, outputWidth: 1600 }, // not used: plans skip the crop step
})[props.shape])
const shown = computed(() => waiting.value?.preview ?? preview.value ?? props.current ?? null)

function pick(e: Event) {
	error.value = ''
	saved.value = false
	const file = (e.target as HTMLInputElement).files?.[0]
	;(e.target as HTMLInputElement).value = ''
	if (!file) return
	const problem = imageProblem(file)
	if (problem) {
		error.value = problem
		return
	}
	// A floor plan is kept whole (cropping could cut off tables).
	if (props.shape === 'plan') {
		accept(file)
		return
	}
	// Let them move and zoom it first.
	cropping.value = file
}

/** The adjusted picture: waits for the form's Save, or uploads now. */
function accept(file: File) {
	const original = cropping.value ?? file
	cropping.value = null
	// Only when the picture couldn't be shrunk here (see CropDialog).
	if (file.size > imageRules.maxBytes) {
		error.value = props.shape === 'plan' ? 'Choose a picture under 5 MB.' : 'That picture couldn\'t be shrunk here. Try one under 5 MB.'
		return
	}
	if (batch) {
		batch.put(key.value, { file, original, preview: URL.createObjectURL(file), upload: props.upload, done: url => emit('changed', url) })
		return
	}
	pending.value = file
	if (preview.value) URL.revokeObjectURL(preview.value)
	preview.value = URL.createObjectURL(file)
	save()
}

function undo() {
	batch?.drop(key.value)
}

function cancel() {
	pending.value = null
	if (preview.value) URL.revokeObjectURL(preview.value)
	preview.value = null
}

async function save() {
	if (!pending.value) return
	busy.value = true
	error.value = ''
	try {
		const url = await props.upload(pending.value)
		cancel()
		saved.value = true
		emit('changed', typeof url === 'string' ? url : null)
	} catch (e) {
		cancel()
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function remove() {
	if (!props.remove || !(await confirmDialog('Remove this picture?', { confirmLabel: 'Remove' }))) return
	busy.value = true
	error.value = ''
	saved.value = false
	try {
		await props.remove()
		emit('changed', null)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

onBeforeUnmount(() => { if (preview.value) URL.revokeObjectURL(preview.value) })
</script>
