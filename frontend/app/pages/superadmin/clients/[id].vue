<template>
	<div>
		<button type="button"
			class="mb-4 flex items-center gap-1.5 text-sm font-semibold text-gray-500 transition hover:text-gray-700"
			@click="navigateTo('/superadmin/clients')">
			<IconBase name="arrow-left" class="h-4 w-4" />
			Back to Clients
		</button>

		<div v-if="isLoading" class="rounded-2xl border border-gray-100 bg-white p-10 text-center text-sm text-gray-500">
			Loading client...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else-if="client">
			<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
				<div class="flex items-center gap-4">
					<SuperadminAvatar :name="client.name" size="lg" />
					<div>
						<h1 class="text-2xl font-extrabold tracking-tight text-gray-950">{{ client.name }}</h1>
						<p class="mt-1 text-gray-500">Client since {{ formatDate(client.joined) }}</p>
					</div>
				</div>
			</div>

			<div class="mb-6 stat-grid grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
				<SuperadminStatCard label="Inquiries Posted" :value="client.inquiries_count" icon="file-text"
					hint="As a client hiring organizers" />
				<SuperadminStatCard label="Booked Events" :value="client.events_count" icon="calendar"
					hint="As a client (paid to the organizer directly)" />
				<SuperadminStatCard label="Ticket Purchases" :value="formatCurrency(client.total_spend)" icon="ticket"
					:hint="`As an attendee: ${client.tickets_count ?? 0} tickets, after refunds`" />
				<SuperadminStatCard label="Reviews Left" :value="client.reviews_count ?? 0" icon="star" />
			</div>

			<div class="mb-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
				<h2 class="mb-4 text-base font-bold text-gray-900">Contact Information</h2>
				<div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
					<div class="flex items-center gap-3">
						<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
							<IconBase name="message" class="h-4 w-4" />
						</div>
						<div class="min-w-0">
							<div class="text-xs text-gray-400">Email</div>
							<div class="truncate text-sm font-semibold text-gray-900">{{ client.email || 'Not provided' }}</div>
						</div>
					</div>
					<div class="flex items-center gap-3">
						<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
							<IconBase name="phone" class="h-4 w-4" />
						</div>
						<div class="min-w-0">
							<div class="text-xs text-gray-400">Phone</div>
							<div class="truncate text-sm font-semibold text-gray-900">{{ client.phone || 'Not provided' }}</div>
						</div>
					</div>
					<div class="flex items-center gap-3">
						<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
							<IconBase name="map-pin" class="h-4 w-4" />
						</div>
						<div class="min-w-0">
							<div class="text-xs text-gray-400">Address</div>
							<div class="truncate text-sm font-semibold text-gray-900">{{ client.address || 'Not provided' }}</div>
						</div>
					</div>
				</div>
			</div>

			<div class="mb-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
				<h2 class="mb-1 text-base font-bold text-gray-900">Inquiries, bookings and reviews</h2>
				<p class="text-sm leading-6 text-gray-500">
					What a client asks for, books and writes stays between them and their organizers. NaSeRy admins see the
					totals above; to look into a specific event, request access from that organizer's page.
				</p>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import { superAdminService, type AdminClientDetail } from '~/services/superAdminService'

definePageMeta({
	layout: 'superadmin',
	middleware: ['superadmin'],
})

const route = useRoute()
const { token } = useAuth('superadmin')

const client = ref<AdminClientDetail | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')

async function loadClient() {
	if (!token.value) {
		return
	}

	isLoading.value = true
	errorMessage.value = ''

	try {
		const response = await superAdminService.getClient(token.value, route.params.id as string)
		client.value = response.data
	} catch (error: unknown) {
		errorMessage.value = getApiErrorMessage(error, 'Client not found.')
	} finally {
		isLoading.value = false
	}
}

function getApiErrorMessage(error: unknown, fallback: string): string {
	const apiError = error as { data?: { message?: string } }
	return apiError?.data?.message || fallback
}

function formatCurrency(amount: number) {
	if (amount >= 1000000) return `₱${(amount / 1000000).toFixed(1)}M`
	if (amount >= 1000) return `₱${(amount / 1000).toFixed(0)}K`
	return `₱${amount}`
}

function formatDate(value: string | null) {
	if (!value) return 'Not scheduled'
	return new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatStatus(status: string) {
	return status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function inquiryStatusClass(status: string) {
	switch (status) {
		case 'awarded':
			return 'bg-green-50 text-green-700'
		case 'cancelled':
			return 'bg-red-50 text-red-600'
		case 'receiving_quotations':
			return 'bg-blue-50 text-blue-700'
		default:
			return 'bg-amber-50 text-amber-700'
	}
}

function eventStatusClass(status: string) {
	switch (status) {
		case 'completed':
			return 'bg-gray-100 text-gray-600'
		case 'published':
		case 'ongoing':
			return 'bg-primary-50 text-primary-700'
		case 'cancelled':
			return 'bg-red-50 text-red-600'
		default:
			return 'bg-amber-50 text-amber-700'
	}
}

onMounted(() => {
	loadClient()
})
</script>
