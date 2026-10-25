<template>
	<!-- Only providers configured on this server (the API says which); nothing at all when none are. -->
	<div v-if="providers.length">
		<div class="mb-5 grid gap-2.5" :class="providers.length > 1 ? 'grid-cols-2' : 'grid-cols-1'">
			<button v-for="provider in providers" :key="provider.id" type="button"
				:disabled="loading === provider.id"
				class="flex items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white px-1 py-2.5 text-[13px] font-semibold text-gray-700 transition hover:-translate-y-px hover:border-primary-200 hover:bg-primary-50/40 disabled:cursor-wait disabled:opacity-60"
				@click="continueWith(provider.id)">
				<svg v-if="provider.id === 'facebook'" aria-hidden="true" viewBox="0 0 24 24"
					class="h-5 w-5 shrink-0">
					<circle cx="12" cy="12" r="11" fill="#1877F2" />
					<path fill="#fff"
						d="M13.7 20v-7h2.35l.35-2.73h-2.7V8.53c0-.79.22-1.33 1.36-1.33h1.45V4.77a19.5 19.5 0 0 0-2.11-.11c-2.09 0-3.52 1.27-3.52 3.62v1.99H8.52V13h2.36v7h2.82Z" />
				</svg>
				<img v-else :src="provider.icon" alt="" class="h-5 w-5 shrink-0" />
				<span class="hidden sm:inline">{{ loading === provider.id ? 'Opening…' : provider.label }}</span>
			</button>
		</div>

		<p v-if="error" class="-mt-2 mb-4 rounded-xl border border-red-100 bg-red-50 px-3 py-2 text-xs text-red-700">
			{{ error }}
		</p>

		<div class="relative my-5 text-center text-[13px] text-gray-400">
			<div class="absolute inset-x-0 top-1/2 h-px bg-gray-200" />
			<span class="relative bg-white px-3">or continue with</span>
		</div>
	</div>
</template>

<script setup lang="ts">
type SocialProvider = 'google' | 'facebook'

const props = withDefaults(defineProps<{
	mode?: 'login' | 'register'
	role?: 'client' | 'organizer'
}>(), { mode: 'login', role: 'client' })

const loading = ref<SocialProvider | null>(null)
const error = ref('')
const allProviders = [
	{ id: 'google' as const, label: 'Google', icon: 'https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg' },
	{ id: 'facebook' as const, label: 'Facebook', icon: '' },
]
const enabled = ref<SocialProvider[]>([])
const providers = computed(() => allProviders.filter(p => enabled.value.includes(p.id)))

const { startSocial, socialProviders } = useAuth()

onMounted(async () => {
	try {
		enabled.value = (await socialProviders()).filter(p => p.enabled).map(p => p.provider as SocialProvider)
	} catch {
		enabled.value = []
	}
})

/** The API hands back the provider's consent URL (and remembers the account type for sign-ups). */
async function continueWith(provider: SocialProvider) {
	error.value = ''
	loading.value = provider
	try {
		await startSocial(provider, props.mode, props.role === 'organizer' ? 'business' : 'client')
	} catch (e) {
		error.value = authMessage(e)
		loading.value = null
	}
}
</script>
