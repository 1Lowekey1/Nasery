<template>
	<div>
		<p class="text-xs font-bold uppercase tracking-widest text-primary-700">NaSeRy administrator</p>
		<h2 class="mt-1 text-2xl font-extrabold text-gray-900">Set up your admin account</h2>
		<p class="mb-6 mt-2 text-sm leading-relaxed text-gray-500">
			You were invited to help run NaSeRy. Choose a password to activate your account.
		</p>

		<div v-if="done" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-800" role="status">
			<p class="font-bold">Your account is ready.</p>
			<p class="mt-1">Sign in with <span class="font-semibold">{{ form.email }}</span> and your new password.</p>
			<NuxtLink to="/superadmin/login" class="mt-4 inline-block rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white">Go to admin sign-in</NuxtLink>
		</div>

		<div v-else-if="!linkOk" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800" role="alert">
			<p class="font-bold">This set-up link is incomplete.</p>
			<p class="mt-1">Open the "Set up my admin account" button from your invitation e-mail again. If it has expired, ask the admin who invited you to resend the invitation from the Admin Team page.</p>
		</div>

		<form v-else class="space-y-5" novalidate @submit.prevent="submit">
			<div v-if="error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</div>

			<div>
				<FormsLabel text="Your e-mail" />
				<p class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-700">{{ form.email }}</p>
			</div>

			<div>
				<FormsLabel text="New password" required />
				<FormsPasswordField v-model="form.password" placeholder="At least 8 characters" size="lg" autocomplete="new-password" required />
				<ul class="mt-2 space-y-1 text-xs" aria-label="Password requirements">
					<li v-for="rule in rules" :key="rule.label" class="flex items-center gap-1.5" :class="rule.ok ? 'text-emerald-700' : 'text-gray-500'">
						<span aria-hidden="true">{{ rule.ok ? '✓' : '○' }}</span>{{ rule.label }}
					</li>
				</ul>
			</div>

			<div>
				<FormsLabel text="Confirm new password" required />
				<FormsPasswordField v-model="form.confirmation" placeholder="Type it again" size="lg" autocomplete="new-password" required />
				<p v-if="form.confirmation && !matches" class="mt-1.5 text-xs font-semibold text-red-600">The passwords don't match yet.</p>
			</div>

			<button :disabled="loading || !valid" class="w-full rounded-xl bg-primary-700 py-4 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">
				{{ loading ? 'Activating…' : 'Activate my account' }}
			</button>
			<p v-if="!valid" class="text-center text-xs text-gray-500">Complete the requirements above to continue.</p>
		</form>
	</div>
</template>

<script setup lang="ts">
// Admin invitation landing page: the e-mail and one-time code come from the link's
// #fragment (never sent to the server or written to logs), so the invitee only
// chooses a password. Same endpoint as "Forgot password", without a second code.
definePageMeta({ layout: 'auth' })
useSeoMeta({ title: 'Set up your admin account | NaSeRy' })

const { resetPassword } = useAuth()
const form = reactive({ email: '', code: '', password: '', confirmation: '' })
const loading = ref(false)
const error = ref('')
const done = ref(false)
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
	linkOk.value = /\S+@\S+/.test(form.email) && /^\d{6}$/.test(form.code)
	// Don't leave the code in the address bar or browser history.
	if (window.location.hash) history.replaceState(null, '', window.location.pathname)
})

async function submit() {
	if (!valid.value) return
	loading.value = true
	error.value = ''
	try {
		await resetPassword({ email: form.email, code: form.code, password: form.password, password_confirmation: form.confirmation })
		done.value = true
	} catch (e: unknown) {
		const message = authMessage(e)
		error.value = /expired|incorrect|invalid|attempts/i.test(message)
			? `${message} Ask the admin who invited you to resend the invitation from the Admin Team page.`
			: message || 'Unable to activate your account. Please try again.'
	} finally {
		loading.value = false
	}
}
</script>
