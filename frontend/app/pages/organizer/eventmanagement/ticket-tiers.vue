<template>
	<div class="space-y-5">
		<div v-if="isLoading" class="space-y-4">
			<div class="h-44 animate-pulse rounded-2xl bg-white ring-1 ring-gray-200" />
			<div class="h-48 animate-pulse rounded-2xl bg-white ring-1 ring-gray-200" />
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm font-semibold text-red-700">{{ errorMessage }}</div>

		<template v-else-if="event">
			<!-- Banner -->
			<section class="relative overflow-hidden rounded-3xl bg-primary-700 px-6 py-7 text-white sm:px-8">
				<div class="tt-dots" aria-hidden="true" />
				<div class="tt-glow" aria-hidden="true" />
				<div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
					<div class="min-w-0">
						<p class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.16em] text-white/60"><span class="h-1.5 w-1.5 rounded-full bg-white/60" />Ticket inventory</p>
						<h2 class="mt-2 truncate text-2xl font-black tracking-tight sm:text-3xl">{{ event.name }}</h2>
						<p class="mt-1 text-sm text-white/70">Track sales, remaining capacity, and pricing for every admission type.</p>
					</div>
					<button type="button" class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-bold text-primary-700 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md active:translate-y-0" @click="addTicketType"><IconBase name="plus" class="h-4 w-4" /> Add ticket type</button>
				</div>
			</section>

			<!-- Summary -->
			<section v-if="event.ticket_types?.length" class="rounded-2xl border border-gray-200 bg-white p-6">

					<div>
						<div class="mb-2 flex items-baseline justify-between text-sm">
							<span class="font-medium text-gray-700">{{ overallPercent }}% issued</span>
							<span class="tabular-nums text-gray-500">{{ totalSold }} of {{ totalCapacity }}</span>
						</div>
						<div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-100">
							<div class="tt-bar h-full rounded-full bg-primary-700 transition-[width] duration-1000 ease-out" :style="{ width: (ready ? overallPercent : 0) + '%' }" />
						</div>
					</div>

					<dl class="mt-6 grid grid-cols-2 divide-gray-100 border-t border-gray-100 pt-5 sm:grid-cols-5 sm:divide-x">
						<div v-for="stat in summaryStats" :key="stat.label" class="py-1 sm:px-5 sm:first:pl-0">
							<dt class="text-xs text-gray-500">{{ stat.label }}</dt>
							<dd class="mt-1 text-xl font-bold tabular-nums" :class="stat.accent ? 'text-primary-700' : 'text-gray-950'">{{ stat.value }}</dd>
						</div>
					</dl>
			</section>

			<!-- Empty -->
			<section v-if="!event.ticket_types?.length" class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
				<span class="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-100 text-gray-500"><IconBase name="ticket" class="h-6 w-6" /></span>
				<h3 class="mt-4 font-semibold text-gray-950">No ticket types yet</h3>
				<p class="mx-auto mt-1 max-w-sm text-sm text-gray-500">Add options like VIP, Regular or Early bird so guests can pick their admission.</p>
				<button type="button" class="tt-btn-primary mx-auto mt-5" @click="addTicketType"><IconBase name="plus" class="h-4 w-4" /> Add ticket type</button>
			</section>

			<!-- Tiers -->
			<section v-else class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
				<div class="hidden grid-cols-[minmax(0,1.4fr)_minmax(0,2fr)_minmax(0,0.8fr)_80px] gap-6 border-b border-gray-100 px-6 py-3 text-xs font-medium text-gray-500 md:grid">
					<span>Ticket type</span><span>Issued</span><span class="text-right">Available</span><span />
				</div>
				<ul class="divide-y divide-gray-100">
					<li v-for="(ticket, i) in event.ticket_types" :key="ticket.id" class="tt-row group grid grid-cols-1 items-center gap-4 px-6 py-5 transition hover:bg-gray-50/70 md:grid-cols-[minmax(0,1.4fr)_minmax(0,2fr)_minmax(0,0.8fr)_80px] md:gap-6" :style="{ '--i': i }">
						<div class="min-w-0">
							<p class="truncate text-sm font-semibold text-gray-950">{{ ticket.name }}</p>
							<p class="mt-0.5 text-sm tabular-nums text-gray-500">{{ formatCurrency(ticket.price) }}</p>
						</div>
						<div>
							<div class="mb-1.5 flex items-baseline justify-between text-xs">
								<span class="text-gray-500">{{ getSoldPercentage(ticket) }}%</span>
								<span class="tabular-nums text-gray-700"><strong class="font-semibold text-gray-950">{{ ticket.sold ?? 0 }}</strong> / {{ ticket.capacity }}</span>
							</div>
							<div class="h-1.5 overflow-hidden rounded-full bg-gray-100">
								<div class="tt-bar h-full rounded-full transition-[width] duration-1000 ease-out" :class="getSoldPercentage(ticket) >= 90 ? 'bg-amber-500' : 'bg-primary-700'" :style="{ width: (ready ? getSoldPercentage(ticket) : 0) + '%' }" />
							</div>
						</div>
						<p class="text-sm md:text-right">
							<span class="font-semibold tabular-nums text-gray-950">{{ getAvailability(ticket) }}</span>
							<span class="ml-1 text-gray-500">left</span>
							<span v-if="getAvailability(ticket) === 0" class="ml-2 rounded-full bg-gray-900 px-2 py-0.5 text-[10px] font-bold text-white">Sold out</span>
						</p>
						<div class="flex justify-end gap-1 opacity-100 transition md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100">
							<button type="button" class="tt-icon" :aria-label="`Edit ${ticket.name}`" title="Edit" @click="editTicketType(ticket)"><IconBase name="edit" class="h-4 w-4" /></button>
							<button type="button" class="tt-icon hover:!bg-red-50 hover:!text-red-600" :aria-label="`Remove ${ticket.name}`" title="Remove" @click="deleteTicketType(ticket)"><IconBase name="trash" class="h-4 w-4" /></button>
						</div>
					</li>
				</ul>
				<button type="button" class="flex w-full items-center justify-center gap-2 border-t border-gray-100 py-3.5 text-sm font-medium text-gray-500 transition hover:bg-gray-50 hover:text-primary-700" @click="addTicketType"><IconBase name="plus" class="h-4 w-4" /> Add another ticket type</button>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
interface TicketType {
	id: number
	event_id: number
	name: string
	price: string | number
	capacity: number
	sold?: number
}

interface OrganizerEvent {
	id: number

	name: string

	ticket_types?: TicketType[]
}

type EventResponse =
	| OrganizerEvent
	| {
		data: OrganizerEvent
	}

definePageMeta({
	layout: 'dashboard',
	middleware: ['organizer'],
})

const route =
	useRoute()

const config =
	useRuntimeConfig()

const {
	token,
} = useAuth()

const event =
	ref<OrganizerEvent | null>(
		null,
	)

const isLoading =
	ref(false)

const errorMessage =
	ref('')

const totalSold = computed(() =>
	event.value?.ticket_types?.reduce(
		(total, ticket) => total + (ticket.sold ?? 0),
		0,
	) ?? 0,
)

const totalCapacity = computed(() =>
	event.value?.ticket_types?.reduce(
		(total, ticket) => total + ticket.capacity,
		0,
	) ?? 0,
)

const totalAvailable = computed(() =>
	Math.max(totalCapacity.value - totalSold.value, 0),
)

const eventId =
	computed(() => routeId(route.query.event))

function extractEvent(
	response: EventResponse,
): OrganizerEvent {
	if (
		'data' in response &&
		response.data
	) {
		return response.data
	}

	return response as OrganizerEvent
}

async function loadTicketTypes() {
	errorMessage.value =
		''

	if (!eventId.value) {
		await navigateTo(
			'/organizer/eventmanagement/all-events',
			{
				replace: true,
			},
		)

		return
	}

	isLoading.value =
		true

	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		const [apiEvent, tiers] = await Promise.all([
			useOrganizerEvents().show(org.id, eventId.value),
			useOrganizerTicketing().tiers(org.id, eventId.value),
		])
		event.value = {
			id: apiEvent.id as unknown as number,
			name: apiEvent.name,
			ticket_types: tiers.tiers.map(t => ({
				id: t.id as unknown as number,
				event_id: apiEvent.id as unknown as number,
				name: t.name,
				price: t.price,
				capacity: t.capacity,
				sold: t.sold,
			})),
		}
	} catch (error: unknown) {
		console.error(
			'Failed to load ticket types:',
			error,
		)

		const apiError =
			error as {
				data?: {
					message?: string
				}
			}

		errorMessage.value =
			apiError.data?.message ??
			'Unable to load ticket types.'

	} finally {
		isLoading.value =
			false
	}
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
			style:
				'currency',

			currency:
				'PHP',

			maximumFractionDigits:
				0,
		},
	).format(
		amount,
	)
}

function getAvailability(
	ticket: TicketType,
): number {
	const sold =
		ticket.sold ?? 0

	return Math.max(
		ticket.capacity -
		sold,
		0,
	)
}

function getSoldPercentage(
	ticket: TicketType,
): number {
	if (
		ticket.capacity <= 0
	) {
		return 0
	}

	const sold =
		ticket.sold ?? 0

	return Math.min(
		Math.round(
			(
				sold /
				ticket.capacity
			) *
			100,
		),
		100,
	)
}

async function addTicketType() {
	if (!event.value) {
		return
	}

	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			event:
				event.value.id,

			mode:
				'edit',
		},
	})
}

async function editTicketType(
	ticket: TicketType,
) {
	if (!event.value) {
		return
	}

	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			event:
				event.value.id,

			mode:
				'edit',
		},
	})
}

/*
 * ============================================================
 * DELETE TICKET TYPE
 * ============================================================
 *
 * Your current EventController updates
 * all ticket types together.
 *
 * So for now it is safer to edit/remove
 * the ticket type inside Edit Event.
 *
 * Later we can create:
 *
 * DELETE /organizer/events/{event}/ticket-types/{ticketType}
 */

async function deleteTicketType(
	ticket: TicketType,
) {
	if (!event.value) {
		return
	}

	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			event:
				event.value.id,

			mode:
				'edit',
		},
	})
}

watch(
	eventId,
	() => {
		loadTicketTypes()
	},
)

onMounted(() => {
	loadTicketTypes()
})

// ---------- Minimal view helpers ----------
const ready = ref(false)
const overallPercent = computed(() => totalCapacity.value ? Math.round((totalSold.value / totalCapacity.value) * 100) : 0)
const revenueSoFar = computed(() => (event.value?.ticket_types ?? []).reduce((sum: number, t: any) => sum + (Number(t.price) || 0) * (Number(t.sold) || 0), 0))
// Numbers count up once when the page loads.
const tick = ref(0)
function eased(target: number) { return Math.round(target * tick.value) }
const summaryStats = computed(() => [
	{ label: 'Ticket types', value: eased(event.value?.ticket_types?.length ?? 0) },
	{ label: 'Issued', value: eased(totalSold.value) },
	{ label: 'Capacity', value: eased(totalCapacity.value) },
	{ label: 'Available', value: eased(totalAvailable.value) },
	{ label: 'Revenue so far', value: formatCurrency(eased(revenueSoFar.value)), accent: true },
])
function runCountUp() {
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { tick.value = 1; return }
	const start = performance.now()
	const step = (now: number) => {
		const t = Math.min(1, (now - start) / 900)
		tick.value = 1 - Math.pow(1 - t, 3)
		if (t < 1) requestAnimationFrame(step)
	}
	requestAnimationFrame(step)
}
watch(() => isLoading.value, (loading) => {
	if (loading || !import.meta.client) return
	setTimeout(() => { ready.value = true; runCountUp() }, 120)
}, { immediate: true })
</script>

<style scoped>
.tt-btn-primary { @apply inline-flex h-10 items-center gap-2 rounded-xl bg-primary-700 px-4 text-sm font-semibold text-white transition hover:bg-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 active:scale-[0.98]; }
.tt-icon { @apply flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600; }
.tt-dots { position: absolute; inset: 0; pointer-events: none; background-image: radial-gradient(rgba(255, 255, 255, 0.14) 1px, transparent 1.3px); background-size: 16px 16px; mask-image: linear-gradient(105deg, transparent 25%, black 80%); }
.tt-glow { position: absolute; right: -4rem; top: -5rem; width: 14rem; height: 14rem; border-radius: 9999px; background: rgba(255, 255, 255, 0.1); filter: blur(48px); pointer-events: none; animation: tt-drift 9s ease-in-out infinite; }
@keyframes tt-drift { 50% { transform: translate(-24px, 18px); } }
/* A soft shine sweeps across the bars once they fill. */
.tt-bar { position: relative; overflow: hidden; }
.tt-bar::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent); transform: translateX(-100%); animation: tt-shine 2.4s ease-in-out 1.1s 2; }
@keyframes tt-shine { to { transform: translateX(100%); } }
.tt-row { position: relative; }
.tt-row:hover { box-shadow: inset 3px 0 0 #285F6b; }
.tt-row { animation: tt-rise 0.4s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 50ms); }
@keyframes tt-rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .tt-dots { position: absolute; inset: 0; pointer-events: none; background-image: radial-gradient(rgba(255, 255, 255, 0.14) 1px, transparent 1.3px); background-size: 16px 16px; mask-image: linear-gradient(105deg, transparent 25%, black 80%); }
.tt-glow { position: absolute; right: -4rem; top: -5rem; width: 14rem; height: 14rem; border-radius: 9999px; background: rgba(255, 255, 255, 0.1); filter: blur(48px); pointer-events: none; animation: tt-drift 9s ease-in-out infinite; }
@keyframes tt-drift { 50% { transform: translate(-24px, 18px); } }
/* A soft shine sweeps across the bars once they fill. */
.tt-bar { position: relative; overflow: hidden; }
.tt-bar::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.45), transparent); transform: translateX(-100%); animation: tt-shine 2.4s ease-in-out 1.1s 2; }
@keyframes tt-shine { to { transform: translateX(100%); } }
.tt-row { position: relative; }
.tt-row:hover { box-shadow: inset 3px 0 0 #285F6b; }
.tt-row { animation: none; } }
</style>
