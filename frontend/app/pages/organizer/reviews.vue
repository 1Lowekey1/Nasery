<template>
	<div class="mx-auto max-w-5xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Reviews</h1>
		<p class="mt-1 text-gray-500">What clients said after their events. Only clients who booked you through NaSeRy can review.</p>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />
		<div v-if="!data && !error" class="py-12 text-center text-sm text-gray-500">Loading reviews…</div>

		<template v-if="data">
			<section class="mt-5 grid gap-5 rounded-2xl border border-gray-200 bg-white p-6 sm:grid-cols-[200px_1fr]">
				<div>
					<p class="text-5xl font-extrabold text-gray-900">{{ data.summary.average?.toFixed(1) ?? '—' }}</p>
					<StarRating :model-value="Math.round(data.summary.average ?? 0)" readonly class="mt-1" />
					<p class="mt-1 text-sm text-gray-500">{{ data.summary.count }} published review{{ data.summary.count === 1 ? '' : 's' }}</p>
					<p v-if="data.summary.awaiting_reply" class="mt-2 text-xs font-semibold text-amber-700">{{ data.summary.awaiting_reply }} waiting for your reply</p>
				</div>
				<ul class="space-y-1.5" aria-label="Rating distribution">
					<li v-for="n in [5, 4, 3, 2, 1]" :key="n" class="flex items-center gap-2 text-sm">
						<span class="w-10 text-gray-600">{{ n }} ★</span>
						<div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-amber-500" :style="{ width: `${share(n)}%` }" /></div>
						<span class="w-8 text-right text-gray-500">{{ data.summary.distribution[n] ?? 0 }}</span>
					</li>
				</ul>
			</section>

			<div class="mt-5 mb-3 flex flex-wrap items-center gap-2">
				<h2 class="mr-auto text-base font-bold text-gray-900">All reviews</h2>
				<label class="flex items-center gap-2 text-sm text-gray-700"><input v-model="onlyUnreplied" type="checkbox"> Waiting for a reply</label>
			</div>
			<p v-if="!shown.length" class="rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center text-sm text-gray-500">
				{{ data.reviews.length ? 'Every review has a reply.' : 'No reviews yet. Clients are invited to review when you mark their event completed.' }}
			</p>

			<article v-for="r in shown" :key="r.id" class="mb-4 rounded-2xl border border-gray-200 bg-white p-5">
				<div class="flex flex-wrap items-start justify-between gap-2">
					<div>
						<StarRating :model-value="r.rating" readonly />
						<p class="text-xs text-gray-500">{{ r.reviewer }} · {{ r.event_name }} · {{ date(r.created_at) }}<template v-if="r.edited"> · edited</template></p>
					</div>
					<div class="flex flex-wrap gap-1.5 text-xs">
						<span v-if="r.status === 'disputed'" class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">Under NaSeRy review · hidden meanwhile</span>
						<span v-else-if="r.status === 'hidden'" class="rounded-full bg-gray-100 px-2 py-0.5 font-semibold text-gray-600">Hidden by moderation</span>
						<span v-else-if="r.open_reports" class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-800">⚑ Reported · with NaSeRy admin</span>
					</div>
				</div>
				<p v-if="r.written_review" class="mt-2 whitespace-pre-line text-sm text-gray-800">{{ r.written_review }}</p>
				<p v-else class="mt-2 text-sm italic text-gray-400">Rating only, no written review.</p>

				<div v-if="r.reply && replying !== r.id" class="mt-3 rounded-lg border-l-4 border-primary-700 bg-gray-50 p-3 text-sm">
					<p class="text-xs font-bold uppercase tracking-wide text-gray-400">Your public reply · {{ date(r.reply.at) }}</p>
					<p class="mt-1 whitespace-pre-line text-gray-700">{{ r.reply.text }}</p>
				</div>

				<form v-if="replying === r.id" class="mt-3 space-y-2" @submit.prevent="saveReply(r)">
					<label :for="`reply-${r.id}`" class="text-xs font-semibold text-gray-600">Public reply (shown on your profile under the review)</label>
					<textarea :id="`reply-${r.id}`" v-model="replyText" rows="3" maxlength="1500" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
					<div class="flex gap-2">
						<button type="submit" :disabled="busy || replyText.trim().length < 2" class="rounded-lg bg-primary-700 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">Post reply</button>
						<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-semibold" @click="replying = null">Cancel</button>
					</div>
				</form>

				<div v-else class="mt-3 flex flex-wrap gap-4 text-xs font-semibold">
					<template v-if="data.can.reply">
						<button type="button" class="text-primary-700" @click="startReply(r)">{{ r.reply ? 'Edit reply' : 'Reply' }}</button>
						<button v-if="r.reply" type="button" class="text-rose-700" @click="removeReply(r)">Remove reply</button>
					</template>
					<button v-if="!r.open_reports" type="button" class="text-gray-500 hover:text-gray-800" @click="report(r)">Report to NaSeRy</button>
				</div>
			</article>
			<p v-if="!data.can.reply" class="text-xs text-gray-500">Only the organization owner can reply to reviews.</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { OrganizerReviews, Review } from '~/composables/useReviews'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const api = useReviews()
const data = ref<OrganizerReviews | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const onlyUnreplied = ref(false)
const replying = ref<string | null>(null)
const replyText = ref('')

const shown = computed(() => (data.value?.reviews ?? []).filter(r => !onlyUnreplied.value || (!r.reply && r.status === 'published')))
const share = (n: number) => (data.value?.summary.count ? ((data.value.summary.distribution[n] ?? 0) / data.value.summary.count) * 100 : 0)
const date = (at: string) => new Date(at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })

async function orgId() {
	return (organization.value ?? await fetchOrganization())!.id
}

async function load() {
	try {
		data.value = await api.forOrganization(await orgId())
	} catch (e) {
		error.value = authMessage(e)
	}
}

function startReply(r: Review) {
	replying.value = r.id
	replyText.value = r.reply?.text ?? ''
}

async function saveReply(r: Review) {
	busy.value = true
	error.value = ''
	try {
		await api.reply(await orgId(), r.id, replyText.value.trim())
		replying.value = null
		notice.value = 'Reply posted. It appears on your public profile.'
		await load()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function removeReply(r: Review) {
	if (!(await confirmDialog('Remove your reply from your public profile?', { confirmLabel: 'Remove reply' }))) return
	await api.removeReply(await orgId(), r.id)
	await load()
}

async function report(r: Review) {
	const reason = await promptDialog('Why should NaSeRy look at this review? (e.g. it isn\'t about your service, or contains personal attacks)', { title: 'Report this review', placeholder: 'At least 5 characters', minLength: 5, confirmLabel: 'Send report' })
	if (!reason) return
	try {
		await api.report(r.id, reason.trim())
		notice.value = 'Reported. A NaSeRy administrator will review it; it stays visible until they take the case.'
		await load()
	} catch (e) {
		error.value = authMessage(e)
	}
}

onMounted(load)
</script>
