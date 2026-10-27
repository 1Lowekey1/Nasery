<template>
	<!-- NaSeRy's own error page (missing pages, missing organizers, crashes) instead of Nuxt's default. -->
	<main class="flex min-h-dvh items-center justify-center bg-gray-50 px-5 py-12">
		<div class="w-full max-w-md text-center">
			<NuxtLink to="/" class="mx-auto mb-8 flex w-fit items-center gap-2.5" aria-label="NaSeRy home">
				<BrandMark class="h-10 w-10 text-lg" />
				<span class="text-xl font-extrabold tracking-tight text-gray-900">NaSeRy</span>
			</NuxtLink>
			<p class="text-sm font-bold uppercase tracking-widest text-primary-700">{{ notFound ? 'Not found' : 'Something went wrong' }}</p>
			<h1 class="mt-2 text-2xl font-extrabold text-gray-950">{{ heading }}</h1>
			<p class="mt-3 text-sm leading-relaxed text-gray-500">{{ hint }}</p>
			<div class="mt-7 flex flex-wrap justify-center gap-3">
				<button type="button" class="rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white hover:bg-primary-900" @click="goHome">Go to the home page</button>
				<button v-if="!notFound" type="button" class="rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="retry">Try again</button>
			</div>
		</div>
	</main>
</template>

<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{ error: NuxtError }>()
const notFound = computed(() => props.error.statusCode === 404)
// Page code may pass a friendly message ("Organizer not found"); Nuxt's own 404 says "Page not found: /path".
const heading = computed(() => {
	if (!notFound.value) return 'This page didn’t load'
	const message = props.error.statusMessage || props.error.message || ''
	return message && !message.startsWith('Page not found') ? message : 'We can’t find that page'
})
const hint = computed(() => notFound.value
	? 'The link may be old or mistyped. Start from the home page to find organizers and events.'
	: 'Check your connection and try again. If it keeps happening, sign out and back in.')

useSeoMeta({ title: () => `${heading.value} | NaSeRy`, robots: 'noindex' })

function goHome() { clearError({ redirect: '/' }) }
function retry() { clearError(); reloadNuxtApp() }
</script>
