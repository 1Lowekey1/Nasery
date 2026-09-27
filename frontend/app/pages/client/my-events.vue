<template>
	<div>
		<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
			<div>
				<p class="text-sm font-semibold text-primary-700">Client Portal</p>
				<h1 class="mt-1 text-2xl font-extrabold text-gray-900">Welcome back, {{ clientName }} &#128075;</h1>
				<p class="mt-1 text-gray-500">Post an event inquiry and let organizers come to you.</p>
			</div>
			<button type="button"
				class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1f4a54] sm:w-auto sm:shrink-0"
				@click="navigateTo('/client/post-event')">
				<IconBase name="plus" class="h-4 w-4 shrink-0" /> Post New Event
			</button>
		</div>

		<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-5 xl:grid-cols-4">
			<div v-for="stat in stats" :key="stat.label" class="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5">
				<div class="flex items-start justify-between">
					<span class="text-sm text-gray-500">{{ stat.label }}</span>
					<div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="stat.iconBg">
						<IconBase :name="stat.icon" class="h-4 w-4" :class="stat.iconColor" />
					</div>
				</div>
				<div class="mt-2 text-3xl font-extrabold text-gray-900">{{ stat.value }}</div>
			</div>
		</div>

		<Alert v-if="error" type="danger" :text="error" />

		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading your inquiries…</div>

		<div v-else-if="inquiries.length" class="space-y-4">
			<NuxtLink v-for="inquiry in inquiries" :key="inquiry.id" :to="`/client/inquiries/${inquiry.id}`"
				class="block rounded-2xl border border-gray-200 bg-white p-4 hover:border-primary-300 sm:p-6">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div class="flex min-w-0 items-start gap-3">
						<div
							class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
							<IconBase :name="eventTypeIcon(inquiry.event_type)" class="h-5 w-5" />
						</div>
						<div class="min-w-0">
							<h3 class="truncate text-base font-bold text-gray-900">
								{{ (inquiry.event_type ?? 'Event') + ' inquiry' }}
							</h3>
							<p class="mt-0.5 text-sm text-gray-500">
								{{ formatDate(inquiry.requested_date) || 'Date not set' }} &bull; {{ inquiry.location_text || 'Location not set' }}
							</p>
						</div>
					</div>
					<span
						class="shrink-0 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-600">
						{{ inquiryStatusLabels[inquiry.status] }}
					</span>
				</div>

				<div class="mt-4 grid grid-cols-3 gap-3">
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="text-xs text-gray-500">Guests</div>
						<div class="mt-1 text-sm font-bold text-gray-900">{{ inquiry.guest_count ?? '—' }}</div>
					</div>
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="text-xs text-gray-500">Budget</div>
						<div class="mt-1 truncate text-sm font-bold text-gray-900">{{ budgetLabel(inquiry.budget_min, inquiry.budget_max) }}</div>
					</div>
					<div class="rounded-lg bg-gray-50 p-3">
						<div class="text-xs text-gray-500">Organizers</div>
						<div class="mt-1 truncate text-sm font-bold text-gray-900">
							{{ inquiry.status === 'draft' ? '—' : `${inquiry.invited_count ?? 0} invited · ${inquiry.viewed_count ?? 0} viewed` }}
						</div>
					</div>
				</div>
			</NuxtLink>

			<div v-if="pagination && pagination.last_page > 1" class="flex justify-center gap-2 pt-2">
				<button type="button" :disabled="page <= 1" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page - 1)">Previous</button>
				<span class="px-2 py-1.5 text-sm text-gray-500">Page {{ page }} of {{ pagination.last_page }}</span>
				<button type="button" :disabled="page >= pagination.last_page" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page + 1)">Next</button>
			</div>
		</div>

		<div v-else
			class="flex flex-col items-center justify-center rounded-3xl border-2 border-dashed border-gray-200 bg-gray-50/60 px-5 py-16 text-center sm:px-6 sm:py-20">
			<div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
				<IconBase name="sparkles" class="h-6 w-6" />
			</div>
			<h2 class="mt-4 text-lg font-bold text-gray-900">No event inquiries yet</h2>
			<p class="mt-1 max-w-sm text-sm text-gray-500">Post your first event inquiry and qualified organizers will
				submit bids for you to review.</p>
			<button type="button"
				class="mt-5 flex items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1f4a54]"
				@click="navigateTo('/client/post-event')">
				<IconBase name="plus" class="h-4 w-4" /> Post My First Event
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { ClientInquiry } from '~/composables/useInquiries'

definePageMeta({ layout: 'client' })

const { user } = useAuth()
const { list } = useInquiries()

const clientName = computed(() => user.value?.first_name ?? 'there')
const inquiries = ref<ClientInquiry[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const page = ref(1)
const loading = ref(true)
const error = ref('')

const eventTypeIcons: Record<string, string> = {
	Wedding: 'heart',
	Corporate: 'briefcase',
	Birthday: 'gift',
	Debut: 'star',
	Concert: 'music',
	Conference: 'users',
	Reunion: 'users',
	Seminar: 'award'
}

function eventTypeIcon(type: string | null) {
	return (type && eventTypeIcons[type]) || 'sparkles'
}

function formatDate(dateStr: string | null) {
	if (!dateStr) return ''
	return new Date(`${dateStr.slice(0, 10)}T00:00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
}

async function load(p = 1) {
	loading.value = true
	error.value = ''
	try {
		const r = await list(p)
		inquiries.value = r.items
		pagination.value = r.pagination
		page.value = p
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

// Counts for the current page; the total comes from the server.
const stats = computed(() => [
	{ label: 'Total Inquiries', value: pagination.value?.total ?? inquiries.value.length, icon: 'file-text', iconBg: 'bg-gray-100', iconColor: 'text-gray-500' },
	{ label: 'Awaiting Organizers', value: inquiries.value.filter(i => i.status === 'submitted' || i.status === 'receiving_bids').length, icon: 'sparkles', iconBg: 'bg-gray-100', iconColor: 'text-gray-500' },
	{ label: 'Accepted', value: inquiries.value.filter(i => i.status === 'accepted' || i.status === 'converted_to_event').length, icon: 'award', iconBg: 'bg-green-50', iconColor: 'text-green-600' },
	{ label: 'Drafts', value: inquiries.value.filter(i => i.status === 'draft').length, icon: 'clock', iconBg: 'bg-amber-50', iconColor: 'text-amber-600' }
])

onMounted(() => load())
</script>
