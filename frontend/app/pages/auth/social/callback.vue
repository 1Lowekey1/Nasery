<template>
	<div class="text-center">
		<template v-if="!error">
			<div class="mx-auto mb-5 h-10 w-10 animate-spin rounded-full border-4 border-primary-100 border-t-primary-600" />
			<h2 class="mb-1.5 text-xl font-extrabold text-gray-900">Signing you in…</h2>
			<p class="text-sm text-gray-500">Just a moment.</p>
		</template>
		<template v-else>
			<Alert type="danger" :text="error" />
			<NuxtLink to="/login" class="font-bold text-primary-600">Back to sign in</NuxtLink>
		</template>
	</div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'auth' })

const route = useRoute()
const { exchangeSocial, homePath } = useAuth()
const error = ref('')

onMounted(async () => {
	const code = typeof route.query.code === 'string' ? route.query.code : ''
	if (!code) {
		error.value = authMessage('SOCIAL_LOGIN_EXPIRED')
		return
	}
	try {
		const user = await exchangeSocial(code)
		// Drop the one-time code from the address bar / history.
		await navigateTo(homePath(user), { replace: true })
	} catch (e) {
		error.value = authMessage(e)
	}
})
</script>
