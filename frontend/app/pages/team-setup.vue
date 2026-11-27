<template>
	<div>
		<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Team invitation</p>
		<h2 class="mt-1 text-2xl font-extrabold text-gray-900">{{ form.org ? `Join ${form.org}` : 'Join your team' }}</h2>
		<p class="mb-6 mt-2 text-sm leading-relaxed text-gray-500">
			<template v-if="form.org">You were invited to <span class="font-semibold text-gray-700">{{ form.org }}</span>{{ form.role ? ` as ${form.role}` : '' }}. </template>
			<template v-if="!done && linkOk && linkStatus === 'ready' && !otherSignedIn">Choose a password to create your team account. That's all: no business set-up needed.</template>
		</p>

		<div v-if="done" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-800" role="status">
			<p class="font-bold">{{ doneMessage }}</p>
			<p class="mt-1">Sign in with <span class="font-semibold">{{ form.email }}</span> and your new password to see your assignments.</p>
			<NuxtLink :to="signInLink" class="mt-4 inline-block rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white">Sign in</NuxtLink>
		</div>

		<div v-else-if="!linkOk" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800" role="alert">
			<p class="font-bold">This invitation link is incomplete.</p>
			<p class="mt-1">Open the "Join the team" button from your invitation e-mail again. If it has expired, ask the person who invited you to press Resend on their Team page.</p>
		</div>
		<!-- Invite fix (Nov 2026): the link is checked first, so a used link never shows the form again. -->
		<!-- Someone else is signed in on this browser (e.g. the owner who sent the invite): sign them out
		     first, so the new member never ends up working in that person's account. -->
		<div v-else-if="otherSignedIn" class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 text-sm text-gray-600" role="status">
			<p class="flex items-center gap-2 font-bold text-gray-900"><IconBase name="users" class="h-4 w-4 text-primary-700" />You're signed in as someone else</p>
			<p class="mt-1.5 leading-relaxed">
				This invitation is for <span class="font-semibold text-gray-900">{{ form.email }}</span>, but this browser is signed in as
				<span class="font-semibold text-gray-900">{{ otherSignedIn }}</span>. To set up the new account here, sign {{ otherName }} out first.
			</p>
			<div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
				<button type="button" :disabled="signingOut" class="rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white disabled:opacity-50" @click="signOutOther">
					{{ signingOut ? 'Signing out…' : 'Sign out and continue' }}
				</button>
				<NuxtLink :to="homeLink" class="text-sm font-semibold text-primary-700 hover:underline">Stay signed in as {{ otherName }}</NuxtLink>
			</div>
		</div>
		<p v-else-if="linkStatus === 'checking'" class="flex items-center gap-2 text-sm text-gray-500"><IconBase name="refresh-cw" class="h-4 w-4 animate-spin" />Checking your invitation…</p>
		<div v-else-if="linkStatus === 'used'" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-4 text-sm text-emerald-800" role="status">
			<template v-if="signedInAsInvitee">
				<p class="font-bold">You're already on the team.</p>
				<p class="mt-1">Your account is set up and you're signed in.</p>
				<NuxtLink :to="homeLink" class="mt-4 inline-block rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white">Go to my dashboard</NuxtLink>
			</template>
			<template v-else>
				<p class="font-bold">Your account is already set up.</p>
				<p class="mt-1">This link was used to choose your password. Sign in with <span class="font-semibold">{{ form.email }}</span> and the password you chose then.</p>
				<div class="mt-4 flex flex-wrap items-center gap-3">
					<NuxtLink :to="signInLink" class="inline-block rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white">Sign in</NuxtLink>
					<NuxtLink to="/forgot-password" class="text-sm font-semibold text-emerald-800 underline">Forgot your password?</NuxtLink>
				</div>
			</template>
		</div>
		<div v-else-if="linkStatus === 'expired' || linkStatus === 'invalid'" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-800" role="alert">
			<p class="font-bold">{{ linkStatus === 'expired' ? 'This invitation link has expired.' : 'This invitation link no longer works.' }}</p>
			<p class="mt-1">
				<template v-if="linkStatus === 'invalid'">A newer e-mail may have replaced it (a new invitation, or a password reset): use the newest one. </template>
				You can also ask the person who invited you to press Resend on their Team page, or set a password with Forgot password: you'll join the team either way.
			</p>
			<div class="mt-4 flex flex-wrap items-center gap-3">
				<NuxtLink to="/forgot-password" class="inline-block rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white">Forgot password</NuxtLink>
				<NuxtLink :to="signInLink" class="text-sm font-semibold text-amber-900 underline">Sign in</NuxtLink>
			</div>
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
// Invite fix (Nov 2026): what the link is now (the server checks the code without using it).
const linkStatus = ref<'checking' | 'ready' | 'used' | 'expired' | 'invalid'>('checking')
const { fetchUser, homePath } = useAuth()
const signedInAsInvitee = ref(false)
const homeLink = ref('/login')
// Same-browser fix (Nov 2026): the e-mail of another account signed in here, if any.
const otherSignedIn = ref('')
const otherName = ref('them')
const signingOut = ref(false)
async function signOutOther() {
	signingOut.value = true
	try {
		await useAuth().logout()
		otherSignedIn.value = ''
	} finally {
		signingOut.value = false
	}
}
// The sign-in page with the new member's e-mail filled in.
const signInLink = computed(() => ({ path: '/login', query: form.email ? { email: form.email } : {} }))

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
	if (linkOk.value) {
		void checkLink()
		void fetchUser().then((user) => {
			if (user && user.email?.toLowerCase() !== form.email) {
				otherSignedIn.value = user.email ?? 'another account'
				otherName.value = user.first_name || 'them'
				homeLink.value = homePath(user)
			}
		}).catch(() => {})
	}
})

async function checkLink() {
	try {
		const r = await api.post<{ status: 'ready' | 'used' | 'expired' | 'invalid' }>('/auth/team-setup/check', { email: form.email, code: form.code })
		linkStatus.value = r.data.status
		if (r.data.status === 'used') {
			const user = await fetchUser().catch(() => null)
			signedInAsInvitee.value = !!user && user.email?.toLowerCase() === form.email
			if (user) homeLink.value = homePath(user)
		}
	} catch {
		// Can't check (offline, too many tries): show the form; submitting explains any problem.
		linkStatus.value = 'ready'
	}
}

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
		if (/already used/i.test(message)) {
			linkStatus.value = 'used'
			return
		}
		error.value = /expired|incorrect|invalid|attempts/i.test(message)
			? `${message} Ask the person who invited you to press Resend on their Team page, or use Forgot password.`
			: message || 'Unable to create your account. Please try again.'
	} finally {
		loading.value = false
	}
}
</script>
