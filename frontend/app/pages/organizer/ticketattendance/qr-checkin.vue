<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="!eventId" class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-10 text-center text-sm text-gray-500">Choose an event above.</div>
		<div v-else-if="!summary && !error" class="py-12 text-center text-sm text-gray-500">Loading check-in…</div>

		<div v-if="summary" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
			<!-- Door -->
			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div>
						<h2 class="text-lg font-bold text-gray-900">QR check-in</h2>
						<p class="mt-1 text-sm text-gray-500">{{ openSessions.length ? `Checking in to ${activeSessionLabel}` : windowNote }}</p>
					</div>
					<select v-if="openSessions.length > 1" v-model="sessionId" class="rounded-xl border border-gray-200 px-3 py-2 text-sm" aria-label="Session">
						<option v-for="s in openSessions" :key="s.id" :value="s.id">{{ s.label }}</option>
					</select>
				</div>

				<p v-if="!summary.can.scan" class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">You can see the numbers, but checking guests in needs the Check-in permission.</p>

				<template v-else>
					<div v-if="outcome" class="mt-5 rounded-2xl border-2 p-5" :class="toneClass[scanTone[outcome.result]]" role="status" aria-live="assertive">
						<p class="text-xs font-bold uppercase tracking-widest">{{ scanTitle[outcome.result] }}</p>
						<p v-if="outcome.guest" class="mt-1 text-2xl font-extrabold">{{ outcome.guest.name }}</p>
						<p v-if="outcome.guest" class="text-sm font-semibold">{{ outcome.guest.tier }}<span v-if="outcome.guest.zone"> · {{ outcome.guest.zone }}</span></p>
						<p v-if="outcome.guest?.benefits.length && outcome.result === 'checked_in'" class="mt-1 text-sm">Includes: {{ outcome.guest.benefits.join(' · ') }}</p>
						<p class="mt-2 text-sm">{{ outcome.result === 'checked_in' ? '' : outcome.message }}
							<template v-if="outcome.result === 'duplicate' && outcome.checked_in_at">First checked in at {{ time(outcome.checked_in_at) }}<template v-if="outcome.checked_in_by"> by {{ outcome.checked_in_by }}</template>. Scanned {{ outcome.duplicate_attempts }} more time{{ outcome.duplicate_attempts === 1 ? '' : 's' }}.</template>
						</p>
					</div>

					<div class="mt-5">
						<OrganizerQrScanner :disabled="!openSessions.length" @decode="submitToken" />
					</div>

					<form class="mt-5 flex gap-2" @submit.prevent="submitToken(typed)">
						<label class="sr-only" for="typed-code">Ticket code</label>
						<input id="typed-code" v-model="typed" autocomplete="off" placeholder="Type or scan the code (NSR1.…)"
							class="min-w-0 flex-1 rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
						<button type="submit" :disabled="!typed.trim()" class="rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Check</button>
					</form>
					<p class="mt-1 text-xs text-gray-400">A USB or Bluetooth barcode scanner works here too: click the box and scan.</p>

					<div class="mt-6 border-t border-gray-100 pt-5">
						<h3 class="text-sm font-bold text-gray-900">No QR? Find the guest</h3>
						<input v-model="query" placeholder="Name, email or phone" class="mt-2 w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm" @input="searchSoon">
						<ul v-if="matches.length" class="mt-2 divide-y divide-gray-100 rounded-xl border border-gray-100">
							<li v-for="g in matches" :key="g.ticket_id" class="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
								<div class="min-w-0">
									<p class="truncate font-semibold text-gray-900">{{ g.name }}</p>
									<p class="truncate text-xs text-gray-500">{{ g.tier }}<span v-if="g.zone"> · {{ g.zone }}</span><span v-if="g.email"> · {{ g.email }}</span></p>
									<p v-if="g.last_checked_in_at" class="text-xs font-semibold text-green-700">Arrived {{ time(g.last_checked_in_at) }}</p>
								</div>
								<span v-if="g.status === 'cancelled'" class="text-xs font-semibold text-rose-700">Cancelled</span>
								<button v-else type="button" :disabled="!openSessions.length" class="shrink-0 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-800 disabled:opacity-50" @click="checkInManually(g)">
									Check in
								</button>
							</li>
						</ul>
						<p v-else-if="query.trim().length >= 2 && !searching" class="mt-2 text-xs text-gray-400">No guest matches “{{ query.trim() }}”.</p>
					</div>
				</template>
			</section>

			<!-- Live numbers -->
			<aside class="space-y-5">
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<div class="flex items-baseline justify-between">
						<h3 class="text-base font-bold text-gray-900">Live attendance</h3>
						<span class="text-xs text-gray-400">updates every 5 s</span>
					</div>
					<p class="mt-3 text-4xl font-extrabold text-gray-900">{{ summary.checked_in }}<span class="text-lg font-semibold text-gray-400"> / {{ summary.expected }}</span></p>
					<div class="mt-2 h-2.5 overflow-hidden rounded-full bg-gray-100">
						<div class="h-full rounded-full bg-primary-700 transition-all" :style="{ width: pct(summary.checked_in, summary.expected) + '%' }" />
					</div>
					<p class="mt-1 text-xs text-gray-500">{{ pct(summary.checked_in, summary.expected) }}% of ticket holders have arrived</p>
					<p v-if="summary.walk_ins" class="mt-1 text-xs font-semibold text-gray-700">+ {{ summary.walk_ins }} walk-in{{ summary.walk_ins === 1 ? '' : 's' }} without a ticket · {{ summary.arrived }} people in total</p>

					<div v-if="summary.sessions.length > 1" class="mt-4 space-y-1 text-sm">
						<p v-for="s in summary.sessions" :key="s.id" class="flex justify-between"><span class="text-gray-600">{{ s.label }}<span v-if="s.is_open" class="ml-1 text-xs font-semibold text-green-700">open</span></span><span class="font-semibold">{{ s.checked_in }}</span></p>
					</div>
					<div v-if="summary.tiers.length" class="mt-4 space-y-2">
						<div v-for="t in summary.tiers" :key="t.name">
							<div class="flex justify-between text-xs"><span class="text-gray-600">{{ t.name }}</span><span class="text-gray-500">{{ t.checked_in }} / {{ t.expected }}</span></div>
							<div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-primary-500" :style="{ width: pct(t.checked_in, t.expected) + '%' }" /></div>
						</div>
					</div>
				</section>

				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<h3 class="text-base font-bold text-gray-900">Recent check-ins</h3>
					<ul v-if="summary.recent.length" class="mt-3 divide-y divide-gray-100">
						<li v-for="r in summary.recent" :key="r.id" class="flex items-start justify-between gap-3 py-2.5">
							<div class="min-w-0">
								<p class="truncate text-sm font-semibold text-gray-900">{{ r.name }}</p>
								<p class="text-xs text-gray-500">{{ r.tier }} · {{ r.method === 'manual' ? 'manual' : 'QR' }}<span v-if="r.checked_in_by"> · {{ r.checked_in_by }}</span><span v-if="r.duplicate_attempts" class="text-amber-700"> · rescanned {{ r.duplicate_attempts }}×</span></p>
							</div>
							<div class="shrink-0 text-right">
								<p class="text-xs text-gray-400">{{ time(r.checked_in_at) }}</p>
								<button v-if="summary.can.undo" type="button" class="text-xs font-semibold text-rose-700" @click="startUndo(r)">Undo</button>
							</div>
						</li>
					</ul>
					<p v-else class="mt-3 text-sm text-gray-400">No one has checked in yet.</p>
				</section>
			</aside>
		</div>

		<div v-if="undoing" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="undoing = null">
			<form class="w-full max-w-md rounded-2xl bg-white p-6" @submit.prevent="undo">
				<h2 class="text-lg font-bold text-gray-900">Undo {{ undoing.name }}'s check-in?</h2>
				<p class="mt-1 text-sm text-gray-500">Their ticket works again at the door. This is recorded in the activity log.</p>
				<Alert v-if="undoError" type="danger" :text="undoError" class="mt-3" />
				<FormsLabel text="Reason" class="mt-4" />
				<FormsTextField v-model="undoReason" placeholder="e.g. Checked in the wrong guest" />
				<div class="mt-5 flex justify-end gap-2">
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="undoing = null">Close</button>
					<button type="submit" :disabled="busy || undoReason.trim().length < 3" class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Undo check-in</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script setup lang="ts">
import { scanTitle, scanTone, type CheckInSummary, type DoorGuest, type RecentCheckIn, type ScanOutcome } from '~/composables/useCheckIn'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useCheckIn()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const summary = ref<CheckInSummary | null>(null)
const outcome = ref<ScanOutcome | null>(null)
const error = ref('')
const busy = ref(false)
const typed = ref('')
const sessionId = ref<string | null>(null)
const query = ref('')
const matches = ref<DoorGuest[]>([])
const searching = ref(false)
const undoing = ref<RecentCheckIn | null>(null)
const undoReason = ref('')
const undoError = ref('')

const toneClass = {
	ok: 'border-green-500 bg-green-50 text-green-900',
	warn: 'border-amber-400 bg-amber-50 text-amber-900',
	stop: 'border-rose-500 bg-rose-50 text-rose-900',
}

const openSessions = computed(() => summary.value?.sessions.filter(s => s.is_open) ?? [])
const activeSessionLabel = computed(() => openSessions.value.find(s => s.id === sessionId.value)?.label ?? openSessions.value[0]?.label ?? '')
const windowNote = computed(() => {
	const s = summary.value
	if (!s) return ''
	if (!['published', 'ongoing'].includes(s.event_status)) return 'Check-in is available once the event is published.'
	const next = s.sessions.find(x => new Date(x.opens_at) > new Date())
	return next
		? `Check-in opens ${new Date(next.opens_at).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })} (${s.opens_before_minutes / 60} h before “${next.label}”).`
		: 'Check-in has closed: every session has ended.'
})

function time(at: string) {
	return new Date(at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
function pct(n: number, of: number) {
	return of ? Math.round(n / of * 100) : 0
}

async function orgId() {
	return (organization.value ?? await fetchOrganization())!.id
}

async function load() {
	if (!eventId.value) return
	try {
		summary.value = await api.summary(await orgId(), eventId.value)
		// Keep the chosen session while it stays open; otherwise let the server pick.
		if (sessionId.value && !openSessions.value.some(s => s.id === sessionId.value)) sessionId.value = null
		if (!sessionId.value && openSessions.value.length > 1) sessionId.value = openSessions.value[0]!.id
	} catch (e) {
		error.value = authMessage(e)
	}
}

// Short audio + vibration cue so door staff needn't read the screen.
function cue(tone: 'ok' | 'warn' | 'stop') {
	try {
		navigator.vibrate?.(tone === 'ok' ? 80 : [120, 80, 120])
		const ctx = new AudioContext()
		const osc = ctx.createOscillator()
		osc.frequency.value = tone === 'ok' ? 880 : tone === 'warn' ? 520 : 220
		osc.connect(ctx.destination)
		osc.start()
		osc.stop(ctx.currentTime + (tone === 'ok' ? 0.12 : 0.35))
		osc.onended = () => ctx.close()
	} catch {
		// Sound is a nicety; some browsers block it until the page is tapped.
	}
}

// Scans are queued, never dropped: a barcode scanner or a line of guests
// can deliver the next code before the previous answer arrives.
let queue: Promise<void> = Promise.resolve()
function run(call: () => Promise<ScanOutcome>) {
	queue = queue.then(async () => {
		busy.value = true
		error.value = ''
		try {
			outcome.value = await call()
			cue(scanTone[outcome.value.result])
		} catch (e) {
			error.value = authMessage(e)
		} finally {
			busy.value = false
		}
		load()
	})
	return queue
}

async function submitToken(text: string) {
	const token = text.trim()
	if (!token) return
	typed.value = ''
	await run(async () => api.scan(await orgId(), eventId.value!, token, sessionId.value))
}

async function checkInManually(g: DoorGuest) {
	await run(async () => api.manual(await orgId(), eventId.value!, g.ticket_id, sessionId.value))
	await search()
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
function searchSoon() {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(search, 300)
}
async function search() {
	const q = query.value.trim()
	if (q.length < 2) {
		matches.value = []
		return
	}
	searching.value = true
	try {
		matches.value = await api.search(await orgId(), eventId.value!, q)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		searching.value = false
	}
}

function startUndo(r: RecentCheckIn) {
	undoing.value = r
	undoReason.value = ''
	undoError.value = ''
}
async function undo() {
	busy.value = true
	undoError.value = ''
	try {
		await api.undo(await orgId(), eventId.value!, undoing.value!.id, undoReason.value.trim())
		undoing.value = null
		outcome.value = null
		await load()
	} catch (e) {
		undoError.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

// Live numbers: poll while the page is visible (other doors are scanning too).
let poll: ReturnType<typeof setInterval> | undefined
onMounted(() => {
	load()
	poll = setInterval(() => {
		if (!document.hidden && !busy.value) load()
	}, 5000)
})
onBeforeUnmount(() => {
	clearInterval(poll)
	clearTimeout(searchTimer)
})
watch(eventId, () => {
	summary.value = null
	outcome.value = null
	sessionId.value = null
	matches.value = []
	query.value = ''
	load()
})
</script>
