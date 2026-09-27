<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<p v-if="notice" class="mb-4 rounded-xl border border-green-100 bg-green-50 px-3 py-2 text-sm text-green-800">{{ notice }}</p>

		<h2 class="mb-1.5 text-2xl font-extrabold text-gray-900 sm:text-[28px]">
			Verify your email
		</h2>
		<p class="mb-6 text-sm text-gray-500">
			We sent a 6-digit code to <span class="font-semibold text-gray-800">{{ user?.email }}</span>.
			It expires in 10 minutes.
		</p>

		<form novalidate @submit.prevent="handleVerify">
			<div class="mb-6">
				<FormsLabel text="Verification Code" required />
				<FormsTextField v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
					placeholder="123456" size="lg" required class="tracking-[0.4em]" />
			</div>

			<button type="submit" :disabled="submitting || code.length !== 6"
				class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-60">
				{{ submitting ? 'Verifying…' : 'Verify Email' }}
			</button>
		</form>

		<div class="mt-5 flex items-center justify-between text-sm">
			<button type="button" class="font-semibold text-primary-600 disabled:text-gray-400" :disabled="cooldown > 0 || resending"
				@click="handleResend">
				{{ cooldown > 0 ? `Resend code in ${cooldown}s` : 'Resend code' }}
			</button>
			<button type="button" class="font-semibold text-gray-500 hover:text-gray-700" @click="signOut">Wrong e-mail? Sign out</button>
		</div>
	</div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const { user, verifyEmail, sendVerificationCode, homePath, logout } = useAuth()

async function signOut() {
	await logout()
	await navigateTo('/login')
}

const code = ref('')
const submitting = ref(false)
const resending = ref(false)
const error = ref('')
const notice = ref('')
const cooldown = ref(60) // a code was just sent at sign-up / sign-in
let timer: ReturnType<typeof setInterval> | undefined

function startCooldown(seconds: number) {
	cooldown.value = seconds
	clearInterval(timer)
	timer = setInterval(() => {
		cooldown.value = Math.max(0, cooldown.value - 1)
		if (cooldown.value === 0) clearInterval(timer)
	}, 1000)
}

onMounted(() => {
	if (user.value?.email_verified) {
		navigateTo(homePath())
		return
	}
	startCooldown(cooldown.value)
})
onBeforeUnmount(() => clearInterval(timer))

watch(code, value => { code.value = value.replace(/\D/g, '').slice(0, 6) })

async function handleVerify() {
	error.value = ''
	notice.value = ''
	submitting.value = true
	try {
		const verified = await verifyEmail(code.value)
		await navigateTo(homePath(verified))
	} catch (e) {
		error.value = authMessage(e)
		if (e instanceof ApiError && typeof e.details.attempts_remaining === 'number') {
			error.value += ` ${e.details.attempts_remaining} attempt(s) left.`
		}
	} finally {
		submitting.value = false
	}
}

async function handleResend() {
	error.value = ''
	notice.value = ''
	resending.value = true
	try {
		const { data } = await sendVerificationCode()
		notice.value = 'We sent a new code to your email.'
		startCooldown(data.resend_in_seconds)
	} catch (e) {
		error.value = authMessage(e)
		if (e instanceof ApiError && typeof e.details.retry_after_seconds === 'number') {
			startCooldown(e.details.retry_after_seconds)
		}
	} finally {
		resending.value = false
	}
}
</script>
