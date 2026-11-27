<template>
	<!-- Delete an account permanently (only the admin e-mails in NASERY_HARD_DELETE_ADMINS see this). -->
	<section class="mt-5 rounded-2xl border border-rose-200 bg-white p-6">
		<h2 class="text-base font-bold text-gray-900">Delete an account permanently</h2>
		<p class="mt-1 text-sm text-gray-500">
			For test accounts. Removes the account, any organization it is the only owner of, and everything linked to them. A database backup is taken first; there is no undo here.
		</p>

		<form class="mt-4 flex flex-wrap items-end gap-2" novalidate @submit.prevent="check">
			<label class="min-w-0 flex-1">
				<span class="mb-1 block text-xs font-semibold text-gray-600">Account e-mail</span>
				<input v-model="email" type="email" autocomplete="off" placeholder="name@example.com" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
			</label>
			<button type="submit" :disabled="busy || !email.trim()" class="rounded-xl border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-800 disabled:opacity-50">
				{{ busy && !preview ? 'Checking…' : 'Check what goes' }}
			</button>
		</form>
		<p v-if="error" class="mt-3 rounded-xl bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700" role="alert">{{ error }}</p>
		<p v-if="done" class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800" role="status">{{ done }}</p>

		<div v-if="preview" class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
			<p class="font-bold text-gray-900">{{ preview.account.name || preview.account.email }}
				<span class="font-normal text-gray-500">· {{ preview.account.email }} · {{ typeLabel[preview.account.type] ?? preview.account.type }}{{ preview.account.status !== 'active' ? ` (${preview.account.status})` : '' }}</span></p>
			<p v-if="preview.blocked" class="mt-2 font-semibold text-amber-800">{{ preview.blocked }}</p>
			<template v-else>
				<ul class="mt-2 list-disc space-y-0.5 pl-5 text-gray-700">
					<li v-if="preview.removes.organizations.length">Organization{{ preview.removes.organizations.length === 1 ? '' : 's' }} they alone own: <strong>{{ preview.removes.organizations.join(', ') }}</strong></li>
					<li v-if="preview.removes.events">{{ preview.removes.events }} event{{ s(preview.removes.events) }}, with {{ preview.removes.tickets_on_those_events }} ticket{{ s(preview.removes.tickets_on_those_events) }} (including other people's)</li>
					<li v-if="preview.removes.events_booked_from_their_requests" class="font-semibold text-rose-700">{{ preview.removes.events_booked_from_their_requests }} event{{ s(preview.removes.events_booked_from_their_requests) }} organizers made from their requests</li>
					<li v-if="preview.removes.requests">{{ preview.removes.requests }} request{{ s(preview.removes.requests) }} and {{ preview.removes.offers }} offer{{ s(preview.removes.offers) }}</li>
					<li v-if="preview.removes.tickets_they_bought">{{ preview.removes.tickets_they_bought }} ticket{{ s(preview.removes.tickets_they_bought) }} they bought</li>
					<li v-if="preview.removes.reviews">{{ preview.removes.reviews }} review{{ s(preview.removes.reviews) }}</li>
					<li v-if="preview.removes.team_memberships">Their place on {{ preview.removes.team_memberships }} team{{ s(preview.removes.team_memberships) }} they don't own (those teams stay)</li>
					<li>Their messages, notifications and sign-in sessions</li>
				</ul>
				<form class="mt-4 space-y-3" novalidate @submit.prevent="remove">
					<label class="block">
						<span class="mb-1 block text-xs font-semibold text-gray-600">Why? (kept in the activity log)</span>
						<input v-model="reason" type="text" maxlength="1000" placeholder="e.g. Old test account" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
					</label>
					<label class="block">
						<span class="mb-1 block text-xs font-semibold text-gray-600">Type <strong>{{ preview.account.email }}</strong> to confirm</span>
						<input v-model="confirmEmail" type="text" autocomplete="off" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
					</label>
					<div class="flex flex-wrap gap-2">
						<button type="submit" :disabled="busy || !ready" class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">{{ busy ? 'Deleting…' : 'Delete permanently' }}</button>
						<button type="button" :disabled="busy" class="rounded-xl px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100" @click="reset">Cancel</button>
					</div>
				</form>
			</template>
		</div>
	</section>
</template>

<script setup lang="ts">
type Preview = {
	account: { name: string, email: string, type: string, status: string }
	blocked: string | null
	removes: { organizations: string[], team_memberships: number, events: number, events_booked_from_their_requests: number, tickets_on_those_events: number, tickets_they_bought: number, requests: number, offers: number, reviews: number }
}

const api = useApi()
const email = ref('')
const reason = ref('')
const confirmEmail = ref('')
const preview = ref<Preview | null>(null)
const busy = ref(false)
const error = ref('')
const done = ref('')
const typeLabel: Record<string, string> = { client: 'Client', business: 'Organizer / team', admin: 'Admin' }
const s = (n: number) => (n === 1 ? '' : 's')
const ready = computed(() => !!preview.value && reason.value.trim().length >= 5 && confirmEmail.value.trim().toLowerCase() === preview.value.account.email.toLowerCase())

function reset() {
	preview.value = null
	reason.value = ''
	confirmEmail.value = ''
	error.value = ''
}

async function check() {
	reset()
	done.value = ''
	busy.value = true
	try {
		preview.value = (await api.get<Preview>('/admin/account-removal', { query: { email: email.value.trim() } })).data
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function remove() {
	if (!preview.value || !ready.value) return
	if (!(await confirmDialog(`Delete ${preview.value.account.email} and everything listed? This can't be undone here.`, { title: 'Delete permanently?', confirmLabel: 'Delete permanently', danger: true }))) return
	busy.value = true
	error.value = ''
	try {
		await api.post('/admin/account-removal', { email: preview.value.account.email, confirm_email: confirmEmail.value.trim(), reason: reason.value.trim() })
		done.value = `${preview.value.account.email} was deleted. A backup was taken first.`
		email.value = ''
		reset()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}
</script>
