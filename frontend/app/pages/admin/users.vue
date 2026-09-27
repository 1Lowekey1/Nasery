<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold text-gray-900">Users</h1>
			<p class="mt-1 text-gray-500">Every account on NaSeRy. Suspending signs the person out everywhere and e-mails them the reason.</p>
		</div>

		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />

		<div class="rounded-2xl border border-gray-200 bg-white p-6">
			<div class="mb-4 flex flex-wrap items-end gap-3">
				<div class="w-full sm:w-72"><FormsTextField v-model="filters.search" placeholder="Search name or e-mail…" /></div>
				<select v-model="filters.type" aria-label="Account type" class="rounded-xl border border-gray-200 px-3 py-2.5 text-sm" @change="load()">
					<option value="">All types</option><option value="client">Clients</option><option value="business">Organizers</option><option value="admin">Admins</option>
				</select>
				<select v-model="filters.status" aria-label="Status" class="rounded-xl border border-gray-200 px-3 py-2.5 text-sm" @change="load()">
					<option value="">Any status</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="deactivated">Deactivated</option>
				</select>
			</div>

			<div class="overflow-x-auto">
				<table class="w-full text-left text-sm">
					<thead><tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
						<th class="pb-3 pr-4">Name</th><th class="pb-3 pr-4">Type</th><th class="pb-3 pr-4">Organizations</th><th class="pb-3 pr-4">Joined</th><th class="pb-3 pr-4">Status</th><th class="pb-3" />
					</tr></thead>
					<tbody class="divide-y divide-gray-100">
						<tr v-if="loading"><td colspan="6" class="py-8 text-center text-gray-400">Loading…</td></tr>
						<tr v-else-if="!items.length"><td colspan="6" class="py-8 text-center text-gray-400">No accounts match.</td></tr>
						<template v-for="u in items" v-else :key="u.id">
							<tr>
								<td class="py-3 pr-4">
									<div class="font-semibold text-gray-900">{{ u.name }}</div>
									<div class="text-xs text-gray-500">{{ u.email }}<template v-if="!u.verified"> · unverified</template></div>
								</td>
								<td class="py-3 pr-4 text-gray-600">{{ typeLabel[u.type] }}</td>
								<td class="py-3 pr-4 text-gray-500">{{ u.organizations || '—' }}</td>
								<td class="py-3 pr-4 text-gray-500">{{ shortDate(u.joined) }}</td>
								<td class="py-3 pr-4"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize" :class="statusBadge[u.status]">{{ u.status }}</span></td>
								<td class="py-3 text-right">
									<template v-if="u.type !== 'admin' && u.status !== 'deactivated'">
										<button v-if="u.status === 'active'" type="button" class="text-xs font-semibold text-rose-700" @click="start(u, 'suspended')">Suspend</button>
										<button v-else type="button" class="text-xs font-semibold text-green-700" @click="start(u, 'active')">Reactivate</button>
									</template>
								</td>
							</tr>
							<tr v-if="editing?.id === u.id">
								<td colspan="6" class="pb-4">
									<form class="flex flex-wrap items-end gap-2 rounded-xl bg-gray-50 p-3" @submit.prevent="save">
										<div class="min-w-64 flex-1">
											<label :for="`why-${u.id}`" class="text-xs font-semibold text-gray-600">Reason (e-mailed to {{ u.name }})</label>
											<input :id="`why-${u.id}`" v-model="reason" maxlength="1000" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
										</div>
										<button type="submit" :disabled="busy || reason.trim().length < 5" class="rounded-xl px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
											:class="editing.to === 'suspended' ? 'bg-rose-600' : 'bg-green-600'">{{ editing.to === 'suspended' ? 'Suspend account' : 'Reactivate account' }}</button>
										<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm" @click="editing = null">Cancel</button>
									</form>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</div>

			<div v-if="pagination && pagination.last_page > 1" class="mt-5 flex items-center justify-between text-sm text-gray-500">
				<span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} accounts</span>
				<div class="flex gap-2">
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Previous</button>
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Next</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { AdminUser } from '~/composables/useAdmin'

definePageMeta({ layout: 'admin' })

const admin = useAdmin()
const filters = reactive({ search: '', type: '', status: '' })
const items = ref<AdminUser[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const editing = ref<{ id: string, to: 'active' | 'suspended' } | null>(null)
const reason = ref('')

const typeLabel = { client: 'Client', business: 'Organizer', admin: 'Admin' }
const statusBadge = { active: 'bg-green-50 text-green-700', suspended: 'bg-rose-50 text-rose-700', deactivated: 'bg-gray-100 text-gray-600' }

async function load(page = 1) {
	loading.value = true
	error.value = ''
	try {
		const r = await admin.users({ ...filters, search: filters.search.trim(), page })
		items.value = r.items
		pagination.value = r.pagination
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

function start(u: AdminUser, to: 'active' | 'suspended') {
	editing.value = { id: u.id, to }
	reason.value = ''
}

async function save() {
	if (!editing.value) return
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		await admin.setUserStatus(editing.value.id, editing.value.to, reason.value.trim())
		notice.value = editing.value.to === 'suspended' ? 'Account suspended and signed out everywhere.' : 'Account reactivated.'
		editing.value = null
		await load(pagination.value?.current_page ?? 1)
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.search, () => {
	clearTimeout(timer)
	timer = setTimeout(() => load(), 350)
})
onMounted(() => load())
</script>
