<template>
	<div class="mx-auto max-w-4xl px-5 pt-8 pb-16 sm:px-8">
		<h1 class="text-3xl font-bold tracking-tight">My tickets</h1>
		<p class="mt-2 text-gray-500">Show the QR code at the entrance. Each person needs their own.</p>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />

		<section v-if="pending.length" class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5">
			<h2 class="text-base font-bold text-amber-900">Waiting for payment</h2>
			<ul class="mt-3 space-y-3">
				<li v-for="r in pending" :key="r.registration_id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 text-sm">
					<div>
						<p class="font-semibold text-gray-900">{{ r.event }} · {{ r.quantity }}× {{ r.tier }}</p>
						<p class="text-xs text-gray-500">{{ formatPeso(r.amount) }} · held until {{ new Date(r.hold_expires_at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) }}</p>
					</div>
					<div class="flex gap-2">
						<button type="button" :disabled="busy" class="rounded-lg bg-primary-700 px-4 py-2 font-semibold text-white disabled:opacity-50" @click="pay(r.registration_id)">Pay now</button>
						<button type="button" :disabled="busy" class="rounded-lg border border-gray-200 px-4 py-2 font-semibold text-gray-700" @click="release(r.registration_id)">Cancel</button>
					</div>
				</li>
			</ul>
			<p v-if="mode !== 'live'" class="mt-3 text-xs text-amber-800">Test mode: payments are simulated; no real money is charged.</p>
		</section>
		<div v-if="loading" class="mt-8 h-40 animate-pulse rounded-2xl bg-gray-100" />

		<div v-else-if="!tickets.length" class="mt-8 rounded-2xl border-2 border-dashed border-gray-200 p-10 text-center">
			<p class="text-gray-500">No tickets yet.</p>
			<NuxtLink to="/events" class="mt-3 inline-block font-semibold text-primary-700">Browse public events</NuxtLink>
		</div>

		<div v-else class="mt-8 space-y-5">
			<article v-for="t in tickets" :key="t.id" class="grid gap-5 rounded-2xl border border-gray-200 bg-white p-6 sm:grid-cols-[1fr_auto]">
				<div>
					<p class="text-xs font-semibold uppercase tracking-wide text-primary-700">{{ t.tier }}<span v-if="t.zone"> · {{ t.zone }}</span></p>
					<h2 class="mt-1 text-xl font-bold text-gray-900">{{ t.event }}</h2>
					<p class="text-sm text-gray-500">by {{ t.organizer }}</p>
					<p class="mt-3 text-sm font-semibold text-gray-900">{{ t.attendee }}</p>
					<p class="text-sm text-gray-600">{{ t.starts_at ? new Date(t.starts_at).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'Date to be announced' }}</p>
					<p class="text-sm text-gray-500">{{ t.venue }}</p>
					<p v-if="t.benefits.length" class="mt-2 text-xs text-gray-500">Includes: {{ t.benefits.join(' · ') }}</p>
					<p class="mt-3 inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
						:class="t.status === 'valid' ? 'bg-green-50 text-green-700' : t.status === 'used' ? 'bg-primary-50 text-primary-700' : 'bg-gray-100 text-gray-500'">
						{{ { valid: 'Ready to use', used: 'Checked in', cancelled: 'Cancelled', invalidated: 'No longer valid' }[t.status] }}
					</p>
					<button v-if="t.qr_token" type="button" class="ml-3 text-xs font-semibold text-primary-700" @click="downloadPdf(t)">Download PDF</button>
					<button v-if="t.cancellable" type="button" class="ml-3 text-xs font-semibold text-rose-700" @click="cancel(t)">Cancel ticket</button>
				</div>
				<QrCode v-if="t.qr_token" :value="t.qr_token" :label="`QR ticket for ${t.attendee} at ${t.event}`" class="w-48 justify-self-center" />
			</article>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { MyTicket, PendingReservation } from '~/composables/useTicketing'

definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'My tickets | NaSeRy' })

const api = usePublicEvents()
const payments = usePayments()
const tickets = ref<MyTicket[]>([])
const pending = ref<PendingReservation[]>([])
const mode = ref<'fake' | 'test' | 'live'>('fake')
const busy = ref(false)
const loading = ref(true)
const error = ref('')

async function load() {
	try {
		const [t, p] = await Promise.all([api.myTickets(), payments.pending()])
		tickets.value = t
		pending.value = p.items
		mode.value = p.mode
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

async function pay(registrationId: string) {
	busy.value = true
	try {
		window.location.href = (await payments.checkout(registrationId)).checkout_url
	} catch (e) {
		error.value = authMessage(e)
		busy.value = false
	}
}

async function release(registrationId: string) {
	if (!confirm('Cancel this reservation? The places are released for others.')) return
	busy.value = true
	try {
		await payments.cancelReservation(registrationId)
		await load()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function downloadPdf(t: MyTicket) {
	try {
		const url = URL.createObjectURL(await api.myTicketPdf(t.id))
		const a = document.createElement('a')
		a.href = url
		a.download = `ticket-${t.event.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-${t.attendee.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.pdf`
		a.click()
		URL.revokeObjectURL(url)
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function cancel(t: MyTicket) {
	if (!confirm(`Cancel ${t.attendee}'s ticket to ${t.event}? This can't be undone.`)) return
	try {
		await api.cancelMine(t.id)
		await load()
	} catch (e) {
		error.value = authMessage(e)
	}
}

onMounted(load)
</script>
