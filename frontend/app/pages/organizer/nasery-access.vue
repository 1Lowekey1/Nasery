<template>
	<div class="mx-auto max-w-4xl">
		<h1 class="text-2xl font-extrabold text-gray-900">NaSeRy Access</h1>
		<p class="mt-1 text-gray-500">NaSeRy staff can look inside your workspace only if you allow it: read-only, for a limited time, and you can end it anytime.</p>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />
		<div v-if="!data && !error" class="py-12 text-center text-sm text-gray-400">Loading…</div>

		<template v-if="data">
			<section v-if="requests.length" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50/50 p-6">
				<h2 class="text-base font-bold text-gray-900">Waiting for your answer</h2>
				<article v-for="r in requests" :key="r.id" class="mt-4 rounded-xl border border-amber-100 bg-white p-4">
					<p class="text-sm text-gray-900"><b>{{ r.admin }}</b> from NaSeRy asks to view your workspace for {{ r.duration_hours }} hour{{ r.duration_hours === 1 ? '' : 's' }}.</p>
					<p class="mt-1 text-sm text-gray-700">Reason: “{{ r.reason }}”</p>
					<p class="mt-1 text-xs text-gray-500">Asked {{ dateTime(r.created_at) }} · lapses {{ dateTime(r.expires_at) }} if unanswered. They would see your events, clients, team, payments and reviews, but can't change anything.</p>
					<div v-if="data.can.manage" class="mt-3 flex flex-wrap items-end gap-2">
						<input v-model="notes[r.id]" maxlength="1000" placeholder="Note (optional)" :aria-label="`Note for ${r.admin}`" class="min-w-56 flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm">
						<button type="button" :disabled="busy" class="rounded-xl bg-green-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="decide(r, true)">Allow</button>
						<button type="button" :disabled="busy" class="rounded-xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-700 disabled:opacity-50" @click="decide(r, false)">Refuse</button>
					</div>
					<p v-else class="mt-2 text-xs text-gray-500">Only an owner can answer.</p>
				</article>
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Who has access now</h2>
				<p v-if="!data.grants.length" class="mt-3 text-sm text-gray-500">Nobody from NaSeRy can see your workspace.</p>
				<ul class="mt-3 divide-y divide-gray-100">
					<li v-for="g in data.grants" :key="g.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
						<div>
							<p class="text-sm font-semibold text-gray-900">{{ g.admin }}</p>
							<p class="text-xs text-gray-500">Until {{ dateTime(g.expires_at) }} · allowed by {{ g.granted_by ?? 'your team' }} · {{ g.reason }}</p>
						</div>
						<button v-if="data.can.manage" type="button" :disabled="busy" class="rounded-xl border border-rose-200 px-3 py-1.5 text-sm font-semibold text-rose-700" @click="end(g.id)">End access</button>
					</li>
				</ul>
			</section>

			<section v-if="data.can.manage" class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Ask NaSeRy support for help</h2>
				<div v-if="invite" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-sky-50 p-4 text-sm text-sky-900">
					<span>Invite sent {{ dateTime(invite.created_at) }}: “{{ invite.reason }}”. An administrator will pick it up.</span>
					<button type="button" :disabled="busy" class="font-semibold underline" @click="cancelInvite(invite.id)">Withdraw</button>
				</div>
				<form v-else class="mt-3 space-y-3" @submit.prevent="sendInvite">
					<p class="text-sm text-gray-600">Something not working? Describe it and let a NaSeRy administrator look at your workspace (read-only) to help.</p>
					<textarea v-model="inviteReason" rows="2" maxlength="1000" aria-label="What do you need help with?" placeholder="e.g. Buyers say checkout fails for our VIP tier"
						class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" />
					<div class="flex flex-wrap items-end gap-3">
						<label class="text-xs font-semibold text-gray-600">Allow for
							<select v-model.number="inviteHours" class="mt-1 block rounded-xl border border-gray-200 px-3 py-2 text-sm">
								<option v-for="h in hourOptions" :key="h" :value="h">{{ h }} hour{{ h === 1 ? '' : 's' }}</option>
							</select>
						</label>
						<button type="submit" :disabled="busy || inviteReason.trim().length < 5" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Invite NaSeRy support</button>
					</div>
				</form>
			</section>

			<section v-if="data.history.length" class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">History</h2>
				<ul class="mt-3 divide-y divide-gray-100 text-sm">
					<li v-for="h in data.history" :key="h.id" class="py-2.5">
						<span class="font-semibold text-gray-900">{{ h.initiated_by === 'admin' ? `${h.admin} asked` : `${h.requested_by} invited support` }}</span>
						<span class="text-gray-600"> · {{ historyLabel(h) }}</span>
						<span class="block text-xs text-gray-500">“{{ h.reason }}” · {{ dateTime(h.decided_at ?? h.created_at) }}</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { AccessGrant, AccessRequest } from '~/composables/useAdmin'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const api = useNaseryAccess()
const data = ref<{ pending: AccessRequest[], grants: AccessGrant[], history: AccessRequest[], default_hours: number, can: { manage: boolean } } | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const notes = reactive<Record<string, string>>({})
const inviteReason = ref('')
const inviteHours = ref(24)

const requests = computed(() => data.value?.pending.filter(r => r.initiated_by === 'admin') ?? [])
const invite = computed(() => data.value?.pending.find(r => r.initiated_by === 'organizer') ?? null)
const hourOptions = computed(() => [...new Set([1, 4, 8, data.value?.default_hours ?? 24])].sort((a, b) => a - b))

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
		inviteHours.value = data.value.default_hours
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function run(fn: () => Promise<unknown>, message: string) {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		await fn()
		notice.value = message
		await load()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

const decide = async (r: AccessRequest, approve: boolean) => run(async () => api.decide(await orgId(), r.id, approve, notes[r.id]),
	approve ? `Access allowed for ${r.duration_hours} hours. You can end it anytime.` : 'Request refused.')
const end = async (id: string) => (await confirmDialog('End NaSeRy access now?', { confirmLabel: 'End access' })) && run(async () => api.endGrant(await orgId(), id), 'Access ended.')
const sendInvite = () => run(async () => {
	await api.invite(await orgId(), inviteReason.value.trim(), inviteHours.value)
	inviteReason.value = ''
}, 'NaSeRy support was invited.')
const cancelInvite = (id: string) => run(async () => api.cancelInvite(await orgId(), id), 'Invite withdrawn.')

onMounted(load)
</script>
