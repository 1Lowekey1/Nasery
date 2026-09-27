<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold text-gray-900">All Events</h1>
			<p class="mt-1 text-gray-500">Counts cover every event on NaSeRy. The list shows public events, plus private ones from organizers who gave you access.</p>
		</div>

		<Alert v-if="error" type="danger" :text="error" />

		<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-6">
			<button v-for="s in statuses" :key="s.value" type="button" class="rounded-2xl border bg-white p-4 text-left transition hover:border-primary-300"
				:class="status === s.value ? 'border-primary-500 ring-2 ring-primary-500/15' : 'border-gray-200'" @click="status = s.value; load()">
				<span class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ s.label }}</span>
				<div class="mt-1 text-2xl font-extrabold text-gray-900">{{ counts?.[s.value || 'total'] ?? '–' }}</div>
			</button>
		</div>

		<div class="rounded-2xl border border-gray-200 bg-white p-6">
			<div class="mb-4 w-full sm:w-72">
				<FormsTextField v-model="search" placeholder="Search event or organizer…" />
			</div>
			<div class="overflow-x-auto">
				<table class="w-full text-left text-sm">
					<thead><tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
						<th class="pb-3 pr-4">Event</th><th class="pb-3 pr-4">Organizer</th><th class="pb-3 pr-4">Date</th><th class="pb-3 pr-4">Venue</th><th class="pb-3 pr-4">Tickets</th><th class="pb-3">Status</th>
					</tr></thead>
					<tbody class="divide-y divide-gray-100">
						<tr v-if="loading"><td colspan="6" class="py-8 text-center text-gray-400">Loading…</td></tr>
						<tr v-else-if="!items.length"><td colspan="6" class="py-8 text-center text-gray-400">No events to show.</td></tr>
						<tr v-for="e in items" v-else :key="e.id">
							<td class="py-3.5 pr-4">
								<div class="font-semibold text-gray-900">{{ e.name }}</div>
								<div class="text-xs text-gray-400">{{ e.visibility === 'public' ? 'Public' : 'Private · via your access' }}</div>
							</td>
							<td class="py-3.5 pr-4"><NuxtLink :to="`/admin/organizers/${e.organizer.id}`" class="text-primary-700">{{ e.organizer.name }}</NuxtLink></td>
							<td class="py-3.5 pr-4 text-gray-500">{{ shortDate(e.starts_at) }}</td>
							<td class="py-3.5 pr-4 text-gray-500">{{ e.venue || '—' }}</td>
							<td class="py-3.5 pr-4 text-gray-700">{{ e.tickets_sold }}<template v-if="e.capacity">/{{ e.capacity }}</template></td>
							<td class="py-3.5"><span class="rounded-full px-3 py-1 text-xs font-semibold capitalize" :class="badge[e.status] ?? 'bg-gray-100 text-gray-600'">{{ e.status }}</span></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { AdminEvent, PlatformTotals } from '~/composables/useAdmin'

definePageMeta({ layout: 'admin' })

const admin = useAdmin()
const statuses = [
	{ value: '', label: 'All' }, { value: 'draft', label: 'Draft' }, { value: 'published', label: 'Published' },
	{ value: 'ongoing', label: 'Ongoing' }, { value: 'completed', label: 'Completed' }, { value: 'cancelled', label: 'Cancelled' },
] as const
const status = ref<string>('')
const search = ref('')
const items = ref<AdminEvent[]>([])
const counts = ref<PlatformTotals['events'] | null>(null)
const loading = ref(false)
const error = ref('')
const badge: Record<string, string> = {
	published: 'bg-primary-50 text-primary-700', ongoing: 'bg-green-50 text-green-700', completed: 'bg-gray-100 text-gray-600', cancelled: 'bg-rose-50 text-rose-700',
}

async function load() {
	loading.value = true
	error.value = ''
	try {
		const r = await admin.events({ status: status.value, search: search.value.trim() })
		items.value = r.items
		counts.value = r.counts
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
	clearTimeout(timer)
	timer = setTimeout(load, 350)
})
onMounted(load)
</script>
