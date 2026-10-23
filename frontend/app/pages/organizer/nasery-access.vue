<template>
	<div class="w-full space-y-6 pb-8">
		<header>
			<h1 class="text-2xl font-extrabold tracking-tight text-gray-950 sm:text-[28px]">NaSeRy Access</h1>
			<p class="mt-1 max-w-3xl text-sm text-gray-500">NaSeRy staff can look inside your workspace only if you allow it: read-only, for a limited time, and you can end it anytime.</p>
		</header>

		<p v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

		<div v-if="!data && !error" class="h-56 animate-pulse rounded-3xl bg-white ring-1 ring-gray-200" />

		<template v-if="data">
			<!-- Requests from NaSeRy waiting for an answer -->
			<section v-for="r in requests" :key="r.id" class="rounded-3xl border border-amber-200 bg-amber-50/60 p-6">
				<div class="flex flex-wrap items-start gap-4">
					<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white text-amber-600 ring-1 ring-amber-200"><SaLock :open="false" class="h-5 w-5" /></span>
					<div class="min-w-0 flex-1">
						<p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">Waiting for your answer</p>
						<p class="mt-1 text-base font-bold text-gray-950">{{ r.admin }} from NaSeRy asks to view your workspace for {{ durationLabel(r.duration_hours) }}</p>
						<p class="mt-1 text-sm text-gray-700">“{{ r.reason }}”</p>
						<p class="mt-1 text-xs text-gray-500">Asked {{ formatDateTime(r.created_at) }} · lapses {{ formatDateTime(r.expires_at) }} if unanswered.</p>
						<div v-if="data.can.manage" class="mt-4 flex flex-wrap items-center gap-2">
							<input v-model="notes[r.id]" maxlength="1000" placeholder="Note (optional)" :aria-label="`Note for ${r.admin}`" class="sa-field !h-11 min-w-56 flex-1">
							<button type="button" :disabled="busy" class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-700 px-5 text-sm font-bold text-white transition hover:bg-primary-800 disabled:opacity-50" @click="decide(r, true)"><SaLock :open="true" class="h-4 w-4" /> Allow</button>
							<button type="button" :disabled="busy" class="inline-flex h-11 items-center rounded-xl border border-gray-200 bg-white px-5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50" @click="decide(r, false)">Refuse</button>
						</div>
						<p v-else class="mt-2 text-xs text-gray-500">Only an owner can answer.</p>
					</div>
				</div>
			</section>

			<Transition name="sa-swap" mode="out-in">
				<!-- Access on -->
				<section v-if="active" key="on" class="relative overflow-hidden rounded-3xl bg-primary-800 text-white">
					<div class="sa-dots" aria-hidden="true" />
					<div class="relative grid gap-8 p-6 sm:p-8 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-center">
						<div class="relative mx-auto h-40 w-40 shrink-0 lg:mx-0" role="timer" :aria-label="`${countdown} left`">
							<svg viewBox="0 0 160 160" class="h-full w-full -rotate-90" aria-hidden="true">
								<circle cx="80" cy="80" r="70" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="8" />
								<circle cx="80" cy="80" r="70" fill="none" stroke="white" stroke-width="8" stroke-linecap="round" :stroke-dasharray="RING" :stroke-dashoffset="RING * (1 - remainingRatio)" class="transition-[stroke-dashoffset] duration-1000 ease-linear" />
							</svg>
							<div class="absolute inset-0 flex flex-col items-center justify-center">
								<SaLock :open="true" class="h-9 w-9" />
								<p class="mt-1.5 font-mono text-lg font-bold tabular-nums tracking-tight">{{ countdown }}</p>
								<p class="text-[11px] font-medium text-white/60">left</p>
							</div>
						</div>
						<div class="min-w-0 text-center lg:text-left">
							<p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/15">
								<span class="relative flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" /><span class="relative inline-flex h-2 w-2 rounded-full bg-green-400" /></span>
								{{ active.admin }} can see your workspace
							</p>
							<h2 class="mt-3 text-2xl font-extrabold tracking-tight sm:text-3xl">Read-only access is on</h2>
							<blockquote class="mx-auto mt-3 max-w-xl border-l-2 border-white/30 pl-4 text-left text-sm leading-6 text-white/85 lg:mx-0">“{{ active.reason }}”</blockquote>
							<p class="mt-3 text-xs text-white/60">Started {{ formatDateTime(active.starts_at) }}{{ active.granted_by ? ` · allowed by ${active.granted_by}` : '' }} · Ends {{ formatDateTime(active.expires_at) }}</p>
							<p v-if="data.grants.length > 1" class="mt-1 text-xs text-white/60">{{ data.grants.length - 1 }} more {{ data.grants.length === 2 ? 'person has' : 'people have' }} access too (see below).</p>
						</div>
						<div v-if="data.can.manage" class="flex flex-col gap-2 sm:flex-row lg:flex-col">
							<button type="button" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-white px-5 text-sm font-bold text-primary-800 shadow-lg shadow-black/10 transition hover:-translate-y-0.5 hover:shadow-xl active:translate-y-0 disabled:opacity-60" :disabled="busy" @click="end(active.id)">
								<SaLock :open="false" class="h-4 w-4" /> End access now
							</button>
						</div>
					</div>
				</section>

				<!-- Access off -->
				<section v-else key="off" class="relative overflow-hidden rounded-3xl border border-gray-200 bg-white">
					<div class="sa-dots sa-dots-dark" aria-hidden="true" />
					<div class="relative flex flex-col items-center gap-6 p-6 text-center sm:flex-row sm:p-8 sm:text-left xl:gap-10">
						<div class="sa-vault flex h-24 w-24 shrink-0 items-center justify-center rounded-3xl bg-primary-50 text-primary-700 ring-1 ring-primary-100">
							<SaLock :open="false" class="h-11 w-11" />
						</div>
						<div class="min-w-0 flex-1">
							<h2 class="text-xl font-extrabold tracking-tight text-gray-950 sm:text-2xl">Your workspace is private</h2>
							<p class="mt-1 text-sm text-gray-500">Nobody from NaSeRy can see it right now.</p>
							<ul class="mt-4 flex flex-wrap justify-center gap-2 sm:justify-start">
								<li v-for="promise in promises" :key="promise.label" class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700">
									<IconBase :name="promise.icon" class="h-3.5 w-3.5 text-primary-700" />{{ promise.label }}
								</li>
							</ul>
						</div>
						<ol class="hidden shrink-0 gap-3 lg:grid lg:grid-cols-3 xl:w-[560px]" aria-label="How it works">
							<li v-for="(step, i) in steps" :key="step.title" class="sa-step rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur" :style="{ '--i': i }">
								<span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary-700 text-xs font-bold text-white">{{ i + 1 }}</span>
								<p class="mt-3 text-sm font-semibold text-gray-950">{{ step.title }}</p>
								<p class="mt-0.5 text-xs leading-5 text-gray-500">{{ step.text }}</p>
							</li>
						</ol>
					</div>
				</section>
			</Transition>

			<!-- Other people with access -->
			<section v-if="data.grants.length > 1" class="rounded-3xl border border-gray-200 bg-white">
				<h2 class="border-b border-gray-100 px-6 py-4 text-base font-bold text-gray-950">Everyone who has access now</h2>
				<ul class="divide-y divide-gray-100">
					<li v-for="g in data.grants" :key="g.id" class="flex flex-wrap items-center justify-between gap-3 px-6 py-3.5">
						<div>
							<p class="text-sm font-semibold text-gray-900">{{ g.admin }}</p>
							<p class="text-xs text-gray-500">Until {{ formatDateTime(g.expires_at) }} · allowed by {{ g.granted_by ?? 'your team' }} · {{ g.reason }}</p>
						</div>
						<button v-if="data.can.manage" type="button" :disabled="busy" class="rounded-xl border border-gray-200 px-3 py-1.5 text-sm font-semibold text-red-600 hover:bg-red-50" @click="end(g.id)">End access</button>
					</li>
				</ul>
			</section>

			<!-- Invite (left) + what it means and history (right) -->
			<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
				<Transition name="sa-fade" mode="out-in">
					<section v-if="invite" key="sent" class="rounded-3xl border border-gray-200 bg-white p-6">
						<p class="text-xs font-bold uppercase tracking-[0.14em] text-primary-700">Invite sent</p>
						<h2 class="mt-1 text-base font-bold text-gray-950">Support will pick it up soon</h2>
						<p class="mt-2 text-sm text-gray-700">“{{ invite.reason }}”</p>
						<p class="mt-1 text-xs text-gray-500">Sent {{ formatDateTime(invite.created_at) }} · for {{ durationLabel(invite.duration_hours) }} once an administrator accepts.</p>
						<button v-if="data.can.manage" type="button" :disabled="busy" class="mt-4 inline-flex h-10 items-center rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50" @click="cancelInvite(invite.id)">Withdraw invite</button>
					</section>
					<form v-else-if="data.can.manage" key="form" class="rounded-3xl border border-gray-200 bg-white" novalidate @submit.prevent="sendInvite">
						<div class="border-b border-gray-100 px-6 py-5">
							<h2 class="text-base font-bold text-gray-950">Ask NaSeRy support for help</h2>
							<p class="mt-0.5 text-sm text-gray-500">Tell us what's wrong. Once an administrator accepts, they get read-only access to look into it.</p>
						</div>
						<fieldset :disabled="busy" class="space-y-6 px-6 py-6">
							<div>
								<p class="sa-label">What's it about?</p>
								<div class="grid grid-cols-2 gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Topic">
									<button v-for="t in topics" :key="t.label" type="button" role="radio" class="sa-chip" :class="topic === t.label ? 'is-on' : ''" :aria-checked="topic === t.label" @click="topic = topic === t.label ? '' : t.label">
										<IconBase :name="t.icon" class="h-4 w-4" />{{ t.label }}
									</button>
								</div>
							</div>
							<div>
								<div class="flex items-baseline justify-between">
									<label for="sa-reason" class="sa-label">What's going wrong?</label>
									<span class="text-xs tabular-nums text-gray-400">{{ inviteReason.length }} / 900</span>
								</div>
								<textarea id="sa-reason" v-model="inviteReason" rows="4" maxlength="900" class="sa-field min-h-[112px] resize-y py-3 leading-6" placeholder="e.g. Buyers say checkout fails for our VIP tier" />
								<p class="mt-1.5 text-xs text-gray-500">Mention the event or page, and what you expected to happen.</p>
							</div>
							<div>
								<p class="sa-label">How long can support look?</p>
								<div class="sa-seg" role="radiogroup" aria-label="Duration">
									<span class="sa-seg-thumb" aria-hidden="true" :style="{ width: `calc((100% - 8px) / ${hourOptions.length})`, transform: `translateX(${durationIndex * 100}%)` }" />
									<button v-for="hrs in hourOptions" :key="hrs" type="button" role="radio" :aria-checked="inviteHours === hrs" :class="inviteHours === hrs ? 'is-on' : ''" @click="inviteHours = hrs">{{ durationLabel(hrs) }}</button>
								</div>
							</div>
						</fieldset>
						<div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-6 py-4">
							<p class="text-xs text-gray-500">You can end access anytime from this page.</p>
							<button type="submit" class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-700 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-primary-800 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50" :disabled="busy || inviteReason.trim().length < 5">
								<SaLock :open="true" class="h-4 w-4" /> Invite NaSeRy support
							</button>
						</div>
					</form>
				</Transition>

				<aside class="space-y-6 xl:sticky xl:top-0">
					<section class="rounded-3xl border border-gray-200 bg-white p-5">
						<h3 class="text-sm font-bold text-gray-950">Support can</h3>
						<ul class="mt-3 space-y-2.5">
							<li v-for="item in canDo" :key="item" class="flex items-start gap-2.5 text-sm text-gray-700"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-50 text-green-600"><IconBase name="check" class="h-3 w-3" /></span>{{ item }}</li>
						</ul>
						<h3 class="mt-5 text-sm font-bold text-gray-950">Support can't</h3>
						<ul class="mt-3 space-y-2.5">
							<li v-for="item in cantDo" :key="item" class="flex items-start gap-2.5 text-sm text-gray-700"><span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-500"><IconBase name="x" class="h-3 w-3" /></span>{{ item }}</li>
						</ul>
					</section>

					<section class="rounded-3xl border border-gray-200 bg-white">
						<div class="border-b border-gray-100 px-5 py-4">
							<h2 class="text-base font-bold text-gray-950">Access history</h2>
							<p class="mt-0.5 text-sm text-gray-500">Every request, and how it ended.</p>
						</div>
						<p v-if="!data.history.length" class="px-5 py-10 text-center text-sm text-gray-500">Nothing yet.</p>
						<ol v-else class="max-h-[460px] overflow-y-auto px-5 py-5">
							<li v-for="(h, i) in data.history" :key="h.id" class="sa-item relative flex gap-4 pb-6 last:pb-0" :style="{ '--i': i }">
								<span v-if="i < data.history.length - 1" class="absolute left-[15px] top-8 h-[calc(100%-2rem)] w-px bg-gray-200" aria-hidden="true" />
								<span class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white" :class="h.status === 'approved' ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-400'">
									<SaLock :open="h.status === 'approved'" class="h-4 w-4" />
								</span>
								<div class="min-w-0 flex-1">
									<div class="flex flex-wrap items-center gap-2">
										<p class="text-sm font-semibold text-gray-950">{{ h.initiated_by === 'admin' ? `${h.admin} asked` : `${h.requested_by} invited support` }}</p>
										<span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="h.status === 'approved' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'">{{ historyLabel(h) }}</span>
									</div>
									<p class="mt-1 line-clamp-2 text-sm text-gray-600">“{{ h.reason }}”</p>
									<p class="mt-1 text-xs text-gray-400">{{ formatDateTime(h.decided_at ?? h.created_at) }} · {{ durationLabel(h.duration_hours) }}</p>
								</div>
							</li>
						</ol>
					</section>
				</aside>
			</div>
		</template>

		<Teleport to="body">
			<Transition name="sa-toast">
				<div v-if="notice" class="fixed bottom-6 left-1/2 z-[130] flex -translate-x-1/2 items-center gap-2.5 rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-xl" role="status">
					<IconBase name="check-circle" class="h-4 w-4 text-green-400" />{{ notice }}
				</div>
			</Transition>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
// NaSeRy Access (B17) in the redesign's look: requests from NaSeRy to answer,
// the organizer's own invite to support, live grants (with a countdown) and history.
import { defineComponent, h } from 'vue'
import type { AccessGrant, AccessRequest } from '~/composables/useAdmin'

definePageMeta({ layout: 'dashboard' })

// Padlock icon whose shackle lifts when open.
const SaLock = defineComponent({
	props: { open: { type: Boolean, default: false } },
	setup(props) {
		return () => h('svg', { 'viewBox': '0 0 24 24', 'fill': 'none', 'stroke': 'currentColor', 'stroke-width': 1.9, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true', 'class': ['sa-lock', props.open ? 'is-open' : ''] }, [
			h('path', { class: 'sa-shackle', d: 'M8 10.5V7.5a4 4 0 0 1 8 0v3' }),
			h('rect', { x: 4.5, y: 10.5, width: 15, height: 10, rx: 2.5 }),
			h('path', { d: 'M12 14.5v2.5' }),
		])
	},
})

const { organization, fetchOrganization } = useOrganization()
const api = useNaseryAccess()
const data = ref<{ pending: AccessRequest[], grants: AccessGrant[], history: AccessRequest[], default_hours: number, can: { manage: boolean } } | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const notes = reactive<Record<string, string>>({})
const topic = ref('')
const inviteReason = ref('')
const inviteHours = ref(24)

const topics = [
	{ label: 'Payments', icon: 'cash' },
	{ label: 'Tickets & check-in', icon: 'ticket' },
	{ label: 'Events', icon: 'calendar' },
	{ label: 'Inquiries & quotes', icon: 'file-text' },
	{ label: 'Account & sign-in', icon: 'user' },
	{ label: 'Something else', icon: 'message' },
]
const steps = [
	{ title: 'Tell us what’s wrong', text: 'Pick a topic and describe the problem.' },
	{ title: 'Choose how long', text: 'A few hours up to a day.' },
	{ title: 'Support takes a look', text: 'Read-only, until time runs out or you end it.' },
]
const promises = [
	{ label: 'Read-only', icon: 'shield-check' },
	{ label: 'Ends on its own', icon: 'clock' },
	{ label: 'End it anytime', icon: 'x-circle' },
]
const canDo = ['See your events, clients and tickets', 'See your team, payments and reviews', 'Read the note you send with your request']
const cantDo = ['Change, add or delete anything', 'See your password', 'Move money or issue refunds']

const requests = computed(() => data.value?.pending.filter(r => r.initiated_by === 'admin') ?? [])
const invite = computed(() => data.value?.pending.find(r => r.initiated_by === 'organizer') ?? null)
const hourOptions = computed(() => [...new Set([1, 4, 8, data.value?.default_hours ?? 24])].sort((a, b) => a - b))
const durationIndex = computed(() => Math.max(0, hourOptions.value.indexOf(inviteHours.value)))

// ---------- Active grant countdown ----------
const RING = 2 * Math.PI * 70
const now = ref(Date.now())
const active = computed(() => data.value?.grants.find(g => new Date(g.expires_at).getTime() > now.value) ?? null)
const remainingMs = computed(() => active.value ? Math.max(0, new Date(active.value.expires_at).getTime() - now.value) : 0)
const remainingRatio = computed(() => {
	if (!active.value) return 0
	const total = new Date(active.value.expires_at).getTime() - new Date(active.value.starts_at).getTime()
	return total > 0 ? Math.min(1, remainingMs.value / total) : 0
})
const countdown = computed(() => {
	const s = Math.floor(remainingMs.value / 1000)
	const hh = Math.floor(s / 3600)
	const mm = Math.floor((s % 3600) / 60)
	const ss = s % 60
	return `${hh}:${String(mm).padStart(2, '0')}:${String(ss).padStart(2, '0')}`
})
let clock: ReturnType<typeof setInterval> | null = null

function formatDateTime(value: string) {
	return new Date(value).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function durationLabel(hours: number) { return `${hours} hour${hours === 1 ? '' : 's'}` }
function historyLabel(h: AccessRequest) {
	if (h.status === 'approved') return h.initiated_by === 'admin' ? `allowed by ${h.decided_by}` : `accepted by ${h.admin}`
	return { declined: `refused by ${h.decided_by}`, cancelled: 'withdrawn', expired: 'no answer', pending: 'waiting' }[h.status]
}

async function orgId() {
	return (organization.value ?? await fetchOrganization())!.id
}

async function load() {
	try {
		data.value = await api.load(await orgId())
		if (!hourOptions.value.includes(inviteHours.value)) inviteHours.value = data.value.default_hours
	} catch (e) {
		error.value = authMessage(e)
	}
}

let noticeTimer: ReturnType<typeof setTimeout> | null = null
async function run(fn: () => Promise<unknown>, message: string) {
	busy.value = true
	error.value = ''
	try {
		await fn()
		notice.value = message
		if (noticeTimer) clearTimeout(noticeTimer)
		noticeTimer = setTimeout(() => { notice.value = '' }, 3200)
		await load()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

const decide = async (r: AccessRequest, approve: boolean) => run(async () => api.decide(await orgId(), r.id, approve, notes[r.id]),
	approve ? `Access allowed for ${durationLabel(r.duration_hours)}. You can end it anytime.` : 'Request refused.')
const end = async (id: string) => (await confirmDialog('End NaSeRy access now?', { confirmLabel: 'End access' })) && run(async () => api.endGrant(await orgId(), id), 'Access ended.')
const sendInvite = () => run(async () => {
	const reason = topic.value ? `${topic.value}: ${inviteReason.value.trim()}` : inviteReason.value.trim()
	await api.invite(await orgId(), reason, inviteHours.value)
	inviteReason.value = ''
	topic.value = ''
}, 'NaSeRy support was invited.')
const cancelInvite = (id: string) => run(async () => api.cancelInvite(await orgId(), id), 'Invite withdrawn.')

onMounted(() => {
	load()
	clock = setInterval(() => { now.value = Date.now() }, 1000)
})
onBeforeUnmount(() => { if (clock) clearInterval(clock) })
</script>

<style scoped>
.sa-label { @apply mb-2 block text-sm font-semibold text-gray-800; }
.sa-field { @apply w-full rounded-xl border border-gray-200 bg-white px-3.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10; }
.sa-invalid { @apply border-red-300 focus:border-red-400 focus:ring-red-500/10; }
.sa-chip { @apply inline-flex h-11 w-full items-center gap-2.5 rounded-xl border border-gray-200 bg-white px-3.5 text-sm font-medium text-gray-700 transition hover:border-gray-300 hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600; }
.sa-chip.is-on { @apply border-primary-600 bg-primary-25 text-primary-800 ring-1 ring-primary-600; }

.sa-seg { position: relative; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-radius: 0.875rem; background: #f3f4f6; padding: 0.25rem; }
.sa-seg > button { position: relative; z-index: 1; height: 2.5rem; border-radius: 0.625rem; font-size: 0.875rem; font-weight: 600; color: #6b7280; transition: color 0.2s; }
.sa-seg > button:hover { color: #1f2937; }
.sa-seg > button.is-on { color: #1c4f5c; }
.sa-seg > button:focus-visible { outline: 2px solid #346f7a; outline-offset: 2px; }
.sa-seg-thumb {
	position: absolute; top: 0.25rem; bottom: 0.25rem; left: 0.25rem; width: calc((100% - 0.5rem) / 4);
	border-radius: 0.625rem; background: white; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08), 0 1px 3px rgba(16, 24, 40, 0.06);
	transition: transform 0.32s cubic-bezier(0.3, 1.3, 0.5, 1);
}

.sa-dots {
	position: absolute; inset: 0; pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1px, transparent 1.3px); background-size: 16px 16px;
	mask-image: linear-gradient(105deg, transparent 20%, black 75%);
}
.sa-dots-dark { background-image: radial-gradient(rgba(16, 63, 77, 0.09) 1px, transparent 1.3px); }

/* Padlock: the shackle lifts when access is open. */
:deep(.sa-lock .sa-shackle) { transition: transform 0.45s cubic-bezier(0.3, 1.5, 0.5, 1); transform-origin: 16px 10.5px; }
:deep(.sa-lock.is-open .sa-shackle) { transform: translateY(-2.5px) rotate(18deg); }

.sa-vault { animation: sa-breathe 4s ease-in-out infinite; }
@keyframes sa-breathe { 50% { box-shadow: 0 0 0 10px rgba(65, 127, 136, 0.06); } }

.sa-step { animation: sa-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(120ms + var(--i, 0) * 90ms); }
.sa-item { animation: sa-rise 0.45s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 50ms); }
@keyframes sa-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.sa-swap-enter-active, .sa-swap-leave-active { transition: opacity 0.28s ease, transform 0.28s ease; }
.sa-swap-enter-from { opacity: 0; transform: scale(0.98) translateY(6px); }
.sa-swap-leave-to { opacity: 0; transform: scale(0.98); }
.sa-fade-enter-active, .sa-fade-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.sa-fade-enter-from, .sa-fade-leave-to { opacity: 0; transform: translateY(6px); }
.sa-toast-enter-active, .sa-toast-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.sa-toast-enter-from, .sa-toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }

@media (prefers-reduced-motion: reduce) {
	.sa-vault, .sa-item, .sa-step { animation: none; }
	.sa-seg-thumb, :deep(.sa-lock .sa-shackle), .sa-swap-enter-active, .sa-swap-leave-active, .sa-fade-enter-active, .sa-fade-leave-active { transition: none; }
}
</style>
