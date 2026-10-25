<template>
	<div class="w-full space-y-6">
		<header class="fade-up relative overflow-hidden rounded-3xl border border-primary-100 bg-white px-6 py-7 shadow-sm sm:px-8" style="--d: 0ms">
			<span class="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full bg-amber-200/40 blur-3xl" />
			<span class="pointer-events-none absolute -bottom-20 left-1/3 h-56 w-56 rounded-full bg-primary-100/70 blur-3xl" />
			<div class="relative">
				<div class="mb-3 inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-primary-700">
					<IconBase name="star" class="h-3.5 w-3.5" />
					Reviews
				</div>
				<h1 class="text-2xl font-extrabold tracking-tight text-gray-950 sm:text-3xl">What your clients are saying</h1>
				<p class="mt-2 max-w-2xl text-sm leading-6 text-gray-500 sm:text-base">
					Every review from clients who booked you through NaSeRy, with the event it was for. Reply publicly to say thanks.
				</p>
			</div>
		</header>

		<p v-if="notice" class="rounded-xl border border-green-100 bg-green-50 px-4 py-3 text-sm text-green-800">{{ notice }}</p>
		<p v-if="errorMessage && data" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

		<div v-if="isLoading && !data" class="rounded-2xl border border-gray-200 bg-white p-12 text-center text-sm text-gray-500">
			Loading your reviews...
		</div>

		<div v-else-if="errorMessage && !data" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<div v-else-if="data && !data.summary.reviews_count" class="fade-up rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center" style="--d: 80ms">
			<span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-500">
				<IconBase name="star" class="h-7 w-7" />
			</span>
			<h2 class="mt-4 text-lg font-bold text-gray-900">No reviews yet</h2>
			<p class="mx-auto mt-1 max-w-md text-sm text-gray-500">
				After an event you ran through NaSeRy, your client can rate it and leave a comment. It will show up here with the event it was for.
			</p>
		</div>

		<template v-else-if="data">
			<!-- Summary -->
			<section class="grid grid-cols-1 gap-5 lg:grid-cols-12">
				<div class="fade-up rounded-2xl border border-gray-200 bg-white p-6 shadow-sm lg:col-span-3" style="--d: 60ms">
					<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Overall rating</p>
					<div class="mt-3 flex items-end gap-2">
						<span class="text-5xl font-extrabold leading-none tracking-tight text-gray-950 sm:text-6xl">{{ data.summary.average_rating.toFixed(1) }}</span>
						<span class="mb-1 text-sm font-semibold text-gray-400">/ 5</span>
					</div>
					<div class="mt-3 flex gap-0.5">
						<svg v-for="n in 5" :key="n" viewBox="0 0 20 20" class="h-5 w-5" :class="n <= Math.round(data.summary.average_rating) ? 'text-amber-400' : 'text-gray-200'" fill="currentColor">
							<path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9L10 1.5z" />
						</svg>
					</div>
					<p class="mt-3 text-sm text-gray-500">
						{{ data.summary.reviews_count }} published review{{ data.summary.reviews_count === 1 ? '' : 's' }}
					</p>
				</div>

				<div class="fade-up rounded-2xl border border-gray-200 bg-white p-6 shadow-sm lg:col-span-6" style="--d: 120ms">
					<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Rating breakdown</p>
					<ClientOnly>
						<apexchart type="bar" height="190" :options="distributionOptions" :series="distributionSeries" />
					</ClientOnly>
				</div>

				<div class="grid grid-cols-3 gap-4 lg:col-span-3 lg:grid-cols-1">
					<div v-for="(tile, index) in tiles" :key="tile.label" class="fade-up rounded-2xl border border-gray-200 bg-white p-4 shadow-sm lg:flex lg:items-center lg:gap-4" :style="{ '--d': 180 + index * 70 + 'ms' }">
						<span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl lg:flex" :class="tile.bg">
							<IconBase :name="tile.icon" class="h-5 w-5" :class="tile.color" />
						</span>
						<div>
							<p class="text-2xl font-extrabold tracking-tight text-gray-950">{{ tile.value }}</p>
							<p class="text-xs font-semibold text-gray-500">{{ tile.label }}</p>
						</div>
					</div>
				</div>
			</section>

			<!-- Events + reviews -->
			<section class="grid grid-cols-1 gap-5 lg:grid-cols-12">
				<aside class="fade-up lg:col-span-3" style="--d: 220ms">
					<div class="rounded-2xl border border-gray-200 bg-white p-3 shadow-sm lg:sticky lg:top-0">
						<p class="px-3 pb-2 pt-2 text-xs font-bold uppercase tracking-[0.1em] text-gray-400">By event</p>

						<button type="button" class="event-row flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm transition"
							:class="selectedEvent === null ? 'bg-primary-700 text-white shadow-md shadow-primary-700/20' : 'text-gray-700 hover:bg-gray-50'"
							@click="selectedEvent = null">
							<span class="font-bold">All events</span>
							<span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="selectedEvent === null ? 'bg-white/20' : 'bg-gray-100 text-gray-500'">{{ data.summary.reviews_count }}</span>
						</button>

						<div class="mt-1 max-h-[420px] space-y-1 overflow-y-auto">
							<button v-for="event in data.events" :key="event.id" type="button"
								class="event-row flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left transition"
								:class="selectedEvent === event.id ? 'bg-primary-700 text-white shadow-md shadow-primary-700/20' : 'text-gray-700 hover:bg-gray-50'"
								@click="selectedEvent = event.id">
								<span class="min-w-0">
									<span class="block truncate text-sm font-bold">{{ event.name }}</span>
									<span class="mt-0.5 flex items-center gap-1 text-xs" :class="selectedEvent === event.id ? 'text-white/75' : 'text-gray-400'">
										<svg viewBox="0 0 20 20" class="h-3 w-3" :class="selectedEvent === event.id ? 'text-amber-300' : 'text-amber-400'" fill="currentColor"><path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9L10 1.5z" /></svg>
										{{ event.average_rating.toFixed(1) }}
									</span>
								</span>
								<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold" :class="selectedEvent === event.id ? 'bg-white/20' : 'bg-gray-100 text-gray-500'">{{ event.reviews_count }}</span>
							</button>
						</div>
					</div>
				</aside>

				<div class="space-y-4 lg:col-span-9">
					<!-- Toolbar -->
					<div class="fade-up flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm xl:flex-row xl:items-center" style="--d: 260ms">
						<div class="relative flex-1">
							<IconBase name="search" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
							<input v-model="search" type="text" placeholder="Search by client, comment or event"
								class="w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-10 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15" />
						</div>

						<div class="flex flex-wrap items-center gap-2">
							<div class="flex rounded-xl bg-gray-100 p-1 text-xs font-bold">
								<button v-for="option in ratingOptions" :key="option.value" type="button" class="min-h-9 rounded-lg px-3 py-1.5 transition"
									:class="ratingFilter === option.value ? 'bg-white text-primary-700 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
									@click="ratingFilter = option.value">{{ option.label }}</button>
							</div>

							<select v-model="sort" class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-xs font-bold text-gray-700 focus:border-primary-500 focus:outline-none">
								<option value="newest">Newest first</option>
								<option value="highest">Highest rated</option>
								<option value="lowest">Lowest rated</option>
							</select>

							<label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-xs font-bold transition" :class="awaitingOnly ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-gray-200 text-gray-600 hover:bg-gray-50'">
								<input v-model="awaitingOnly" type="checkbox" class="h-3.5 w-3.5 rounded border-gray-300 text-primary-700 focus:ring-primary-500" />
								Waiting for a reply
								<span class="rounded-full bg-white/70 px-1.5 text-[11px]">{{ awaitingCount }}</span>
							</label>
						</div>
					</div>

					<p v-if="!filteredReviews.length" class="rounded-2xl border border-dashed border-gray-300 bg-white py-14 text-center text-sm text-gray-400">
						No reviews match these filters.
					</p>

					<TransitionGroup name="list" tag="div" class="space-y-4">
						<article v-for="review in filteredReviews" :key="review.id"
							class="review-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-200 hover:shadow-md sm:p-6">
							<div class="flex items-start gap-4">
								<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary-700 to-[#3b8693] text-sm font-extrabold text-white">
									{{ initials(review.reviewer) }}
								</span>

								<div class="min-w-0 flex-1">
									<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
										<div class="flex flex-wrap items-center gap-x-3 gap-y-1">
											<h3 class="text-sm font-extrabold text-gray-950">{{ review.reviewer }}</h3>
											<div class="flex gap-0.5">
												<svg v-for="n in 5" :key="n" viewBox="0 0 20 20" class="h-4 w-4" :class="n <= review.rating ? 'text-amber-400' : 'text-gray-200'" fill="currentColor">
													<path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9L10 1.5z" />
												</svg>
											</div>
										</div>
										<span class="flex items-center gap-2 text-xs font-medium text-gray-400">
											<span v-if="review.status === 'hidden'" class="rounded-full bg-gray-100 px-2 py-0.5 font-bold text-gray-600">Hidden by NaSeRy</span>
											<span v-else-if="review.open_reports" class="rounded-full bg-amber-50 px-2 py-0.5 font-bold text-amber-700">Reported</span>
											{{ formatDate(review.created_at) }}
										</span>
									</div>

									<span v-if="review.event" class="mt-2 inline-flex max-w-full items-center gap-1.5 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-700">
										<IconBase name="calendar" class="h-3.5 w-3.5 shrink-0" />
										<span class="truncate">{{ review.event.name }}</span>
										<span v-if="review.event.event_date" class="shrink-0 font-medium text-primary-700/70">· {{ formatDate(review.event.event_date) }}</span>
									</span>

									<p v-if="review.review" class="mt-3 text-sm leading-6 text-gray-700">{{ review.review }}</p>
									<p v-else class="mt-3 text-sm italic text-gray-400">Rated {{ review.rating }} star{{ review.rating === 1 ? '' : 's' }} with no written comment.</p>

									<!-- Reply -->
									<div v-if="review.reply && replyingId !== review.id" class="mt-4 rounded-xl border-l-4 border-primary-700 bg-gray-50 px-4 py-3">
										<p class="text-[11px] font-bold uppercase tracking-[0.1em] text-gray-400">Your public reply · {{ formatDate(review.replied_at!) }}</p>
										<p class="mt-1 text-sm leading-6 text-gray-700">{{ review.reply }}</p>
									</div>

									<div v-if="replyingId === review.id" class="mt-4">
										<textarea v-model="replyDraft" rows="3" maxlength="1000" placeholder="Write a public reply..."
											class="w-full resize-none rounded-xl border border-gray-200 bg-gray-50 p-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15" />
										<div class="mt-2 flex items-center justify-between gap-3">
											<span class="text-xs text-gray-400">{{ replyDraft.length }}/1000 · visible to everyone on your profile</span>
											<div class="flex gap-2">
												<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-bold text-gray-600 hover:bg-gray-50" :disabled="savingId === review.id" @click="cancelReply">Cancel</button>
												<button type="button" class="rounded-lg bg-primary-700 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-primary-800 disabled:opacity-60" :disabled="savingId === review.id || !replyDraft.trim()" @click="saveReply(review)">
													{{ savingId === review.id ? 'Posting...' : 'Post reply' }}
												</button>
											</div>
										</div>
										<p v-if="replyError" class="mt-2 text-xs font-semibold text-red-600">{{ replyError }}</p>
									</div>

									<div v-if="replyingId !== review.id" class="mt-4 flex items-center gap-4 text-xs font-bold">
										<button v-if="!review.reply" type="button" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg bg-primary-50 px-3.5 py-2 text-primary-700 transition hover:bg-primary-100" @click="startReply(review)">
											<IconBase name="message" class="h-3.5 w-3.5" />
											Reply
										</button>
										<template v-else>
											<button type="button" class="text-primary-700 hover:underline" @click="startReply(review)">Edit reply</button>
											<button type="button" class="text-red-600 hover:underline disabled:opacity-60" :disabled="savingId === review.id" @click="removeReply(review)">Remove reply</button>
										</template>
										<button v-if="!review.open_reports && review.status !== 'hidden'" type="button" class="tap-target ml-auto text-gray-400 hover:text-gray-600 hover:underline" @click="report(review)">Report to NaSeRy</button>
									</div>
								</div>
							</div>
						</article>
					</TransitionGroup>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ApexOptions } from 'apexcharts'

definePageMeta({ layout: 'dashboard', middleware: ['organizer'] })

// Our reviews (B16) mapped onto the redesign's shape.
const route = useRoute()
const reviewsApi = useReviews()
const notice = ref('')
let orgId = ''

interface Review {
	id: string
	status?: 'published' | 'hidden' | 'disputed'
	open_reports?: number
	rating: number
	review: string | null
	created_at: string
	reviewer: string
	event: { id: string, name: string, event_type: string | null, event_date: string | null } | null
	reply: string | null
	replied_at: string | null
}

interface ReviewsData {
	summary: { average_rating: number, reviews_count: number, distribution: Record<string, number>, awaiting_reply: number }
	events: Array<{ id: string, name: string, event_type: string | null, event_date: string | null, reviews_count: number, average_rating: number }>
	reviews: Review[]
}

const data = ref<ReviewsData | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')

const selectedEvent = ref<string | null>(null)
const ratingFilter = ref<number | 'all'>('all')
const sort = ref<'newest' | 'highest' | 'lowest'>('newest')
const search = ref('')
const awaitingOnly = ref(false)

const replyingId = ref<string | null>(null)
const replyDraft = ref('')
const replyError = ref('')
const savingId = ref<string | null>(null)

const ratingOptions: Array<{ label: string, value: number | 'all' }> = [
	{ label: 'All', value: 'all' },
	{ label: '5★', value: 5 },
	{ label: '4★', value: 4 },
	{ label: '3★', value: 3 },
	{ label: '2★', value: 2 },
	{ label: '1★', value: 1 },
]

function formatDate(value: string) {
	return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function initials(name: string) {
	return name.split(' ').filter(Boolean).slice(0, 2).map(part => part[0]?.toUpperCase()).join('') || 'C'
}

const awaitingCount = computed(() => (data.value?.reviews ?? []).filter(review => !review.reply).length)

const tiles = computed(() => {
	const summary = data.value?.summary
	const reviews = data.value?.reviews ?? []
	if (!summary || !reviews.length) return []

	const fiveStar = Math.round(((summary.distribution['5'] ?? 0) / Math.max(1, summary.reviews_count)) * 100)
	const replied = reviews.length - awaitingCount.value
	const replyRate = Math.round((replied / reviews.length) * 100)

	return [
		{ label: '5-star reviews', value: `${fiveStar}%`, icon: 'award', bg: 'bg-amber-50', color: 'text-amber-600' },
		{ label: 'Reply rate', value: `${replyRate}%`, icon: 'message', bg: 'bg-primary-50', color: 'text-primary-700' },
		{ label: 'Awaiting reply', value: String(awaitingCount.value), icon: 'clock', bg: 'bg-gray-100', color: 'text-gray-600' },
	]
})

const distributionSeries = computed(() => [{
	name: 'Reviews',
	data: [5, 4, 3, 2, 1].map(star => data.value?.summary.distribution[String(star)] ?? 0),
}])

const distributionOptions = computed<ApexOptions>(() => ({
	chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit', animations: { speed: 700 } },
	colors: ['#285F6b'],
	plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 6, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
	dataLabels: { enabled: true, textAnchor: 'start', offsetX: 8, style: { fontSize: '12px', fontWeight: 700, colors: ['#285F6b'] } },
	grid: { show: false, padding: { left: 0, right: 14, top: -10, bottom: -10 } },
	xaxis: {
		categories: ['5 ★', '4 ★', '3 ★', '2 ★', '1 ★'],
		labels: { show: false },
		axisBorder: { show: false },
		axisTicks: { show: false },
		max: Math.max(...Object.values(data.value?.summary.distribution ?? { 0: 1 }), 1) * 1.15,
	},
	yaxis: { labels: { style: { colors: '#6b7280', fontSize: '13px', fontWeight: 700 } } },
	tooltip: { y: { formatter: (value: number) => `${value} review${value === 1 ? '' : 's'}` } },
	states: { hover: { filter: { type: 'none' } } },
}))

const filteredReviews = computed(() => {
	const query = search.value.trim().toLowerCase()

	const list = (data.value?.reviews ?? []).filter((review) => {
		if (selectedEvent.value !== null && review.event?.id !== selectedEvent.value) return false
		if (ratingFilter.value !== 'all' && review.rating !== ratingFilter.value) return false
		if (awaitingOnly.value && review.reply) return false
		if (!query) return true

		return [review.reviewer, review.review ?? '', review.event?.name ?? '']
			.some(value => value.toLowerCase().includes(query))
	})

	return [...list].sort((a, b) => {
		if (sort.value === 'highest') return b.rating - a.rating || b.created_at.localeCompare(a.created_at)
		if (sort.value === 'lowest') return a.rating - b.rating || b.created_at.localeCompare(a.created_at)
		return b.created_at.localeCompare(a.created_at)
	})
})

function startReply(review: Review) {
	replyingId.value = review.id
	replyDraft.value = review.reply ?? ''
	replyError.value = ''
}

function cancelReply() {
	replyingId.value = null
	replyDraft.value = ''
	replyError.value = ''
}

function applyUpdate(updated: Review) {
	if (!data.value) return
	const index = data.value.reviews.findIndex(review => review.id === updated.id)
	if (index !== -1) data.value.reviews[index] = updated
}

async function saveReply(review: Review) {
	if (!replyDraft.value.trim()) return
	savingId.value = review.id
	replyError.value = ''
	try {
		await reviewsApi.reply(orgId, review.id, replyDraft.value.trim())
		cancelReply()
		notice.value = 'Reply posted. It appears on your public profile.'
		await loadReviews()
	} catch (error: unknown) {
		replyError.value = Object.values(fieldErrors(error))[0] ?? (authMessage(error) || 'Unable to post your reply.')
	} finally {
		savingId.value = null
	}
}

async function removeReply(review: Review) {
	if (!(await confirmDialog('Remove your public reply to this review?', { confirmLabel: 'Remove reply' }))) return
	savingId.value = review.id
	try {
		await reviewsApi.removeReply(orgId, review.id)
		await loadReviews()
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to remove your reply.'
	} finally {
		savingId.value = null
	}
}

/** Ask NaSeRy to look at a review (it stays visible until an admin decides). */
async function report(review: Review) {
	const reason = await promptDialog('Why should NaSeRy look at this review? (e.g. it isn\'t about your service, or contains personal attacks)', { title: 'Report this review', placeholder: 'At least 5 characters', minLength: 5, confirmLabel: 'Send report' })
	if (!reason) return
	try {
		await reviewsApi.report(review.id, reason.trim())
		notice.value = 'Reported. A NaSeRy administrator will review it; it stays visible until they take the case.'
		await loadReviews()
	} catch (error: unknown) {
		errorMessage.value = authMessage(error)
	}
}

async function loadReviews(quiet = false) {
	if (!quiet) isLoading.value = !data.value
	errorMessage.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId = org.id
		const ours = await reviewsApi.forOrganization(orgId)
		const reviews: Review[] = ours.reviews.map(r => ({
			id: r.id, status: r.status, open_reports: r.open_reports,
			rating: r.rating, review: r.written_review, created_at: r.created_at, reviewer: r.reviewer || 'Client',
			event: { id: r.event_id, name: r.event_name || 'Event', event_type: r.event_type ?? null, event_date: null },
			reply: r.reply?.text ?? null, replied_at: r.reply?.at ?? null,
		}))
		const byEvent = new Map<string, { id: string, name: string, event_type: string | null, event_date: string | null, ratings: number[] }>()
		for (const r of reviews) {
			const e = r.event!
			if (!byEvent.has(e.id)) byEvent.set(e.id, { ...e, ratings: [] })
			byEvent.get(e.id)!.ratings.push(r.rating)
		}
		const distribution: Record<string, number> = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 }
		for (const [k, v] of Object.entries(ours.summary.distribution ?? {})) distribution[String(k)] = Number(v)
		data.value = {
			summary: { average_rating: Number(ours.summary.average ?? 0), reviews_count: ours.summary.count, distribution, awaiting_reply: ours.summary.awaiting_reply },
			events: [...byEvent.values()].map(e => ({ id: e.id, name: e.name, event_type: e.event_type, event_date: e.event_date, reviews_count: e.ratings.length, average_rating: e.ratings.reduce((a, b) => a + b, 0) / e.ratings.length })),
			reviews,
		}
		const wanted = typeof route.query.event === 'string' ? route.query.event : null
		if (wanted && data.value.events.some(event => event.id === wanted)) selectedEvent.value = wanted
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load your reviews.'
	} finally {
		isLoading.value = false
	}
}

onMounted(() => {
	loadReviews()
})
// Close to live (review #12): other people's changes appear without a reload.
useLiveRefresh(() => loadReviews(true), { interval: 60000 })
</script>

<style scoped>
.fade-up {
	animation: fade-up 0.6s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.list-enter-active, .list-leave-active, .list-move {
	transition: opacity 0.3s ease, transform 0.3s ease;
}

.list-enter-from, .list-leave-to {
	opacity: 0;
	transform: translateY(10px);
}

.list-leave-active {
	position: absolute;
	width: 100%;
}

@keyframes fade-up {
	from { opacity: 0; transform: translateY(16px); }
	to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
	.fade-up { animation: none; }
	.list-enter-active, .list-leave-active, .list-move { transition: none; }
}
</style>
