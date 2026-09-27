<template>
	<section v-if="state && (state.review || state.can_review)" id="review" class="mt-4 rounded-xl border border-amber-200 bg-amber-50/40 p-4">
		<!-- Existing review -->
		<template v-if="state.review && !editing">
			<div class="flex flex-wrap items-center justify-between gap-2">
				<p class="text-sm font-bold text-gray-900">Your review of {{ organizer }}</p>
				<StarRating :model-value="state.review.rating" readonly />
			</div>
			<p v-if="state.review.written_review" class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ state.review.written_review }}</p>
			<p class="mt-1 text-xs text-gray-500">
				Posted {{ date(state.review.created_at) }}<template v-if="state.review.edited"> · edited</template>
				<template v-if="state.review.status === 'disputed'"> · under review by NaSeRy (hidden until they decide)</template>
				<template v-else-if="state.review.status === 'hidden'"> · hidden by NaSeRy moderation</template>
			</p>
			<div v-if="state.review.reply" class="mt-3 rounded-lg border-l-4 border-primary-700 bg-white p-3 text-sm">
				<p class="text-xs font-bold uppercase tracking-wide text-gray-400">Reply from {{ organizer }}</p>
				<p class="mt-1 whitespace-pre-line text-gray-700">{{ state.review.reply.text }}</p>
			</div>
			<div v-if="canChange" class="mt-3 flex gap-3 text-xs font-semibold">
				<button type="button" class="text-primary-700" @click="startEdit">Edit</button>
				<button type="button" class="text-rose-700" @click="remove">Remove</button>
				<span class="font-normal text-gray-400">You can change it until {{ date(state.review.editable_until) }}.</span>
			</div>
		</template>

		<!-- New review / edit -->
		<form v-else class="space-y-3" @submit.prevent="save">
			<p class="text-sm font-bold text-gray-900">{{ editing ? 'Edit your review' : `How was ${organizer}?` }}</p>
			<p v-if="!editing" class="text-xs text-gray-500">Your review appears on their public profile with your first name and last initial. You can change it for 30 days.</p>
			<StarRating v-model="form.rating" label="Your rating" />
			<div>
				<label for="review-text" class="sr-only">Your review (optional)</label>
				<textarea id="review-text" v-model="form.text" rows="3" maxlength="2000" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm"
					placeholder="What went well? What could be better? (optional)" />
			</div>
			<Alert v-if="error" type="danger" :text="error" />
			<div class="flex gap-2">
				<button type="submit" :disabled="busy || !form.rating" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ editing ? 'Save changes' : 'Post review' }}</button>
				<button v-if="editing" type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="editing = false">Cancel</button>
			</div>
		</form>
	</section>
</template>

<script setup lang="ts">
import type { MyReviewState } from '~/composables/useReviews'

// B16: the booking client reviews their completed event (paper: verified
// reviews tied to the accepted bid only).
const props = defineProps<{ eventId: string, organizer: string }>()
const api = useReviews()
const state = ref<MyReviewState | null>(null)
const editing = ref(false)
const busy = ref(false)
const error = ref('')
const form = reactive({ rating: 0, text: '' })

const canChange = computed(() => !!state.value?.review && new Date(state.value.review.editable_until) > new Date())
const date = (at: string) => new Date(at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

async function load() {
	try {
		state.value = await api.mine(props.eventId)
	} catch {
		state.value = null
	}
}

function startEdit() {
	Object.assign(form, { rating: state.value!.review!.rating, text: state.value!.review!.written_review ?? '' })
	editing.value = true
}

async function save() {
	busy.value = true
	error.value = ''
	try {
		const body = { rating: form.rating, written_review: form.text.trim() || null }
		const review = editing.value ? await api.update(props.eventId, body) : await api.create(props.eventId, body)
		state.value = { can_review: false, reason: null, review }
		editing.value = false
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function remove() {
	if (!confirm('Remove your review? It disappears from their profile.')) return
	await api.remove(props.eventId)
	Object.assign(form, { rating: 0, text: '' })
	await load()
}

onMounted(load)
</script>
