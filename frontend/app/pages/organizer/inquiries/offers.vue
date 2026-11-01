<template>
	<div class="space-y-6">
		<OrganizerThreadDrawer :thread-id="threads.open.value?.id ?? null" :title="threads.open.value?.title ?? ''"
			@close="threads.open.value = null; if (orgId) threads.loadUnread(orgId)" @read="threads.markRead()" />

		<div v-if="threadError" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ threadError }}</div>

		<div v-if="isLoading" class="rounded-3xl border border-gray-200 bg-white p-12 text-center text-sm text-gray-500">
			Loading your offers...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else-if="counts.all > 0">
			<!-- Summary -->
			<div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
				<div v-for="(tile, index) in tiles" :key="tile.label" class="of-card of-rise group relative min-w-0 overflow-hidden rounded-2xl border border-gray-200 bg-white p-4 sm:p-5" :style="{ '--d': index * 60 + 'ms' }">
					<span class="of-line absolute inset-x-0 top-0 h-[2px] bg-[#285F6b]" />
					<div class="flex items-start justify-between gap-3">
						<span class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">{{ tile.label }}</span>
						<span class="hidden h-9 w-9 items-center justify-center rounded-xl bg-primary-50 text-primary-700 transition group-hover:scale-105 sm:flex">
							<IconBase :name="tile.icon" class="h-4 w-4" />
						</span>
					</div>
					<!-- Long peso amounts wrap instead of being cut off -->
					<div class="mt-2 text-xl font-extrabold leading-tight tracking-tight text-gray-950 [overflow-wrap:anywhere] sm:text-3xl">{{ tile.value }}</div>
				</div>
			</div>

			<!-- Filters -->
			<div class="of-rise chip-row flex flex-wrap gap-2" style="--d: 200ms">
				<button v-for="chip in chips" :key="chip.key" type="button"
					class="inline-flex min-h-9 items-center rounded-full px-3.5 py-1.5 text-xs font-bold transition duration-200"
					:class="activeFilter === chip.key ? 'bg-primary-700 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700'"
					@click="setFilter(chip.key)">
					{{ chip.label }} <span class="ml-1 opacity-60">{{ chip.count }}</span>
				</button>
			</div>

			<p v-if="!visibleOffers.length" class="rounded-3xl border border-dashed border-gray-300 bg-white py-14 text-center text-sm text-gray-400">
				No {{ activeFilter === 'all' ? '' : statusLabel(activeFilter).toLowerCase() + ' ' }}offers yet.
			</p>

			<!-- Offers -->
			<div class="space-y-5">
				<article v-for="(offer, index) in visibleOffers" :key="offer.id"
					class="of-rise of-offer overflow-hidden rounded-3xl border border-gray-200 bg-white"
					:style="{ '--d': 240 + index * 60 + 'ms' }">
					<div class="p-6 sm:p-7">
						<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
							<div class="min-w-0">
								<h3 class="line-clamp-2 break-words text-lg font-extrabold tracking-tight text-gray-950 sm:text-xl">
									{{ offer.inquiry?.event_title || `${offer.inquiry?.event_type || 'Event'} Inquiry` }}
								</h3>
								<p class="mt-1 flex items-center gap-2 text-sm text-gray-500">
									<IconBase name="tag" class="h-3.5 w-3.5 text-gray-400" />
									{{ offer.package_name || 'Custom quotation' }}
								</p>
							</div>

							<span class="inline-flex w-fit shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold" :class="statusBadgeClass(offer.quotation_status)">
								<IconBase :name="statusIcon(offer.quotation_status)" class="h-3.5 w-3.5" />
								{{ statusLabel(offer.quotation_status) }}
							</span>
						</div>

						<!-- 0051: the client changed the request after this offer -->
						<div v-if="raw(offer)?.before_changes"
							class="mt-5 flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 sm:flex-row sm:items-center">
							<IconBase name="clock" class="hidden h-5 w-5 shrink-0 text-amber-600 sm:block" />
							<p class="min-w-0 flex-1 leading-6">
								<span class="font-semibold">The client changed this request</span>
								<template v-if="raw(offer)?.inquiry?.revised_at"> on {{ formatDate(raw(offer)!.inquiry!.revised_at!) }}</template>,
								after you sent this offer. The details below are the new ones. Revise your offer if it no longer fits.
							</p>
							<button type="button" class="shrink-0 rounded-xl bg-amber-600 px-4 py-2 text-sm font-bold text-white hover:bg-amber-700"
								@click="revising = raw(offer)">Revise offer</button>
						</div>

						<div class="mt-5 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-2xl bg-gray-50 px-4 py-3 text-sm">
							<span class="flex items-center gap-2 font-semibold text-gray-900">
								<IconBase name="calendar" class="h-4 w-4 text-primary-700" />{{ formatEventDate(offer.inquiry?.event_date) }}
							</span>
							<span class="flex items-center gap-2 font-semibold text-gray-700">
								<IconBase name="clock" class="h-4 w-4 text-primary-700" />{{ formatSchedule(offer.inquiry?.start_time, offer.inquiry?.end_time) }}
							</span>
							<span v-if="offer.inquiry?.location" class="flex min-w-0 items-center gap-2 text-gray-500">
								<IconBase name="map-pin" class="h-4 w-4 shrink-0 text-primary-700" /><span class="truncate">{{ offer.inquiry.location }}</span>
							</span>
						</div>

						<div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
							<div>
								<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Offer amount</p>
								<p class="mt-1 text-2xl font-extrabold tracking-tight text-primary-700">{{ formatCurrency(offer.quotation_amount) }}</p>
							</div>
							<div>
								<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Submitted</p>
								<p class="mt-1 text-sm font-bold text-gray-900">{{ formatDate(offer.created_at) }}</p>
							</div>
							<div v-if="offer.timeline">
								<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Terms</p>
								<p class="mt-1 line-clamp-2 text-sm font-bold text-gray-900">{{ offer.timeline }}</p>
							</div>
						</div>

						<!-- Proposal details -->
						<Transition name="of-expand">
							<div v-if="expandedId === offer.id" class="mt-5 space-y-4 border-t border-gray-100 pt-5">
								<div v-if="offer.quotation_details">
									<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Your pitch</p>
									<p class="mt-1.5 whitespace-pre-line text-sm leading-6 text-gray-700">{{ offer.quotation_details }}</p>
								</div>

								<div v-if="offer.lines?.length">
									<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Price breakdown</p>
									<div class="mt-2 overflow-x-auto rounded-2xl bg-gray-50 p-4">
										<table class="w-full text-sm">
											<thead>
												<tr class="text-left text-xs text-gray-400">
													<th class="py-1 font-semibold">Item</th>
													<th class="py-1 text-right font-semibold">Qty</th>
													<th class="py-1 text-right font-semibold">Unit price</th>
													<th class="py-1 text-right font-semibold">Subtotal</th>
												</tr>
											</thead>
											<tbody class="divide-y divide-gray-100">
												<tr v-for="(line, i) in offer.lines" :key="i" class="text-gray-700">
													<td class="py-1.5 pr-3">{{ line.description }}</td>
													<td class="py-1.5 text-right">{{ line.quantity }}</td>
													<td class="py-1.5 text-right">{{ formatPeso(line.unit_price) }}</td>
													<td class="py-1.5 text-right font-semibold">{{ formatPeso(line.subtotal) }}</td>
												</tr>
											</tbody>
											<tfoot>
												<tr class="border-t border-gray-200 text-gray-900">
													<td colspan="3" class="pt-2 font-bold">Total</td>
													<td class="pt-2 text-right font-extrabold">{{ formatPeso(offer.quotation_amount) }}</td>
												</tr>
											</tfoot>
										</table>
									</div>
								</div>

								<p v-if="!offer.quotation_details && !offer.lines?.length" class="text-sm text-gray-400">This offer has no extra details.</p>
							</div>
						</Transition>
					</div>

					<div class="flex flex-wrap items-center gap-3 border-t border-gray-100 bg-gray-50/60 px-6 py-4 sm:px-7">
						<button v-if="offer.quotation_status === 'accepted'" type="button"
							class="inline-flex items-center gap-2 rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md active:translate-y-0 active:scale-[0.98] disabled:translate-y-0 disabled:cursor-not-allowed disabled:opacity-60"
							:disabled="startingPlanningId === offer.id" @click="startEventPlanning(offer)">
							<IconBase :name="startingPlanningId === offer.id ? 'refresh-cw' : 'arrow-right'" class="h-4 w-4" :class="{ 'animate-spin': startingPlanningId === offer.id }" />
							{{ startingPlanningId === offer.id ? 'Starting...' : offer.event ? 'Open Event' : 'Start Event Planning' }}
						</button>

						<button v-if="offer.quotation_status !== 'rejected'" type="button"
							class="relative inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-bold text-gray-700 transition duration-200 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 active:scale-[0.98] disabled:opacity-60"
							:disabled="openingThreadId === offer.id" @click="openThread(offer)">
							<IconBase name="message" class="h-4 w-4" />
							{{ openingThreadId === offer.id ? 'Opening…' : 'Message' }}
							<span v-if="threads.unread.value[String(offer.inquiry_id)]" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-[#285F6b] px-1.5 text-[11px] font-bold text-white">{{ threads.unread.value[String(offer.inquiry_id)] }}</span>
						</button>

						<button v-if="raw(offer)?.status === 'submitted' && !raw(offer)?.before_changes" type="button"
							class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-bold text-gray-700 transition duration-200 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 active:scale-[0.98]"
							@click="revising = raw(offer)">
							<IconBase name="edit" class="h-4 w-4" /> Revise offer
						</button>

						<button type="button"
							class="ml-auto inline-flex items-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-bold text-gray-500 transition hover:bg-white hover:text-primary-700"
							:aria-expanded="expandedId === offer.id" @click="expandedId = expandedId === offer.id ? null : offer.id">
							{{ expandedId === offer.id ? 'Hide proposal' : 'View proposal' }}
							<IconBase name="chevron-down" class="h-4 w-4 transition-transform" :class="expandedId === offer.id ? 'rotate-180' : ''" />
						</button>
					</div>
				</article>
			</div>
		</template>

		<div v-else class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
			<span class="of-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
				<IconBase name="file-text" class="h-7 w-7" />
			</span>

			<h2 class="mt-4 text-lg font-bold text-gray-900">No offers submitted yet</h2>

			<p class="mt-1 text-sm text-gray-500">Your submitted quotations will appear here.</p>
			<NuxtLink to="/organizer/inquiries/match-inquiries" class="mt-3 inline-block text-sm font-semibold text-primary-700 hover:underline">Browse matching inquiries</NuxtLink>
		</div>

		<OrganizerQuotationForm v-if="revising && orgId" :organization-id="orgId" :inquiry-id="revising.inquiry_id"
			:event-label="`${revising.inquiry?.event_type ?? 'Event'}${revising.inquiry?.requested_date ? ` on ${formatDate(revising.inquiry.requested_date)}` : ''}`"
			:guest-count="revising.inquiry?.guest_count ?? null" :quotation="revising"
			@close="revising = null" @saved="onRevised" />
	</div>
</template>

<script setup lang="ts">
import type { OrganizerQuotation } from '~/composables/useQuotations'

const startingPlanningId =
	ref<number | null>(null)

interface OfferInclusion {
	id: number
	quotation_id: number
	description: string
}

interface OfferInquiry {
	id: number
	event_title: string | null
	event_type: string
	event_date: string
	start_time: string | null
	end_time: string | null
	location: string
}

interface OrganizerEvent {
	id: number
	status: string
}

interface OrganizerOffer {
	id: number
	inquiry_id: number
	organizer_id: number
	quotation_amount: string | number
	package_name: string | null
	timeline: string | null
	quotation_details: string | null
	quotation_status: 'pending' | 'accepted' | 'rejected'
	created_at: string
	updated_at: string
	inclusions?: OfferInclusion[]
	/** The priced lines behind the total ("View Full Proposal"). */
	lines?: { description: string, quantity: number, unit_price: number, subtotal: number }[]
	inquiry?: OfferInquiry
	event?: OrganizerEvent | null
}

interface OrganizerOffersResponse {
	data: OrganizerOffer[]
}

definePageMeta({
	layout: 'dashboard',
})

const { token } = useAuth()
const config = useRuntimeConfig()

const offers = ref<OrganizerOffer[]>([])
const expandedId = ref<number | null>(null)
// Shared with the tab bar (inquiry-tabs.vue), which Match Inquiries also fills.
const matchingInquiryCount = useState<number>('matchingInquiryCount', () => 0)
const myOffersCount = useState<number>('myOffersCount', () => 0)
const isLoading = ref(false)
const errorMessage = ref('')

function statusLabel(status: OrganizerOffer['quotation_status']): string {
	if (status === 'accepted') return 'Won'
	if (status === 'rejected') return 'Lost'
	return 'Pending'
}

function statusBadgeClass(status: OrganizerOffer['quotation_status']) {
	if (status === 'accepted') return 'bg-green-50 text-green-700'
	if (status === 'rejected') return 'bg-red-50 text-red-600'
	return 'bg-gray-100 text-gray-600'
}

function statusTextClass(status: OrganizerOffer['quotation_status']) {
	if (status === 'accepted') return 'text-green-600'
	if (status === 'rejected') return 'text-red-600'
	return 'text-gray-700'
}

function formatCurrency(value: string | number): string {
	const amount = Number(value)

	if (Number.isNaN(amount)) {
		return String(value)
	}

	return new Intl.NumberFormat('en-PH', {
		style: 'currency',
		currency: 'PHP',
		maximumFractionDigits: 0,
	}).format(amount)
}

function formatDate(dateString: string): string {
	if (!dateString) return ''

	return new Date(dateString).toLocaleDateString('en-US', {
		month: 'long',
		day: 'numeric',
		year: 'numeric',
	})
}

function formatEventDate(value?: string): string {
	return value ? formatDate(value) : 'Date not set'
}

function formatTime(value?: string | null): string {
	if (!value) return 'Not set'
	const [hours, minutes] = value.substring(0, 5).split(':').map(Number)
	return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' })
		.format(new Date(2000, 0, 1, hours, minutes))
}

function formatSchedule(start?: string | null, end?: string | null): string {
	if (!start && !end) return 'Time not set'
	return `${formatTime(start)} – ${formatTime(end)}`
}

// Private messages with each client (one thread per inquiry).
const threads = useOrganizerThreads()
const orgId = ref<string | null>(null)

// Revising a sent offer (also when the client changed the request, 0051).
const rawById = ref<Record<string, OrganizerQuotation>>({})
const raw = (offer: { id: unknown }) => rawById.value[String(offer.id)] ?? null
const revising = ref<OrganizerQuotation | null>(null)
async function onRevised() {
	revising.value = null
	await loadOffers()
}
const openingThreadId = ref<number | null>(null)
const threadError = ref('')

async function openThread(offer: OrganizerOffer) {
	if (!orgId.value) return
	openingThreadId.value = offer.id
	threadError.value = ''
	try {
		await threads.openFor(orgId.value, String(offer.inquiry_id), `${offer.inquiry?.event_title ?? 'Inquiry'} · ${offer.package_name ?? 'Offer'}`)
		if (!threads.open.value) threadError.value = 'No conversation is open for this inquiry yet.'
	} catch (error: unknown) {
		threadError.value = authMessage(error) || 'Unable to open messages.'
	} finally {
		openingThreadId.value = null
	}
}

async function loadOffers(quiet = false) {
	if (!quiet) isLoading.value = true
	errorMessage.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId.value = org.id
		void threads.loadUnread(org.id)
		void useOrganizerInquiries().list(org.id, ['pending', 'viewed']).then(r => {
			matchingInquiryCount.value = r.items.filter(i => i.is_open && (!i.quotation || i.quotation.status === 'draft')).length
		}).catch(() => {})
		const quotations = await allItems(page => useOrganizerQuotations().list(org.id, ['submitted', 'accepted', 'rejected', 'withdrawn'], page))
		// One card per request: a revision replaces (withdraws) the earlier version.
		const stamp = (q: OrganizerQuotation) => q.submitted_at ?? q.updated_at ?? ''
		const latest = Object.values(quotations.reduce<Record<string, OrganizerQuotation>>((acc, q) => {
			const seen = acc[q.inquiry_id]
			if (!seen || stamp(q) > stamp(seen)) acc[q.inquiry_id] = q
			return acc
		}, {})).sort((a, b) => stamp(b).localeCompare(stamp(a)))
		rawById.value = Object.fromEntries(latest.map(q => [q.id, q]))
		myOffersCount.value = latest.length
		offers.value = latest.map((q) => {
			const legacy = toLegacyQuotation(q)
			return {
				id: q.id as unknown as number,
				inquiry_id: q.inquiry_id as unknown as number,
				organizer_id: 0,
				quotation_amount: q.total_amount,
				package_name: q.package_name,
				timeline: q.terms,
				quotation_details: q.proposal,
				quotation_status: (legacy.quotation_status === 'withdrawn' ? 'rejected' : legacy.quotation_status) as OrganizerOffer['quotation_status'],
				created_at: q.submitted_at ?? q.updated_at ?? '',
				updated_at: q.updated_at ?? '',
				inclusions: q.items.map((item, i) => ({ id: i, quotation_id: 0, description: `${item.description}${Number(item.quantity) !== 1 ? ` × ${item.quantity}` : ''}` })),
				lines: q.items.map(item => ({
					description: item.description,
					quantity: Number(item.quantity),
					unit_price: Number(item.unit_price),
					subtotal: Number(item.subtotal ?? Number(item.quantity) * Number(item.unit_price)),
				})),
				inquiry: legacy.inquiry ? {
					id: legacy.inquiry.id as unknown as number,
					event_title: legacy.inquiry.event_title,
					event_type: legacy.inquiry.event_type,
					event_date: legacy.inquiry.event_date ?? '',
					start_time: null,
					end_time: null,
					location: legacy.inquiry.location,
				} : undefined,
				event: q.event_id ? { id: q.event_id as unknown as number, status: 'planned' } : null,
			}
		})
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load your offers.'
	} finally {
		isLoading.value = false
	}
}
async function startEventPlanning(
	offer: OrganizerOffer,
) {
	if (offer.event) {
		await navigateTo({ path: '/organizer/eventmanagement/event-details', query: { event: String(offer.event.id) } })

		return
	}

	// Event Details creates the event from the won quotation ("start planning").
	await navigateTo({ path: '/organizer/eventmanagement/event-details', query: { quotation: String(offer.id) } })
}

onMounted(() => {
	loadOffers()
})
// Close to live (review #12): other people's changes appear without a reload.
useLiveRefresh(() => loadOffers(true), { interval: 60000 })
// Summary tiles and filter chips (counted from the offers loaded above).
type OfferFilter = 'all' | OrganizerOffer['quotation_status']
const activeFilter = ref<OfferFilter>('all')
const counts = computed(() => ({
	all: offers.value.length,
	pending: offers.value.filter(o => o.quotation_status === 'pending').length,
	accepted: offers.value.filter(o => o.quotation_status === 'accepted').length,
	rejected: offers.value.filter(o => o.quotation_status === 'rejected').length,
}))
const wonValue = computed(() => offers.value.filter(o => o.quotation_status === 'accepted').reduce((sum, o) => sum + Number(o.quotation_amount || 0), 0))
const chips = computed(() => [
	{ key: 'all' as const, label: 'All', count: counts.value.all },
	{ key: 'pending' as const, label: 'Pending', count: counts.value.pending },
	{ key: 'accepted' as const, label: 'Won', count: counts.value.accepted },
	{ key: 'rejected' as const, label: 'Lost', count: counts.value.rejected },
])
const tiles = computed(() => [
	{ label: 'Total offers', value: String(counts.value.all), icon: 'file-text' },
	{ label: 'Pending', value: String(counts.value.pending), icon: 'clock' },
	{ label: 'Won', value: String(counts.value.accepted), icon: 'award' },
	{ label: 'Won value', value: formatCurrency(wonValue.value), icon: 'cash' },
])
const visibleOffers = computed(() => activeFilter.value === 'all' ? offers.value : offers.value.filter(o => o.quotation_status === activeFilter.value))
function setFilter(key: OfferFilter) {
	activeFilter.value = key
	expandedId.value = null
}

function statusIcon(status: OrganizerOffer['quotation_status']): string {
	if (status === 'accepted') return 'check-circle'
	if (status === 'rejected') return 'x-circle'
	return 'clock'
}
</script>

<style scoped>
.of-rise {
	animation: of-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.of-card, .of-offer {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.of-card:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.of-offer:hover {
	border-color: rgba(40, 95, 107, 0.25);
	box-shadow: 0 18px 36px -26px rgba(17, 24, 39, 0.35);
}

.of-line {
	transform: scaleX(0);
	transform-origin: left;
	transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.of-card:hover .of-line {
	transform: scaleX(1);
}

.of-float {
	animation: of-float 3.2s ease-in-out infinite;
}

.of-expand-enter-active, .of-expand-leave-active {
	transition: opacity 0.25s ease, transform 0.25s ease;
}

.of-expand-enter-from, .of-expand-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

@keyframes of-rise {
	from { opacity: 0; transform: translateY(14px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes of-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
	.of-rise, .of-float { animation: none; }
	.of-card, .of-offer, .of-line, .of-expand-enter-active, .of-expand-leave-active { transition: none; }
}
</style>
