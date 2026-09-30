<template>
	<div>
		<OrganizerThreadDrawer :thread-id="threads.open.value?.id ?? null" :title="threads.open.value?.title ?? ''"
			@close="threads.open.value = null; if (orgId) threads.loadUnread(orgId)" @read="threads.markRead()" />

		<div v-if="threadError" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ threadError }}</div>

		<div v-if="isLoading"
			class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
			Loading your offers...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<div v-else-if="offers.length" class="space-y-5">
			<article v-for="offer in offers" :key="offer.id"
				class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm transition hover:border-primary-200 hover:shadow-md">
				<div class="h-1"
					:class="offer.quotation_status === 'accepted' ? 'bg-emerald-500' : offer.quotation_status === 'rejected' ? 'bg-rose-400' : 'bg-amber-400'" />
				<div class="p-6">
					<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
						<div>
							<h3 class="text-lg font-bold text-gray-900">
								{{ offer.inquiry?.event_title || `${offer.inquiry?.event_type || 'Event'} Inquiry` }}
							</h3>

							<p class="mt-1 text-sm text-gray-500">
								{{ offer.package_name || 'Custom quotation' }}
							</p>
						</div>

						<span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold"
							:class="statusBadgeClass(offer.quotation_status)">
							{{ statusLabel(offer.quotation_status) }}
						</span>
					</div>

					<div
						class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-xl border border-primary-100 bg-primary-50/60 px-4 py-3 text-sm">
						<span class="flex items-center gap-2 font-semibold text-gray-800">
							<IconBase name="calendar" class="h-4 w-4 text-primary-700" />{{
								formatEventDate(offer.inquiry?.event_date) }}
						</span>
						<span class="flex items-center gap-2 font-semibold text-primary-800">
							<IconBase name="clock" class="h-4 w-4" />{{ formatSchedule(offer.inquiry?.start_time,
								offer.inquiry?.end_time) }}
						</span>
						<span v-if="offer.inquiry?.location" class="flex min-w-0 items-center gap-2 text-gray-500">
							<IconBase name="map-pin" class="h-4 w-4 shrink-0" /><span class="truncate">{{
								offer.inquiry.location }}</span>
						</span>
					</div>

					<div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
						<div class="rounded-lg bg-gray-50 p-3">
							<div class="text-xs text-gray-500">
								Offer Amount
							</div>

							<div class="mt-1 text-lg font-bold text-primary-700">
								{{ formatCurrency(offer.quotation_amount) }}
							</div>
						</div>

						<div class="rounded-lg bg-gray-50 p-3">
							<div class="text-xs text-gray-500">
								Submitted
							</div>

							<div class="mt-1 text-sm font-bold text-gray-900">
								{{ formatDate(offer.created_at) }}
							</div>
						</div>

						<div class="rounded-lg bg-gray-50 p-3">
							<div class="text-xs text-gray-500">
								Status
							</div>

							<div class="mt-1 text-sm font-bold" :class="statusTextClass(offer.quotation_status)">
								{{ statusLabel(offer.quotation_status) }}
							</div>
						</div>
					</div>

					<div v-if="offer.timeline || offer.quotation_details" class="mt-4 grid gap-3 sm:grid-cols-2">
						<div v-if="offer.timeline" class="rounded-lg border border-gray-100 p-3">
							<div class="text-xs font-semibold uppercase tracking-wide text-gray-400">
								Timeline
							</div>

							<p class="mt-1 text-sm text-gray-700">
								{{ offer.timeline }}
							</p>
						</div>

						<div v-if="offer.quotation_details" class="rounded-lg border border-gray-100 p-3">
							<div class="text-xs font-semibold uppercase tracking-wide text-gray-400">
								Pitch
							</div>

							<p class="mt-1 text-sm leading-6 text-gray-700">
								{{ offer.quotation_details }}
							</p>
						</div>
					</div>

					<div v-if="offer.inclusions?.length" class="mt-4">
						<div class="mb-2 text-xs font-semibold tracking-wide text-gray-400">
							INCLUSIONS
						</div>

						<ul class="space-y-2">
							<li v-for="inclusion in offer.inclusions" :key="inclusion.id"
								class="flex items-start gap-2 text-sm text-gray-600">
								<span class="mt-0.5 text-primary-700">✓</span>
								<span>{{ inclusion.description }}</span>
							</li>
						</ul>
					</div>

					<div v-if="expandedId === offer.id" class="mt-5 rounded-xl border border-gray-100 bg-gray-50 p-4">
						<div class="mb-2 text-xs font-semibold tracking-wide text-gray-400">PRICE BREAKDOWN</div>
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
						<p v-if="offer.timeline" class="mt-3 text-xs text-gray-500"><span class="font-semibold">Terms:</span> {{ offer.timeline }}</p>
					</div>

					<div class="mt-5 flex flex-wrap gap-3">
						<button v-if="offer.quotation_status === 'accepted'" type="button"
							class="flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
							:disabled="startingPlanningId === offer.id" @click="startEventPlanning(offer)">
							<IconBase name="arrow-right" class="h-4 w-4" />

							{{
								startingPlanningId === offer.id
									? 'Starting...'
									: offer.event
										? 'Open Event'
										: 'Start Event Planning'
							}}
						</button>

						<button v-if="offer.quotation_status !== 'rejected'" type="button"
							class="relative flex items-center gap-2 rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
							:disabled="openingThreadId === offer.id" @click="openThread(offer)">
							<IconBase name="message" class="h-4 w-4" />
							{{ openingThreadId === offer.id ? 'Opening…' : 'Message Client' }}
							<span v-if="threads.unread.value[String(offer.inquiry_id)]"
								class="ml-1 rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white">{{ threads.unread.value[String(offer.inquiry_id)] }}</span>
						</button>

						<button type="button"
							class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
							:aria-expanded="expandedId === offer.id" @click="expandedId = expandedId === offer.id ? null : offer.id">
							{{ expandedId === offer.id ? 'Hide Full Proposal' : 'View Full Proposal' }}
						</button>
					</div>
				</div>
			</article>
		</div>

		<div v-else class="rounded-2xl border-2 border-dashed border-gray-200 bg-white px-6 py-16 text-center">
			<div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100 text-gray-400">
				<IconBase name="file-text" class="h-5 w-5" />
			</div>

			<h2 class="mt-4 text-lg font-bold text-gray-900">
				No offers submitted yet
			</h2>

			<p class="mt-1 text-sm text-gray-500">
				Your submitted quotations will appear here.
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
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

async function loadOffers() {
	isLoading.value = true
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
		myOffersCount.value = quotations.length
		offers.value = quotations.map((q) => {
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
		await navigateTo(
			'/organizer/eventmanagement/all-events',
		)

		return
	}

	// Event Details creates the event from the won quotation ("start planning").
	await navigateTo({ path: '/organizer/eventmanagement/event-details', query: { quotation: String(offer.id) } })
}

onMounted(() => {
	loadOffers()
})
</script>
