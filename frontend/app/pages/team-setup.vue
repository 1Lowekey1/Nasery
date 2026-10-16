<template>
	<div>
		<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Team invitation</p>
		<h2 class="mt-1 text-2xl font-extrabold text-gray-900">{{ form.org ? `Join ${form.org}` : 'Join your team' }}</h2>
		<p class="mb-6 mt-2 text-sm leading-relaxed text-gray-500">
			<template v-if="form.org">You were invited to <span class="font-semibold text-gray-700">{{ form.org }}</span>{{ form.role ? ` as ${form.role}` : '' }}. </template>
			Choose a password to create your team account. That's all: no business set-up needed.
		</p>

		<div v-if="done" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-800" role="status">
			<p class="font-bold">{{ doneMessage }}</p>
			<p class="mt-1">Sign in with <span class="font-semibold">{{ form.email }}</span> and your new password to see your assignments.</p>
			<NuxtLink to="/login" class="mt-4 inline-block rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white">Sign in</NuxtLink>
		</div>

		<div v-else-if="!linkOk" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800" role="alert">
			<p class="font-bold">This invitation link is incomplete.</p>
			<p class="mt-1">Open the "Join the team" button from your invitation e-mail again. If it has expired, ask the person who invited you to press Resend on their Team page.</p>
		</div>

		<form v-else class="space-y-5" novalidate @submit.prevent="submit">
			<div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</div>

			<div>
				<FormsLabel text="Your e-mail" />
				<p class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700">{{ form.email }}</p>
			</div>

			<div>
				<FormsLabel text="Password" required />
				<FormsPasswordField v-model="form.password" placeholder="At least 8 characters" size="lg" autocomplete="new-password" required />
				<ul class="mt-2 space-y-1 text-xs" aria-label="Password requirements">
					<li v-for="rule in rules" :key="rule.label" class="flex items-center gap-1.5" :class="rule.ok ? 'text-emerald-700' : 'text-gray-500'">
						<span aria-hidden="true">{{ rule.ok ? '✓' : '○' }}</span>{{ rule.label }}
					</li>
				</ul>
			</div>

			<div>
				<FormsLabel text="Confirm password" required />
				<FormsPasswordField v-model="form.confirmation" placeholder="Type it again" size="lg" autocomplete="new-password" required />
				<p v-if="form.confirmation && !matches" class="mt-1.5 text-xs font-semibold text-red-600">The passwords don't match yet.</p>
			</div>

			<button :disabled="loading || !valid" class="w-full rounded-xl bg-primary-700 py-4 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">
				{{ loading ? 'Joining…' : 'Create my account and join' }}
			</button>
			<p v-if="!valid" class="text-center text-xs text-gray-500">Complete the requirements above to continue.</p>
		</form>
	</div>
</template>

<script setup lang="ts">
// Team invitation landing page: the invitation created the account, so the
// invitee only chooses a password (same idea as /admin-setup). The e-mail and
// one-time code come from the link's #fragment (never sent to the server or
// logged); organization and role there are only displayed: the server joins
// them to the team that actually invited them.
definePageMeta({ layout: 'auth' })
useSeoMeta({ title: 'Join your team | NaSeRy', robots: 'noindex' })

const api = useApi()
const form = reactive({ email: '', code: '', org: '', role: '', password: '', confirmation: '' })
const loading = ref(false)
const error = ref('')
const done = ref(false)
const doneMessage = ref('')
const linkOk = ref(true)

const rules = computed(() => [
	{ label: 'At least 8 characters', ok: form.password.length >= 8 },
	{ label: 'Contains a letter', ok: /[A-Za-z]/.test(form.password) },
	{ label: 'Contains a number', ok: /\d/.test(form.password) },
])
const matches = computed(() => form.password === form.confirmation)
const valid = computed(() => rules.value.every(r => r.ok) && matches.value && form.confirmation.length > 0)

onMounted(() => {
	const params = new URLSearchParams(window.location.hash.replace(/^#/, ''))
	form.email = (params.get('email') ?? '').trim().toLowerCase()
	form.code = (params.get('code') ?? '').trim()
	form.org = (params.get('org') ?? '').trim().slice(0, 120)
	form.role = (params.get('role') ?? '').trim().slice(0, 60)
	linkOk.value = /\S+@\S+/.test(form.email) && /^\d{6}$/.test(form.code)
	// Don't leave the code in the address bar or browser history.
	if (window.location.hash) history.replaceState(null, '', window.location.pathname)
})

async function submit() {
	if (!valid.value) return
	loading.value = true
	error.value = ''
	try {
		const r = await api.post<{ organization: string | null }>('/auth/team-setup', {
			email: form.email, code: form.code, password: form.password, password_confirmation: form.confirmation,
		})
		doneMessage.value = r.message ?? 'Your account is ready.'
		done.value = true
	} catch (e: unknown) {
		const message = authMessage(e)
		error.value = /expired|incorrect|invalid|attempts|used/i.test(message)
			? `${message} Ask the person who invited you to press Resend on their Team page.`
			: message || 'Unable to create your account. Please try again.'
	} finally {
		loading.value = false
	}
}
</script>
