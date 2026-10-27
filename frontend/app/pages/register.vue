<template>
	<div>
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
				class="flex items-center justify-center gap-2 rounded-xl border py-3 text-sm font-semibold" :class="role === 'organizer'
					? 'border-primary-600 bg-primary-50 text-primary-700'
					: 'border-gray-200 text-gray-500'
					" @click="role = 'organizer'">
				Organizer
			</button>

			<button type="button"
				class="flex items-center justify-center gap-2 rounded-xl border py-3 text-sm font-semibold" :class="role === 'client'
					? 'border-primary-600 bg-primary-50 text-primary-700'
					: 'border-gray-200 text-gray-500'
					" @click="role = 'client'">
				Client
			</button>
		</div>

		<AuthSocialButtons mode="register" :role="role" />

		<form novalidate @submit.prevent="handleSubmit">
			<FormsDraftNotice :draft="draft" />
			<div class="mb-5" data-field="fullName">
				<FormsLabel text="Full Name" required />

				<FormsTextField v-model="form.fullName" type="text" placeholder="Sean Kenneth H. Axalan" size="lg"
					required :error="check.err('fullName')">
					<template #icon>
						<svg viewBox="0 0 20 20"
							class="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400">
							<path fill="currentColor"
								d="M10 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v1h16v-1c0-3.3-4.7-5-8-5Z" />
						</svg>
					</template>
				</FormsTextField>
			</div>
			<div class="mb-5" data-field="email">
				<FormsLabel text="Email Address" required />
				<FormsTextField v-model="form.email" type="email" placeholder="you@example.com" size="lg" required autocomplete="email" :error="check.err('email')" />
				<p v-if="!check.err('email')" class="mt-1.5 text-xs text-gray-400">We'll send a 6-digit code here right after you sign up.</p>
			</div>
			<div class="mb-5" data-field="password">
				<FormsLabel text="Password" required />

				<FormsPasswordField v-model="form.password" placeholder="Create a strong password" size="lg" required :error="check.err('password')">
					<template #icon>
						<svg viewBox="0 0 20 20"
							class="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400">
							<path fill="currentColor"
								d="M5 8.5V6.8a5 5 0 0 1 10 0v1.7a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2Zm1.8 0h6.4V6.8a3.2 3.2 0 0 0-6.4 0v1.7Z" />
						</svg>
					</template>
				</FormsPasswordField>
				<ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Password requirements">
					<li v-for="rule in passwordRules" :key="rule.label" class="flex items-center gap-1" :class="rule.ok ? 'text-emerald-700' : 'text-gray-500'">
						<span aria-hidden="true">{{ rule.ok ? '✓' : '○' }}</span>{{ rule.label }}
					</li>
				</ul>
			</div>
			<div class="mb-5" data-field="phone">
				<FormsLabel text="Phone No" required />

				<FormsTextField v-model="form.phone" type="tel" placeholder="+63 912 345 6789" size="lg" required :error="check.err('phone')">
					<template #icon>
						<svg viewBox="0 0 20 20"
							class="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400">
							<path fill="currentColor"
								d="M4.4 2.6h2.9c.5 0 1 .3 1.1.8l.9 3a1.2 1.2 0 0 1-.3 1.2l-1.5 1.5a11.3 11.3 0 0 0 4.4 4.4l1.5-1.5a1.2 1.2 0 0 1 1.2-.3l3 .9c.5.1.8.6.8 1.1v2.9c0 .7-.6 1.3-1.3 1.2C9.9 17 3 10.1 2.6 2.7c0-.7.6-1.3 1.3-1.3Z" />
						</svg>
					</template>
				</FormsTextField>
			</div>


			<div v-if="role === 'organizer'" class="mb-6 rounded-2xl border border-primary-100 bg-primary-50/40 p-4 sm:p-5">
				<p class="text-sm font-bold text-gray-800">
					Organizer Service Details
				</p>

				<p class="mt-1 text-xs leading-relaxed text-gray-500">
					After you verify your e-mail, you'll set up your business profile: name, service area and services.
					NaSeRy reviews it before your profile goes public.
				</p>
			</div>


			<p class="mb-5 text-[13px] leading-relaxed text-gray-500">
				By creating an account, you agree to our

				<span class="cursor-pointer font-semibold text-primary-600">
					Terms of Service
				</span>

				and

				<span class="cursor-pointer font-semibold text-primary-600">
					Privacy Policy
				</span>.
			</p>


			<div v-if="conversion.active" class="mb-4 rounded-xl border border-primary-200 bg-primary-50 px-4 py-3" data-field="code">
				<p class="text-sm font-semibold text-primary-900">This e-mail already has a NaSeRy account that isn’t in use.</p>
				<p class="mt-1 text-xs leading-relaxed text-primary-800/80">We sent a 6-digit code to {{ conversion.email }}. Enter it to turn that account into your new {{ role === 'organizer' ? 'organizer' : 'client' }} account with the password above. Its photo and history stay.</p>
				<label for="reg-conversion-code" class="mt-3 block text-xs font-semibold text-gray-700">Code from the e-mail</label>
				<input id="reg-conversion-code" v-model="conversion.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"
					class="mt-1 w-40 rounded-lg border border-gray-200 bg-white px-3 py-2 text-center text-lg font-bold tracking-[0.3em] focus:border-primary-500 focus:outline-none">
			</div>

			<div v-if="check.summary.value || errorMessage"
				class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
				{{ check.summary.value || errorMessage }}
			</div>


			<button type="submit" :disabled="isLoading"
				class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white transition hover:bg-primary-900 disabled:cursor-not-allowed disabled:opacity-60">
				<template v-if="isLoading">
					{{ conversion.active ? 'Switching account...' : 'Creating Account...' }}
				</template>

				<template v-else>
					{{ conversion.active ? 'Use my old account' : 'Create My Account' }}

					<svg viewBox="0 0 20 20" class="h-4 w-4">
						<path fill="currentColor"
							d="M11.3 4.3a1 1 0 0 1 1.4 0l5 5a1 1 0 0 1 0 1.4l-5 5a1 1 0 1 1-1.4-1.4L14.6 11H3a1 1 0 1 1 0-2h11.6l-3.3-3.3a1 1 0 0 1 0-1.4Z" />
					</svg>
				</template>
			</button>
		</form>

		<p class="mt-5 text-center text-sm text-gray-500">
			Already have an account?

			<NuxtLink :to="loginLocation" class="font-bold text-primary-600">
				Sign in
			</NuxtLink>
		</p>

		<Teleport to="body">
			<div v-if="showSuccessModal"
				class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 px-4 backdrop-blur-sm">
				<div role="dialog" aria-modal="true" aria-labelledby="registration-success-title"
					class="w-full max-w-sm rounded-2xl bg-white p-7 text-center shadow-2xl">
					<div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
						<svg viewBox="0 0 24 24" class="h-8 w-8 text-green-600">
							<path fill="currentColor"
								d="M9.55 18.2 3.85 12.5l1.4-1.4 4.3 4.3 9.2-9.2 1.4 1.4-10.6 10.6Z" />
						</svg>
					</div>

					<h3 id="registration-success-title" class="mb-2 text-xl font-bold text-gray-900">
						Account Created Successfully
					</h3>

					<p class="mb-6 text-sm leading-relaxed text-gray-500">
						One last step: we sent a 6-digit code to your e-mail. Enter it to finish setting up your account.
					</p>

					<div class="mb-5 rounded-xl bg-gray-50 px-4 py-3">
						<p class="text-xs text-gray-400">
							{{ successContactLabel }}
						</p>

						<p class="mt-1 break-all text-sm font-semibold text-gray-700">
							{{ registeredContact }}
						</p>
					</div>

					<button type="button"
						class="w-full rounded-xl bg-primary-700 py-3.5 text-sm font-bold text-white transition hover:bg-primary-900"
						@click="goToLogin">
						Verify my e-mail
					</button>
				</div>
			</div>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
const authRoute = useRoute()
useSeoMeta({ title: 'Create an account | NaSeRy' })
const loginLocation = computed(() => {
	const redirect = inquiryRedirect(authRoute.query.redirect)
	return { path: '/login', query: redirect ? { redirect } : {} }
})
import type {
	RegisterPayload,
	UserRole,
} from '~/types/auth'

definePageMeta({
	layout: 'auth',
})

interface SelectedLocation {
	address: string
	providerId: string
	latitude: number
	longitude: number
}

const role = ref<UserRole>(inquiryRedirect(authRoute.query.redirect) ? 'client' : 'organizer')
const showSuccessModal = ref(false)
const registeredContact = ref('')
const emailVerificationToken = ref('')

const { register, isLoading, errorMessage } = useAuth()

onMounted(() => {
	if (typeof authRoute.query.social_error === 'string') errorMessage.value = authRoute.query.social_error
})

const form = reactive({
	fullName: '',
	email: '',
	password: '',
	phone: '',
	clientAddress: '',
	organizerLocation: '',
	serviceRadiusKm: 25,
})

const draft = useDraft(() => 'register', form, { omit: ['password'] })

// Signing up again with the e-mail of an account no longer in use (removed team member):
// the server e-mails a code; with it, that account becomes the new one (photo, history stay).
const conversion = reactive({ active: false, email: '', code: '' })
watch(() => form.email.trim().toLowerCase(), (now) => {
	if (conversion.active && now !== conversion.email) Object.assign(conversion, { active: false, code: '' })
})

const serviceRadiusOptions = [5, 10, 15, 25, 50, 75, 100, 150, 200]
const serviceRadiusSelectOptions = serviceRadiusOptions.map(radius => ({
	value: radius,
	label: `${radius} km`,
}))

const selectedOrganizerLocation = ref<SelectedLocation | null>(null)
const selectedClientLocation = ref<SelectedLocation | null>(null)

const hasSelectedClientLocation = computed(() => {
	const selected = selectedClientLocation.value
	return Boolean(selected?.address && selected.providerId && Number.isFinite(selected.latitude) && Number.isFinite(selected.longitude))
})

watch(role, () => {
	errorMessage.value = ''
})

const successContactLabel = computed(() =>
	'Registered email'
)

const successLoginMessage = computed(() =>
	role.value === 'organizer'
		? 'Please sign in to complete your organizer profile and start receiving nearby event inquiries.'
		: 'Please sign in using your email and password to access your new account.'
)

function parseFullName(
	fullName: string
) {
	const parts = fullName
		.trim()
		.split(/\s+/)
		.filter(Boolean)

	if (parts.length === 0) {
		return {
			firstname: '',
			middlename: '',
			lastname: '',
		}
	}

	if (parts.length === 1) {
		return {
			firstname:
				parts[0] ?? '',

			middlename:
				'',

			lastname:
				'',
		}
	}

	if (parts.length === 2) {
		return {
			firstname:
				parts[0] ?? '',

			middlename:
				'',

			lastname:
				parts[1] ?? '',
		}
	}

	return {
		firstname:
			parts[0] ?? '',

		middlename:
			parts
				.slice(1, -1)
				.join(' '),

		lastname:
			parts[
			parts.length - 1
			] ?? '',
	}
}

function normalizePhone(
	phone: string
): string {
	const normalized = phone
		.trim()
		.replace(/[\s\-()]+/g, '')

	if (
		/^\+639\d{9}$/
			.test(normalized)
	) {
		return normalized
	}

	if (
		/^639\d{9}$/
			.test(normalized)
	) {
		return `+${normalized}`
	}

	if (
		/^09\d{9}$/
			.test(normalized)
	) {
		return `+63${normalized.slice(1)}`
	}

	if (
		/^9\d{9}$/
			.test(normalized)
	) {
		return `+63${normalized}`
	}

	return normalized
}

// Every problem at once, under its field (no browser pop-ups, no one-at-a-time messages).
const passwordRules = computed(() => [
	{ label: 'At least 8 characters', ok: form.password.length >= 8 },
	{ label: 'A letter', ok: /[A-Za-z]/.test(form.password) },
	{ label: 'A number', ok: /\d/.test(form.password) },
])
const check = useFormCheck(() => {
	const { firstname, lastname } = parseFullName(form.fullName)
	const email = form.email.trim()
	const phone = normalizePhone(form.phone)
	return {
		fullName: !form.fullName.trim() ? 'Enter your full name.' : (!firstname || !lastname) ? 'Enter both your first and last name.' : '',
		email: !email ? 'Enter your e-mail address.' : !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? 'Enter a valid e-mail address (like name@example.com).' : '',
		password: !form.password ? 'Create a password.' : passwordRules.value.every(r => r.ok) ? '' : 'Your password needs 8+ characters with a letter and a number.',
		phone: !form.phone.trim() ? 'Enter your mobile number.' : !/^\+639\d{9}$/.test(phone) ? 'Enter a Philippine mobile number, e.g. 0917 123 4567.' : '',
	}
})

async function handleSubmit() {
	errorMessage.value = ''

	if (!check.ok()) return

	const { firstname, middlename, lastname } = parseFullName(form.fullName)
	const email = form.email.trim().toLowerCase()
	const phone = normalizePhone(form.phone)

	const registrationPayload:
		RegisterPayload = {
		firstname,

		...(middlename
			? { middlename }
			: {}),

		lastname,

		email,

		password:
			form.password,

		phone,


		role:
			role.value,
	}

	if (conversion.active && !/^\d{6}$/.test(conversion.code.trim())) {
		errorMessage.value = 'Enter the 6-digit code we e-mailed you.'
		return
	}

	try {
		const created = await register(registrationPayload, conversion.active ? conversion.code.trim() : undefined)
		if (!created) {
			// An old NaSeRy account uses this e-mail: ask for the code we just sent to it.
			conversion.active = true
			conversion.email = email
			return
		}

		registeredContact.value =
			email
		draft.clear()

		if (conversion.active) {
			// The code already confirmed the e-mail: straight into the new account.
			await navigateTo(role.value === 'organizer' ? '/organizer/home' : '/client/my-events')
			return
		}
		showSuccessModal.value =
			true
	} catch (error: unknown) {
		// Field problems from the server (e.g. e-mail already registered) go under the field.
		check.fromServer(error, { first_name: 'fullName', last_name: 'fullName', middle_name: 'fullName' })
	}
}

async function goToLogin() {
	showSuccessModal.value = false
	await navigateTo('/verify-email')
}

</script>
