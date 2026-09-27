<template>
	<div class="mx-auto max-w-7xl px-5 pt-7 sm:px-8">
		<NuxtLink to="/organizers" class="inline-flex items-center gap-2 text-sm font-semibold text-primary-700 hover:underline">
			<span aria-hidden="true">←</span> All organizers
		</NuxtLink>

		<div v-if="loading" class="mt-6 animate-pulse" aria-label="Loading organizer profile">
			<div class="h-56 rounded-2xl bg-gray-200" />
			<div class="mt-8 h-64 rounded-2xl bg-gray-100" />
		</div>

		<div v-else-if="notFound" class="mx-auto max-w-xl py-20 text-center">
			<h1 class="text-2xl font-bold">This organizer isn't listed</h1>
			<p class="mt-3 text-sm text-gray-500">They may not be approved yet or have hidden their profile.</p>
			<NuxtLink to="/organizers" class="mt-6 inline-block rounded-lg bg-primary-700 px-5 py-3 text-sm font-bold text-white">Browse organizers</NuxtLink>
		</div>

		<p v-else-if="error" class="mt-6 rounded-xl border border-rose-100 bg-rose-50 p-4 text-sm text-rose-800">{{ error }}</p>

		<template v-else-if="org">
			<section class="mt-4 overflow-hidden rounded-2xl border border-gray-200 bg-white">
				<div class="h-44 bg-gradient-to-br from-primary-600 to-primary-900"
					:style="org.cover_image_url ? { backgroundImage: `url(${JSON.stringify(org.cover_image_url)})`, backgroundSize: 'cover', backgroundPosition: 'center' } : {}" />
				<div class="flex flex-col justify-between gap-6 p-6 sm:p-8 lg:flex-row lg:items-end">
					<div class="flex items-start gap-4">
						<span class="-mt-16 flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl border-4 border-white bg-gray-900 text-2xl font-bold text-white">
							{{ initialsOf(org.display_name) }}
						</span>
						<div>
							<h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ org.display_name }}</h1>
							<p v-if="org.business_name !== org.display_name" class="text-sm text-gray-500">{{ org.business_name }}</p>
							<p class="mt-2 text-sm text-gray-500">{{ org.location_text || 'Location not listed' }}</p>
							<p class="mt-2 text-sm font-semibold" :class="org.rating.count ? 'text-amber-700' : 'text-gray-400'">
								{{ org.rating.count ? `★ ${org.rating.average?.toFixed(1)} · ${org.rating.count} review${org.rating.count === 1 ? '' : 's'}` : 'No reviews yet' }}
							</p>
						</div>
					</div>
					<NuxtLink :to="inquiryLink" class="self-start rounded-lg bg-primary-700 px-5 py-3 text-sm font-bold text-white hover:bg-primary-900 lg:self-auto">
						Request a quotation
					</NuxtLink>
				</div>
			</section>

			<div class="mt-8 grid items-start gap-8 lg:grid-cols-[1fr_320px]">
				<div class="space-y-8">
					<section class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8" aria-labelledby="about-heading">
						<h2 id="about-heading" class="text-xl font-bold">About {{ org.display_name }}</h2>
						<p class="mt-4 whitespace-pre-line text-sm leading-7 text-gray-600">{{ org.description || 'This organizer has not added an introduction yet.' }}</p>
					</section>

					<section class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8" aria-labelledby="services-heading">
						<h2 id="services-heading" class="text-xl font-bold">Services</h2>
						<p v-if="!org.services.length" class="mt-4 text-sm text-gray-500">No services published yet.</p>
						<ul v-else class="mt-5 divide-y divide-gray-100">
							<li v-for="s in org.services" :key="s.id" class="flex items-start justify-between gap-4 py-3">
								<div>
									<h3 class="text-sm font-bold">{{ s.name }}</h3>
									<p v-if="s.description" class="mt-1 text-sm text-gray-500">{{ s.description }}</p>
								</div>
								<span v-if="s.base_price" class="shrink-0 text-sm font-semibold text-gray-700">from {{ formatPeso(s.base_price) }}</span>
							</li>
						</ul>
						<p class="mt-4 text-xs text-gray-400">Prices are starting points; final quotations are agreed with the organizer.</p>
					</section>

					<section v-if="org.price_options.length" class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8" aria-labelledby="calculator-heading">
						<h2 id="calculator-heading" class="text-xl font-bold">Quotation calculator</h2>
						<p class="mt-1 text-sm text-gray-500">Pick what you need for an estimate from {{ org.display_name }}'s own prices.</p>

						<label class="mt-5 block max-w-xs">
							<span class="text-sm font-semibold text-gray-700">Expected guests</span>
							<input v-model.number="calcGuests" type="number" min="1" max="100000"
								class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none">
						</label>

						<fieldset v-for="group in calcGroups" :key="group.key" class="mt-5">
							<legend class="text-sm font-bold text-gray-900">{{ group.label }}</legend>
							<div class="mt-2 space-y-2">
								<label v-for="o in group.options" :key="o.id"
									class="flex cursor-pointer items-start justify-between gap-3 rounded-xl border px-3.5 py-2.5 text-sm"
									:class="picked.has(o.id) ? 'border-primary-600 bg-primary-50' : 'border-gray-200'">
									<span class="flex items-start gap-2.5">
										<input :type="group.single ? 'radio' : 'checkbox'" :name="group.key" :checked="picked.has(o.id)" class="mt-1"
											:aria-label="`${group.label}: ${o.name}`" @click="toggle(group, o.id)">
										<span>
											<span class="font-semibold">{{ o.name }}</span>
											<span v-if="o.description" class="block text-xs text-gray-500">{{ o.description }}</span>
											<span v-if="o.min_guests && o.pricing_unit === 'per_guest'" class="block text-xs text-gray-400">Minimum {{ o.min_guests }} guests</span>
										</span>
									</span>
									<span class="shrink-0 font-semibold text-gray-700">{{ formatPeso(o.price) }}{{ o.pricing_unit === 'per_guest' ? ' / guest' : '' }}</span>
								</label>
							</div>
						</fieldset>

						<div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl bg-gray-50 p-4">
							<div>
								<div class="text-xs text-gray-500">Estimated total</div>
								<div class="text-2xl font-extrabold text-gray-900">{{ formatPeso(calcTotal) || '₱0' }}</div>
							</div>
							<NuxtLink :to="calcInquiryLink"
								class="rounded-lg bg-primary-700 px-5 py-3 text-sm font-bold text-white hover:bg-primary-900"
								:class="{ 'pointer-events-none opacity-50': !picked.size }" :aria-disabled="!picked.size">
								Request a quotation with these
							</NuxtLink>
						</div>
						<p class="mt-2 text-xs text-gray-400">An estimate only; the organizer confirms the final price in their quotation.</p>
					</section>

					<section class="rounded-2xl border border-gray-200 bg-white p-6 sm:p-8" aria-labelledby="reviews-heading">
						<h2 id="reviews-heading" class="text-xl font-bold">Reviews</h2>
						<p v-if="!reviews.length" class="mt-4 text-sm text-gray-500">No reviews yet. Reviews come only from clients who completed an event with this organizer.</p>
						<ul v-else class="mt-5 space-y-5">
							<li v-for="r in reviews" :key="r.id" class="border-b border-gray-100 pb-5 last:border-0">
								<p class="text-sm font-semibold text-amber-700" :aria-label="`${r.rating} out of 5`">{{ '★'.repeat(r.rating) }}<span class="text-gray-300">{{ '★'.repeat(5 - r.rating) }}</span></p>
								<p v-if="r.written_review" class="mt-2 text-sm text-gray-700">{{ r.written_review }}</p>
								<p class="mt-2 text-xs text-gray-400">
									{{ r.reviewer }}<span v-if="r.event_type"> · {{ r.event_type }}</span> · {{ new Date(r.created_at).toLocaleDateString('en-PH', { dateStyle: 'medium' }) }}<span v-if="r.edited"> · edited</span>
									<button v-if="isLoggedIn" type="button" class="ml-2 text-gray-400 underline hover:text-gray-700" @click="reportReview(r.id)">Report</button>
								</p>
								<div v-if="r.reply" class="mt-3 rounded-lg border-l-4 border-primary-700 bg-gray-50 p-3 text-sm">
									<p class="text-xs font-bold uppercase tracking-wide text-gray-400">Reply from {{ org?.display_name }}</p>
									<p class="mt-1 whitespace-pre-line text-gray-700">{{ r.reply.text }}</p>
								</div>
							</li>
						</ul>
						<button v-if="reviewPage && reviewPage.current_page < reviewPage.last_page" type="button"
							class="mt-4 text-sm font-semibold text-primary-700" @click="moreReviews">Show more reviews</button>
					</section>
				</div>

				<aside class="space-y-6">
					<section class="rounded-2xl border border-gray-200 bg-white p-6">
						<h2 class="text-base font-bold">Contact</h2>
						<p class="mt-3 text-sm text-gray-600">{{ org.contact_email || '—' }}</p>
						<p class="text-sm text-gray-600">{{ org.contact_phone || '' }}</p>
						<a v-if="mapLink" :href="mapLink" target="_blank" rel="noopener noreferrer" class="mt-3 inline-block text-sm font-semibold text-primary-700 underline">View on map ↗</a>
					</section>
					<section v-if="org.event_types.length" class="rounded-2xl border border-gray-200 bg-white p-6">
						<h2 class="text-base font-bold">Also organizes</h2>
						<div class="mt-3 flex flex-wrap gap-2">
							<span v-for="t in org.event_types" :key="t.id" class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-700">{{ t.name }}</span>
						</div>
					</section>
				</aside>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { OrganizerPublicProfile, PublicReview } from '~/composables/useMarketplace'
import type { PriceOption } from '~/composables/useQuotations'

definePageMeta({ layout: 'public' })

const route = useRoute()
const { organizer, reviews: fetchReviews } = useMarketplace()

const org = ref<OrganizerPublicProfile | null>(null)
const reviews = ref<PublicReview[]>([])
const reviewPage = ref<{ current_page: number, last_page: number } | null>(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')

const slug = computed(() => String(route.params.slug))
const mapLink = computed(() => org.value?.latitude !== null && org.value?.longitude !== null && org.value
	? `https://www.openstreetmap.org/?mlat=${org.value.latitude}&mlon=${org.value.longitude}#map=15/${org.value.latitude}/${org.value.longitude}`
	: null)
const inquiryLink = computed(() => ({ path: '/client/post-event', query: { organizer: org.value?.slug } }))

// Quotation calculator: one pick per service (alternatives), add-ons freely.
const calcGuests = ref(100)
const picked = reactive(new Set<string>())
const calcGroups = computed(() => {
	if (!org.value) return []
	const byService = new Map<string | null, PriceOption[]>()
	for (const o of org.value.price_options) {
		byService.set(o.organization_service_id, [...(byService.get(o.organization_service_id) ?? []), o])
	}
	return [...byService.entries()]
		.map(([serviceId, options]) => ({
			key: serviceId ?? 'addons',
			label: serviceId ? org.value!.services.find(s => s.id === serviceId)?.name ?? 'Options' : 'Add-ons',
			single: serviceId !== null,
			options,
		}))
		.sort((a, b) => Number(!a.single) - Number(!b.single))
})
const calcTotal = computed(() => {
	const guests = Math.max(1, Number(calcGuests.value) || 1)
	return linesTotal((org.value?.price_options ?? []).filter(o => picked.has(o.id)).map(o => optionAsLine(o, guests)))
})
const calcInquiryLink = computed(() => ({
	path: '/client/post-event',
	query: { organizer: org.value?.slug, guests: String(calcGuests.value || ''), options: [...picked].join(',') },
}))

function toggle(group: { single: boolean, options: PriceOption[] }, id: string) {
	if (picked.has(id)) {
		picked.delete(id)
		return
	}
	if (group.single) group.options.forEach(o => picked.delete(o.id))
	picked.add(id)
}

useSeoMeta({
	title: () => org.value ? `${org.value.display_name} | NaSeRy organizers` : 'Organizer | NaSeRy',
	description: () => org.value?.description?.slice(0, 150) ?? 'Event organizer on NaSeRy.',
})

const { isLoggedIn } = useAuth()
const reviewApi = useReviews()

// B16: anyone signed in can flag a review for the admin; it stays visible meanwhile.
async function reportReview(id: string) {
	const reason = prompt('Why should NaSeRy look at this review?')
	if (!reason || reason.trim().length < 5) return
	try {
		await reviewApi.report(id, reason.trim())
		alert('Thanks. A NaSeRy administrator will review it.')
	} catch (e) {
		alert(authMessage(e))
	}
}

async function moreReviews() {
	if (!reviewPage.value) return
	const next = await fetchReviews(slug.value, reviewPage.value.current_page + 1)
	reviews.value.push(...next.items)
	reviewPage.value = next.pagination
}

onMounted(async () => {
	try {
		org.value = await organizer(slug.value)
		reviews.value = org.value.recent_reviews
		if (org.value.rating.count > reviews.value.length) {
			const page = await fetchReviews(slug.value, 1)
			reviews.value = page.items
			reviewPage.value = page.pagination
		}
	} catch (e) {
		if (e instanceof ApiError && e.status === 404) notFound.value = true
		else error.value = authMessage(e)
	} finally {
		loading.value = false
	}
})
</script>
