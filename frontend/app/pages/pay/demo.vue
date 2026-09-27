<template>
	<div class="mx-auto max-w-md px-5 pt-10 pb-16">
		<div class="rounded-2xl border-2 border-dashed border-amber-300 bg-amber-50 p-3 text-center text-xs font-bold uppercase tracking-widest text-amber-800">
			Demo payment · test mode · no real money
		</div>

		<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
			<h1 class="text-xl font-bold text-gray-900">Checkout</h1>
			<p class="mt-1 text-sm text-gray-500">This simulated page stands in for PayMongo's checkout (card, GCash, GrabPay, Maya) while NaSeRy runs in demo mode.</p>

			<div v-if="state" class="mt-5 flex items-baseline justify-between border-t border-gray-100 pt-4">
				<span class="text-sm text-gray-600">Amount due</span>
				<span class="text-2xl font-extrabold text-gray-900">{{ peso(Number(state.amount)) }}</span>
			</div>
			<p v-if="state?.hold_expires_at" class="mt-1 text-right text-xs text-gray-400">Places held until {{ time(state.hold_expires_at) }}</p>

			<Alert v-if="error" type="danger" :text="error" class="mt-4" />

			<div v-if="state && state.status === 'pending'" class="mt-6 grid gap-2">
				<button type="button" :disabled="busy" class="rounded-lg bg-primary-700 py-3 text-sm font-bold text-white hover:bg-primary-900 disabled:opacity-50" @click="complete('paid')">
					{{ busy ? 'Processing…' : 'Pay (simulate success)' }}
				</button>
				<button type="button" :disabled="busy" class="rounded-lg border border-gray-200 py-3 text-sm font-semibold text-rose-700 hover:bg-rose-50" @click="complete('failed')">
					Decline (simulate a failed payment)
				</button>
				<a :href="String(route.query.cancel ?? '/my-tickets')" class="py-2 text-center text-sm text-gray-500">Cancel and go back</a>
			</div>
			<p v-else-if="state" class="mt-6 text-sm text-gray-600">This payment is already {{ state.status }}. <a :href="String(route.query.success)" class="font-semibold text-primary-700">See the result</a></p>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { PaymentState } from '~/composables/useTicketing'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Demo checkout | NaSeRy', robots: 'noindex' })

const route = useRoute()
const api = usePayments()
const state = ref<PaymentState | null>(null)
const error = ref('')
const busy = ref(false)

function time(at: string) {
	return new Date(at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}

async function complete(outcome: 'paid' | 'failed') {
	error.value = ''
	busy.value = true
	try {
		const action = String(route.query.action)
		// The demo intent id is looked up server-side from the payment; the signed URL authorizes it.
		const intent = new URL(action).searchParams.get('intent') ?? ''
		await api.demoComplete(action, intent, outcome)
		window.location.href = outcome === 'paid' ? String(route.query.success) : `${route.query.success}&failed=1`
	} catch (e) {
		error.value = authMessage(e)
		busy.value = false
	}
}

onMounted(async () => {
	try {
		state.value = await api.status(String(route.query.payment))
	} catch (e) {
		error.value = authMessage(e)
	}
})
</script>
