<template>
	<div v-if="status === 'pending'" class="mx-auto max-w-7xl animate-pulse px-5 pt-10 sm:px-8" aria-live="polite"
		aria-label="Loading organizer profile">
		<div class="h-64 rounded-2xl bg-gray-200" />
		<div class="mt-8 grid gap-8 lg:grid-cols-[1fr_320px]">
			<div class="h-72 rounded-2xl bg-gray-200" />
			<div class="h-72 rounded-2xl bg-gray-100" />
		</div>
	</div>
	<div v-else-if="error" class="mx-auto max-w-2xl px-5 py-20 text-center" role="alert">
		<h1 class="text-2xl font-bold">
			We couldn’t load this organizer
		</h1>
		<p class="mt-3 text-sm text-gray-500">
			Check that the NaSeRy API is running, then try again.
		</p>
		<button class="mt-6 rounded-lg bg-primary-700 px-5 py-3 text-sm font-bold text-white" @click="refresh()">
			Try again
		</button>
	</div>
	<div v-else-if="organizer" class="mx-auto max-w-7xl px-5 pt-7 sm:px-8">
		<NuxtLink to="/organizers"
			class="inline-flex items-center gap-2 text-sm font-semibold text-primary-700 hover:underline">
			<span aria-hidden="true">←</span> All organizers
		</NuxtLink>
		<section class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
			<PortfolioCover :color="organizer.banner_color" :label="organizer.name" :image="organizer.cover_url" />
			<div class="flex flex-col justify-between gap-4 p-6 sm:gap-6 sm:p-8 lg:flex-row lg:items-center">
				<div class="flex items-start gap-4"><span
						class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl text-base font-bold text-white sm:h-16 sm:w-16 sm:text-xl"
						:class="organizer.logo_url ? 'border border-gray-200 bg-white' : ''"
						:style="organizer.logo_url ? {} : { backgroundColor: organizer.banner_color }">
						<img v-if="organizer.logo_url" :src="organizer.logo_url" :alt="`${organizer.name} logo`" class="h-full w-full bg-white object-contain">
						<template v-else>{{ initials(organizer.name) }}</template>
					</span>
					<div>
						<h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ organizer.name }}</h1>
						<p class="mt-2 text-sm text-gray-500">{{ organizer.location || 'Location not added' }}</p>
						<div class="mt-3 flex flex-wrap gap-2">
							<span v-for="tag in organizer.specialties" :key="tag"
								class="rounded-md bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700">
								{{ tag }}
							</span>
						</div>
						<p v-if="organizer.reviews_count" class="mt-3 text-sm font-semibold text-amber-700">
							★ {{ organizer.average_rating.toFixed(1) }} · {{ organizer.reviews_count }}
							{{
								organizer.reviews_count === 1 ?
									'review' : 'reviews' }}
						</p>
					</div>
				</div>
				<div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:gap-3 lg:shrink-0 lg:flex-nowrap lg:self-auto">
					<a href="#past-events"
						class="inline-flex min-h-11 items-center justify-center rounded-lg border border-gray-200 px-3 text-center text-sm font-semibold hover:bg-gray-50 sm:px-5">
						Explore past work ↓
					</a>
					<button v-if="profile?.price_options.length" type="button"
						class="inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-primary-200 bg-primary-50 px-3 text-center text-sm font-bold text-primary-800 hover:bg-primary-100 sm:px-5"
						@click="showCalculator = true">
						<IconBase name="peso" class="h-4 w-4" />Estimate your price
					</button>
					<button v-if="canRequestQuote" type="button"
						class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary-700 px-3 text-center text-sm font-bold text-white hover:bg-primary-900 sm:px-5"
						:class="profile?.price_options.length ? 'col-span-2 sm:col-span-1' : ''" @click="request()">
						Request a quotation
					</button>
				</div>
			</div>
		</section>
		<div class="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_320px]">
			<div class="space-y-8">
				<section aria-labelledby="about-heading" class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
					<p class="mb-3 text-xs font-bold uppercase tracking-widest text-primary-700">
						Meet your event team
					</p>
					<h2 id="about-heading" class="text-xl font-bold">
						About {{ organizer.name }}
					</h2>
					<p class="mt-4 text-sm leading-7 text-gray-600">
						{{ organizer.bio || 'This organizer has not added an introduction yet.' }}
					</p>
					<p v-if="organizer.years_experience" class="mt-4 text-sm font-semibold text-primary-800">
						Experience:
						{{ organizer.years_experience }}
					</p>
					<div v-if="organizer.website || organizer.facebook || organizer.instagram"
						class="mt-5 flex flex-wrap gap-4 text-sm font-semibold">
						<a v-if="organizer.website && /^https?:\/\//i.test(organizer.website)" :href="organizer.website" target="_blank" rel="noopener noreferrer"
							class="text-primary-700 underline">
							Website ↗
						</a>
						<a v-if="socialLink('facebook', organizer.facebook)" :href="socialLink('facebook', organizer.facebook)!" target="_blank"
							rel="noopener noreferrer" class="text-primary-700 underline">
							Facebook ↗
						</a>
						<a v-if="socialLink('instagram', organizer.instagram)" :href="socialLink('instagram', organizer.instagram)!" target="_blank"
							rel="noopener noreferrer" class="text-primary-700 underline">
							Instagram ↗
						</a>
					</div>
				</section>
				<!-- Field test follow-up (Nov 2026): packages first, each one opens in full; the calculator is its own button. -->
				<section v-if="profile?.price_options.length" id="packages" aria-labelledby="packages-heading" class="scroll-mt-24 rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
					<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
						<div>
							<p class="mb-2 text-xs font-bold uppercase tracking-widest text-primary-700">What they offer</p>
							<h2 id="packages-heading" class="text-xl font-bold">Packages & prices</h2>
							<p class="mt-1 text-sm text-gray-500">Tap a package to see its pictures and everything it includes.</p>
						</div>
						<button type="button" class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-primary-200 bg-primary-50 px-4 text-sm font-bold text-primary-800 hover:bg-primary-100" @click="showCalculator = true">
							<IconBase name="peso" class="h-4 w-4" />Estimate your price<template v-if="pickCount"> ({{ pickCount }})</template>
						</button>
					</div>
					<PricingServiceShowcase :options="profile.price_options" :services="profile.services" :picks="picks" @open="openId = $event" />
				</section>

				<section aria-labelledby="services-heading"
					class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
					<h2 id="services-heading" class="text-xl font-bold">Services & expertise</h2>
					<p class="mt-2 text-sm text-gray-500">Specialties published by this organizer.</p>
					<div v-if="organizer.specialties.length" class="mt-6 grid gap-3 sm:grid-cols-2">
						<div v-for="(service, index) in organizer.specialties" :key="service"
							class="flex items-center gap-4 rounded-xl bg-primary-50 p-4"><span
								class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-xs font-bold text-primary-700">{{
									String(index + 1).padStart(2, '0') }}</span>
							<h3 class="text-sm font-bold">{{ service }}</h3>
						</div>
					</div>
					<p v-else class="mt-6 text-sm text-gray-500">No specialties have been published yet.</p>
					<div v-if="organizer.tags?.length" class="mt-6 flex flex-wrap gap-2"><span
							v-for="tag in organizer.tags" :key="tag"
							class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600">{{ tag }}</span>
					</div>
				</section>
				<section id="past-events" aria-labelledby="work-heading" class="scroll-mt-24">
					<div class="mb-5 flex items-center justify-between gap-3">
						<h2 id="work-heading" class="text-xl font-bold">Past events & portfolio</h2>
						<span class="text-sm text-gray-500">
							{{ organizer.events.length }} completed
							{{
								organizer.events.length === 1 ?
									'event' : 'events'
							}}
						</span>
					</div>
					<div v-if="organizer.events.length" class="grid gap-5 sm:grid-cols-2">
						<article v-for="event in shownEvents" :key="event.id"
							class="overflow-hidden rounded-xl border border-gray-200 bg-white">
							<PortfolioCover :color="organizer.banner_color" :label="event.title" :image="event.cover_url" compact />
							<div class="p-5">
								<p class="text-xs font-semibold text-primary-700">
									{{ event.category }}
									<template v-if="event.year">
										· {{ event.year }}
									</template>
								</p>
								<h3 class="mt-2 font-bold">{{ event.title }}</h3>
								<p v-if="event.venue" class="mt-2 text-xs text-gray-500">{{ event.venue }}</p>
								<details v-if="event.description" class="mt-4 border-t border-gray-100 pt-3">
									<summary class="cursor-pointer text-sm font-semibold text-primary-700">
										Project overview
									</summary>
									<p class="mt-3 text-sm leading-relaxed text-gray-600">{{ event.description }}</p>
								</details>
							</div>
						</article>
					</div>
					<button v-if="organizer.events.length > shownEvents.length" type="button"
						class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-gray-200 bg-white text-sm font-semibold text-primary-700 hover:bg-gray-50"
						@click="eventsShown = organizer.events.length">Show all {{ organizer.events.length }} past events</button>
					<div v-else-if="!organizer.events.length"
						class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center text-sm text-gray-500">
						This organizer has not published any completed events yet.
					</div>
				</section>

				<section aria-labelledby="reviews-heading" class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
					<h2 id="reviews-heading" class="text-xl font-bold">Client reviews</h2>
					<p v-if="!reviews.length" class="mt-4 text-sm text-gray-500">No reviews yet. Reviews come only from clients who completed an event with this organizer.</p>
					<ul v-else class="mt-5 space-y-5">
						<li v-for="r in reviews.slice(0, reviewsShown)" :key="r.id" class="border-b border-gray-100 pb-5 last:border-0">
							<p class="text-sm font-semibold text-amber-700" :aria-label="`${r.rating} out of 5`">{{ '★'.repeat(r.rating) }}<span class="text-gray-300">{{ '★'.repeat(5 - r.rating) }}</span></p>
							<p v-if="r.written_review" class="mt-2 text-sm text-gray-700">{{ r.written_review }}</p>
							<p class="mt-2 text-xs text-gray-400">
								{{ r.reviewer }}<span v-if="r.event_type"> · {{ r.event_type }}</span> · {{ new Date(r.created_at).toLocaleDateString('en-PH', { dateStyle: 'medium' }) }}<span v-if="r.edited"> · edited</span>
								<button v-if="user" type="button" class="ml-2 underline hover:text-gray-700" @click="reportReview(r.id)">Report</button>
							</p>
							<div v-if="r.reply" class="mt-3 rounded-lg border-l-4 border-primary-700 bg-gray-50 p-3 text-sm">
								<p class="text-xs font-bold uppercase tracking-wide text-gray-400">Reply from {{ organizer.name }}</p>
								<p class="mt-1 whitespace-pre-line text-gray-700">{{ r.reply.text }}</p>
							</div>
						</li>
					</ul>
					<p v-if="reviewNotice" class="mt-4 rounded-lg bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-800">{{ reviewNotice }}</p>
					<button v-if="reviews.length > reviewsShown || (reviewPage && reviewPage.current_page < reviewPage.last_page)" type="button"
						class="mt-4 inline-flex min-h-10 items-center text-sm font-semibold text-primary-700" @click="moreReviews">Show more reviews</button>
				</section>
			</div>
			<aside class="rounded-2xl border border-primary-100 bg-primary-50 p-7 lg:sticky lg:top-24">
				<p class="text-xs font-bold uppercase tracking-widest text-primary-700">
					Your next occasion
				</p>
				<h2 class="mt-4 text-2xl font-bold leading-tight text-primary-900">
					Inspired by what<br />you see?
				</h2>
				<p class="mt-4 text-sm leading-relaxed text-gray-600">
					Ask this team for a quotation, or post your event to receive
					offers from matching organizers. Asking doesn’t book anyone.
				</p>
				<template v-if="canRequestQuote">
					<button type="button" class="mt-6 block w-full rounded-lg bg-primary-700 px-5 py-3 text-center text-sm font-bold text-white hover:bg-primary-900" @click="request()">
						Request a quotation from {{ organizer.name }}
					</button>
					<button v-if="profile?.price_options.length" type="button"
						class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg border border-primary-200 bg-white px-5 py-3 text-center text-sm font-bold text-primary-800 hover:bg-primary-50" @click="showCalculator = true">
						<IconBase name="peso" class="h-4 w-4" />Estimate your price<template v-if="pickCount"> ({{ pickCount }})</template>
					</button>
					<button type="button" class="mt-2 block w-full rounded-lg px-5 py-2 text-center text-sm font-semibold text-primary-800 underline-offset-2 hover:underline" @click="request({}, false)">
						Or post to nearby organizers
					</button>
					<p class="mt-3 text-center text-xs text-gray-500">
						{{
							user?.role === 'client' ?
								'Share your vision in the inquiry form.' :
								'You’ll be asked to sign in as a client.'
						}}
					</p>
				</template>
				<p v-else class="mt-5 text-sm font-medium text-primary-800">
					Event inquiries are posted from client accounts.
				</p>
				<div class="mt-7 border-t border-primary-200 pt-5">
					<h3 class="text-sm font-semibold">
						Still exploring?
					</h3>
					<NuxtLink to="/organizers" class="mt-2 inline-block text-sm text-primary-700 underline">
						Browse other organizers
					</NuxtLink>
				</div>
			</aside>
		</div>

		<!-- One package in full -->
		<PricingPackageOverlay v-if="openOption && openGroup" :option="openOption" :siblings="openGroup.options" :group-label="openGroup.label"
			:picked="openOption.id in picks" :can-request="canRequestQuote" :organizer-name="organizer.name" :select-mode="fromCalculator"
			@close="openId = null; fromCalculator = false" @open="openId = $event" @toggle="togglePick" @request="requestOne" />

		<!-- The calculator, in its own panel (full screen on phones) -->
		<Teleport to="body">
			<div v-if="showCalculator && profile" class="fixed inset-0 z-[150] flex justify-end bg-gray-950/60" role="dialog" aria-modal="true" aria-labelledby="calculator-heading"
				@click.self="showCalculator = false" @keydown.esc="showCalculator = false">
				<!-- Wide screens: hovering an option previews it here (pictures cycle); "Details" opens it in full. -->
				<!-- Centred in the space left of the panel (42rem wide), as large as that space allows. -->
				<div v-if="hoverOption && !openId" class="pointer-events-none fixed left-[calc((100vw-42rem)/2)] top-1/2 hidden max-h-[90dvh] w-[min(44rem,calc(100vw-42rem-4rem))] -translate-x-1/2 -translate-y-1/2 overflow-hidden rounded-3xl bg-white shadow-2xl xl:block" aria-hidden="true">
					<div v-if="hoverImages.length" class="relative aspect-[4/3] max-h-[62dvh] w-full bg-gray-950">
						<PricingImageCycle v-model:index="hoverShown" :images="hoverImages" autoplay fit="contain" />
						<span v-if="hoverImages.length > 1" class="absolute bottom-3 right-3 rounded-full bg-gray-900/70 px-2.5 py-0.5 text-xs font-semibold text-white">{{ hoverShown + 1 }} / {{ hoverImages.length }}</span>
					</div>
					<div class="p-5">
						<p class="text-lg font-bold text-gray-900">{{ hoverOption.name }}</p>
						<p class="font-bold text-primary-800">{{ formatPeso(hoverOption.price) }}<span class="text-sm font-normal text-gray-500">{{ priceSuffix(hoverOption) }}</span></p>
						<p v-if="hoverOption.description" class="mt-2 line-clamp-3 text-sm text-gray-600">{{ hoverOption.description }}</p>
						<ul v-if="hoverOption.includes?.length" class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1">
							<li v-for="(line, i) in hoverOption.includes.slice(0, 8)" :key="i" class="flex items-start gap-1.5 text-xs text-gray-600"><IconBase name="check" class="mt-0.5 h-3 w-3 shrink-0 text-primary-600" />{{ line }}</li>
						</ul>
					</div>
				</div>
				<div ref="calculatorPanel" tabindex="-1" class="flex h-full w-full max-w-2xl flex-col bg-white shadow-2xl outline-none">
					<div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6">
						<div>
							<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Quick estimate</p>
							<h2 id="calculator-heading" class="mt-1 text-xl font-bold">Estimate your price</h2>
							<p class="mt-1 text-sm text-gray-500">Pick what you need from {{ organizer.name }}’s own prices.</p>
						</div>
						<button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100" aria-label="Close" @click="showCalculator = false"><IconBase name="x" class="h-5 w-5" /></button>
					</div>
					<div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6">
						<PricingPriceCalculator :options="profile.price_options" :services="profile.services" :settings="profile.estimate" :initial-picks="picks" :show-details="false"
							details-button @change="picks = $event" @details="openFromCalculator" @hover="hoverId = $event">
							<template #action="{ estimate, picks: chosenPicks, guests }">
								<div v-if="canRequestQuote" class="mt-4 flex justify-end">
									<button type="button" class="rounded-lg bg-primary-700 px-5 py-3 text-sm font-bold text-white hover:bg-primary-900 disabled:opacity-50"
										:disabled="!estimate.lines.length" @click="request({ guests: String(guests), options: chosenPicks })">
										Request a quotation with these
									</button>
								</div>
							</template>
						</PricingPriceCalculator>
					</div>
				</div>
			</div>
		</Teleport>

		<!-- Not signed in: say why, keep their picks, and bring them back to the request -->
		<Teleport to="body">
			<div v-if="signInTarget" class="fixed inset-0 z-[160] flex items-end justify-center bg-gray-950/60 p-4 sm:items-center" role="dialog" aria-modal="true" aria-labelledby="signin-heading"
				@click.self="signInTarget = null" @keydown.esc="signInTarget = null">
				<div ref="signInPanel" tabindex="-1" class="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl outline-none">
					<div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-50 text-primary-700"><IconBase name="send" class="h-5 w-5" /></div>
					<h2 id="signin-heading" class="mt-4 text-lg font-bold text-gray-900">Sign in to send your request</h2>
					<p class="mt-1 text-sm leading-6 text-gray-600">
						Requests are sent from a free client account, so the organizer can reply with their quotation.
						<template v-if="signInTarget.includes('options=')">Your picks are saved and</template><template v-else>You'll</template>
						come right back to the request form.
					</p>
					<div class="mt-5 grid gap-2">
						<NuxtLink :to="{ path: '/register', query: { redirect: signInTarget } }" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary-700 px-4 text-sm font-bold text-white hover:bg-primary-900">Create a free account</NuxtLink>
						<NuxtLink :to="{ path: '/login', query: { redirect: signInTarget } }" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-200 px-4 text-sm font-bold text-gray-800 hover:bg-gray-50">I already have an account</NuxtLink>
						<button type="button" class="min-h-10 text-sm font-semibold text-gray-500 hover:text-gray-700" @click="signInTarget = null">Keep browsing</button>
					</div>
				</div>
			</div>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
import { useOrganizerPortfolio } from '~/services/organizerPortfolioService'
import type { OrganizerPublicProfile, PublicReview } from '~/composables/useMarketplace'
import type { PriceOption } from '~/composables/useQuotations'

definePageMeta({ layout: 'public', key: route => route.path })
const route = useRoute()
const identifier = computed(() => String(route.params.slug))
const { data, status, error, refresh } = await useOrganizerPortfolio(identifier)
if (error.value && (error.value.statusCode === 404 || error.value.status === 404)) {
	throw createError({ statusCode: 404, statusMessage: 'Organizer not found' })
}
const organizer = computed(() => data.value?.data)
useSeoMeta({ title: () => `${organizer.value?.name || 'Organizer'} | NaSeRy`, description: () => organizer.value?.bio || 'View this event organizer’s services and completed events.' })
const { user } = useAuth()
// Only clients (and guests, who are sent to sign in) ask for quotations; organizers, team
// members and admins never see the button (an admin following it landed on the admin home).
const canRequestQuote = computed(() => !user.value || user.value.role === 'client')

// Field test follow-up (Nov 2026): packages first, the calculator in its own panel; picks are shared.
const router = useRouter()
const picks = ref<Record<string, number>>({})
const pickCount = computed(() => Object.keys(picks.value).length)
const openId = ref<string | null>(null)
const showCalculator = ref(false)
const signInTarget = ref<string | null>(null)
const calculatorPanel = ref<HTMLElement | null>(null)
const signInPanel = ref<HTMLElement | null>(null)
const optionGroups = computed(() => groupOptions(profile.value?.price_options ?? [], profile.value?.services ?? []))
const openGroup = computed(() => optionGroups.value.find(g => g.options.some(o => o.id === openId.value)))
const openOption = computed(() => openGroup.value?.options.find(o => o.id === openId.value))
// The calculator: an option's full view on top ("Select this package"), and a hover preview on wide screens.
const fromCalculator = ref(false)
function openFromCalculator(id: string) {
	hoverId.value = null
	fromCalculator.value = true
	openId.value = id
}
const hoverId = ref<string | null>(null)
const hoverOption = computed(() => profile.value?.price_options.find(o => o.id === hoverId.value) ?? null)
const hoverImages = computed(() => (hoverOption.value?.images ?? []).filter(i => i.url))
// Which picture the hover preview shows (it fades through them on its own; a new option starts at the first).
const hoverShown = ref(0)
watch(hoverId, () => { hoverShown.value = 0 })
watch(showCalculator, open => { if (!open) hoverId.value = null })
const firstQuantity = (o: PriceOption) => (picksQuantity(o) ? quantityBounds(o).min : 1)

function togglePick(id: string) {
	const group = optionGroups.value.find(g => g.options.some(o => o.id === id))
	const option = group?.options.find(o => o.id === id)
	if (!group || !option) return
	const next = { ...picks.value }
	if (id in next) delete next[id]
	else {
		if (group.single) group.options.forEach(o => delete next[o.id])
		next[id] = firstQuantity(option)
	}
	picks.value = next
}

// Signed-out visitors get a short explanation; their request (and picks) survive signing in.
function request(query: Record<string, string> = {}, toThisOrganizer = true) {
	const target = router.resolve({ path: '/client/post-event', query: toThisOrganizer ? { organizer: identifier.value, ...query } : query }).fullPath
	if (!user.value) {
		signInTarget.value = target
		return
	}
	showCalculator.value = false
	openId.value = null
	return navigateTo(target)
}
function requestOne(id: string) {
	const option = profile.value?.price_options.find(o => o.id === id)
	if (option) request({ options: encodePicks(profile.value!.price_options, { [id]: firstQuantity(option) }) })
}

// Overlays: no page scroll behind them, and keyboard focus moves in.
watch(() => Boolean(openId.value || showCalculator.value || signInTarget.value), open => {
	if (import.meta.client) document.body.style.overflow = open ? 'hidden' : ''
})
watch(showCalculator, async open => { if (open) { await nextTick(); calculatorPanel.value?.focus() } })
watch(signInTarget, async open => { if (open) { await nextTick(); signInPanel.value?.focus() } })
onBeforeUnmount(() => { if (import.meta.client) document.body.style.overflow = '' })

// Our marketplace profile: the price list (calculator) and reviews the portfolio shape leaves out.
const profile = ref<OrganizerPublicProfile | null>(null)
const reviews = ref<PublicReview[]>([])
const reviewPage = ref<{ current_page: number, last_page: number } | null>(null)
const reviewNotice = ref('')

// Anyone signed in can flag a review for the admin; it stays visible meanwhile.
async function reportReview(id: string) {
	const reason = await promptDialog('Why should NaSeRy look at this review?', { title: 'Report this review', minLength: 5, placeholder: 'At least 5 characters', confirmLabel: 'Send report' })
	if (!reason) return
	try {
		await useReviews().report(id, reason)
		reviewNotice.value = 'Thanks. A NaSeRy administrator will review it.'
	} catch (e: unknown) {
		reviewNotice.value = authMessage(e)
	}
}

// Long lists start short (less scrolling on phones); one tap shows the rest.
const eventsShown = ref(4)
const shownEvents = computed(() => (organizer.value?.events ?? []).slice(0, eventsShown.value))
const reviewsShown = ref(3)

async function moreReviews() {
	reviewsShown.value += 5
	if (reviews.value.length >= reviewsShown.value || !reviewPage.value || reviewPage.value.current_page >= reviewPage.value.last_page) return
	const next = await useMarketplace().reviews(identifier.value, reviewPage.value.current_page + 1)
	reviews.value.push(...next.items)
	reviewPage.value = next.pagination
}

async function loadProfile() {
	try {
		profile.value = await useMarketplace().organizer(identifier.value)
		reviews.value = profile.value.recent_reviews
		if (profile.value.rating.count > reviews.value.length) {
			const page = await useMarketplace().reviews(identifier.value, 1)
			reviews.value = page.items
			reviewPage.value = page.pagination
		}
	} catch {
		// The portfolio above still shows; the calculator and reviews stay hidden.
	}
}
onMounted(loadProfile)

function initials(name: string) {
	return name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part.charAt(0).toUpperCase()).join('') || 'OR'
}
</script>