<template>
	<article class="mx-auto max-w-3xl px-5 py-10 sm:px-8 sm:py-14">
		<p class="text-sm font-bold uppercase tracking-widest text-primary-700">NaSeRy</p>
		<h1 class="mb-2 mt-2 text-3xl font-extrabold tracking-tight text-gray-950 sm:text-4xl">Privacy Policy</h1>
		<LegalDocument doc="privacy" @open="navigateTo(`/${$event}`)" />
	</article>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Privacy Policy | NaSeRy', description: 'What personal information NaSeRy collects, why, and your rights under the Data Privacy Act of 2012.' })
</script>
