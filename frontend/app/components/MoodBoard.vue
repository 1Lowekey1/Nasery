<template>
	<div>
		<!-- 0062 (organizer field test): the client's mood board / colour palette pictures. -->
		<ul v-if="images.length || editable" class="grid gap-2" :class="compact ? 'grid-cols-3 sm:grid-cols-4' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4'">
			<li v-for="img in images" :key="img.id" class="group relative overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
				<button type="button" class="block aspect-[4/3] w-full" :aria-label="img.caption ? `See “${img.caption}” larger` : 'See this picture larger'" @click="zoom = img">
					<img v-if="img.url" :src="img.url" :alt="img.caption || 'Mood board picture'" class="h-full w-full object-cover" loading="lazy">
				</button>
				<p v-if="img.caption" class="truncate px-2 py-1 text-xs text-gray-600">{{ img.caption }}</p>
				<button v-if="editable" type="button" class="absolute right-1.5 top-1.5 flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-gray-600 shadow-sm ring-1 ring-black/5 hover:text-rose-600"
					:disabled="busy" :aria-label="`Remove ${img.caption || 'this picture'}`" @click="emit('remove', img.id)">
					<IconBase name="trash" class="h-3.5 w-3.5" />
				</button>
			</li>
			<li v-if="editable && images.length < limit">
				<label class="flex aspect-[4/3] cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-gray-300 text-center text-xs font-semibold text-gray-500 transition hover:border-primary-300 hover:text-primary-700"
					:class="busy ? 'pointer-events-none opacity-60' : ''">
					<IconBase :name="busy ? 'refresh-cw' : 'plus'" class="h-5 w-5" :class="busy ? 'animate-spin' : ''" />
					{{ busy ? 'Uploading…' : 'Add picture' }}
					<input type="file" class="sr-only" :accept="imageRules.accept" multiple :disabled="busy" @change="pick">
				</label>
			</li>
		</ul>
		<p v-if="editable" class="mt-1.5 text-xs" :class="problem ? 'font-semibold text-rose-600' : 'text-gray-400'">
			{{ problem || `Inspiration pictures, colour palettes or a mood board: up to ${limit}. Organizers who get your request see them.` }}
		</p>

		<Teleport to="body">
			<div v-if="zoom" class="fixed inset-0 z-[210] flex items-center justify-center bg-gray-950/75 p-4" role="dialog" aria-modal="true"
				:aria-label="zoom.caption || 'Mood board picture'" @click="zoom = null" @keydown.esc="zoom = null">
				<figure class="max-h-full max-w-4xl" @click.stop>
					<img v-if="zoom.url" :src="zoom.url" :alt="zoom.caption || 'Mood board picture'" class="max-h-[80dvh] w-auto rounded-2xl bg-white object-contain shadow-2xl">
					<figcaption class="mt-2 flex items-center justify-between gap-3 text-sm font-semibold text-white">
						<span class="min-w-0 truncate">{{ zoom.caption || ' ' }}</span>
						<span class="flex shrink-0 gap-2">
							<button v-if="images.length > 1" type="button" class="rounded-lg bg-white/15 px-3 py-1.5 text-xs hover:bg-white/25" @click="step(-1)">Previous</button>
							<button v-if="images.length > 1" type="button" class="rounded-lg bg-white/15 px-3 py-1.5 text-xs hover:bg-white/25" @click="step(1)">Next</button>
							<button type="button" class="rounded-lg bg-white/15 px-3 py-1.5 text-xs hover:bg-white/25" @click="zoom = null">Close</button>
						</span>
					</figcaption>
				</figure>
			</div>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
export type MoodImage = { id: string, url: string | null, caption: string | null }

const props = withDefaults(defineProps<{ images: MoodImage[], editable?: boolean, busy?: boolean, compact?: boolean, limit?: number }>(),
	{ editable: false, busy: false, compact: false, limit: 8 })
const emit = defineEmits<{ add: [files: File[]], remove: [id: string] }>()

const zoom = ref<MoodImage | null>(null)
const problem = ref('')

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

function step(dir: number) {
	const i = props.images.findIndex(x => x.id === zoom.value?.id)
	zoom.value = props.images[(i + dir + props.images.length) % props.images.length] ?? null
}
</script>
