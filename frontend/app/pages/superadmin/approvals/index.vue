<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold text-gray-900">Approvals</h1>
			<p class="mt-1 text-gray-500">Review organizer applications and manage who can operate on NaSeRy.</p>
		</div>

		<Alert v-if="error" type="danger" :text="error" />

		<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-5">
			<button v-for="s in statuses" :key="s.value" type="button"
				class="rounded-2xl border bg-white p-4 text-left transition hover:border-primary-300"
				:class="filters.status === s.value ? 'border-primary-500 ring-2 ring-primary-500/15' : 'border-gray-200'"
				@click="setStatus(s.value)">
				<span class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ s.label }}</span>
				<div class="mt-1 text-2xl font-extrabold text-gray-900">{{ counts?.[s.value] ?? '–' }}</div>
			</button>
		</div>

		<div class="rounded-2xl border border-gray-200 bg-white p-6">
			<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
				<div>
					<h2 class="text-lg font-bold text-gray-900">{{ currentLabel }}</h2>
					<p class="mt-1 text-sm text-gray-500">Click a row to review the full application.</p>
				</div>
				<div class="flex items-center gap-2">
					<button v-if="filters.status" type="button" class="text-sm font-semibold text-primary-600" @click="setStatus('')">
						Show all
					</button>
					<div class="w-64">
						<FormsTextField v-model="filters.search" placeholder="Search by name…" />
					</div>
				</div>
			</div>

			<div class="overflow-x-auto">
				<table class="w-full text-left text-sm">
					<thead>
						<tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
							<th class="pb-3 pr-4">Organizer</th>
							<th class="pb-3 pr-4">Owner e-mail</th>
							<th class="pb-3 pr-4 text-right">Events</th>
							<th class="pb-3 pr-4 text-right">Ticket sales</th>
							<th class="pb-3 pr-4">Rating</th>
							<th class="pb-3 pr-4">Applied</th>
							<th class="pb-3">Status</th>
						</tr>
					</thead>
					<tbody class="divide-y divide-gray-100">
						<tr v-if="loading">
							<td colspan="7" class="py-8 text-center text-gray-400">Loading…</td>
						</tr>
						<tr v-else-if="!items.length">
							<td colspan="7" class="py-8 text-center text-gray-400">No organizers here.</td>
						</tr>
						<tr v-for="org in items" v-else :key="org.id" class="cursor-pointer hover:bg-gray-50"
							@click="navigateTo(`/superadmin/approvals/${org.id}`)">
							<td class="py-3.5 pr-4">
								<div class="font-semibold text-gray-900">{{ org.display_name }}</div>
								<div class="text-xs text-gray-500">{{ org.legal_name }}<template v-if="org.location_text"> · {{ org.location_text }}</template></div>
							</td>
							<td class="py-3.5 pr-4 text-gray-500">{{ org.owner_email || '—' }}</td>
							<td class="py-3.5 pr-4 text-right text-gray-700">{{ org.totals?.events ?? 0 }}</td>
							<td class="py-3.5 pr-4 text-right text-gray-700">{{ peso(org.totals?.sales ?? 0) }}</td>
							<td class="py-3.5 pr-4 text-gray-700">
								<span v-if="org.totals?.rating" class="inline-flex items-center gap-1"><IconBase name="star" class="h-3.5 w-3.5 text-amber-500" />{{ org.totals.rating.toFixed(1) }}</span>
								<span v-else class="text-gray-400">—</span>
							</td>
							<td class="py-3.5 pr-4 text-gray-500">{{ formatDate(org.submitted_at) }}</td>
							<td class="py-3.5">
								<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" :class="approvalBadge[org.approval_status]">
									{{ approvalLabels[org.approval_status] }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div v-if="pagination && pagination.last_page > 1" class="mt-5 flex items-center justify-between text-sm text-gray-500">
				<span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} organizers</span>
				<div class="flex gap-2">
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 disabled:opacity-40"
						:disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</button>
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 disabled:opacity-40"
						:disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { ApprovalStatus } from '~/composables/useOrganization'
import type { ApplicationSummary } from '~/composables/useOrganizerApplications'

definePageMeta({ layout: 'superadmin' })

const route = useRoute()
const { list } = useOrganizerApplications()

const statuses: { value: ApprovalStatus, label: string }[] = [
	{ value: 'pending', label: 'Pending' },
	{ value: 'under_review', label: 'Under review' },
	{ value: 'approved', label: 'Approved' },
	{ value: 'rejected', label: 'Needs changes' },
	{ value: 'suspended', label: 'Suspended' },
]

const filters = reactive({
	status: (typeof route.query.status === 'string' ? route.query.status : '') as ApprovalStatus | '',
	search: '',
})
const items = ref<ApplicationSummary[]>([])
const counts = ref<Record<ApprovalStatus, number> | null>(null)
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const loading = ref(false)
const error = ref('')

const currentLabel = computed(() => statuses.find(s => s.value === filters.status)?.label ?? 'All organizers')

async function load(page = 1) {
	loading.value = true
	error.value = ''
	try {
		const result = await list({ status: filters.status, search: filters.search.trim(), page })
		items.value = result.items
		counts.value = result.counts
		pagination.value = result.pagination
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

function setStatus(status: ApprovalStatus | '') {
	filters.status = status
	navigateTo({ query: status ? { status } : {} }, { replace: true })
	load()
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.search, () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(() => load(), 350)
})

function formatDate(value: string) {
	return new Date(value).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
}

onMounted(() => load())
</script>
