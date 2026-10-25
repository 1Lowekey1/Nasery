<template><main class="flex min-h-screen items-center justify-center bg-slate-50 p-6"><section class="w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-xl shadow-slate-900/5"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-50 text-amber-600">←</div><h1 class="mt-5 text-2xl font-extrabold text-slate-900">Payment was cancelled</h1><p class="mt-2 text-sm leading-6 text-slate-500">No ticket was issued. You can return to the registration page when you are ready to complete payment.</p><button class="mt-6 rounded-xl bg-[#285F6b] px-5 py-3 text-sm font-bold text-white" @click="back">Return to registration</button><div class="mt-3"><NuxtLink to="/" class="text-sm font-bold text-slate-500 hover:text-primary-700">Back to home</NuxtLink></div></section></main></template>
<script setup lang="ts">
definePageMeta({ layout: false })
// PayMongo's own page is "back" in the history, so go to the event's registration page instead.
const route = useRoute()
const back = () => navigateTo(typeof route.query.event === 'string' ? registrationPath(route.query.event) : '/marketplace')
</script>
