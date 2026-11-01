<template>
	<div>
		<!-- Hero (wider screens; on phones the top bar already names the page) -->
		<div class="tk-hero tk-rise relative mb-6 hidden overflow-hidden rounded-[2rem] px-9 py-8 text-white shadow-sm sm:block">
			<span class="tk-orb tk-orb-a" />
			<span class="tk-orb tk-orb-b" />
			<span class="tk-dots" />

			<div class="relative flex flex-col gap-3 sm:gap-5 lg:flex-row lg:items-center lg:justify-between">
				<div class="max-w-2xl">
					<div class="hidden items-center gap-2 rounded-full bg-white/12 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-white/85 ring-1 ring-white/20 backdrop-blur sm:inline-flex">
						<IconBase name="ticket" class="h-3.5 w-3.5" />
						Ticketing &amp; Attendance
					</div>

					<h1 class="text-xl font-extrabold tracking-tight sm:mt-4 sm:text-4xl">
						<span class="sm:hidden">Ticketing &amp; Attendance</span><span class="hidden sm:inline">Registration, Ticketing &amp; Attendance</span>
					</h1>

					<p class="mt-2 hidden text-sm leading-6 text-white/70 sm:block sm:text-base">
						Manage tickets, payments, and attendee check-in
					</p>
				</div>

				<div v-if="selectedEvent" class="flex shrink-0 items-center gap-2">
					<div class="flex max-w-xs items-center gap-2.5 rounded-full bg-white/12 py-2 pl-3.5 pr-4 text-sm font-semibold ring-1 ring-white/25 backdrop-blur-md">
						<span class="relative flex h-2.5 w-2.5 shrink-0">
							<span v-if="selectedEvent.status === 'ongoing'" class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-300 opacity-75" />
							<span class="relative inline-flex h-2.5 w-2.5 rounded-full" :class="selectedEvent.status === 'ongoing' ? 'bg-emerald-300' : 'bg-white/70'" />
						</span>
						<span class="truncate">{{ selectedEvent.name }}</span>
						<span v-if="selectedEvent.status === 'ongoing'" class="rounded-full bg-emerald-300/25 px-2 py-0.5 text-[11px] font-extrabold uppercase tracking-wider text-emerald-100">Live</span>
					</div>

					<button type="button"
						class="flex h-10 w-10 items-center justify-center rounded-full bg-white/12 text-white/80 ring-1 ring-white/25 backdrop-blur-md transition hover:bg-white/25 hover:text-white"
						title="Clear selected event" aria-label="Clear selected event" @click="clearSelectedEvent">
						<IconBase name="x" class="h-4 w-4" />
					</button>
				</div>
			</div>
		</div>

		<!-- Event selector -->
		<div class="tk-rise relative z-30 mb-3 sm:mb-6" style="--d: 80ms">
			<!-- Phones: the selector itself is the control; the label row is for wider screens -->
			<div class="mb-2 hidden items-center justify-between gap-3 sm:flex">
				<label class="block text-xs font-bold uppercase tracking-[0.12em] text-gray-400">
					Select Event
				</label>

				<button v-if="selectedEvent" type="button"
					class="-my-2 flex min-h-10 items-center gap-1.5 px-1 text-xs font-semibold text-gray-500 transition hover:text-[#285F6b]"
					@click="clearSelectedEvent">
					<IconBase name="arrow-left" class="h-3.5 w-3.5" />

					Choose another event
				</button>
			</div>

			<div ref="eventSelectorRef" class="relative">
				<button type="button"
					class="group flex w-full items-center justify-between rounded-2xl border border-gray-200 bg-white px-3 py-2.5 text-left sm:px-4 sm:py-3.5 shadow-sm transition hover:border-[#285F6b]/40 hover:shadow-md focus:border-[#285F6b] focus:outline-none focus:ring-4 focus:ring-[#285F6b]/10"
					@click="showEventSelector = !showEventSelector">
					<div v-if="selectedEvent" class="flex min-w-0 items-center gap-3">
						<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#285F6b] text-white sm:h-11 sm:w-11">
							<IconBase name="calendar" class="h-5 w-5" />
						</div>

						<div class="min-w-0">
							<p class="truncate text-sm font-bold text-gray-900">
								{{ selectedEvent.name }}
							</p>

							<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
								<span class="flex items-center gap-1">
									<IconBase name="calendar" class="h-3.5 w-3.5" />
									{{ formatEventDate(selectedEvent.event_date) }}
								</span>

								<span v-if="selectedEvent.location" class="hidden items-center gap-1 sm:flex">
									<IconBase name="map-pin" class="h-3.5 w-3.5" />
									{{ selectedEvent.location }}
								</span>
							</div>
						</div>
					</div>

					<div v-else class="flex items-center gap-3">
						<div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
							<IconBase name="calendar" class="h-5 w-5" />
						</div>

						<div>
							<p class="text-sm font-semibold text-gray-700">
								Choose an event
							</p>

							<p class="mt-0.5 text-xs text-gray-400">
								Select an event to manage ticketing and attendance
							</p>
						</div>
					</div>

					<IconBase name="chevron-down" class="h-5 w-5 shrink-0 text-gray-400 transition-transform" :class="{ 'rotate-180': showEventSelector }" />
				</button>

				<Transition name="dropdown">
					<div v-if="showEventSelector"
						class="absolute left-0 right-0 z-50 mt-2 max-h-80 overflow-y-auto rounded-2xl border border-gray-200 bg-white p-2 shadow-xl">
						<button v-for="event in organizerEvents" :key="event.id" type="button"
							class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left transition hover:bg-gray-50"
							:class="selectedEventId ===
								event.id
								? 'bg-[#285F6b]/5'
								: ''
								" @click="
									selectEvent(
										event,
									)
									">
							<div
								class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#285F6b]/10 text-[#285F6b]">
								<IconBase name="calendar" class="h-4 w-4" />
							</div>

							<div class="min-w-0 flex-1">
								<div class="flex items-center justify-between gap-3">
									<p class="truncate text-sm font-bold text-gray-900">
										{{ event.name }}
									</p>

									<span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="eventStatusClass(
										event.status,
									)
										">
										{{
											formatEventStatus(
												event.status,
											)
										}}
									</span>
								</div>

								<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
									<span class="flex items-center gap-1">
										<IconBase name="calendar" class="h-3.5 w-3.5" />

										{{
											formatEventDate(
												event.event_date,
											)
										}}
									</span>

									<span v-if="
										event.location
									" class="flex items-center gap-1">
										<IconBase name="map-pin" class="h-3.5 w-3.5" />

										{{
											event.location
										}}
									</span>
								</div>
							</div>

							<IconBase v-if="
								selectedEventId ===
								event.id
							" name="check-circle" class="h-5 w-5 shrink-0 text-[#285F6b]" />
						</button>

						<div v-if="
							!organizerEvents.length
						" class="px-4 py-8 text-center">
							<IconBase name="calendar" class="mx-auto h-6 w-6 text-gray-400" />

							<p class="mt-2 text-sm font-semibold text-gray-700">
								No events available
							</p>

							<p class="mt-1 text-xs text-gray-400">
								Configure an event first.
							</p>
						</div>
					</div>
				</Transition>
			</div>
		</div>

		<div v-if="summaryError" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
			{{ summaryError }}
		</div>

		<template v-if="selectedEventId">
			<!-- Stats -->
			<!-- Phones: the four numbers in one compact row -->
			<div class="mb-3 grid grid-cols-4 divide-x divide-gray-100 rounded-2xl border border-gray-200 bg-white py-2 sm:hidden">
				<div v-for="card in statCards" :key="card.key" class="min-w-0 px-1 text-center">
					<div class="text-base font-extrabold leading-tight tracking-tight text-gray-950">{{ card.value }}<span v-if="card.suffix" class="text-sm text-gray-400">{{ card.suffix }}</span></div>
					<div class="mt-0.5 truncate text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ card.short }}</div>
				</div>
			</div>

			<div class="mb-6 hidden grid-cols-2 gap-5 sm:grid xl:grid-cols-4">
				<div v-for="(card, index) in statCards" :key="card.key" class="tk-card tk-rise group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5" :style="{ '--d': 120 + index * 70 + 'ms' }">
					<span class="tk-line absolute inset-x-0 top-0 h-[2px] bg-[#285F6b]" />

					<div class="flex items-start justify-between gap-3">
						<div class="min-w-0">
							<span class="text-xs font-bold uppercase tracking-[0.1em] text-gray-400">{{ card.label }}</span>

							<div class="mt-2 text-4xl font-extrabold tracking-tight text-gray-950">
								{{ card.value }}<span v-if="card.suffix" class="text-2xl text-gray-400">{{ card.suffix }}</span>
							</div>

							<div class="mt-2 text-xs font-medium text-gray-500">{{ card.hint }}</div>
						</div>

						<div v-if="card.ring !== null" class="relative h-14 w-14 shrink-0">
							<svg viewBox="0 0 52 52" class="h-full w-full -rotate-90">
								<circle cx="26" cy="26" r="22" fill="none" stroke="#eef2f3" stroke-width="5" />
								<circle cx="26" cy="26" r="22" fill="none" :stroke="card.stroke" stroke-width="5" stroke-linecap="round"
									:stroke-dasharray="RING" :stroke-dashoffset="RING * (1 - Math.min(card.ring, 100) / 100)" class="tk-ring" />
							</svg>
							<span class="absolute inset-0 flex items-center justify-center rounded-full" :class="card.iconColor">
								<IconBase :name="card.icon" class="h-[18px] w-[18px]" />
							</span>
						</div>

						<div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl transition group-hover:scale-105" :class="[card.iconBg, card.iconColor]">
							<IconBase :name="card.icon" class="h-5 w-5" />
						</div>
					</div>
				</div>
			</div>

			<!-- Tabs -->
			<!-- Phones: an icon bar of five equal buttons; wider screens: pill tabs -->
			<div class="mb-4 grid w-full grid-cols-5 gap-1 rounded-2xl border border-gray-200 bg-white p-1 sm:mb-6 sm:p-1.5 sm:inline-flex sm:w-auto sm:max-w-full sm:flex-wrap">
				<NuxtLink v-for="tab in tabs" :key="tab.label" :to="tab.to" :aria-label="tab.label"
					class="flex min-h-11 min-w-0 flex-col items-center justify-center gap-0.5 rounded-xl px-1 py-1 text-[11px] font-bold transition duration-200 active:scale-[0.97] sm:min-h-0 sm:flex-row sm:gap-2 sm:px-4 sm:py-2.5 sm:text-sm"
					:class="route.path === tab.path
						? 'bg-[#285F6b] text-white shadow-sm'
						: 'text-gray-500 hover:bg-primary-50 hover:text-primary-700'">
					<IconBase :name="tab.icon" class="h-4 w-4 shrink-0" />

					<span class="max-w-full truncate sm:hidden">{{ tab.short }}</span>
					<span class="hidden sm:inline">{{ tab.label }}</span>
				</NuxtLink>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
interface OrganizerEvent {
	id: number
	name: string
	event_type: string
	event_date: string | null
	location: string | null
	status: string
}

interface AttendeeSummary {
	total_registered: number
	checked_in: number
	walk_ins: number
	capacity: number
	capacity_percent: number
}

type OrganizerEventsResponse =
	| OrganizerEvent[]
	| {
		data: OrganizerEvent[]
	}

interface AttendeeSummaryResponse {
	data: AttendeeSummary
}



const route =
	useRoute()

const router =
	useRouter()

const organizerEvents =
	useState<OrganizerEvent[]>(
		'ticketingOrganizerEvents',
		() => [],
	)

const selectedEventId =
	useState<number | null>(
		'ticketingSelectedEventId',
		() => null,
	)

const selectedEvent =
	computed<OrganizerEvent | null>(
		() => {
			if (
				!selectedEventId.value
			) {
				return null
			}

			return (
				organizerEvents.value.find(
					event =>
						event.id ===
						selectedEventId.value,
				) ?? null
			)
		},
	)

const summary =
	useState<AttendeeSummary>(
		'ticketingAttendeeSummary',
		() => ({
			total_registered: 0,
			checked_in: 0,
			walk_ins: 0,
			capacity: 0,
			capacity_percent: 0,
		}),
	)

const showEventSelector =
	ref(false)

const eventSelectorRef =
	ref<HTMLElement | null>(null)

function handleClickOutsideEventSelector(event: MouseEvent) {
	if (!showEventSelector.value) {
		return
	}

	if (
		eventSelectorRef.value &&
		!eventSelectorRef.value.contains(event.target as Node)
	) {
		showEventSelector.value = false
	}
}

const summaryError =
	ref('')

const tabs =
	computed(() => {
		const eventQuery =
			selectedEventId.value
				? {
					event:
						selectedEventId.value,
				}
				: {}

		return [
			{
				label: 'Attendees',
				short: 'Guests',

				path:
					'/organizer/ticketattendance/attendees',

				to: {
					path:
						'/organizer/ticketattendance/attendees',

					query:
						eventQuery,
				},

				icon:
					'users',
			},

			{
				label:
					'QR Check-in',
				short: 'Scan',

				path:
					'/organizer/ticketattendance/qr-checkin',

				to: {
					path:
						'/organizer/ticketattendance/qr-checkin',

					query:
						eventQuery,
				},

				icon:
					'qr-code',
			},

			{
				label:
					'Walk-in Counter',
				short: 'Walk-in',

				path:
					'/organizer/ticketattendance/walkin-counter',

				to: {
					path:
						'/organizer/ticketattendance/walkin-counter',

					query:
						eventQuery,
				},

				icon:
					'user-plus',
			},

			{
				label:
					'Attendance Report',
				short: 'Report',

				path:
					'/organizer/ticketattendance/attendance-report',

				to: {
					path:
						'/organizer/ticketattendance/attendance-report',

					query:
						eventQuery,
				},

				icon:
					'clipboard-list',
			},

			{
				label: 'Payments',
				short: 'Payments',
				path: '/organizer/ticketattendance/payments',
				to: { path: '/organizer/ticketattendance/payments', query: eventQuery },
				icon: 'credit-card',
			},
		]
	})

const RING = 2 * Math.PI * 22

// Numbers count up to their real value when the summary loads or changes.
const shown = reactive({ total_registered: 0, checked_in: 0, walk_ins: 0, capacity_percent: 0 })
let countFrame = 0

function animateCounters(target: AttendeeSummary) {
	// animation frames are paused in a hidden tab, so show the real numbers straight away
	if (document.visibilityState === 'hidden') {
		Object.assign(shown, {
			total_registered: target.total_registered,
			checked_in: target.checked_in,
			walk_ins: target.walk_ins,
			capacity_percent: target.capacity_percent,
		})
		return
	}

	cancelAnimationFrame(countFrame)

	const from = { ...shown }
	const start = performance.now()
	const duration = 700

	const step = (now: number) => {
		const t = Math.min((now - start) / duration, 1)
		const ease = 1 - Math.pow(1 - t, 3)

		shown.total_registered = Math.round(from.total_registered + (target.total_registered - from.total_registered) * ease)
		shown.checked_in = Math.round(from.checked_in + (target.checked_in - from.checked_in) * ease)
		shown.walk_ins = Math.round(from.walk_ins + (target.walk_ins - from.walk_ins) * ease)
		shown.capacity_percent = Math.round(from.capacity_percent + (target.capacity_percent - from.capacity_percent) * ease)

		if (t < 1) countFrame = requestAnimationFrame(step)
	}

	countFrame = requestAnimationFrame(step)
}

watch(summary, value => animateCounters(value), { deep: true })

const checkInRate = computed(() =>
	summary.value.total_registered > 0
		? Math.round((summary.value.checked_in / summary.value.total_registered) * 100)
		: 0,
)

const statCards = computed(() => [
	{
		key: 'registered', short: 'Registered',
		label: 'Total Registered',
		value: shown.total_registered,
		suffix: '',
		hint: 'Issued attendee tickets',
		icon: 'users',
		ring: null as number | null,
		iconBg: 'bg-primary-50',
		iconColor: 'text-primary-700',
		stroke: '#285F6b',
	},
	{
		key: 'checked', short: 'Checked in',
		label: 'Checked In',
		value: shown.checked_in,
		suffix: '',
		hint: `${checkInRate.value}% of registered attendees`,
		icon: 'check-circle',
		ring: checkInRate.value,
		iconBg: 'bg-primary-50',
		iconColor: 'text-primary-700',
		stroke: '#285F6b',
	},
	{
		key: 'walkins', short: 'Walk-ins',
		label: 'Walk-ins',
		value: shown.walk_ins,
		suffix: '',
		hint: 'Paid on-site registrations',
		icon: 'user-plus',
		ring: null as number | null,
		iconBg: 'bg-primary-50',
		iconColor: 'text-primary-700',
		stroke: '#285F6b',
	},
	{
		key: 'capacity', short: 'Capacity',
		label: 'Capacity Status',
		value: shown.capacity_percent,
		suffix: '%',
		hint: `${summary.value.total_registered} of ${summary.value.capacity} spots`,
		icon: 'chart-bar',
		ring: summary.value.capacity_percent,
		iconBg: 'bg-primary-50',
		iconColor: 'text-primary-700',
		stroke: '#285F6b',
	},
])

function resetSummary() {
	summary.value = {
		total_registered: 0,
		checked_in: 0,
		walk_ins: 0,
		capacity: 0,
		capacity_percent: 0,
	}
}



let orgId = ''

async function loadOrganizerEvents() {
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) return
		orgId = org.id
		const events = useOrganizerEvents()
		organizerEvents.value = (await allItems(page => events.list(orgId, [], page)))
			.map(e => toLegacyEvent(e)) as unknown as OrganizerEvent[]

		const queryEvent = routeId(route.query.event)
		if (queryEvent && organizerEvents.value.some(event => String(event.id) === queryEvent)) {
			selectedEventId.value = queryEvent as unknown as number
			return
		}
		selectedEventId.value = null
		resetSummary()
	} catch (error: unknown) {
		summaryError.value = authMessage(error) || 'Unable to load organizer events.'
	}
}

/** Registered / checked in / walk-ins for the selected event (check-in summary). */
async function loadSummary() {
	if (!selectedEventId.value || !orgId) {
		resetSummary()
		return
	}
	summaryError.value = ''
	try {
		const data = await useCheckIn().summary(orgId, String(selectedEventId.value))
		const event = organizerEvents.value.find(e => String(e.id) === String(selectedEventId.value)) as unknown as { expected_guests: number | null } | undefined
		const capacity = event?.expected_guests ?? data.expected
		summary.value = {
			total_registered: data.expected,
			checked_in: data.checked_in,
			walk_ins: data.walk_ins + (data.door_sales ?? 0),
			capacity,
			capacity_percent: capacity ? Math.round((data.arrived / capacity) * 100) : 0,
		}
	} catch (error: unknown) {
		summaryError.value = authMessage(error) || 'Unable to load ticketing summary.'
	}
}

async function selectEvent(
	event: OrganizerEvent,
) {
	showEventSelector.value =
		false

	selectedEventId.value =
		event.id

	await syncEventQuery(
		event.id,
	)
}

async function clearSelectedEvent() {
	showEventSelector.value =
		false

	selectedEventId.value =
		null

	resetSummary()

	summaryError.value =
		''

	const remainingQuery = {
		...route.query,
	}

	delete remainingQuery.event

	await router.replace({
		path:
			route.path,

		query:
			remainingQuery,
	})
}

async function syncEventQuery(
	eventId: number,
) {
	await router.replace({
		path:
			route.path,

		query: {
			...route.query,

			event:
				String(eventId),
		},
	})
}

function formatEventDate(
	value: string | null,
): string {
	if (!value) {
		return 'Date not set'
	}

	const date =
		new Date(
			value.includes('T')
				? value
				: `${value}T00:00:00`,
		)

	return date.toLocaleDateString(
		'en-US',
		{
			month:
				'short',

			day:
				'numeric',

			year:
				'numeric',
		},
	)
}

function formatEventStatus(
	status: string,
): string {
	if (!status) {
		return 'Draft'
	}

	return (
		status.charAt(0).toUpperCase() +
		status.slice(1)
	)
}

function eventStatusClass(
	status: string,
): string {
	switch (status) {
		case 'published':
		case 'confirmed':
			return 'bg-green-50 text-green-700'

		case 'ongoing':
			return 'bg-blue-50 text-blue-700'

		case 'completed':
			return 'bg-gray-100 text-gray-600'

		case 'cancelled':
			return 'bg-red-50 text-red-600'

		default:
			return 'bg-gray-100 text-gray-600'
	}
}

watch(
	selectedEventId,
	async (
		newEventId,
		oldEventId,
	) => {
		if (
			newEventId ===
			oldEventId
		) {
			return
		}

		if (!newEventId) {
			resetSummary()

			return
		}

		await loadSummary()
	},
)

watch(
	() => route.query.event,
	async (value) => {
		const id = routeId(value)
		if (!id || (organizerEvents.value.length && !organizerEvents.value.some(event => String(event.id) === id))) {
			if (selectedEventId.value !== null) selectedEventId.value = null
			resetSummary()
			return
		}
		if (String(selectedEventId.value) !== id) {
			selectedEventId.value = id as unknown as number
		}
	},
)

// Header counters stay current during the event (review #12).
useLiveRefresh(() => loadSummary(), { interval: 20000 })

onMounted(async () => {
	document.addEventListener('click', handleClickOutsideEventSelector)

	await loadOrganizerEvents()

	if (
		selectedEventId.value
	) {
		await loadSummary()
	}

	animateCounters(summary.value)
})

onBeforeUnmount(() => {
	cancelAnimationFrame(countFrame)
	document.removeEventListener('click', handleClickOutsideEventSelector)
})
</script>

<style scoped>
.dropdown-enter-active,
.dropdown-leave-active {
	transition:
		opacity 0.15s ease,
		transform 0.15s ease;
}

.dropdown-enter-from,
.dropdown-leave-to {
	opacity: 0;
	transform: translateY(-6px);
}

.tk-hero {
	background: #285f6b;
}

.tk-orb {
	position: absolute;
	border-radius: 9999px;
	filter: blur(60px);
	pointer-events: none;
}

.tk-orb-a {
	top: -110px;
	right: 6%;
	height: 300px;
	width: 300px;
	background: rgba(255, 255, 255, 0.1);
	animation: tk-drift 16s ease-in-out infinite;
}

.tk-orb-b {
	bottom: -130px;
	left: -50px;
	height: 280px;
	width: 280px;
	background: rgba(255, 255, 255, 0.06);
	animation: tk-drift 20s ease-in-out infinite reverse;
}

.tk-dots {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
	-webkit-mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
}

.tk-rise {
	animation: tk-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards;
	animation-delay: var(--d, 0ms);
}

.tk-card {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.tk-card:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.tk-line {
	transform: scaleX(0);
	transform-origin: left;
	transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.tk-card:hover .tk-line {
	transform: scaleX(1);
}

.tk-ring {
	transition: stroke-dashoffset 1s cubic-bezier(0.22, 1, 0.36, 1) 0.15s;
}

@keyframes tk-rise {
	from { opacity: 0; transform: translateY(16px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes tk-drift {
	0%, 100% { transform: translate(0, 0) scale(1); }
	50% { transform: translate(-22px, 16px) scale(1.08); }
}

@media (prefers-reduced-motion: reduce) {
	.tk-orb, .tk-rise { animation: none; }
	.tk-card, .tk-ring, .tk-line { transition: none; }
}
</style>
