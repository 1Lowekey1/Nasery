<template>
	<div class="space-y-5">
		<!-- Toolbar -->
		<div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
			<div class="ev-seg" role="tablist" aria-label="Filter events">
				<span class="ev-seg-thumb" aria-hidden="true" :style="{ width: `calc((100% - 0.5rem) / ${eventFilters.length})`, transform: `translateX(${filterIndex * 100}%)` }" />
				<button v-for="f in eventFilters" :key="f.value" type="button" role="tab" :aria-selected="eventFilter === f.value" :class="eventFilter === f.value ? 'is-on' : ''" @click="eventFilter = f.value">
					{{ f.label }} <span class="tabular-nums opacity-50">{{ f.count }}</span>
				</button>
			</div>
			<div class="flex items-center gap-3">
				<span v-if="isLoading" class="flex items-center gap-2 text-xs text-gray-400"><IconBase name="refresh-cw" class="h-3.5 w-3.5 animate-spin" /> Refreshing</span>
				<div class="relative w-full lg:w-72">
					<IconBase name="search" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
					<input v-model="eventSearch" type="search" placeholder="Search events or places" aria-label="Search events" class="h-11 w-full rounded-xl border border-gray-200 bg-white pl-10 pr-3.5 text-sm outline-none transition placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10" />
				</div>
			</div>
		</div>

		<div v-if="errorMessage && !planningItems.length" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">{{ errorMessage }}</div>

		<!-- Loading skeleton -->
		<div v-else-if="isLoading && !planningItems.length" class="space-y-4">
			<div v-for="n in 3" :key="n" class="h-40 animate-pulse rounded-3xl bg-white ring-1 ring-gray-200" />
		</div>

		<!-- Tickets -->
		<Transition v-else-if="visibleItems.length" name="ev-swap" mode="out-in">
			<div :key="eventFilter" class="space-y-4">
				<article v-for="(item, index) in visibleItems" :id="item.event ? `event-${item.event.id}` : `quotation-${item.quotation?.id}`" :key="item.key"
					class="ev-ticket group" :class="[kindOf(item).ticket, selectedEventId && item.event?.id === selectedEventId ? 'is-selected' : '']" :style="{ '--i': index }"
					tabindex="0" @click="openPlanningItem(item)" @keydown.enter="openPlanningItem(item)">
					<!-- Date stub -->
					<div class="ev-stub" :class="kindOf(item).stub">
						<span class="text-[11px] font-bold uppercase tracking-wider opacity-80">{{ dateParts(item.eventDate).month }}</span>
						<span class="text-4xl font-extrabold leading-none tabular-nums">{{ dateParts(item.eventDate).day }}</span>
						<span class="text-xs font-medium opacity-70">{{ dateParts(item.eventDate).weekday }}</span>
					</div>
					<div class="ev-perf" aria-hidden="true" />

					<!-- Body -->
					<div class="min-w-0 flex-1 p-5 sm:pl-6">
						<div class="flex flex-wrap items-center gap-2">
							<span class="ev-pill" :class="kindOf(item).pill"><span class="h-1.5 w-1.5 rounded-full bg-current" />{{ kindOf(item).label }}</span>
							<span v-if="countdown(item.eventDate)" class="text-xs font-medium text-gray-400">{{ countdown(item.eventDate) }}</span>
						</div>
						<h3 class="mt-2 truncate text-lg font-bold text-gray-950">{{ item.name }}</h3>
						<p class="mt-0.5 flex min-w-0 items-center gap-1.5 text-sm text-gray-500" :title="item.location || undefined">
							<IconBase name="map-pin" class="h-4 w-4 shrink-0 text-gray-400" /><span class="truncate">{{ shortPlace(item.location) }}</span>
						</p>

						<dl class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm">
							<div class="flex items-center gap-2"><IconBase name="users" class="h-4 w-4 text-gray-400" /><dt class="sr-only">Expected guests</dt><dd class="font-semibold text-gray-900">{{ guestsOf(item) }} <span class="font-normal text-gray-500">guests</span></dd></div>
							<div class="flex items-center gap-2"><IconBase name="clock" class="h-4 w-4 text-gray-400" /><dt class="sr-only">Time</dt><dd class="font-semibold text-gray-900">{{ timeRange(item) }}</dd></div>
							<div v-if="item.quotation" class="flex min-w-0 items-center gap-2"><IconBase name="gift" class="h-4 w-4 text-gray-400" /><dt class="sr-only">Package</dt><dd class="truncate font-semibold text-gray-900">{{ item.quotation.package_name || 'Custom package' }}</dd></div>
							<div v-if="item.quotation" class="flex items-center gap-2"><IconBase name="cash" class="h-4 w-4 text-gray-400" /><dt class="sr-only">{{ item.quotation.quotation_status === 'accepted' ? 'Agreed amount' : 'Offer amount' }}</dt><dd class="font-bold text-primary-700">{{ formatCurrency(item.quotation.quotation_amount) }}</dd></div>
							<div v-if="item.quotation?.timeline" class="flex items-center gap-2"><IconBase name="calendar" class="h-4 w-4 text-gray-400" /><dt class="sr-only">Timeline</dt><dd class="text-gray-600">Timeline: <span class="font-semibold text-gray-900">{{ item.quotation.timeline }}</span></dd></div>
						</dl>
					</div>

					<!-- Readiness + actions -->
					<div class="flex shrink-0 items-center gap-4 border-t border-dashed border-gray-200 px-5 py-4 sm:border-l sm:border-t-0 sm:px-6 lg:w-[260px] lg:flex-col lg:items-stretch lg:justify-center">
						<div v-if="item.event || item.quotation?.quotation_status === 'accepted'" class="flex items-center gap-3">
							<div class="relative h-14 w-14 shrink-0">
								<svg viewBox="0 0 56 56" class="h-full w-full -rotate-90" aria-hidden="true">
									<circle cx="28" cy="28" r="23" fill="none" stroke="#eef0f2" stroke-width="5" />
									<circle cx="28" cy="28" r="23" fill="none" stroke="#285F6b" stroke-width="5" stroke-linecap="round" :stroke-dasharray="RING" :stroke-dashoffset="RING * (1 - (ringsReady ? readiness(item) : 0) / 100)" class="transition-[stroke-dashoffset] duration-1000 ease-out" />
								</svg>
								<span class="absolute inset-0 flex items-center justify-center text-xs font-extrabold tabular-nums text-gray-900">{{ readiness(item) }}%</span>
							</div>
							<div class="min-w-0">
								<p class="text-sm font-semibold text-gray-900">Readiness</p>
								<p class="text-xs text-gray-500">{{ readinessHint(item) }}</p>
							</div>
						</div>
						<p v-else class="text-sm text-amber-700">Waiting for the client to decide.</p>

						<div class="ml-auto flex gap-2 lg:ml-0">
							<button v-if="item.event" type="button" class="ev-btn" :aria-label="`Edit ${item.name}`" @click.stop="editEvent(item.event)"><IconBase name="edit" class="h-4 w-4" /> Edit</button>
							<button type="button" class="ev-btn ev-btn-primary flex-1 justify-center" @click.stop="openPlanningItem(item)">
								{{ item.quotation?.quotation_status === 'pending' && !item.event ? 'View offer' : 'Open' }} <IconBase name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" />
							</button>
						</div>
					</div>
				</article>
			</div>
		</Transition>

		<!-- Empty -->
		<div v-else-if="!isLoading" class="rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
			<span class="ev-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700"><IconBase name="calendar" class="h-7 w-7" /></span>
			<h3 class="mt-4 font-bold text-gray-900">{{ planningItems.length ? 'No events match' : 'No events yet' }}</h3>
			<p class="mx-auto mt-1 max-w-md text-sm text-gray-500">{{ planningItems.length ? 'Try another filter or search.' : 'Bids your clients accept and events you create yourself show up here.' }}</p>
			<button v-if="planningItems.length" type="button" class="mt-4 text-sm font-semibold text-primary-700 hover:underline" @click="eventFilter = 'all'; eventSearch = ''">Show all events</button>
			<p v-else class="mt-3 text-sm text-gray-500">Use <span class="font-semibold">Create event</span> above, or
				<NuxtLink to="/organizer/inquiries/match-inquiries" class="font-semibold text-primary-700 hover:underline">win one from an inquiry</NuxtLink>.</p>
		</div>
	</div>
</template>

<script setup lang="ts">
interface OrganizerEvent {
	id: number
	inquiry_id: number | null
	quotation_id: number | null

	name: string
	event_type: string
	description: string | null

	event_date: string | null
	location: string | null

	expected_guests: number | null

	start_time: string | null
	end_time: string | null

	status: string
}

interface OfferInquiry {
	id: number

	event_title: string | null
	event_type: string

	event_date: string
	start_time: string | null
	end_time: string | null
	location: string

	expected_guests?: number
}

interface OrganizerQuotation {
	id: number
	inquiry_id: number
	organizer_id: number

	quotation_amount: string | number

	package_name: string | null

	timeline: string | null

	quotation_details: string | null

	quotation_status:
	| 'pending'
	| 'accepted'
	| 'rejected'
	| 'withdrawn'

	inquiry?: OfferInquiry

	event?: OrganizerEvent | null
}

interface EventResponse {
	data: OrganizerEvent[]
}

interface QuotationResponse {
	data: OrganizerQuotation[]
}

interface PlanningItem {
	key: string

	name: string

	eventDate: string | null
	startTime: string | null
	endTime: string | null

	location: string | null

	event: OrganizerEvent | null

	quotation: OrganizerQuotation | null
}

interface ActivityTimelineEvent {
	key: string
	eventId: number | null
	quotationId: number | null
	name: string
	eventType: string
	eventDate: string | null
	location: string | null
	status: string
	hasRealEvent: boolean
}

definePageMeta({
	layout: 'dashboard',
	middleware: ['organizer'],
})

const config =
	useRuntimeConfig()

const route =
	useRoute()

const {
	token,
} = useAuth()

const selectedEventId =
	computed(() => routeId(route.query.event))
const events =
	useState<OrganizerEvent[]>(
		'organizerEvents',
		() => [],
	)

const quotations =
	useState<OrganizerQuotation[]>(
		'organizerEventQuotations',
		() => [],
	)

const hasLoadedEvents =
	useState<boolean>(
		'organizerEventsLoaded',
		() => false,
	)

const activityTimelineEvents =
	useState<ActivityTimelineEvent[]>(
		'organizerActivityTimelineEvents',
		() => [],
	)

const isLoading =
	ref(false)

const errorMessage =
	ref('')

const planningItems =
	computed<PlanningItem[]>(() => {
		const items:
			PlanningItem[] = []

		for (
			const event of
			events.value
		) {
			const quotation =
				quotations.value.find(
					item =>
						item.id ===
						event.quotation_id,
				) ?? null

			items.push({
				key:
					`event-${event.id}`,

				name:
					event.name,

				eventDate:
					event.event_date,

				startTime: event.start_time,
				endTime: event.end_time,

				location:
					event.location,

				event,

				quotation,
			})
		}
		for (
			const quotation of
			quotations.value
		) {
			if (['rejected', 'withdrawn'].includes(quotation.quotation_status)) {
				continue
			}

			const alreadyHasEvent =
				events.value.some(
					event =>
						event.quotation_id ===
						quotation.id,
				)

			if (alreadyHasEvent) {
				continue
			}

			items.push({
				key:
					`quotation-${quotation.id}`,

				name:
					quotation.inquiry
						?.event_title ||
					`${quotation.inquiry
						?.event_type ||
					'Event'} Event`,

				eventDate:
					quotation.inquiry
						?.event_date ??
					null,

				startTime: quotation.inquiry?.start_time ?? null,
				endTime: quotation.inquiry?.end_time ?? null,

				location:
					quotation.inquiry
						?.location ??
					null,

				event:
					null,

				quotation,
			})
		}

		return items
	})

function syncActivityTimelineEvents() {
	activityTimelineEvents.value =
		planningItems.value.map(
			item => ({
				key: item.key,
				eventId: item.event?.id ?? null,
				quotationId: item.quotation?.id ?? null,
				name: item.name,
				eventType:
					item.event?.event_type ??
					item.quotation?.inquiry?.event_type ??
					'Event',
				eventDate: item.eventDate,
				location: item.location,
				status: item.event
					? item.event.status
					: 'awarded',
				hasRealEvent: Boolean(item.event),
			}),
		)
}

function formatDate(
	dateString: string | null,
): string {
	if (!dateString) {
		return 'Date not set'
	}

	const date =
		new Date(
			dateString.includes('T')
				? dateString
				: `${dateString}T00:00:00`,
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

function formatCurrency(
	value: string | number,
): string {
	const amount =
		Number(value)

	if (
		Number.isNaN(amount)
	) {
		return String(value)
	}

	return new Intl.NumberFormat(
		'en-PH',
		{
			style: 'currency',
			currency: 'PHP',
			maximumFractionDigits: 0,
		},
	).format(amount)
}

function formatEventStatus(
	status: string,
): string {
	if (!status) {
		return 'Draft'
	}

	return (
		status
			.charAt(0)
			.toUpperCase() +
		status.slice(1)
	)
}

function readiness(
	item: PlanningItem,
): number {
	if (!item.event) {
		return 20
	}

	let score = 20

	if (
		item.event.name
	) {
		score += 10
	}

	if (
		item.event.event_date
	) {
		score += 10
	}

	if (
		item.event.location
	) {
		score += 15
	}

	if (
		item.event.expected_guests
	) {
		score += 10
	}

	if (
		item.event.start_time
	) {
		score += 10
	}

	if (
		item.event.end_time
	) {
		score += 10
	}

	if (
		item.event.description
	) {
		score += 5
	}

	return Math.min(
		score,
		100,
	)
}


async function loadEvents(
	options: {
		silent?: boolean
	} = {},
) {
	const shouldShowLoading =
		!options.silent &&
		!hasLoadedEvents.value

	if (shouldShowLoading) {
		isLoading.value = true
	}

	errorMessage.value = ''

	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		const data = await loadLegacyOrganizerData(org.id)

		events.value = data.events as unknown as OrganizerEvent[]
		quotations.value = data.quotations as unknown as OrganizerQuotation[]

		hasLoadedEvents.value =
			true

		syncActivityTimelineEvents()

		await scrollToSelectedEvent()

	} catch (error: unknown) {
		console.error(
			'Failed to load event management data:',
			error,
		)

		errorMessage.value =
			authMessage(error) ||
			'Unable to load events.'

	} finally {
		isLoading.value =
			false
	}
}

async function scrollToSelectedEvent() {
	if (
		!selectedEventId.value
	) {
		return
	}

	if (
		import.meta.server
	) {
		return
	}

	await nextTick()

	const element =
		document.getElementById(
			`event-${selectedEventId.value}`,
		)

	if (!element) {
		return
	}

	scrollToElement(element, { block: 'center' })
}
async function openPlanningItem(
	item: PlanningItem,
) {
	if (item.event) {
		await viewEvent(
			item.event,
		)

		return
	}
	if (!item.quotation) {
		return
	}

	if (item.quotation.quotation_status === 'pending') {
		await navigateTo('/organizer/inquiries/offers')
		return
	}

	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			quotation:
				item.quotation.id,
		},
	})
}

async function viewEvent(
	event: OrganizerEvent,
) {
	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			event:
				event.id,
		},
	})
}

async function editEvent(
	event: OrganizerEvent,
) {
	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			event:
				event.id,

			mode:
				'edit',
		},
	})
}

watch(
	() =>
		route.query.event,

	() => {
		scrollToSelectedEvent()
	},
)

watch(
	[
		events,
		quotations,
	],
	() => {
		syncActivityTimelineEvents()
	},
	{
		deep: true,
	},
)

onMounted(async () => {
	if (
		!hasLoadedEvents.value
	) {
		await loadEvents()

		return
	}
	await loadEvents({
		silent:
			true,
	})
})

// ---------- Ticket view: filters, search and display helpers ----------
type EventFilter = 'all' | 'upcoming' | 'drafts' | 'offers'
const eventFilter = ref<EventFilter>('all')
const eventSearch = ref('')
const ringsReady = ref(false)
const RING = 2 * Math.PI * 23

function startOfToday() { const d = new Date(); d.setHours(0, 0, 0, 0); return d.getTime() }
function parseDay(value: string | null) { return value ? new Date(value.includes('T') ? value : `${value}T00:00:00`) : null }
function matchesFilter(item: PlanningItem, filter: EventFilter) {
	if (filter === 'offers') return !item.event
	if (filter === 'drafts') return !!item.event && !['published', 'confirmed', 'ongoing', 'completed', 'cancelled'].includes(item.event.status)
	if (filter === 'upcoming') { const d = parseDay(item.eventDate); return !!d && d.getTime() >= startOfToday() }
	return true
}
const eventFilters = computed(() => ([
	{ value: 'all' as const, label: 'All' },
	{ value: 'upcoming' as const, label: 'Upcoming' },
	{ value: 'drafts' as const, label: 'Drafts' },
	{ value: 'offers' as const, label: 'Offers' },
]).map(f => ({ ...f, count: planningItems.value.filter(i => matchesFilter(i, f.value)).length })))
const filterIndex = computed(() => Math.max(0, eventFilters.value.findIndex(f => f.value === eventFilter.value)))
const visibleItems = computed(() => {
	const q = eventSearch.value.trim().toLowerCase()
	return planningItems.value
		.filter(i => matchesFilter(i, eventFilter.value))
		.filter(i => !q || [i.name, i.location, i.quotation?.package_name].filter(Boolean).join(' ').toLowerCase().includes(q))
})

function kindOf(item: PlanningItem) {
	if (!item.event) {
		return item.quotation?.quotation_status === 'accepted'
			? { label: 'Bid awarded · set up the event', pill: 'bg-green-50 text-green-700', stub: 'bg-green-700 text-white', ticket: '' }
			: { label: 'Offer sent · waiting for client', pill: 'bg-amber-50 text-amber-700', stub: 'bg-amber-50 text-amber-800', ticket: 'is-offer' }
	}
	const status = item.event.status
	if (status === 'published' || status === 'confirmed') return { label: formatEventStatus(status), pill: 'bg-green-50 text-green-700', stub: 'bg-primary-700 text-white', ticket: '' }
	if (status === 'ongoing') return { label: 'Happening now', pill: 'bg-primary-50 text-primary-700', stub: 'bg-primary-800 text-white', ticket: '' }
	if (status === 'completed') return { label: 'Completed', pill: 'bg-gray-100 text-gray-600', stub: 'bg-gray-100 text-gray-600', ticket: 'is-muted' }
	if (status === 'cancelled') return { label: 'Cancelled', pill: 'bg-red-50 text-red-600', stub: 'bg-gray-100 text-gray-500', ticket: 'is-muted' }
	return { label: 'Draft', pill: 'bg-gray-100 text-gray-600', stub: 'bg-primary-50 text-primary-800', ticket: '' }
}
function dateParts(value: string | null) {
	const d = parseDay(value)
	if (!d) return { month: 'TBD', day: '—', weekday: 'No date' }
	return { month: d.toLocaleDateString('en-US', { month: 'short' }), day: String(d.getDate()), weekday: d.toLocaleDateString('en-US', { weekday: 'short' }) }
}
function countdown(value: string | null) {
	const d = parseDay(value)
	if (!d) return ''
	const days = Math.round((d.getTime() - startOfToday()) / 86400000)
	if (days === 0) return 'Today'
	if (days === 1) return 'Tomorrow'
	if (days === -1) return 'Yesterday'
	return days > 0 ? `In ${days} days` : `${Math.abs(days)} days ago`
}
function shortPlace(value: string | null) { return value ? value.split(',').map(p => p.trim()).filter(Boolean).slice(0, 2).join(', ') : 'Location not set' }
function guestsOf(item: PlanningItem) { return item.event?.expected_guests ?? item.quotation?.inquiry?.expected_guests ?? 0 }
function timeRange(item: PlanningItem) {
	const start = item.startTime ? formatTime(item.startTime) : ''
	const end = item.endTime ? formatTime(item.endTime) : ''
	if (start && end) return `${start} – ${end}`
	return start || end || 'Time not set'
}
function readinessHint(item: PlanningItem) {
	if (!item.event) return 'Create the event to start planning'
	const missing = [
		!item.event.location && 'location',
		!item.event.expected_guests && 'guest count',
		!item.event.start_time && 'start time',
		!item.event.end_time && 'end time',
		!item.event.description && 'description',
	].filter(Boolean) as string[]
	if (missing.length) return `Add ${missing.slice(0, 2).join(' and ')}`
	return ['published', 'confirmed', 'ongoing', 'completed'].includes(item.event.status) ? 'Everything is set' : 'Ready to publish'
}
onMounted(() => { setTimeout(() => { ringsReady.value = true }, 150) })
</script>

<style scoped>
.ev-seg { position: relative; display: inline-flex; border-radius: 0.875rem; background: #eef0f2; padding: 0.25rem; }
.ev-seg > button { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; gap: 0.35rem; min-width: 6rem; height: 2.25rem; padding: 0 0.85rem; border-radius: 0.625rem; font-size: 0.875rem; font-weight: 600; color: #6b7280; transition: color 0.2s; }
.ev-seg > button:hover { color: #1f2937; }
.ev-seg > button.is-on { color: #1c4f5c; }
.ev-seg > button:focus-visible { outline: 2px solid #346f7a; outline-offset: 2px; }
/* Phones: the tabs share the full width equally (the thumb assumes equal widths). */
@media (max-width: 639px) {
	.ev-seg { display: flex; width: 100%; }
	.ev-seg > button { flex: 1 1 0; min-width: 0; padding: 0 0.4rem; font-size: 0.8125rem; }
}
.ev-seg-thumb { position: absolute; top: 0.25rem; bottom: 0.25rem; left: 0.25rem; border-radius: 0.625rem; background: white; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08), 0 1px 3px rgba(16, 24, 40, 0.06); transition: transform 0.32s cubic-bezier(0.3, 1.3, 0.5, 1); }

/* Ticket card */
.ev-ticket {
	position: relative; display: flex; flex-direction: column; cursor: pointer; border-radius: 1.5rem; border: 1px solid #e5e7eb; background: white;
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
	animation: ev-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 60ms);
}
@media (min-width: 640px) { .ev-ticket { flex-direction: row; align-items: stretch; } }
.ev-ticket:hover { transform: translateY(-2px); border-color: #d7e6e8; box-shadow: 0 18px 36px -22px rgba(16, 63, 77, 0.45); }
.ev-ticket:focus-visible { outline: none; box-shadow: 0 0 0 3px white, 0 0 0 5px #346f7a; }
.ev-ticket.is-selected { border-color: #417f88; box-shadow: 0 0 0 1px #417f88; }
.ev-ticket.is-offer { background: linear-gradient(90deg, #fffbf2, white 40%); border-color: #f5e3bf; }
.ev-ticket.is-muted { opacity: 0.75; }
.ev-stub { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.15rem; padding: 1rem; border-radius: 1.5rem 1.5rem 0 0; }
@media (min-width: 640px) { .ev-stub { width: 7.5rem; flex-shrink: 0; border-radius: 1.5rem 0 0 1.5rem; } }
/* Perforation with notches between stub and body */
.ev-perf { position: relative; display: none; width: 0; border-left: 2px dashed #e5e7eb; }
@media (min-width: 640px) { .ev-perf { display: block; } }
.ev-perf::before, .ev-perf::after { content: ''; position: absolute; left: -11px; width: 20px; height: 20px; border-radius: 9999px; background: #f9fafb; border: 1px solid #e5e7eb; }
.ev-perf::before { top: -11px; clip-path: inset(50% 0 0 0); }
.ev-perf::after { bottom: -11px; clip-path: inset(0 0 50% 0); }
.ev-ticket .ev-stub { transition: filter 0.25s ease; }
.ev-ticket:hover .ev-stub { filter: brightness(1.05); }

.ev-pill { display: inline-flex; align-items: center; gap: 0.4rem; border-radius: 9999px; padding: 0.2rem 0.65rem; font-size: 0.75rem; font-weight: 600; }
.ev-btn { display: inline-flex; align-items: center; gap: 0.4rem; height: 2.5rem; padding: 0 0.9rem; border-radius: 0.75rem; border: 1px solid #e5e7eb; background: white; font-size: 0.875rem; font-weight: 600; color: #374151; transition: background-color 0.15s, border-color 0.15s, color 0.15s; }
.ev-btn:hover { background: #f9fafb; border-color: #d1d5db; }
.ev-btn-primary { border-color: #285F6b; background: #285F6b; color: white; }
.ev-btn-primary:hover { background: #1c4f5c; border-color: #1c4f5c; }
.ev-btn:focus-visible { outline: 2px solid #346f7a; outline-offset: 2px; }

.ev-float { animation: ev-float 3.5s ease-in-out infinite; }
@keyframes ev-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@keyframes ev-float { 50% { transform: translateY(-4px); } }
.ev-swap-enter-active, .ev-swap-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.ev-swap-enter-from { opacity: 0; transform: translateY(6px); }
.ev-swap-leave-to { opacity: 0; transform: translateY(-4px); }
@media (prefers-reduced-motion: reduce) {
	.ev-ticket, .ev-float { animation: none; }
	.ev-ticket:hover { transform: none; }
	.ev-seg-thumb, .ev-swap-enter-active, .ev-swap-leave-active { transition: none; }
}
</style>
