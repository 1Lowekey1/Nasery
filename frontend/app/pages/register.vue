<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />

		<h2 class="mb-1.5 text-2xl font-extrabold text-gray-900 sm:text-[28px]">
			Create account
		</h2>
		<p class="mb-6 text-sm text-gray-500">
			Join NaSeRy to start posting or winning events
		</p>

		<span class="mb-2 block text-[11px] font-semibold tracking-[0.08em] text-gray-400">
			I AM JOINING AS
		</span>
		<div class="mb-5 grid grid-cols-2 gap-3">
			<button type="button"
				class="flex items-center justify-center gap-2 rounded-xl border py-3 text-sm font-semibold"
				:class="form.account_type === 'business' ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-500'"
				@click="form.account_type = 'business'">
				Organizer
			</button>
			<button type="button"
				class="flex items-center justify-center gap-2 rounded-xl border py-3 text-sm font-semibold"
				:class="form.account_type === 'client' ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-gray-200 text-gray-500'"
				@click="form.account_type = 'client'">
				Client
			</button>
		</div>

		<AuthSocialButtons mode="register" :account-type="form.account_type" />

		<form novalidate @submit.prevent="handleSubmit">
			<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
				<div>
					<FormsLabel text="First Name" required />
					<FormsTextField v-model="form.first_name" autocomplete="given-name" placeholder="Sean Kenneth" size="lg" required />
					<p v-if="fields.first_name" class="mt-1.5 text-xs text-red-600">{{ fields.first_name }}</p>
				</div>
				<div>
					<FormsLabel text="Last Name" required />
					<FormsTextField v-model="form.last_name" autocomplete="family-name" placeholder="Axalan" size="lg" required />
					<p v-if="fields.last_name" class="mt-1.5 text-xs text-red-600">{{ fields.last_name }}</p>
				</div>
			</div>

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

			<div class="mb-5">
				<FormsLabel text="Mobile Number (optional)" />
				<FormsTextField v-model="form.phone" type="tel" autocomplete="tel" placeholder="0917 123 4567" size="lg">
					<template #icon>
						<svg viewBox="0 0 20 20"
							class="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400">
							<path fill="currentColor"
								d="M4.4 2.6h2.9c.5 0 1 .3 1.1.8l.9 3a1.2 1.2 0 0 1-.3 1.2l-1.5 1.5a11.3 11.3 0 0 0 4.4 4.4l1.5-1.5a1.2 1.2 0 0 1 1.2-.3l3 .9c.5.1.8.6.8 1.1v2.9c0 .7-.6 1.3-1.3 1.2C9.9 17 3 10.1 2.6 2.7c0-.7.6-1.3 1.3-1.3Z" />
						</svg>
					</template>
				</FormsTextField>
				<p v-if="fields.phone" class="mt-1.5 text-xs text-red-600">{{ fields.phone }}</p>
			</div>

			<div class="mb-5">
				<FormsLabel text="Password" required />
				<FormsPasswordField v-model="form.password" autocomplete="new-password" placeholder="At least 8 characters, letters and numbers" size="lg" required>
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

			<div class="mb-6">
				<FormsLabel text="Confirm Password" required />
				<FormsPasswordField v-model="form.password_confirmation" autocomplete="new-password" placeholder="Type it again" size="lg" required />
			</div>

			<p class="mb-5 text-[13px] leading-relaxed text-gray-500">
				<!-- TODO(team): link real Terms of Service and Privacy Policy pages (Data Privacy Act, RA 10173) once written. -->
				By creating an account, you agree to NaSeRy's Terms of Service and Privacy Policy.
			</p>

			<button type="submit" :disabled="submitting"
				class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white hover:bg-primary-900 disabled:cursor-wait disabled:opacity-70">
				{{ submitting ? 'Creating your account…' : 'Create My Account' }}
			</button>
		</form>

		<p class="mt-5 text-center text-sm text-gray-500">
			Already have an account? <NuxtLink to="/login" class="font-bold text-primary-600">Sign in</NuxtLink>
		</p>
	</div>
</template>

<script setup lang="ts">
import type { RegisterPayload } from '~/composables/useAuth'

definePageMeta({ layout: 'auth' })

const route = useRoute()
const { register } = useAuth()

const form = reactive<RegisterPayload>({
	account_type: route.query.as === 'client' ? 'client' : 'business',
	first_name: '',
	last_name: '',
	email: '',
	phone: '',
	password: '',
	password_confirmation: '',
})
const submitting = ref(false)
const error = ref(typeof route.query.social_error === 'string' ? authMessage(route.query.social_error) : '')
const fields = ref<Record<string, string>>({})

async function handleSubmit() {
	error.value = ''
	fields.value = {}
	if (form.password !== form.password_confirmation) {
		fields.value = { password: 'The passwords do not match.' }
		return
	}
	submitting.value = true
	try {
		await register({ ...form, phone: form.phone || null })
		await navigateTo('/verify-email')
	} catch (e) {
		fields.value = fieldErrors(e)
		error.value = Object.keys(fields.value).length ? '' : authMessage(e)
	} finally {
		submitting.value = false
	}
}
</script>
