<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<p v-if="notice" class="mb-4 rounded-xl border border-green-100 bg-green-50 px-3 py-2 text-sm text-green-800">{{ notice }}</p>

		<h2 class="mb-1.5 text-2xl font-extrabold text-gray-900 sm:text-[28px]">
			{{ step === 'email' ? 'Forgot your password?' : 'Set a new password' }}
		</h2>
		<p class="mb-6 text-sm text-gray-500">
			<template v-if="step === 'email'">Enter your account email and we'll send you a 6-digit reset code.</template>
			<template v-else>Enter the code sent to <span class="font-semibold text-gray-800">{{ form.email }}</span> and choose a new password.</template>
		</p>

		<form v-if="step === 'email'" novalidate @submit.prevent="requestCode">
			<div class="mb-6">
				<FormsLabel text="Email Address" required />
				<FormsTextField v-model="form.email" type="email" autocomplete="email" placeholder="you@example.com" size="lg" required />
				<p v-if="fields.email" class="mt-1.5 text-xs text-red-600">{{ fields.email }}</p>
			</div>
			<button type="submit" :disabled="submitting"
				class="flex w-full items-center justify-center rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white hover:bg-primary-900 disabled:cursor-wait disabled:opacity-70">
				{{ submitting ? 'Sending…' : 'Send Reset Code' }}
			</button>
		</form>

		<form v-else novalidate @submit.prevent="resetPasswordNow">
			<div class="mb-5">
				<FormsLabel text="Reset Code" required />
				<FormsTextField v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"
					size="lg" required class="tracking-[0.4em]" />
			</div>
			<div class="mb-5">
				<FormsLabel text="New Password" required />
				<FormsPasswordField v-model="form.password" autocomplete="new-password" placeholder="At least 8 characters, letters and numbers" size="lg" required />
				<p v-if="fields.password" class="mt-1.5 text-xs text-red-600">{{ fields.password }}</p>
			</div>
			<div class="mb-6">
				<FormsLabel text="Confirm New Password" required />
				<FormsPasswordField v-model="form.password_confirmation" autocomplete="new-password" placeholder="Type it again" size="lg" required />
			</div>
			<button type="submit" :disabled="submitting || form.code.length !== 6"
				class="flex w-full items-center justify-center rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-60">
				{{ submitting ? 'Saving…' : 'Update Password' }}
			</button>
			<button type="button" class="mt-4 w-full text-sm font-semibold text-primary-600" @click="step = 'email'">
				Use a different email or resend the code
			</button>
		</form>

		<p class="mt-5 text-center text-sm text-gray-500">
			Remembered it? <NuxtLink to="/login" class="font-bold text-primary-600">Back to sign in</NuxtLink>
		</p>
	</div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const { forgotPassword, resetPassword } = useAuth()

const step = ref<'email' | 'reset'>('email')
const form = reactive({ email: '', code: '', password: '', password_confirmation: '' })
const submitting = ref(false)
const error = ref('')
const notice = ref('')
const fields = ref<Record<string, string>>({})

watch(() => form.code, value => { form.code = value.replace(/\D/g, '').slice(0, 6) })

async function requestCode() {
	error.value = ''
	notice.value = ''
	fields.value = {}
	submitting.value = true
	try {
		const { message } = await forgotPassword(form.email)
		notice.value = message ?? ''
		step.value = 'reset'
	} catch (e) {
		fields.value = fieldErrors(e)
		error.value = Object.keys(fields.value).length ? '' : authMessage(e)
	} finally {
		submitting.value = false
	}
}

async function resetPasswordNow() {
	error.value = ''
	notice.value = ''
	fields.value = {}
	if (form.password !== form.password_confirmation) {
		fields.value = { password: 'The passwords do not match.' }
		return
	}
	submitting.value = true
	try {
		await resetPassword({ ...form })
		await navigateTo({ path: '/login', query: { reset: '1' } })
	} catch (e) {
		fields.value = fieldErrors(e)
		error.value = Object.keys(fields.value).length ? '' : authMessage(e)
	} finally {
		submitting.value = false
	}
}
</script>
