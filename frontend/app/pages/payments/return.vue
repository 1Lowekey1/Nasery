<template>
	<div class="mx-auto max-w-lg px-5 pt-12 pb-16 text-center">
		<div v-if="!state && !error" class="py-16 text-gray-500">Checking your payment…</div>
		<Alert v-if="error" type="danger" :text="error" />

		<template v-if="state">
			<div v-if="state.status === 'paid' && state.registration_status === 'confirmed'" class="rounded-2xl border border-green-200 bg-green-50 p-8">
				<h1 class="text-2xl font-bold text-green-900">Payment received</h1>
				<p class="mt-2 text-sm text-green-800">{{ formatPeso(state.amount) }} paid. Your QR tickets are ready.</p>
				<NuxtLink to="/my-tickets" class="mt-5 inline-block rounded-lg bg-green-700 px-5 py-3 text-sm font-bold text-white">Open My Tickets</NuxtLink>
			</div>

			<div v-else-if="state.status === 'paid'" class="rounded-2xl border border-amber-200 bg-amber-50 p-8">
				<h1 class="text-xl font-bold text-amber-900">Payment refunded</h1>
				<p class="mt-2 text-sm text-amber-800">Your payment arrived after the places were gone, so it was refunded in full.</p>
			</div>

			<div v-else-if="state.status === 'pending' && !cancelled && !failed" class="rounded-2xl border border-gray-200 bg-white p-8">
				<h1 class="text-xl font-bold text-gray-900">Waiting for confirmation…</h1>
				<p class="mt-2 text-sm text-gray-500">This usually takes a few seconds. You can close this page; your tickets will appear in My Tickets once the payment is confirmed.</p>
			</div>

			<div v-else class="rounded-2xl border border-gray-200 bg-white p-8">
				<h1 class="text-xl font-bold text-gray-900">{{ cancelled ? 'Payment not completed' : 'Payment failed' }}</h1>
				<p class="mt-2 text-sm text-gray-500">
					{{ state.failure_reason ? `${state.failure_reason.replace(/\.?$/, '.')}` : 'No money was taken.' }}
					<template v-if="state.hold_expires_at">Your places stay reserved until {{ new Date(state.hold_expires_at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) }}.</template>
				</p>
				<NuxtLink to="/my-tickets" class="mt-5 inline-block rounded-lg bg-primary-700 px-5 py-3 text-sm font-bold text-white">Try again from My Tickets</NuxtLink>
			</div>
			<p v-if="state.mode !== 'live'" class="mt-4 text-xs text-gray-400">Test mode: no real money was charged.</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { PaymentState } from '~/composables/useTicketing'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Payment | NaSeRy', robots: 'noindex' })

const route = useRoute()
const api = usePayments()
const state = ref<PaymentState | null>(null)
const error = ref('')
const cancelled = computed(() => route.query.cancelled === '1')
const failed = computed(() => route.query.failed === '1')

// Webhooks can lag a few seconds behind the redirect: poll briefly.
async function check(attempt = 0) {
	try {
		state.value = await api.status(String(route.query.payment))
		if (state.value.status === 'pending' && !cancelled.value && !failed.value && attempt < 8) {
			setTimeout(() => check(attempt + 1), 2500)
		}
	} catch (e) {
		error.value = authMessage(e)
	}
}

onMounted(() => check())
</script>
