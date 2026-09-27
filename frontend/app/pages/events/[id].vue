<template>
	<div class="mx-auto max-w-5xl px-5 pt-7 pb-16 sm:px-8">
		<NuxtLink to="/events" class="text-sm font-semibold text-primary-700 hover:underline">← All events</NuxtLink>

		<div v-if="loading" class="mt-6 h-64 animate-pulse rounded-2xl bg-gray-100" />
		<div v-else-if="notFound" class="py-20 text-center">
			<h1 class="text-2xl font-bold">This event isn't open to the public</h1>
			<p class="mt-2 text-sm text-gray-500">It may be private, finished or no longer listed.</p>
		</div>
		<p v-else-if="error && !event" class="mt-6 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{{ error }}</p>

		<template v-if="event">
			<section class="mt-4 rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
				<p class="text-xs font-semibold uppercase tracking-wide text-primary-700">{{ event.event_type ?? 'Event' }}</p>
				<h1 class="mt-1 text-3xl font-bold tracking-tight">{{ event.name }}</h1>
				<p class="mt-1 text-sm text-gray-500">by <NuxtLink :to="`/organizers/${event.organizer.slug}`" class="font-semibold text-primary-700">{{ event.organizer.name }}</NuxtLink></p>
				<div class="mt-5 grid gap-4 sm:grid-cols-2">
					<div>
						<p class="text-xs text-gray-500">When</p>
						<p v-for="(s, i) in event.sessions" :key="i" class="text-sm font-semibold text-gray-900">
							<span v-if="s.name">{{ s.name }}: </span>{{ sessionLabel(s, event.timezone) }}
						</p>
					</div>
					<div>
						<p class="text-xs text-gray-500">Where</p>
						<p class="text-sm font-semibold text-gray-900">{{ event.venue_name || 'To be announced' }}</p>
						<p class="text-sm text-gray-500">{{ event.venue_address }}</p>
					</div>
				</div>
				<p v-if="event.description" class="mt-5 whitespace-pre-line text-sm leading-7 text-gray-600">{{ event.description }}</p>
			</section>

			<section class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 sm:p-8" aria-labelledby="tickets-heading">
				<h2 id="tickets-heading" class="text-xl font-bold">Tickets</h2>
				<p v-if="!event.tiers.length" class="mt-3 text-sm text-gray-500">Registration hasn't opened yet.</p>

				<div class="mt-4 space-y-3">
					<label v-for="t in event.tiers" :key="t.id" class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border p-4"
						:class="[selected === t.id ? 'border-primary-600 bg-primary-50' : 'border-gray-200', t.availability !== 'available' ? 'cursor-not-allowed opacity-60' : '']">
						<span class="flex items-start gap-3">
							<input v-model="selected" type="radio" name="tier" :value="t.id" :disabled="t.availability !== 'available'" class="mt-1">
							<span>
								<span class="font-bold text-gray-900">{{ t.name }}</span><span v-if="t.zone_name" class="text-sm text-primary-700"> · {{ t.zone_name }}</span>
								<span v-if="t.description" class="block text-sm text-gray-500">{{ t.description }}</span>
								<span v-if="t.benefits.length" class="mt-1 block text-xs text-gray-500">Includes: {{ t.benefits.join(' · ') }}</span>
								<span class="mt-1 block text-xs" :class="t.availability === 'available' ? 'text-gray-400' : 'text-rose-700'">{{ availabilityLabel(t) }}</span>
							</span>
						</span>
						<span class="shrink-0 text-lg font-extrabold text-gray-900">{{ priceLabel(t.price) }}</span>
					</label>
				</div>

				<form v-if="selectedTier" class="mt-6 border-t border-gray-100 pt-6" @submit.prevent="register">
					<h3 class="text-base font-bold text-gray-900">Who's going?</h3>
					<p class="text-xs text-gray-500">One QR ticket per person (up to {{ maxPeople }}).</p>
					<div v-for="(p, i) in people" :key="i" class="mt-3 grid grid-cols-[1fr_1fr_auto] gap-2">
						<FormsTextField v-model="p.full_name" :aria-label="`Attendee ${i + 1} name`" placeholder="Full name" />
						<FormsTextField v-model="p.email" :aria-label="`Attendee ${i + 1} email`" type="email" placeholder="Email (optional)" />
						<button v-if="people.length > 1" type="button" class="px-2 text-rose-700" :aria-label="`Remove attendee ${i + 1}`" @click="people.splice(i, 1)">×</button>
					</div>
					<button v-if="people.length < maxPeople" type="button" class="mt-2 text-sm font-semibold text-primary-700" @click="people.push({ full_name: '', email: '' })">+ Add another person</button>

					<Alert v-if="error" type="danger" :text="error" class="mt-4" />
					<div class="mt-5 flex flex-wrap items-center justify-between gap-3">
						<p class="text-sm text-gray-600">Total: <strong>{{ Number(selectedTier.price) === 0 ? 'Free' : formatPeso(Number(selectedTier.price) * people.length) }}</strong></p>
						<button type="submit" :disabled="busy" class="rounded-lg bg-primary-700 px-5 py-3 text-sm font-bold text-white hover:bg-primary-900 disabled:opacity-50">
							{{ !isLoggedIn ? 'Sign in to register' : busy ? 'Registering…' : Number(selectedTier.price) === 0 ? 'Get my tickets' : 'Continue to payment' }}
						</button>
					</div>
				</form>

				<div v-if="result" class="mt-6 rounded-xl p-4 text-sm" :class="result.status === 'confirmed' ? 'bg-green-50 text-green-900' : 'bg-amber-50 text-amber-900'">
					<template v-if="result.status === 'confirmed'">
						You're registered! <NuxtLink to="/my-tickets" class="font-semibold underline">Open My Tickets</NuxtLink> to see your QR codes.
					</template>
					<template v-else>
						Your places are reserved until {{ new Date(result.hold_expires_at!).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) }}.
						Finish paying from <NuxtLink to="/my-tickets" class="font-semibold underline">My Tickets</NuxtLink>; the reservation is released if it isn't paid.
					</template>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { PublicEventDetail, PublicTier } from '~/composables/useTicketing'

definePageMeta({ layout: 'public' })

const route = useRoute()
const { isLoggedIn } = useAuth()
const api = usePublicEvents()

const event = ref<PublicEventDetail | null>(null)
const loading = ref(true)
const notFound = ref(false)
const error = ref('')
const busy = ref(false)
const selected = ref<string | null>(null)
const people = reactive([{ full_name: '', email: '' }])
const result = ref<{ status: 'confirmed' | 'awaiting_payment', hold_expires_at: string | null } | null>(null)
const maxPeople = 10

const selectedTier = computed(() => event.value?.tiers.find(t => t.id === selected.value) ?? null)

useSeoMeta({ title: () => event.value ? `${event.value.name} | NaSeRy events` : 'Event | NaSeRy' })

function availabilityLabel(t: PublicTier) {
	return {
		available: t.remaining <= 20 ? `Only ${t.remaining} left` : 'Available',
		sold_out: 'Sold out',
		not_yet: t.sales_start_at ? `Opens ${new Date(t.sales_start_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })}` : 'Not open yet',
		ended: 'Sales closed',
	}[t.availability]
}

async function register() {
	if (!isLoggedIn.value) {
		await navigateTo({ path: '/login', query: { redirect: route.fullPath } })
		return
	}
	error.value = ''
	busy.value = true
	try {
		const attendees = people.filter(p => p.full_name.trim()).map(p => ({ full_name: p.full_name.trim(), email: p.email.trim() || null }))
		const r = await api.register(event.value!.id, selected.value!, attendees)
		if (r.data.checkout) {
			// Paid tier: continue on the checkout page (PayMongo, or the demo gateway in test builds).
			window.location.href = r.data.checkout.checkout_url
			return
		}
		result.value = r.data
		event.value = await api.show(event.value!.id) // refresh availability
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		event.value = await api.show(String(route.params.id))
		const { user } = useAuth()
		if (user.value) people[0]!.full_name = `${user.value.first_name} ${user.value.last_name}`.trim()
	} catch (e) {
		if (e instanceof ApiError && e.status === 404) notFound.value = true
		else error.value = authMessage(e)
	} finally {
		loading.value = false
	}
})
</script>
