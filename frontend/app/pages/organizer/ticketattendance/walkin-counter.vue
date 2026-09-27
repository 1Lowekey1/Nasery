<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="!eventId" class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-10 text-center text-sm text-gray-500">Choose an event above.</div>
		<div v-else-if="!summary && !error" class="py-12 text-center text-sm text-gray-500">Loading the walk-in counter…</div>

		<template v-if="summary">
			<!-- Venue capacity -->
			<div class="mb-5 rounded-2xl border p-5" :class="capacityBox" role="status" aria-live="polite">
				<div class="flex flex-wrap items-baseline justify-between gap-2">
					<p class="text-sm font-bold">{{ capacityTitle }}</p>
					<p v-if="cap.limit !== null" class="text-sm font-semibold">{{ cap.used }} / {{ cap.limit }}</p>
				</div>
				<div v-if="cap.limit !== null" class="mt-2 h-2.5 overflow-hidden rounded-full bg-white/70">
					<div class="h-full rounded-full transition-all" :class="capacityBar" :style="{ width: Math.min(100, cap.percent ?? 0) + '%' }" />
				</div>
				<p class="mt-2 text-xs">{{ capacityNote }}</p>
			</div>

			<div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div>
							<h2 class="text-lg font-bold text-gray-900">Walk-in counter</h2>
							<p class="mt-1 text-sm text-gray-500">{{ doorOpen ? `Recording for ${activeSessionLabel}` : doorNote }}</p>
						</div>
						<select v-if="openSessions.length > 1" v-model="sessionId" class="rounded-xl border border-gray-200 px-3 py-2 text-sm" aria-label="Session">
							<option v-for="s in openSessions" :key="s.id" :value="s.id">{{ s.label }}</option>
						</select>
					</div>

					<p v-if="!summary.can.record" class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">You can see the numbers, but recording walk-ins needs the Check-in permission.</p>

					<template v-else>
						<div class="mt-5 inline-flex flex-wrap gap-1 rounded-xl bg-gray-100 p-1" role="tablist">
							<button v-for="m in modes" :key="m.key" type="button" role="tab" :aria-selected="mode === m.key"
								class="rounded-lg px-4 py-2 text-sm font-semibold" :class="mode === m.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
								@click="mode = m.key">{{ m.label }}</button>
						</div>
						<p class="mt-2 text-xs text-gray-500">{{ modes.find(m => m.key === mode)?.hint }}</p>

						<div v-if="result" class="mt-4 rounded-xl border-2 border-green-500 bg-green-50 p-4 text-green-900" role="status">
							<p class="font-bold">{{ result.message }}</p>
							<p v-if="result.over_capacity_by" class="text-sm">Admitted past capacity (over by {{ result.over_capacity_by }}).</p>
						</div>

						<!-- Venue full: stop, or let a manager admit anyway -->
						<div v-if="full" class="mt-4 rounded-xl border-2 border-rose-500 bg-rose-50 p-4 text-rose-900" role="alert">
							<p class="font-bold">{{ full.message }}</p>
							<template v-if="full.scope === 'event' && summary.can.override">
								<p class="mt-1 text-sm">You can admit anyway. Say why (this is recorded):</p>
								<div class="mt-2 flex gap-2">
									<input v-model="overrideReason" placeholder="e.g. Performers' families" class="min-w-0 flex-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm">
									<button type="button" :disabled="busy || overrideReason.trim().length < 3" class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="retryWithOverride">Admit anyway</button>
								</div>
							</template>
							<p v-else-if="full.scope === 'event'" class="mt-1 text-sm">Ask the organizer or a manager: only they can admit past capacity.</p>
							<p v-else class="mt-1 text-sm">Choose another tier, or ask the organizer to raise this tier's capacity.</p>
						</div>

						<!-- Door sale / on-site registration -->
						<form v-if="mode === 'sale'" class="mt-5 space-y-3" @submit.prevent="sell">
							<div>
								<FormsLabel text="Ticket tier" />
								<select v-model="sale.ticket_type_id" required class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
									<option value="" disabled>Choose a tier</option>
									<option v-for="t in summary.tiers" :key="t.id" :value="t.id" :disabled="t.remaining < 1">
										{{ t.name }}{{ t.zone ? ` · ${t.zone}` : '' }} — {{ Number(t.price) > 0 ? formatPeso(t.price) : 'Free' }} ({{ t.remaining < 1 ? 'full' : `${t.remaining} left` }})
									</option>
								</select>
								<p v-if="!summary.tiers.length" class="mt-1 text-xs text-gray-500">This event has no ticket tiers. Use “Log a name” or “Headcount”, or add a tier under Ticket Tiers.</p>
							</div>
							<div class="grid gap-3 sm:grid-cols-2">
								<div><FormsLabel text="Full name" /><FormsTextField v-model="sale.full_name" placeholder="e.g. Rico Dizon" /></div>
								<div><FormsLabel text="Mobile (optional)" /><FormsTextField v-model="sale.contact_number" placeholder="0917 123 4567" /></div>
							</div>
							<div><FormsLabel text="Email (optional)" /><FormsTextField v-model="sale.email" type="email" placeholder="for their ticket" /></div>
							<label v-if="chosenTierPrice > 0" class="flex items-center gap-2 rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900">
								<input v-model="sale.cash_received" type="checkbox"> {{ formatPeso(chosenTierPrice) }} collected in cash
							</label>
							<button type="submit" :disabled="busy || !doorOpen || !sale.ticket_type_id || sale.full_name.trim().length < 2 || (chosenTierPrice > 0 && !sale.cash_received)"
								class="w-full rounded-xl bg-primary-700 py-3 text-sm font-bold text-white hover:bg-primary-900 disabled:opacity-50">
								{{ chosenTierPrice > 0 ? 'Sell ticket and check in' : 'Register and check in' }}
							</button>
						</form>

						<!-- Name log (no ticket) -->
						<form v-else-if="mode === 'name'" class="mt-5 space-y-3" @submit.prevent="logName">
							<div class="grid gap-3 sm:grid-cols-2">
								<div><FormsLabel text="Full name" /><FormsTextField v-model="person.full_name" placeholder="e.g. Joy Santos" /></div>
								<div><FormsLabel text="Mobile (optional)" /><FormsTextField v-model="person.contact_number" placeholder="0917 123 4567" /></div>
							</div>
							<button type="submit" :disabled="busy || !doorOpen || person.full_name.trim().length < 2"
								class="w-full rounded-xl bg-primary-700 py-3 text-sm font-bold text-white hover:bg-primary-900 disabled:opacity-50">Log walk-in</button>
						</form>

						<!-- Headcount -->
						<div v-else class="mt-5">
							<button type="button" :disabled="!doorOpen" class="flex h-28 w-full items-center justify-center rounded-2xl bg-primary-700 text-4xl font-extrabold text-white active:scale-[0.99] hover:bg-primary-900 disabled:opacity-50" @click="count(1)">+1</button>
							<form class="mt-3 flex gap-2" @submit.prevent="count(groupSize)">
								<label class="sr-only" for="group-size">Group size</label>
								<input id="group-size" v-model.number="groupSize" type="number" min="1" max="50" class="w-24 rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
								<button type="submit" :disabled="!doorOpen || groupSize < 1 || groupSize > 50" class="flex-1 rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-800 disabled:opacity-50">Add a group of {{ groupSize || 0 }}</button>
							</form>
							<p class="mt-2 text-xs text-gray-500">Every tap counts, even in quick succession.</p>
						</div>
					</template>
				</section>

				<aside class="space-y-5">
					<section class="rounded-2xl border border-gray-200 bg-white p-6">
						<h3 class="text-base font-bold text-gray-900">Walk-ins so far</h3>
						<p class="mt-2 text-4xl font-extrabold text-gray-900">{{ summary.totals.walk_ins }}</p>
						<dl class="mt-3 space-y-1 text-sm">
							<div class="flex justify-between"><dt class="text-gray-600">Door sales / on-site tickets</dt><dd class="font-semibold">{{ summary.totals.door_sales }}</dd></div>
							<div v-if="Number(summary.totals.door_revenue) > 0" class="flex justify-between"><dt class="text-gray-600">Cash at the door</dt><dd class="font-semibold">{{ formatPeso(summary.totals.door_revenue) }}</dd></div>
							<div class="flex justify-between"><dt class="text-gray-600">Names logged</dt><dd class="font-semibold">{{ summary.totals.names }}</dd></div>
							<div class="flex justify-between"><dt class="text-gray-600">Headcount</dt><dd class="font-semibold">{{ summary.totals.headcount }}</dd></div>
						</dl>
					</section>

					<section class="rounded-2xl border border-gray-200 bg-white p-6">
						<h3 class="text-base font-bold text-gray-900">Recent</h3>
						<ul v-if="summary.recent.length" class="mt-3 divide-y divide-gray-100">
							<li v-for="w in summary.recent" :key="w.id" class="flex items-start justify-between gap-3 py-2.5">
								<div class="min-w-0">
									<p class="truncate text-sm font-semibold text-gray-900">{{ entryLabel(w) }}</p>
									<p class="text-xs text-gray-500">{{ categoryLabel[w.category] }}<span v-if="w.recorded_by"> · {{ w.recorded_by }}</span></p>
								</div>
								<div class="shrink-0 text-right">
									<p class="text-xs text-gray-400">{{ time(w.recorded_at) }}</p>
									<button v-if="summary.can.undo" type="button" class="text-xs font-semibold text-rose-700" @click="startUndo(w)">Undo</button>
								</div>
							</li>
						</ul>
						<p v-else class="mt-3 text-sm text-gray-400">No walk-ins yet.</p>
					</section>
				</aside>
			</div>
		</template>

		<div v-if="undoing" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="undoing = null">
			<form class="w-full max-w-md rounded-2xl bg-white p-6" @submit.prevent="undo">
				<h2 class="text-lg font-bold text-gray-900">Remove “{{ entryLabel(undoing) }}”?</h2>
				<p class="mt-1 text-sm text-gray-500">{{ undoing.category === 'ticket_linked' ? 'Their door ticket is cancelled. Return any cash collected yourself.' : 'The entry is removed from the count.' }} This is recorded in the activity log.</p>
				<Alert v-if="undoError" type="danger" :text="undoError" class="mt-3" />
				<FormsLabel text="Reason" class="mt-4" />
				<FormsTextField v-model="undoReason" placeholder="e.g. Counted twice" />
				<div class="mt-5 flex justify-end gap-2">
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="undoing = null">Close</button>
					<button type="submit" :disabled="busy || undoReason.trim().length < 3" class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Remove</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ApiError } from '~/composables/useApi'
import type { WalkInEntry, WalkInResult, WalkInSummary } from '~/composables/useCheckIn'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useCheckIn()

type Mode = 'sale' | 'name' | 'headcount'
const modes: { key: Mode, label: string, hint: string }[] = [
	{ key: 'sale', label: 'Sell / register ticket', hint: 'Buy-at-the-door or on-site registration: issues a real ticket in a tier and checks the guest in.' },
	{ key: 'name', label: 'Log a name', hint: 'Open events: record who came in, without a ticket.' },
	{ key: 'headcount', label: 'Headcount', hint: 'Count people walking in without asking them anything.' },
]
const categoryLabel: Record<WalkInEntry['category'], string> = {
	ticket_linked: 'Door ticket', paid_walk_in: 'Paid at door', registered_attendee: 'Name logged', headcount_only: 'Headcount',
}

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const summary = ref<WalkInSummary | null>(null)
const mode = ref<Mode>('sale')
const error = ref('')
const busy = ref(false)
const result = ref<WalkInResult | null>(null)
const full = ref<{ message: string, scope: 'tier' | 'event' } | null>(null)
const overrideReason = ref('')
let lastAction: ((override: string | null) => Promise<WalkInResult>) | null = null
const sessionId = ref<string | null>(null)
const sale = reactive({ ticket_type_id: '', full_name: '', email: '', contact_number: '', cash_received: false })
const person = reactive({ full_name: '', contact_number: '' })
const groupSize = ref(2)
const undoing = ref<WalkInEntry | null>(null)
const undoReason = ref('')
const undoError = ref('')

const cap = computed(() => summary.value!.capacity)
const openSessions = computed(() => summary.value?.sessions.filter(s => s.is_open) ?? [])
const doorOpen = computed(() => ['published', 'ongoing'].includes(summary.value?.event_status ?? '') && openSessions.value.length > 0)
const activeSessionLabel = computed(() => openSessions.value.find(s => s.id === sessionId.value)?.label ?? openSessions.value[0]?.label ?? '')
const doorNote = computed(() => {
	const s = summary.value
	if (!s || !['published', 'ongoing'].includes(s.event_status)) return 'Walk-ins can be recorded once the event is published.'
	const next = s.sessions.find(x => new Date(x.opens_at) > new Date())
	return next ? `The door opens ${new Date(next.opens_at).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}.` : 'The door has closed: every session has ended.'
})
const chosenTierPrice = computed(() => Number(summary.value?.tiers.find(t => t.id === sale.ticket_type_id)?.price ?? 0))

const capacityBox = computed(() => ({
	unlimited: 'border-gray-200 bg-gray-50 text-gray-700',
	ok: 'border-green-200 bg-green-50 text-green-900',
	warning: 'border-amber-300 bg-amber-50 text-amber-900',
	full: 'border-rose-400 bg-rose-50 text-rose-900',
	over: 'border-rose-500 bg-rose-100 text-rose-900',
}[cap.value.level]))
const capacityBar = computed(() => ({ unlimited: 'bg-gray-400', ok: 'bg-green-600', warning: 'bg-amber-500', full: 'bg-rose-600', over: 'bg-rose-700' }[cap.value.level]))
const capacityTitle = computed(() => ({
	unlimited: `${cap.value.used} people counted`,
	ok: 'Venue capacity',
	warning: `Almost full: ${cap.value.remaining} place${cap.value.remaining === 1 ? '' : 's'} left`,
	full: 'The venue is full',
	over: `Over capacity by ${cap.value.used - (cap.value.limit ?? 0)}`,
}[cap.value.level]))
const capacityNote = computed(() => cap.value.level === 'unlimited'
	? 'No overall capacity is set for this event, so walk-ins are counted but never refused. Set one in the event details to enforce a limit.'
	: `Tickets, active reservations and walk-ins all count. Warning from ${cap.value.warning_percent}%; at 100% only a manager can admit more.`)

function time(at: string) {
	return new Date(at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
function entryLabel(w: WalkInEntry) {
	if (w.category === 'headcount_only') return `+${w.count} ${w.count === 1 ? 'person' : 'people'}`
	return `${w.name ?? 'Guest'}${w.tier ? ` · ${w.tier}` : ''}${w.amount && Number(w.amount) > 0 ? ` · ${formatPeso(w.amount)}` : ''}`
}

async function orgId() {
	return (organization.value ?? await fetchOrganization())!.id
}

async function load() {
	if (!eventId.value) return
	try {
		summary.value = await api.walkIns(await orgId(), eventId.value)
		if (sessionId.value && !openSessions.value.some(s => s.id === sessionId.value)) sessionId.value = null
		if (!sessionId.value && openSessions.value.length > 1) sessionId.value = openSessions.value[0]!.id
	} catch (e) {
		error.value = authMessage(e)
	}
}

// Actions run one after another (a burst of +1 taps must all count), and a
// "venue full" answer can be retried by a manager with an override reason.
let queue: Promise<void> = Promise.resolve()
function run(action: (override: string | null) => Promise<WalkInResult>, onDone?: () => void) {
	queue = queue.then(async () => {
		busy.value = true
		error.value = ''
		result.value = null
		try {
			result.value = await action(null)
			full.value = null
			onDone?.()
		} catch (e) {
			if (e instanceof ApiError && e.code === 'CAPACITY_EXCEEDED') {
				full.value = { message: e.message, scope: e.details.scope === 'tier' ? 'tier' : 'event' }
				lastAction = action
				overrideReason.value = ''
			} else {
				error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
			}
		} finally {
			busy.value = false
		}
		load()
	})
	return queue
}

async function retryWithOverride() {
	if (!lastAction) return
	const action = lastAction
	busy.value = true
	try {
		result.value = await action(overrideReason.value.trim())
		full.value = null
		lastAction = null
		resetForms()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
	load()
}

function resetForms() {
	Object.assign(sale, { full_name: '', email: '', contact_number: '', cash_received: false })
	Object.assign(person, { full_name: '', contact_number: '' })
}

function sell() {
	const body = { ...sale, full_name: sale.full_name.trim(), email: sale.email.trim() || null, contact_number: sale.contact_number.trim() || null }
	run(async override => api.sell(await orgId(), eventId.value!, { ...body, session_id: sessionId.value, override_reason: override }), resetForms)
}
function logName() {
	const body = { full_name: person.full_name.trim(), contact_number: person.contact_number.trim() || null }
	run(async override => api.logName(await orgId(), eventId.value!, { ...body, session_id: sessionId.value, override_reason: override }), resetForms)
}
function count(n: number) {
	run(async override => api.headcount(await orgId(), eventId.value!, { count: n, session_id: sessionId.value, override_reason: override }))
}

function startUndo(w: WalkInEntry) {
	undoing.value = w
	undoReason.value = ''
	undoError.value = ''
}
async function undo() {
	busy.value = true
	undoError.value = ''
	try {
		await api.undoWalkIn(await orgId(), eventId.value!, undoing.value!.id, undoReason.value.trim())
		undoing.value = null
		result.value = null
		await load()
	} catch (e) {
		undoError.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

let poll: ReturnType<typeof setInterval> | undefined
onMounted(() => {
	load()
	poll = setInterval(() => {
		if (!document.hidden && !busy.value) load()
	}, 5000)
})
onBeforeUnmount(() => clearInterval(poll))
watch(eventId, () => {
	summary.value = null
	result.value = null
	full.value = null
	sessionId.value = null
	load()
})
</script>
