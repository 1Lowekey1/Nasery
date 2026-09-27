<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="!data && !error" class="py-12 text-center text-sm text-gray-500">Checking for live events…</div>

		<template v-if="data">
			<div v-if="!data.live.length" class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-10 text-center">
				<p class="font-semibold text-gray-700">No event is live right now</p>
				<p class="mt-1 text-sm text-gray-500">An event goes live here when its door opens (3 hours before a session) and stays until the session ends.</p>
			</div>

			<section v-for="e in data.live" :key="e.event_id" class="mb-5 rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div>
						<h2 class="flex items-center gap-2 text-lg font-bold text-gray-900">
							<span class="inline-block h-2.5 w-2.5 animate-pulse rounded-full bg-green-600" aria-hidden="true" />{{ e.name }}
						</h2>
						<p class="text-sm text-gray-500">{{ sessionLine(e.session) }}</p>
					</div>
					<div class="flex gap-2 text-sm">
						<NuxtLink :to="`/organizer/ticketattendance/qr-checkin?event=${e.event_id}`" class="rounded-xl border border-gray-200 px-3 py-2 font-semibold text-gray-800 hover:bg-gray-50">QR check-in</NuxtLink>
						<NuxtLink :to="`/organizer/ticketattendance/walkin-counter?event=${e.event_id}`" class="rounded-xl border border-gray-200 px-3 py-2 font-semibold text-gray-800 hover:bg-gray-50">Walk-in counter</NuxtLink>
					</div>
				</div>

				<div class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
					<div>
						<p class="text-sm text-gray-500">Check-in rate</p>
						<p class="mt-1 text-3xl font-extrabold text-gray-900">{{ pctLabel(e.check_in_rate) }}</p>
						<p class="text-xs text-gray-500">{{ e.checked_in }} of {{ e.expected }} ticket holders</p>
					</div>
					<div>
						<p class="text-sm text-gray-500">Walk-ins vs registered</p>
						<p class="mt-1 text-3xl font-extrabold text-gray-900">{{ e.walk_ins }} : {{ e.checked_in }}</p>
						<p class="text-xs text-gray-500">{{ pctLabel(e.walk_in_ratio) }} of people inside walked in</p>
					</div>
					<div>
						<p class="text-sm text-gray-500">Capacity fill</p>
						<p class="mt-1 text-3xl font-extrabold text-gray-900">{{ e.capacity.limit ? pctLabel(e.occupancy) : e.arrived }}</p>
						<p class="text-xs text-gray-500">{{ e.capacity.limit ? `${e.arrived} inside of ${e.capacity.limit} · ${pctLabel(e.capacity.percent)} booked` : 'people inside · no capacity set' }}</p>
					</div>
					<div>
						<p class="text-sm text-gray-500">Last 15 minutes</p>
						<p class="mt-1 text-3xl font-extrabold text-gray-900">+{{ e.arrivals_last_15_min }}</p>
						<p class="text-xs text-gray-500">arrivals</p>
					</div>
				</div>

				<div class="mt-5 grid gap-5 lg:grid-cols-2">
					<div>
						<h3 class="text-sm font-bold text-gray-900">Check-in progress</h3>
						<div class="mt-2 h-3 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="e.check_in_rate ?? 0" aria-valuemin="0" aria-valuemax="100">
							<div class="h-full rounded-full transition-all" :style="{ width: `${e.check_in_rate ?? 0}%`, background: '#2a78d6' }" />
						</div>
						<div v-for="t in e.tiers" :key="t.name" class="mt-3">
							<div class="flex justify-between text-xs"><span class="text-gray-600">{{ t.name }}</span><span class="text-gray-500">{{ t.checked_in }} / {{ t.expected }}</span></div>
							<div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full" :style="{ width: `${t.expected ? (t.checked_in / t.expected) * 100 : 0}%`, background: '#2a78d6' }" /></div>
						</div>
						<template v-if="e.capacity.limit">
							<h3 class="mt-5 text-sm font-bold text-gray-900">Venue</h3>
							<div class="mt-2 h-3 overflow-hidden rounded-full bg-gray-100">
								<div class="h-full rounded-full" :style="{ width: `${Math.min(100, e.occupancy ?? 0)}%`, background: e.capacity.level === 'full' || e.capacity.level === 'over' ? '#e34948' : '#2a78d6' }" />
							</div>
							<p class="mt-1 text-xs" :class="e.capacity.level === 'full' || e.capacity.level === 'over' ? 'font-semibold text-rose-700' : 'text-gray-500'">
								{{ { ok: 'Room to spare', warning: '⚠ Almost full (booked)', full: '⛔ Fully booked', over: '⛔ Over capacity', unlimited: '' }[e.capacity.level] }}
							</p>
						</template>
					</div>
					<div>
						<h3 class="text-sm font-bold text-gray-900">Latest arrivals</h3>
						<ul v-if="e.recent.length" class="mt-2 divide-y divide-gray-100 text-sm">
							<li v-for="(r, i) in e.recent" :key="i" class="flex justify-between py-1.5"><span class="text-gray-800">{{ r.name }} <span class="text-gray-400">· {{ r.tier }}</span></span><span class="text-gray-500">{{ time(r.checked_in_at) }}</span></li>
						</ul>
						<p v-else class="mt-2 text-sm text-gray-400">No one has checked in yet.</p>
					</div>
				</div>
			</section>

			<section v-if="data.upcoming.length" class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Coming up this week</h2>
				<ul class="mt-3 divide-y divide-gray-100 text-sm">
					<li v-for="u in data.upcoming" :key="u.event_id" class="flex flex-wrap justify-between gap-2 py-2">
						<span class="font-medium text-gray-900">{{ u.name }}</span>
						<span class="text-gray-500">{{ new Date(u.starts_at).toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) }} · door opens {{ time(u.opens_at) }}</span>
					</li>
				</ul>
			</section>
			<p class="mt-3 text-xs text-gray-400">Updates every 10 seconds.</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import { pctLabel, type LiveEvent } from '~/composables/useAnalytics'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const api = useAnalytics()
const data = ref<{ live: LiveEvent[], upcoming: { event_id: string, name: string, starts_at: string, opens_at: string }[] } | null>(null)
const error = ref('')

const time = (at: string) => new Date(at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
// Unnamed sessions are labelled with their start date and time already.
function sessionLine(s: LiveEvent['session']) {
	if (!s) return 'Ongoing'
	return s.label.includes(time(s.starts_at)) ? `${s.label} – ${time(s.ends_at)}` : `${s.label} · ${time(s.starts_at)} – ${time(s.ends_at)}`
}

async function load() {
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) data.value = await api.live(org.id)
		error.value = ''
	} catch (e) {
		error.value = authMessage(e)
	}
}

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
	load()
	timer = setInterval(() => {
		if (!document.hidden) load()
	}, 10000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
