<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<p v-if="route.query.reset" class="mb-4 rounded-xl border border-green-100 bg-green-50 px-3 py-2 text-sm text-green-800">Password updated. Sign in with your new password.</p>

		<h2 class="mb-1.5 text-2xl font-extrabold text-gray-900 sm:text-[28px]">
			Welcome back
		</h2>
		<p class="mb-6 text-sm text-gray-500">
			Sign in to manage your events and bids
		</p>

		<AuthSocialButtons mode="login" />

		<form novalidate @submit.prevent="handleSubmit">
			<div class="mb-5">
				<FormsLabel text="Email Address" required />
				<FormsTextField v-model="form.email" type="email" autocomplete="email" placeholder="you@example.com" size="lg" required>
					<template #icon>
						<svg viewBox="0 0 20 20"
							class="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400">
							<path fill="currentColor"
								d="M2.5 4.5A1.5 1.5 0 0 1 4 3h12a1.5 1.5 0 0 1 1.5 1.5v11A1.5 1.5 0 0 1 16 17H4a1.5 1.5 0 0 1-1.5-1.5v-11Zm1.7.3 5.34 4.27a.9.9 0 0 0 1.12 0L16 4.8a.3.3 0 0 0-.19-.3H4.19a.3.3 0 0 0-.19.3Z" />
						</svg>
					</template>
				</FormsTextField>
				<p v-if="fields.email" class="mt-1.5 text-xs text-red-600">{{ fields.email }}</p>
			</div>

			<div class="mb-2 flex items-center justify-between">
				<label class="text-sm font-semibold text-gray-700">
					Password
				</label>
				<NuxtLink to="/forgot-password" class="text-[13px] font-semibold text-primary-600">
					Forgot password?
				</NuxtLink>
			</div>
			<div class="mb-6">
				<FormsPasswordField v-model="form.password" autocomplete="current-password" placeholder="Enter password" size="lg" required>
					<template #icon>
						<svg viewBox="0 0 20 20"
							class="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400">
							<path fill="currentColor"
								d="M5 8.5V6.8a5 5 0 0 1 10 0v1.7a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2Zm1.8 0h6.4V6.8a3.2 3.2 0 0 0-6.4 0v1.7Z" />
						</svg>
					</template>
				</FormsPasswordField>
				<p v-if="fields.password" class="mt-1.5 text-xs text-red-600">{{ fields.password }}</p>
			</div>

			<button type="submit" :disabled="submitting"
				class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white hover:bg-primary-900 disabled:cursor-wait disabled:opacity-70">
				{{ submitting ? 'Signing in…' : 'Sign In to NaSeRy' }}
				<svg v-if="!submitting" viewBox="0 0 20 20" class="h-4 w-4">
					<path fill="currentColor"
						d="M11.3 4.3a1 1 0 0 1 1.4 0l5 5a1 1 0 0 1 0 1.4l-5 5a1 1 0 1 1-1.4-1.4L14.6 11H3a1 1 0 1 1 0-2h11.6l-3.3-3.3a1 1 0 0 1 0-1.4Z" />
				</svg>
			</button>
		</form>

		<p class="mt-5 text-center text-sm text-gray-500">
			New to NaSeRy? <NuxtLink to="/register" class="font-bold text-primary-600">Create account</NuxtLink>
		</p>
	</div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const route = useRoute()
const { login, homePath } = useAuth()

const form = reactive({ email: '', password: '' })
const submitting = ref(false)
const error = ref(typeof route.query.social_error === 'string' ? authMessage(route.query.social_error) : '')
const fields = ref<Record<string, string>>({})

async function handleSubmit() {
	error.value = ''
	fields.value = {}
	submitting.value = true
	try {
		const user = await login(form.email, form.password)
		// Same-site paths only: "//evil.com" or "/\evil.com" would leave the app.
		const redirect = typeof route.query.redirect === 'string' && /^\/(?![/\\])/.test(route.query.redirect) ? route.query.redirect : null
		await navigateTo(!user.email_verified ? '/verify-email' : (redirect ?? homePath(user)))
	} catch (e) {
		fields.value = fieldErrors(e)
		error.value = Object.keys(fields.value).length ? '' : authMessage(e)
	} finally {
		submitting.value = false
	}
}
</script>
