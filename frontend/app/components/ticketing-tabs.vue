<template>
	<div>
		<div class="mb-4">
			<h1 class="text-2xl font-extrabold text-gray-900">Registration, Ticketing &amp; Attendance</h1>
			<p class="mt-1 text-gray-500">Guest lists, QR tickets and attendee check-in</p>
		</div>

		<OrganizerEventPicker :model-value="eventId" />

		<div v-if="eventId && stats" class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
			<div v-for="stat in statCards" :key="stat.label" class="rounded-2xl border border-gray-200 bg-white p-5">
				<span class="text-sm text-gray-500">{{ stat.label }}</span>
				<div class="mt-2 text-3xl font-extrabold text-gray-900">{{ stat.value }}</div>
				<div class="mt-2 text-sm text-gray-500">{{ stat.description }}</div>
			</div>
		</div>

		<div class="mb-6 inline-flex flex-wrap gap-1 rounded-xl bg-gray-100 p-1">
			<NuxtLink v-for="tab in tabs" :key="tab.label" :to="{ path: tab.to, query: eventId ? { event: eventId } : {} }"
				class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold"
				:class="route.path === tab.to ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
				<IconBase :name="tab.icon" class="h-4 w-4" />
				{{ tab.label }}
			</NuxtLink>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { TicketSummary } from '~/composables/useTicketing'

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerTicketing()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const stats = ref<{ summary: TicketSummary, capacity: number | null } | null>(null)

const statCards = computed(() => {
	const s = stats.value!
	return [
		{ label: 'Tickets Issued', value: s.summary.total, description: 'Guest list and registrations' },
		{ label: 'Checked In', value: s.summary.used, description: 'QR scans completed' },
		{ label: 'Cancelled', value: s.summary.cancelled, description: 'No longer valid' },
		{ label: 'Capacity', value: s.capacity ? `${Math.round(s.summary.total / s.capacity * 100)}%` : '—', description: s.capacity ? `${s.summary.total}/${s.capacity}` : 'No overall limit set' },
	]
})

const tabs = [
	{ label: 'Attendees', to: '/organizer/ticketattendance/attendees', icon: 'users' },
	{ label: 'QR Check-in', to: '/organizer/ticketattendance/qr-checkin', icon: 'qr-code' },
	{ label: 'Walk-in Counter', to: '/organizer/ticketattendance/walkin-counter', icon: 'user' },
	{ label: 'Attendance Report', to: '/organizer/ticketattendance/attendance-report', icon: 'clipboard-list' },
	{ label: 'Payments', to: '/organizer/ticketattendance/payments', icon: 'cash' }
]

async function loadStats() {
	stats.value = null
	if (!eventId.value) return
	try {
		const org = organization.value ?? await fetchOrganization()
		if (!org) return
		const r = await api.tiers(org.id, eventId.value)
		stats.value = { summary: r.summary, capacity: r.overall_capacity }
	} catch {
		// Stats are a header convenience; the page below reports its own errors.
	}
}

// Pages bump this after changes (e.g. a guest was added) to refresh the numbers.
const statsVersion = useState('ticketStatsVersion', () => 0)
watch([eventId, statsVersion], loadStats)
onMounted(loadStats)
</script>
