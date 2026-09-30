<template>
	<div class="mx-auto max-w-5xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Moderation</h1>
		<p class="mt-1 text-gray-500">Reported reviews and refund fee claims. Every decision is logged and the people involved are notified.</p>

		<div class="mt-5 flex gap-1 border-b border-gray-200" role="tablist">
			<button v-for="t in mainTabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
				class="-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold"
				:class="tab === t.key ? 'border-primary-700 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-800'"
				@click="setTab(t.key)">
				{{ t.label }} <span v-if="t.count" class="ml-1 rounded-full bg-amber-500 px-1.5 text-xs text-white">{{ t.count }}</span>
			</button>
		</div>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />

		<!-- Reviews -->
		<template v-if="tab === 'reviews'">
			<div class="mt-4 flex flex-wrap gap-2">
				<button v-for="q in queues" :key="q.key" type="button" class="rounded-full border px-3 py-1.5 text-sm font-semibold"
					:class="queue === q.key ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-700'"
					@click="queue = q.key; loadReviews()">
					{{ q.label }}<template v-if="q.count !== undefined"> · {{ q.count }}</template>
				</button>
			</div>
			<p class="mt-3 text-xs text-gray-500">{{ queueHelp[queue] }}</p>

			<div v-if="reviewsLoading" class="py-10 text-center text-sm text-gray-400">Loading…</div>
			<p v-else-if="!reviews.length" class="mt-4 rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center text-sm text-gray-500">Nothing here.</p>

			<article v-for="r in reviews" :key="r.id" class="mt-4 rounded-2xl border border-gray-200 bg-white p-5">
				<div class="flex flex-wrap items-start justify-between gap-2">
					<div>
						<StarRating :model-value="r.rating" readonly />
						<p class="text-xs text-gray-500">{{ r.reviewer }} on
							<NuxtLink :to="`/organizers/${r.organizer.slug}`" class="font-semibold text-primary-700" target="_blank">{{ r.organizer.name }}</NuxtLink>
							· {{ r.event_name }} · {{ shortDate(r.created_at) }}</p>
					</div>
					<span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="reviewBadge[r.status]">{{ reviewLabel[r.status] }}</span>
				</div>
				<p class="mt-2 whitespace-pre-line text-sm text-gray-800">{{ r.written_review || 'Rating only, no text.' }}</p>
				<p v-if="r.reply" class="mt-2 border-l-4 border-gray-200 pl-3 text-sm text-gray-600">Organizer's reply: {{ r.reply }}</p>

				<div class="mt-3 rounded-xl bg-amber-50/60 p-3">
					<p class="text-xs font-bold uppercase tracking-wide text-amber-800">Reports</p>
					<ul class="mt-1 space-y-1 text-sm">
						<li v-for="(p, i) in r.reports" :key="i" class="text-gray-800">“{{ p.reason }}” <span class="text-xs text-gray-500">— {{ p.by }} ({{ p.by_role }}), {{ shortDate(p.at) }} · {{ reportStatus[p.status] ?? p.status }}</span></li>
					</ul>
				</div>
				<ul v-if="r.history.length" class="mt-2 space-y-0.5 text-xs text-gray-500">
					<li v-for="(h, i) in r.history" :key="i">{{ dateTime(h.at) }} · {{ h.by ?? 'Admin' }}: {{ h.action ? historyLabel[h.action] ?? h.action : 'took the case' }}<template v-if="h.note"> — “{{ h.note }}”</template></li>
				</ul>

				<form v-if="acting === r.id" class="mt-3 space-y-2" @submit.prevent="submit(r)">
					<label :for="`why-${r.id}`" class="text-xs font-semibold text-gray-600">{{ actionPrompt[action] }}</label>
					<textarea :id="`why-${r.id}`" v-model="why" rows="2" maxlength="1000" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
					<div class="flex gap-2">
						<button type="submit" :disabled="busy || (action !== 'start' && why.trim().length < 5)" class="rounded-lg bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">{{ actionButton[action] }}</button>
						<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm" @click="acting = null">Cancel</button>
					</div>
				</form>
				<div v-else class="mt-3 flex flex-wrap gap-2 text-sm font-semibold">
					<button v-if="r.status === 'published' && r.reports.some(p => p.status === 'open')" type="button" class="rounded-lg border border-gray-200 px-3 py-1.5" @click="startAction(r.id, 'start')">Take the case (hide meanwhile)</button>
					<template v-if="r.status === 'disputed' || (r.status === 'published' && r.reports.some(p => p.status === 'open'))">
						<button type="button" class="rounded-lg bg-green-600 px-3 py-1.5 text-white" @click="startAction(r.id, 'keep')">Keep review</button>
						<button type="button" class="rounded-lg bg-rose-600 px-3 py-1.5 text-white" @click="startAction(r.id, 'hide')">Hide review</button>
					</template>
					<button v-if="r.status === 'hidden'" type="button" class="rounded-lg border border-gray-200 px-3 py-1.5" @click="startAction(r.id, 'restore')">Restore</button>
				</div>
			</article>
		</template>

		<!-- Fee claims -->
		<template v-else>
			<p class="mt-4 text-sm text-gray-600">Organizers choose the refund reason themselves. “Force majeure” and “Payment error” return NaSeRy's platform fee, so we check those. Flagging tells the owners and keeps it on record; no money moves.</p>
			<div class="mt-3 flex flex-wrap gap-2">
				<button v-for="s in claimFilters" :key="s.key" type="button" class="rounded-full border px-3 py-1.5 text-sm font-semibold"
					:class="claimStatus === s.key ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-700'"
					@click="claimStatus = s.key; loadClaims()">{{ s.label }}</button>
			</div>
			<div v-if="claimsLoading" class="py-10 text-center text-sm text-gray-400">Loading…</div>
			<p v-else-if="!claims.length" class="mt-4 rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center text-sm text-gray-500">Nothing here.</p>

			<article v-for="c in claims" :key="c.id" class="mt-4 rounded-2xl border border-gray-200 bg-white p-5">
				<div class="flex flex-wrap items-start justify-between gap-2">
					<div>
						<p class="font-semibold text-gray-900">{{ c.organizer.name }} · {{ c.event.name }}</p>
						<p class="text-xs text-gray-500">{{ dateTime(c.refunded_at) }} · recorded by {{ c.recorded_by }} · event {{ c.event.status }}</p>
					</div>
					<div class="text-right">
						<p class="text-lg font-extrabold text-gray-900">{{ peso(c.amount) }}</p>
						<p class="text-xs text-gray-500">of {{ peso(c.payment_amount) }} · fee returned {{ c.fee_returned !== null ? peso(c.fee_returned) : '—' }}</p>
					</div>
				</div>
				<p class="mt-2 text-sm"><span class="rounded bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-800">{{ c.category }}</span> <span class="text-gray-800">{{ c.reason }}</span></p>

				<p v-if="c.review" class="mt-3 text-sm" :class="c.review.outcome === 'flagged' ? 'text-rose-700' : 'text-green-700'">
					{{ c.review.outcome === 'flagged' ? 'Flagged' : 'Accepted' }} by {{ c.review.by }} on {{ shortDate(c.review.at) }}<template v-if="c.review.note"> — “{{ c.review.note }}”</template>
				</p>
				<form v-else-if="flagging === c.id" class="mt-3 space-y-2" @submit.prevent="reviewClaim(c.id, 'flagged')">
					<label :for="`flag-${c.id}`" class="text-xs font-semibold text-gray-600">Why doesn't the reason hold? (sent to the owners)</label>
					<textarea :id="`flag-${c.id}`" v-model="why" rows="2" maxlength="1000" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
					<div class="flex gap-2">
						<button type="submit" :disabled="busy || why.trim().length < 5" class="rounded-lg bg-rose-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">Flag claim</button>
						<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm" @click="flagging = null">Cancel</button>
					</div>
				</form>
				<div v-else class="mt-3 flex gap-2 text-sm font-semibold">
					<button type="button" :disabled="busy" class="rounded-lg bg-green-600 px-3 py-1.5 text-white" @click="reviewClaim(c.id, 'accepted')">Reason holds</button>
					<button type="button" class="rounded-lg border border-rose-200 px-3 py-1.5 text-rose-700" @click="flagging = c.id; why = ''">Flag</button>
				</div>
			</article>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { FeeClaim, ModeratedReview } from '~/composables/useAdmin'

definePageMeta({ layout: 'superadmin' })

type Queue = 'open' | 'in_review' | 'hidden' | 'closed'
type Action = 'start' | 'keep' | 'hide' | 'restore'

const route = useRoute()
const admin = useAdmin()
const tab = ref<'reviews' | 'claims'>(route.query.tab === 'claims' ? 'claims' : 'reviews')
const queue = ref<Queue>('open')
const reviews = ref<ModeratedReview[]>([])
const counts = ref<{ open: number, in_review: number, hidden: number } | null>(null)
const claims = ref<FeeClaim[]>([])
const claimCounts = ref<{ pending: number, flagged: number } | null>(null)
const claimStatus = ref<'pending' | 'flagged' | 'accepted' | 'all'>('pending')
const reviewsLoading = ref(false)
const claimsLoading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const acting = ref<string | null>(null)
const action = ref<Action>('start')
const flagging = ref<string | null>(null)
const why = ref('')

const mainTabs = computed(() => [
	{ key: 'reviews' as const, label: 'Reported reviews', count: (counts.value?.open ?? 0) + (counts.value?.in_review ?? 0) },
	{ key: 'claims' as const, label: 'Refund fee claims', count: claimCounts.value?.pending ?? 0 },
])
const queues = computed(() => [
	{ key: 'open' as Queue, label: 'New reports', count: counts.value?.open },
	{ key: 'in_review' as Queue, label: 'Under review', count: counts.value?.in_review },
	{ key: 'hidden' as Queue, label: 'Hidden', count: counts.value?.hidden },
	{ key: 'closed' as Queue, label: 'Kept' },
])
const claimFilters = [
	{ key: 'pending' as const, label: 'To check' }, { key: 'flagged' as const, label: 'Flagged' },
	{ key: 'accepted' as const, label: 'Accepted' }, { key: 'all' as const, label: 'All' },
]
const queueHelp: Record<Queue, string> = {
	open: 'Reported, still visible. Take the case to hide it while you check, or decide right away.',
	in_review: 'Hidden from the profile and rating until you keep or hide it.',
	hidden: 'Hidden by a decision. Restore if it was a mistake.',
	closed: 'Reports looked into; the review stayed.',
}
const reviewLabel = { published: 'Visible', disputed: 'Under review · hidden', hidden: 'Hidden' }
const reviewBadge = { published: 'bg-green-50 text-green-700', disputed: 'bg-amber-50 text-amber-800', hidden: 'bg-gray-100 text-gray-700' }
const reportStatus: Record<string, string> = { open: 'new', in_review: 'being looked into', resolved: 'upheld', dismissed: 'dismissed' }
const historyLabel: Record<string, string> = { kept: 'kept it', hidden: 'hid it', restored: 'restored it' }
const actionPrompt: Record<Action, string> = {
	start: 'Note for the record (optional)', keep: 'Why does it stay? (sent to the reporters and the organizer)',
	hide: 'Why is it hidden? (sent to the client and the organizer)', restore: 'Why restore it?',
}
const actionButton: Record<Action, string> = { start: 'Take the case', keep: 'Keep review', hide: 'Hide review', restore: 'Restore review' }

function setTab(t: 'reviews' | 'claims') {
	tab.value = t
	navigateTo({ query: t === 'claims' ? { tab: 'claims' } : {} }, { replace: true })
	t === 'claims' ? loadClaims() : loadReviews()
}

async function loadReviews() {
	reviewsLoading.value = true
	try {
		const r = await admin.reportedReviews(queue.value)
		reviews.value = r.items
		counts.value = r.counts
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		reviewsLoading.value = false
	}
}

async function loadClaims() {
	claimsLoading.value = true
	try {
		const r = await admin.feeClaims(claimStatus.value)
		claims.value = r.items
		claimCounts.value = r.counts
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		claimsLoading.value = false
	}
}

function startAction(id: string, a: Action) {
	acting.value = id
	action.value = a
	why.value = ''
}

async function submit(r: ModeratedReview) {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		const reason = why.value.trim()
		if (action.value === 'start') await admin.startReview(r.id, reason)
		else if (action.value === 'restore') await admin.restoreReview(r.id, reason)
		else await admin.decideReview(r.id, action.value, reason)
		notice.value = { start: 'Case taken. The review is hidden until you decide.', keep: 'Review kept. Everyone involved was told.', hide: 'Review hidden. Everyone involved was told.', restore: 'Review restored.' }[action.value]
		acting.value = null
		// Follow the review to where it now lives.
		queue.value = ({ start: 'in_review', keep: 'closed', hide: 'hidden', restore: 'closed' } as const)[action.value]
		await loadReviews()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function reviewClaim(id: string, outcome: 'accepted' | 'flagged') {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		await admin.reviewFeeClaim(id, outcome, outcome === 'flagged' ? why.value.trim() : undefined)
		notice.value = outcome === 'flagged' ? 'Claim flagged. The owners were told.' : 'Claim accepted.'
		flagging.value = null
		await loadClaims()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	// Both lists load together: the tab badges need both counts.
	await Promise.all([loadReviews(), tab.value === 'claims' ? loadClaims() : admin.feeClaims('pending').then((r) => { claimCounts.value = r.counts }).catch(() => {})])
})
</script>
