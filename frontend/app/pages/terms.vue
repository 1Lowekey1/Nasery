<template>
	<article class="mx-auto max-w-3xl px-5 py-10 sm:px-8 sm:py-14">
		<p class="text-sm font-bold uppercase tracking-widest text-primary-700">NaSeRy</p>
		<h1 class="mb-2 mt-2 text-3xl font-extrabold tracking-tight text-gray-950 sm:text-4xl">Terms of Service</h1>
		<LegalDocument doc="terms" @open="navigateTo(`/${$event}`)" />
	</article>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Terms of Service | NaSeRy', description: 'The rules for using NaSeRy as a client or an event organizer.' })
</script>
