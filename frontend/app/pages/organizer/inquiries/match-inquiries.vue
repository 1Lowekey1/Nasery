<template>
	<div>
		<OrganizerThreadDrawer :thread-id="threads.open.value?.id ?? null" :title="threads.open.value?.title ?? ''"
			@close="threads.open.value = null; if (orgId) threads.loadUnread(orgId)" @read="threads.markRead()" />
		<div v-if="threadError" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ threadError }}</div>
		<div class="mb-5 rounded-2xl border border-primary-100 bg-primary-50/70 p-4 sm:p-5">
			<div class="flex items-start gap-3">
				<div
					class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-primary-700 shadow-sm">
					<IconBase name="bell" class="h-5 w-5" />
				</div>

				<div class="min-w-0">
					<h2 class="text-sm font-bold text-gray-900">
						Matched opportunities for your services
					</h2>

					<p class="mt-1 text-sm leading-6 text-gray-600">
						You only see inquiries that match your
						<strong class="font-semibold text-gray-800">service tags</strong>.
						Review the event details and submit a competitive offer when you're ready.
					</p>
				</div>
			</div>
		</div>

		<div v-if="isLoading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center">
			<div class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-gray-50 text-primary-700">
				<IconBase name="refresh-cw" class="h-5 w-5 animate-spin" />
			</div>

			<p class="mt-3 text-sm font-medium text-gray-600">
				Loading matching inquiries...
			</p>
		</div>

		<!-- Error -->
		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-4">
			<div class="flex items-start gap-3">
				<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-red-500">
					<IconBase name="alert-circle" class="h-5 w-5" />
				</div>

				<div>
					<div class="text-sm font-bold text-red-800">
						Unable to load matching inquiries
					</div>

					<p class="mt-1 text-sm text-red-700">
						{{ errorMessage }}
					</p>
				</div>
			</div>
		</div>

		<!-- Matching Inquiries -->
		<div v-else-if="inquiries.length" class="space-y-4">
			<article v-for="inquiry in inquiries" :key="inquiry.id"
				class="group overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-md">
				<div class="p-5 sm:p-6">
					<div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
						<div class="min-w-0">
							<div class="flex flex-wrap items-center gap-2.5">
								<div
									class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
									<IconBase :name="eventTypeIcon(inquiry.event_type)" class="h-5 w-5" />
								</div>

								<div class="min-w-0">
									<div class="flex flex-wrap items-center gap-2">
										<h3 class="truncate text-lg font-bold text-gray-900">
											{{ inquiry.event_title || `${inquiry.event_type} Inquiry` }}
										</h3>

										<span
											class="inline-flex items-center gap-1 rounded-full border border-green-200 bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
											<IconBase name="check-circle" class="h-3.5 w-3.5" />
											Matched
										</span>
										<span v-if="inquiry.direct"
											class="inline-flex items-center gap-1 rounded-full border border-primary-200 bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700"
											title="The client asked only you for a quotation">
											<IconBase name="send" class="h-3.5 w-3.5" />
											Direct request
										</span>
									</div>

									<div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500">
										<span>
											Posted by
											<span class="font-semibold text-gray-700">
												{{ clientName(inquiry) }}
											</span>
										</span>

										<span class="text-gray-300">•</span>

										<span>{{ formatDate(inquiry.created_at) }}</span>
									</div>
								</div>
							</div>
						</div>

						<div v-if="offerError"
							class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
							{{ offerError }}
						</div>

						<div v-if="offerSuccess" class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3">
							<div class="flex items-start gap-3">
								<IconBase name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-green-600" />

								<div>
									<p class="text-sm font-bold text-green-800">
										Quotation submitted successfully
									</p>

									<p class="mt-0.5 text-sm text-green-700">
										Your quotation has been sent to the client.
									</p>
								</div>
							</div>
						</div>
					</div>

					<div class="mt-5 flex flex-wrap items-center gap-2">
						<span class="text-xs font-semibold uppercase tracking-wide text-gray-400">
							Matched service
						</span>

						<span class="rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700">
							{{ inquiry.event_type }}
						</span>
					</div>

					<div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
						<div class="rounded-xl border border-gray-100 bg-gray-50/80 p-4">
							<div class="flex items-center gap-2 text-xs font-medium text-gray-500">
								<IconBase name="calendar" class="h-4 w-4 text-primary-700" />
								Event Date
							</div>

							<div class="mt-2 text-sm font-bold text-gray-900">
								{{ formatDate(inquiry.event_date) }}
							</div>
							<div class="mt-1 flex items-center gap-1.5 text-xs font-semibold text-primary-700">
								<IconBase name="clock" class="h-3.5 w-3.5" />
								{{ formatSchedule(inquiry.start_time, inquiry.end_time) }}
							</div>
						</div>

						<div class="rounded-xl border border-gray-100 bg-gray-50/80 p-4">
							<div class="flex items-center gap-2 text-xs font-medium text-gray-500">
								<IconBase name="map-pin" class="h-4 w-4 text-primary-700" />
								Location
							</div>

							<div class="mt-2 truncate text-sm font-bold text-gray-900">
								{{ inquiry.location }}
							</div>
						</div>

						<div class="rounded-xl border border-gray-100 bg-gray-50/80 p-4">
							<div class="flex items-center gap-2 text-xs font-medium text-gray-500">
								<IconBase name="users" class="h-4 w-4 text-primary-700" />
								Guests
							</div>

							<div class="mt-2 text-sm font-bold text-gray-900">
								{{ inquiry.expected_guests }}
							</div>
						</div>

						<div class="rounded-xl border border-gray-100 bg-gray-50/80 p-4">
							<div class="flex items-center gap-2 text-xs font-medium text-gray-500">
								<IconBase name="cash" class="h-4 w-4 text-primary-700" />
								Budget
							</div>

							<div class="mt-2 text-sm font-bold text-gray-900">
								{{ inquiry.budget_range }}
							</div>
						</div>
					</div>

					<div v-if="inquiry.additional_details" class="mt-4 rounded-xl border border-gray-100 bg-white p-4">
						<div
							class="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-400">
							<IconBase name="message-square" class="h-3.5 w-3.5" />
							Client notes
						</div>

						<p class="whitespace-pre-line text-sm leading-6 text-gray-600">
							{{ inquiry.additional_details }}
						</p>
					</div>
				</div>

				<div
					class="flex flex-col gap-3 border-t border-gray-100 bg-gray-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
					<p class="text-xs text-gray-500">
						Submit a detailed offer to improve your chances of being selected.
					</p>

					<div class="flex flex-col gap-2 sm:flex-row">
						<button type="button"
							class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50"
							@click="askQuestion(inquiry)">
							<IconBase name="message" class="h-4 w-4" />
							Ask Question
							<span v-if="threads.unread.value[String(inquiry.id)]"
								class="rounded-full bg-red-500 px-1.5 text-[10px] font-bold text-white">{{ threads.unread.value[String(inquiry.id)] }}</span>
						</button>

						<button type="button"
							class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-900"
							@click="openOfferForm(inquiry)">
							<IconBase name="send" class="h-4 w-4" />
							Submit Offer
						</button>
					</div>
				</div>
			</article>
		</div>

		<!-- Empty -->
		<div v-else class="rounded-2xl border-2 border-dashed border-gray-200 bg-white px-6 py-16 text-center">
			<div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
				<IconBase name="search" class="h-6 w-6" />
			</div>

			<h2 class="mt-4 text-lg font-bold text-gray-900">
				No matching inquiries yet
			</h2>

			<p class="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500">
				When a client posts an inquiry that matches your service tags,
				it will appear here automatically.
			</p>
		</div>

		<Transition name="fade">
			<div v-if="showOfferForm" class="fixed inset-0 z-40 bg-gray-900/40" @click="closeOfferForm" />
		</Transition>

		<Transition name="slide">
			<div v-if="showOfferForm"
				class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-2xl">

				<div class="flex shrink-0 items-start justify-between border-b border-gray-200 px-6 py-5">
					<div class="flex items-start gap-3">
						<div
							class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-700 text-white">
							<IconBase name="file-text" class="h-5 w-5" />
						</div>

						<div>
							<h2 class="text-base font-bold text-gray-900">
								New Offer
							</h2>

							<p class="text-sm text-gray-500">
								Fill in your event offer details
							</p>
						</div>
					</div>

					<button type="button" class="text-gray-400 hover:text-gray-600" :disabled="isSubmittingOffer"
						@click="closeOfferForm">
						<IconBase name="x" class="h-5 w-5" />
					</button>
				</div>

				<!-- Body -->
				<div class="flex-1 overflow-y-auto px-6 py-5">
					<FormsDraftNotice :draft="offerDraft" />

					<!-- Error -->
					<div v-if="offerError"
						class="mb-5 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3">
						<IconBase name="alert-circle" class="mt-0.5 h-5 w-5 shrink-0 text-red-500" />

						<div>
							<p class="text-sm font-semibold text-red-800">
								Unable to submit quotation
							</p>

							<p class="mt-0.5 text-sm text-red-700">
								{{ offerError }}
							</p>
						</div>
					</div>

					<!-- Success -->
					<div v-if="offerSuccess"
						class="mb-5 flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3">
						<IconBase name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-green-600" />

						<div>
							<p class="text-sm font-semibold text-green-800">
								Quotation submitted successfully
							</p>

							<p class="mt-0.5 text-sm text-green-700">
								Your quotation has been sent to the client.
							</p>
						</div>
					</div>

					<div>
						<FormsLabel text="Event / Inquiry Title" />

						<FormsTextField v-model="offerForm.title" disabled />
					</div>

					<div class="mt-4 grid grid-cols-2 gap-3">
						<div>
							<FormsLabel text="Event Type" />

							<FormsTextField v-model="offerForm.eventType" disabled />
						</div>

						<div>
							<FormsLabel text="Event Date" />

							<FormsDateField v-model="offerForm.eventDate" name="event_date" placeholder="Event date"
								disabled />
						</div>
					</div>

					<div class="mt-4 grid grid-cols-2 gap-3">
						<div>
							<FormsLabel text="Location / Area" />

							<FormsTextField v-model="offerForm.location" disabled />
						</div>

						<div>
							<FormsLabel text="Expected Guests" />

							<FormsTextField v-model="offerForm.guests" type="number" disabled />
						</div>
					</div>

					<!-- Build the offer from the price list: an itemized quote instead of one lump sum. -->
					<div class="mt-5 rounded-2xl border border-primary-100 bg-primary-50/40 p-4">
						<div class="flex flex-wrap items-center justify-between gap-2">
							<div>
								<p class="text-sm font-bold text-gray-900">Build from my price list</p>
								<p class="text-xs text-gray-500">Pick options and quantities; the lines and total go into your offer.</p>
							</div>
							<button type="button" class="rounded-lg border border-primary-200 bg-white px-3 py-1.5 text-xs font-bold text-primary-700"
								@click="toggleBuilder">{{ showBuilder ? 'Close' : offerLines.length ? 'Change' : 'Open' }}</button>
						</div>
						<div v-if="showBuilder" class="mt-4">
							<p v-if="builderLoading" class="text-sm text-gray-500">Loading your price list…</p>
							<p v-else-if="!builderOptions.length" class="text-sm text-gray-500">
								Your price list is empty. <NuxtLink to="/organizer/price-list" class="font-semibold text-primary-700 underline">Add options</NuxtLink> to build offers from it.
							</p>
							<PricingPriceCalculator v-else :options="builderOptions" :services="builderServices" :settings="builderSettings"
								:initial-guests="Number(offerForm.guests) || 1">
								<template #action="{ estimate, guests }">
									<div class="mt-3 flex justify-end">
										<button type="button" class="rounded-lg bg-primary-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-50"
											:disabled="!estimate.lines.length" @click="useEstimate(estimate, guests)">Use these in my offer</button>
									</div>
								</template>
							</PricingPriceCalculator>
						</div>
						<div v-if="offerLines.length && !showBuilder" class="mt-3 rounded-xl bg-white p-3 text-sm">
							<div v-for="(line, i) in offerLines" :key="i" class="flex justify-between gap-3 py-0.5">
								<span class="text-gray-700">{{ line.description }} <span class="text-xs text-gray-400">× {{ Number(line.quantity).toLocaleString('en-PH') }}</span></span>
								<span class="font-semibold text-gray-800">{{ formatPeso(lineSubtotal(line.quantity, line.unit_price)) }}</span>
							</div>
							<button type="button" class="mt-2 text-xs font-semibold text-rose-600" @click="clearLines">Remove breakdown (enter one amount instead)</button>
						</div>
					</div>

					<div class="mt-4" data-field="budget">
						<FormsLabel text="Your Quotation Amount" required />

						<FormsTextField v-model="offerForm.budget" icon="cash" placeholder="e.g. ₱240,000" :disabled="offerLines.length > 0" :error="amountError" />

						<p v-if="!amountError" class="mt-1 text-xs text-gray-400">
							{{ offerLines.length ? 'The total of your itemized lines.' : 'Enter one final quotation amount, or build it from your price list above.' }}
						</p>
					</div>

					<div class="mt-4">
						<FormsLabel text="Package / Offer Name" />

						<FormsTextField v-model="offerForm.packageName"
							placeholder="e.g. Full Coordination Premium Package" />
					</div>

					<div class="mt-4">
						<FormsLabel text="Timeline / Lead Time" />

						<FormsTextField v-model="offerForm.timeline" icon="clock"
							placeholder="e.g. 3 months lead time" />
					</div>

					<label class="mb-1.5 mt-4 block text-sm font-semibold text-gray-700">
						Inclusions / Services
					</label>

					<div class="space-y-2">
						<FormsTextField v-for="(_, index) in offerForm.inclusions" :key="index"
							v-model="offerForm.inclusions[index]" :placeholder="`Inclusion ${index + 1}`" />
					</div>

					<button type="button" class="mt-2 flex items-center gap-1 text-sm font-semibold text-primary-700"
						@click="addInclusion">
						<IconBase name="plus" class="h-3.5 w-3.5" />

						Add Inclusion
					</button>

					<label class="mb-1.5 mt-5 block text-sm font-semibold text-gray-700">
						Your pitch to the client
					</label>

					<textarea v-model="offerForm.pitch" rows="4"
						placeholder="Introduce yourself and explain why you're the best fit for this event..."
						class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15" />
				</div>

				<!-- Footer -->
				<div class="flex shrink-0 gap-3 border-t border-gray-200 px-6 py-4">
					<FormsButton variant="secondary" class="flex-1" :disabled="isSubmittingOffer"
						@click="closeOfferForm">
						Cancel
					</FormsButton>

					<FormsButton class="flex-1" :disabled="isSubmittingOffer ||
						!!offerSuccess
						" @click="requestOfferSubmission">
						<IconBase :name="isSubmittingOffer
							? 'refresh-cw'
							: offerSuccess
								? 'check-circle'
								: 'send'
							" class="h-4 w-4" :class="{
								'animate-spin':
									isSubmittingOffer,
							}" />

						{{
							isSubmittingOffer
								? 'Submitting...'
								: offerSuccess
									? 'Submitted'
									: 'Submit Offer'
						}}
					</FormsButton>
				</div>
			</div>
		</Transition>

		<FormsConfirmationModal :open="showOfferConfirmation" eyebrow="Ready to send" title="Submit this offer?"
			description="Review the key details below. The client will receive this quotation and can compare it with other offers before making a final selection."
			icon="file-text" confirm-icon="send" confirm-label="Yes, submit offer" cancel-label="Review offer"
			loading-label="Submitting offer..." :loading="isSubmittingOffer" @cancel="showOfferConfirmation = false"
			@confirm="submitOffer">
			<div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
				<div class="flex items-start justify-between gap-4">
					<div class="min-w-0">
						<p class="text-xs font-semibold uppercase tracking-wider text-gray-400">Event inquiry</p>
						<p class="mt-1 truncate text-base font-bold text-gray-900">{{ offerForm.title }}</p>
					</div>
					<p class="shrink-0 text-lg font-black text-primary-700">{{ formattedOfferAmount }}</p>
				</div>

				<div class="mt-4 grid grid-cols-2 gap-3 border-t border-gray-200 pt-4 text-sm">
					<div>
						<p class="text-xs text-gray-400">Package</p>
						<p class="mt-1 font-semibold text-gray-700">{{ offerForm.packageName.trim() || 'Custom offer' }}</p>
					</div>
					<div>
						<p class="text-xs text-gray-400">Timeline</p>
						<p class="mt-1 font-semibold text-gray-700">{{ offerForm.timeline.trim() || 'Not specified' }}</p>
					</div>
				</div>
			</div>
		</FormsConfirmationModal>
	</div>
</template>

<script setup lang="ts">
import type { EstimateSettings } from '~/utils/priceCalc'
interface InquiryUser {
	id: number
	firstname: string
	middlename: string | null
	lastname: string
}

interface InquiryClient {
	id: number
	user_id: number
	user?: InquiryUser
}

interface MatchingInquiry {
	id: number
	client_id: number
	event_title: string | null
	event_type: string
	event_date: string
	start_time: string | null
	/** The client asked only this organizer (not an open posting). */
	direct?: boolean
	end_time: string | null
	location: string
	expected_guests: number
	budget_range: string
	additional_details: string | null
	created_at: string
	updated_at: string
	quotations_count?: number
	client?: InquiryClient
}

interface MatchingInquiryResponse {
	message?: string
	data: MatchingInquiry[]
}

interface OrganizerOfferSummary {
	id: number
	inquiry_id: number
	organizer_id: number
	quotation_status:
	| 'pending'
	| 'accepted'
	| 'rejected'
	| 'withdrawn'
}

interface OrganizerOffersResponse {
	data: OrganizerOfferSummary[]
}

interface SubmitQuotationResponse {
	message: string
	data: unknown
}

definePageMeta({
	layout: 'dashboard',
	middleware: ['organizer'],
})

const {
	token,
} = useAuth()

const config =
	useRuntimeConfig()

const inquiries =
	ref<MatchingInquiry[]>([])

const matchingInquiryCount =
	useState<number>(
		'matchingInquiryCount',
		() => 0,
	)

const myOffersCount =
	useState<number>(
		'myOffersCount',
		() => 0,
	)

const isLoading =
	ref(false)

const errorMessage =
	ref('')

const showOfferForm =
	ref(false)

const showOfferConfirmation =
	ref(false)

const selectedInquiryId =
	ref<number | null>(null)

const isSubmittingOffer =
	ref(false)

const offerError =
	ref('')

const offerSuccess =
	ref('')

function emptyOfferForm() {
	return {
		title: '',
		eventType: '',
		eventDate: '',
		location: '',
		guests: '',
		budget: '',
		packageName: '',
		timeline: '',
		inclusions: [''],
		pitch: '',
	}
}

const offerForm =
	reactive(
		emptyOfferForm(),
	)

// Unfinished offer per inquiry (the price, package, timeline, inclusions and pitch you typed).
const offerDraft = useDraft(() => (showOfferForm.value && selectedInquiryId.value ? `offer:${selectedInquiryId.value}` : null), offerForm, {
	isEmpty: v => !v.budget?.trim() && !v.packageName?.trim() && !v.timeline?.trim() && !v.pitch?.trim() && (v.inclusions ?? []).every(i => !String(i).trim()),
})

function parseOfferAmount() {
	return Number(
		offerForm.budget.replace(/[₱,\s]/g, ''),
	)
}

const formattedOfferAmount = computed(() => {
	const amount = parseOfferAmount()

	if (Number.isNaN(amount) || amount <= 0) return 'Not set'

	return new Intl.NumberFormat('en-PH', {
		style: 'currency',
		currency: 'PHP',
		minimumFractionDigits: 2,
	}).format(amount)
})

const peso = (v: string | null) => (v ? `₱${Number(v).toLocaleString('en-PH')}` : null)
let orgId = ''

// Private messages with the client (one thread per inquiry invitation).
const threads = useOrganizerThreads()
const threadError = ref('')

async function askQuestion(inquiry: { id: number | string, event_title?: string | null, event_type?: string | null }) {
	threadError.value = ''
	try {
		await threads.openFor(orgId, String(inquiry.id), inquiry.event_title || inquiry.event_type || 'Inquiry')
		if (!threads.open.value) threadError.value = 'Messages open once this inquiry is shared with you.'
	} catch (error: unknown) {
		threadError.value = authMessage(error) || 'Unable to open messages.'
	}
}

/** Open inquiries this organizer was invited to (or matched with) and hasn't quoted yet. */
async function loadMatchingInquiries() {
	isLoading.value = true
	errorMessage.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId = org.id
		void threads.loadUnread(orgId)
		const [invited, offers] = await Promise.all([
			allItems(page => useOrganizerInquiries().list(orgId, ['pending', 'viewed'], page)),
			useOrganizerQuotations().list(orgId).catch(() => null),
		])
		inquiries.value = invited
			.filter(i => i.is_open && (!i.quotation || i.quotation.status === 'draft'))
			.map(i => {
				const [firstname, ...rest] = i.client.split(' ')
				return {
					id: i.inquiry_id as unknown as number,
					client_id: 0,
					event_title: `${i.event_type ?? 'Event'} for ${i.client}`,
					event_type: i.event_type ?? 'Other',
					event_date: i.requested_date ?? '',
					// Post an Event writes "Time: 16:00 – 21:00" into the notes.
					start_time: i.additional_requirements?.match(/Time: (\d{2}:\d{2})/)?.[1] ?? null,
					end_time: i.additional_requirements?.match(/Time: \d{2}:\d{2} – (\d{2}:\d{2})/)?.[1] ?? null,
					direct: i.via === 'direct',
					location: i.location_text ?? 'Location to be discussed',
					expected_guests: i.guest_count ?? 0,
					budget_range: [peso(i.budget_min), peso(i.budget_max)].filter(Boolean).join(' – ') || 'Open budget',
					additional_details: [
						i.additional_requirements?.replace(/^Time: .*\n?/m, '').trim(),
						...i.services.map(sv => sv.name ? `• ${sv.name}${sv.notes ? ` (${sv.notes})` : ''}` : null),
					].filter(Boolean).join('\n') || null,
					created_at: i.invited_at,
					updated_at: i.responded_at ?? i.invited_at,
					quotations_count: 0,
					client: { id: 0, user_id: 0, user: { id: 0, firstname: firstname ?? i.client, middlename: null, lastname: rest.join(' ') } },
				}
			})
		matchingInquiryCount.value = inquiries.value.length
		myOffersCount.value = offers?.pagination.total ?? 0
	} catch (error: unknown) {
		inquiries.value = []
		matchingInquiryCount.value = 0
		myOffersCount.value = 0
		errorMessage.value = authMessage(error) || 'Unable to load matching inquiries.'
	} finally {
		isLoading.value = false
	}
}

const eventTypeIcons:
	Record<string, string> = {
	Wedding: 'heart',
	Corporate: 'briefcase',
	Birthday: 'gift',
	Debut: 'star',
	Concert: 'music',
	Conference: 'users',
	Reunion: 'users',
	Seminar: 'award',
}

function eventTypeIcon(
	type: string,
): string {
	return (
		eventTypeIcons[type] ??
		'sparkles'
	)
}

function clientName(
	inquiry: MatchingInquiry,
): string {
	const clientUser =
		inquiry.client?.user

	if (!clientUser) {
		return 'Client'
	}

	return [
		clientUser.firstname,
		clientUser.middlename,
		clientUser.lastname,
	]
		.filter(Boolean)
		.join(' ')
}

function formatDate(
	dateString: string,
): string {
	if (!dateString) {
		return ''
	}

	const date =
		dateString.includes('T')
			? new Date(dateString)
			: new Date(
				`${dateString}T00:00:00`,
			)

	return date.toLocaleDateString(
		'en-US',
		{
			month: 'long',
			day: 'numeric',
			year: 'numeric',
		},
	)
}

function formatTime(value: string | null): string {
	if (!value) return 'Not set'
	const [hours, minutes] = value.substring(0, 5).split(':').map(Number)
	return new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit' })
		.format(new Date(2000, 0, 1, hours, minutes))
}

function formatSchedule(start: string | null, end: string | null): string {
	if (!start && !end) return 'Time not set'
	return `${formatTime(start)} – ${formatTime(end)}`
}

function openOfferForm(
	inquiry: MatchingInquiry,
) {
	Object.assign(
		offerForm,
		emptyOfferForm(),
	)
	// A fresh offer: no breakdown carried over from another inquiry.
	offerLines.value = []
	amountError.value = ''
	showBuilder.value = false

	selectedInquiryId.value =
		inquiry.id

	offerError.value = ''
	offerSuccess.value = ''

	offerForm.title =
		inquiry.event_title ||
		`${inquiry.event_type} Inquiry`

	offerForm.eventType =
		inquiry.event_type

	offerForm.eventDate =
		inquiry.event_date.substring(
			0,
			10,
		)

	offerForm.location =
		inquiry.location

	offerForm.guests =
		String(
			inquiry.expected_guests,
		)

	showOfferForm.value = true
}

function closeOfferForm() {
	if (
		isSubmittingOffer.value
	) {
		return
	}

	// Keep what was typed (tapping outside or Cancel), before the form is emptied.
	offerDraft.flush()

	showOfferForm.value = false
	showOfferConfirmation.value = false

	selectedInquiryId.value = null

	offerError.value = ''
	offerSuccess.value = ''

	Object.assign(
		offerForm,
		emptyOfferForm(),
	)
}

function addInclusion() {
	offerForm.inclusions.push('')
}

function validateOffer() {
	if (
		!selectedInquiryId.value
	) {
		offerError.value =
			'No inquiry was selected.'

		return null
	}

	if (!token.value) {
		offerError.value =
			'Your session has expired. Please log in again.'

		return null
	}

	if (
		!offerForm.budget.trim()
	) {
		amountError.value = 'Enter your quotation amount, or build it from your price list.'
		document.querySelector('[data-field="budget"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })

		return null
	}

	const quotationAmount = parseOfferAmount()

	if (
		Number.isNaN(
			quotationAmount,
		) ||
		quotationAmount <= 0
	) {
		amountError.value = 'Enter an amount greater than zero, e.g. 240000.'
		document.querySelector('[data-field="budget"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })

		return null
	}

	offerError.value = ''
	amountError.value = ''
	return quotationAmount
}

function requestOfferSubmission() {
	if (validateOffer() === null) return

	showOfferConfirmation.value = true
}

// ---- offer builder (price list → itemized quotation lines) ----------------------
const amountError = ref('')
const showBuilder = ref(false)
const builderLoading = ref(false)
const builderOptions = ref<import('~/composables/useQuotations').PriceOption[]>([])
const builderServices = ref<{ id: string, name: string }[]>([])
const builderSettings = ref<EstimateSettings>({})
const offerLines = ref<import('~/composables/useQuotations').QuotationLine[]>([])

async function toggleBuilder() {
	showBuilder.value = !showBuilder.value
	if (!showBuilder.value || builderOptions.value.length || builderLoading.value) return
	builderLoading.value = true
	try {
		const org = await useOrganization().fetchOrganization()
		const [list, services] = await Promise.all([usePriceOptions().list(orgId), useOrganization().services()])
		builderOptions.value = list.filter(o => o.is_active)
		builderServices.value = services.map(sv => ({ id: sv.id, name: sv.custom_name || sv.name }))
		const pr = org?.profile as { estimate_service_charge_percent?: number | null, estimate_tax_percent?: number | null } | undefined
		builderSettings.value = { service_charge_percent: pr?.estimate_service_charge_percent ?? null, tax_percent: pr?.estimate_tax_percent ?? null }
	} catch (e: unknown) {
		offerError.value = authMessage(e) || 'Unable to load your price list.'
	} finally {
		builderLoading.value = false
	}
}

/** Turn the estimate into quotation lines (charges as their own lines) and fill the offer. */
function useEstimate(estimate: import('~/utils/priceCalc').Estimate, guests: number) {
	const byId = new Map(builderOptions.value.map(o => [o.id, o]))
	const lines = estimate.lines.map((l) => {
		const o = byId.get(l.id)!
		return { organization_service_id: o.organization_service_id, description: `${o.name}${unitSuffix(o)}`, quantity: l.quantity, unit_price: o.price }
	})
	if (estimate.serviceCharge) lines.push({ organization_service_id: null, description: `Service charge (${estimate.serviceChargePercent}%)`, quantity: 1, unit_price: String(estimate.serviceCharge) })
	if (estimate.tax) lines.push({ organization_service_id: null, description: `VAT (${estimate.taxPercent}%)`, quantity: 1, unit_price: String(estimate.tax) })
	offerLines.value = lines
	offerForm.budget = String(estimate.total)
	amountError.value = ''
	if (!offerForm.inclusions.some(i => i.trim())) offerForm.inclusions = estimate.lines.map(l => `${l.label}: ${l.detail}`)
	if (guests && !offerForm.guests) offerForm.guests = String(guests)
	showBuilder.value = false
}

function clearLines() {
	offerLines.value = []
	offerForm.budget = ''
}

async function submitOffer() {
	const quotationAmount = validateOffer()

	if (quotationAmount === null) {
		showOfferConfirmation.value = false
		return
	}

	offerError.value = ''
	offerSuccess.value = ''

	isSubmittingOffer.value = true

	try {
		// One line for the package price; the inclusions and pitch go in the proposal text.
		const inclusions = offerForm.inclusions.map(item => item.trim()).filter(Boolean)
		const proposal = [offerForm.pitch.trim(), inclusions.length ? `Inclusions:\n${inclusions.map(i => `• ${i}`).join('\n')}` : ''].filter(Boolean).join('\n\n')
		await useOrganizerQuotations().create(orgId, String(selectedInquiryId.value), {
			package_name: offerForm.packageName.trim() || null,
			proposal: proposal || null,
			terms: offerForm.timeline.trim() || null,
			items: offerLines.value.length
				? offerLines.value
				: [{ description: offerForm.packageName.trim() || 'Event package', quantity: 1, unit_price: quotationAmount }],
		}, true)
		const response = { message: 'Quotation sent to the client.' }

		offerSuccess.value =
			response.message ||
			'Quotation submitted successfully.'

		showOfferConfirmation.value = false
		offerDraft.clear()

		await loadMatchingInquiries()

		window.setTimeout(
			() => {
				showOfferForm.value =
					false

				selectedInquiryId.value =
					null

				Object.assign(
					offerForm,
					emptyOfferForm(),
				)

				offerSuccess.value =
					''
			},
			1500,
		)

	} catch (error: unknown) {
		showOfferConfirmation.value = false

		if (error instanceof ApiError) {
			offerError.value = authMessage(error)
			return
		}

		if (
			typeof error === 'object' &&
			error !== null
		) {
			const apiError =
				error as {
					statusCode?: number
					status?: number

					data?: {
						message?: string

						errors?: Record<
							string,
							string[]
						>
					}
				}

			if (
				apiError.data?.message
			) {
				offerError.value =
					apiError.data.message

				return
			}

			const validationErrors =
				apiError.data?.errors

			if (
				validationErrors
			) {
				const firstError =
					Object.values(
						validationErrors,
					)[0]?.[0]

				if (firstError) {
					offerError.value =
						firstError

					return
				}
			}
		}

		offerError.value =
			'Unable to submit your quotation. Please try again.'
	} finally {
		isSubmittingOffer.value =
			false
	}
}

onMounted(() => {
	loadMatchingInquiries()
})
</script>

<style scoped>
.slide-enter-active,
.slide-leave-active {
	transition: transform 0.25s ease;
}

.slide-enter-from,
.slide-leave-to {
	transform: translateX(100%);
}

.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
	opacity: 0;
}
</style>
