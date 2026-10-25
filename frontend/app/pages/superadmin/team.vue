<template>
	<div class="mx-auto max-w-4xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Admin Team</h1>
		<p class="mt-1 text-gray-500">Everyone who can run NaSeRy. New admins get an e-mail with a “Set up my admin account” link and choose their own password; nobody else ever sees it.</p>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />

		<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
			<h2 class="text-base font-bold text-gray-900">Add an admin</h2>
			<form class="mt-4 grid gap-3 sm:grid-cols-3" @submit.prevent="invite">
				<label class="text-xs font-semibold text-gray-600">First name<input v-model="form.first_name" maxlength="80" autocomplete="off" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm"></label>
				<label class="text-xs font-semibold text-gray-600">Last name<input v-model="form.last_name" maxlength="80" autocomplete="off" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm"></label>
				<label class="text-xs font-semibold text-gray-600">E-mail<input v-model="form.email" type="email" maxlength="254" autocomplete="off" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm"></label>
				<p v-if="fields.email" class="text-xs text-red-600 sm:col-span-3">{{ fields.email }}</p>
				<div class="sm:col-span-3">
					<button type="submit" :disabled="busy || !form.first_name.trim() || !form.last_name.trim() || !form.email.trim()"
						class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add admin</button>
					<span class="ml-2 text-xs text-gray-500">Use an e-mail that has no NaSeRy account yet. The set-up link works for 72 hours; if it expires, use “Resend” below.</span>
				</div>
			</form>
		</section>

		<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
			<h2 class="text-base font-bold text-gray-900">Admins</h2>
			<div v-if="!admins && !error" class="py-6 text-center text-sm text-gray-400">Loading…</div>
			<ul v-else class="mt-3 divide-y divide-gray-100">
				<li v-for="a in admins ?? []" :key="a.id" class="py-3">
					<div class="flex flex-wrap items-center justify-between gap-3">
						<div class="min-w-0">
							<p class="font-semibold text-gray-900">{{ a.name }} <span v-if="a.id === user?.id" class="text-xs font-normal text-gray-400">(you)</span></p>
							<p class="text-xs text-gray-500">{{ a.email }} · added {{ shortDate(a.joined) }}</p>
						</div>
						<div class="flex flex-wrap items-center gap-3 text-xs font-semibold">
							<span v-if="a.invited" class="rounded-full bg-amber-50 px-2.5 py-0.5 text-amber-800">Invited · no password yet</span>
							<span v-else-if="a.status === 'active'" class="rounded-full bg-green-50 px-2.5 py-0.5 text-green-700">Active</span>
							<span v-else class="rounded-full bg-rose-50 px-2.5 py-0.5 text-rose-700">Disabled</span>
							<button v-if="a.invited && a.status === 'active'" type="button" :disabled="busy" class="text-primary-700" @click="resend(a.id)">Resend code</button>
							<template v-if="a.id !== user?.id">
								<button v-if="a.status === 'active'" type="button" class="text-rose-700" @click="startStatus(a.id, 'suspended')">Disable</button>
								<button v-else type="button" class="text-green-700" @click="startStatus(a.id, 'active')">Enable</button>
							</template>
						</div>
					</div>
					<form v-if="editing?.id === a.id" class="mt-2 flex flex-wrap items-end gap-2 rounded-xl bg-gray-50 p-3" @submit.prevent="saveStatus">
						<label class="min-w-64 flex-1 text-xs font-semibold text-gray-600">Reason (logged and e-mailed to {{ a.name }})
							<input v-model="reason" maxlength="1000" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
						</label>
						<button type="submit" :disabled="busy || reason.trim().length < 5" class="rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
							:class="editing.to === 'suspended' ? 'bg-rose-600' : 'bg-green-600'">{{ editing.to === 'suspended' ? 'Disable admin' : 'Enable admin' }}</button>
						<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm" @click="editing = null">Cancel</button>
					</form>
				</li>
			</ul>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { AdminMember } from '~/composables/useAdmin'

definePageMeta({ layout: 'superadmin' })

const { user } = useAuth()
const api = useAdmin()
const admins = ref<AdminMember[] | null>(null)
const form = reactive({ first_name: '', last_name: '', email: '' })
const fields = ref<Record<string, string>>({})
const error = ref('')
const notice = ref('')
const busy = ref(false)
const editing = ref<{ id: string, to: 'active' | 'suspended' } | null>(null)
const reason = ref('')

async function load() {
	try {
		admins.value = await api.admins()
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function run(fn: () => Promise<unknown>, message: string) {
	busy.value = true
	error.value = ''
	notice.value = ''
	fields.value = {}
	try {
		await fn()
		notice.value = message
		await load()
		return true
	} catch (e) {
		fields.value = fieldErrors(e)
		error.value = Object.keys(fields.value).length ? '' : authMessage(e)
		return false
	} finally {
		busy.value = false
	}
}

async function invite() {
	const email = form.email.trim()
	if (await run(() => api.inviteAdmin({ first_name: form.first_name.trim(), last_name: form.last_name.trim(), email }),
		`${form.first_name.trim()} was added. We e-mailed ${email} a link to set up their account (valid for 72 hours).`)) {
		Object.assign(form, { first_name: '', last_name: '', email: '' })
	}
}

const resend = (id: string) => run(() => api.resendAdminInvite(id), 'A new code is on its way.')

function startStatus(id: string, to: 'active' | 'suspended') {
	editing.value = { id, to }
	reason.value = ''
}

async function saveStatus() {
	const e = editing.value!
	if (await run(() => api.setAdminStatus(e.id, e.to, reason.value.trim()), e.to === 'suspended' ? 'Admin disabled and signed out everywhere.' : 'Admin enabled.')) {
		editing.value = null
	}
}

onMounted(load)
</script>
