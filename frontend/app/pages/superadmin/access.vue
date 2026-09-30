<template>
	<div class="mx-auto max-w-5xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Workspace Access</h1>
		<p class="mt-1 text-gray-500">Organizers decide who from NaSeRy may look inside their workspace. Access is read-only and ends on its own.</p>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />
		<div v-if="!data && !error" class="py-12 text-center text-sm text-gray-400">Loading…</div>

		<template v-if="data">
			<section class="mt-6 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Support invites from organizers</h2>
				<p class="mt-1 text-sm text-gray-500">An owner asked NaSeRy for help (for example, to look into a bug). Accepting gives you read-only access right away.</p>
				<p v-if="!data.invites.length" class="mt-4 text-sm text-gray-400">No open invites.</p>
				<ul class="mt-4 space-y-3">
					<li v-for="r in data.invites" :key="r.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-100 p-4">
						<div class="min-w-0">
							<p class="font-semibold text-gray-900">{{ r.organization.name }}</p>
							<p class="text-sm text-gray-700">“{{ r.reason }}”</p>
							<p class="text-xs text-gray-500">From {{ r.requested_by }} · {{ dateTime(r.created_at) }} · for {{ r.duration_hours }} h</p>
						</div>
						<button type="button" :disabled="busy" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="accept(r.id)">Accept and open</button>
					</li>
				</ul>
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Your active access</h2>
				<p v-if="!data.grants.length" class="mt-4 text-sm text-gray-400">None right now. Request access from an organizer's page.</p>
				<ul class="mt-4 divide-y divide-gray-100">
					<li v-for="g in data.grants" :key="g.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
						<div>
							<NuxtLink :to="`/superadmin/organizers/${g.organization.id}`" class="font-semibold text-primary-700">{{ g.organization.name }}</NuxtLink>
							<p class="text-xs text-gray-500">Until {{ dateTime(g.expires_at) }} · approved by {{ g.granted_by ?? 'the organizer' }} · {{ g.reason }}</p>
						</div>
						<button type="button" :disabled="busy" class="text-sm font-semibold text-rose-700" @click="end(g.id)">End now</button>
					</li>
				</ul>
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Your requests</h2>
				<p v-if="!data.requests.length" class="mt-4 text-sm text-gray-400">You haven't asked any organizer yet.</p>
				<div v-else class="mt-4 overflow-x-auto">
					<table class="w-full text-left text-sm">
						<thead><tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
							<th class="pb-2 pr-4">Organizer</th><th class="pb-2 pr-4">Reason</th><th class="pb-2 pr-4">Asked</th><th class="pb-2 pr-4">Answer</th><th class="pb-2" />
						</tr></thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="r in data.requests" :key="r.id">
								<td class="py-2.5 pr-4 font-semibold text-gray-900">{{ r.organization.name }}</td>
								<td class="py-2.5 pr-4 text-gray-600">{{ r.reason }}</td>
								<td class="py-2.5 pr-4 text-gray-500">{{ shortDate(r.created_at) }}</td>
								<td class="py-2.5 pr-4">
									<span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass[r.status]">{{ statusLabel[r.status] }}</span>
									<span v-if="r.decision_note" class="ml-1 text-xs text-gray-500">“{{ r.decision_note }}”</span>
								</td>
								<td class="py-2.5 text-right">
									<button v-if="r.status === 'pending'" type="button" class="text-xs font-semibold text-gray-600" :disabled="busy" @click="withdraw(r.id)">Withdraw</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { AccessGrant, AccessRequest, AccessStatus } from '~/composables/useAdmin'

definePageMeta({ layout: 'superadmin' })

const admin = useAdmin()
const data = ref<{ invites: AccessRequest[], requests: AccessRequest[], grants: AccessGrant[] } | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)

const statusLabel: Record<AccessStatus, string> = { pending: 'Waiting', approved: 'Approved', declined: 'Refused', cancelled: 'Withdrawn', expired: 'No answer' }
const statusClass: Record<AccessStatus, string> = {
	pending: 'bg-amber-50 text-amber-800', approved: 'bg-green-50 text-green-700', declined: 'bg-rose-50 text-rose-700',
	cancelled: 'bg-gray-100 text-gray-600', expired: 'bg-gray-100 text-gray-600',
}

async function load() {
	try {
		data.value = await admin.access()
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
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function accept(id: string) {
	const r = await admin.acceptInvite(id).catch((e) => { error.value = authMessage(e) })
	if (r) await navigateTo(`/superadmin/organizers/${r.organization.id}`)
}
const end = async (id: string) => (await confirmDialog('End this access now?', { confirmLabel: 'End access' })) && run(() => admin.endGrant(id), 'Access ended.')
const withdraw = (id: string) => run(() => admin.cancelRequest(id), 'Request withdrawn.')

onMounted(load)
</script>
