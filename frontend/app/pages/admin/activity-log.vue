<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold text-gray-900">Activity Log</h1>
			<p class="mt-1 text-gray-500">The audit trail of organizer, client and admin actions across NaSeRy. It can't be edited or deleted.
				An organizer's own details (reasons, changed values) show only while they've given you access.</p>
		</div>

		<Alert v-if="error" type="danger" :text="error" />

		<div class="rounded-2xl border border-gray-200 bg-white p-6">
			<div class="mb-4 flex flex-wrap items-end gap-3">
				<div class="w-full sm:w-64"><FormsTextField v-model="filters.search" placeholder="Action, e.g. review or refund" /></div>
				<select v-model="filters.actor" aria-label="Done by" class="rounded-xl border border-gray-200 px-3 py-2.5 text-sm" @change="load()">
					<option value="">Anyone</option><option value="admin">Admins</option><option value="business">Organizers</option><option value="client">Clients</option><option value="system">System</option>
				</select>
				<label class="text-xs text-gray-500">From <input v-model="filters.from" type="date" class="ml-1 rounded-xl border border-gray-200 px-2 py-2 text-sm" @change="load()"></label>
				<label class="text-xs text-gray-500">To <input v-model="filters.to" type="date" class="ml-1 rounded-xl border border-gray-200 px-2 py-2 text-sm" @change="load()"></label>
			</div>

			<div v-if="loading" class="py-8 text-center text-sm text-gray-400">Loading…</div>
			<p v-else-if="!items.length" class="py-8 text-center text-sm text-gray-400">No activity matches.</p>
			<ol v-else class="divide-y divide-gray-100">
				<li v-for="a in items" :key="a.id" class="py-3">
					<button type="button" class="flex w-full flex-wrap items-start justify-between gap-2 text-left" :aria-expanded="open === a.id" @click="open = open === a.id ? null : a.id">
						<span class="min-w-0">
							<span class="text-sm text-gray-900"><span class="font-semibold">{{ a.actor }}</span> <span class="text-xs text-gray-400">({{ actorLabel[a.actor_type] ?? a.actor_type }})</span> · {{ actionLabel(a.action) }}</span>
							<span v-if="a.organization" class="block text-xs text-gray-500">{{ a.organization.name }}</span>
						</span>
						<span class="text-xs text-gray-400">{{ dateTime(a.at) }}</span>
					</button>
					<div v-if="open === a.id" class="mt-2 rounded-xl bg-gray-50 p-3 text-xs text-gray-700">
						<p class="text-gray-400">{{ a.action }} · {{ a.entity }}</p>
						<p v-if="a.details_hidden" class="mt-1">Details hidden: this belongs to {{ a.organization?.name }}'s workspace. Request access on their page to see it.</p>
						<template v-else>
							<p v-if="a.reason" class="mt-1"><b>Reason:</b> {{ a.reason }}</p>
							<pre v-if="a.changes?.old" class="mt-1 whitespace-pre-wrap break-words"><b>Before:</b> {{ JSON.stringify(a.changes.old, null, 1) }}</pre>
							<pre v-if="a.changes?.new" class="mt-1 whitespace-pre-wrap break-words"><b>After:</b> {{ JSON.stringify(a.changes.new, null, 1) }}</pre>
							<p v-if="!a.reason && !a.changes?.old && !a.changes?.new" class="mt-1 text-gray-400">No extra details recorded.</p>
						</template>
					</div>
				</li>
			</ol>

			<div v-if="pagination && pagination.last_page > 1" class="mt-5 flex items-center justify-between text-sm text-gray-500">
				<span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
				<div class="flex gap-2">
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 disabled:opacity-40" :disabled="pagination.current_page <= 1" @click="load(pagination.current_page - 1)">Newer</button>
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 disabled:opacity-40" :disabled="pagination.current_page >= pagination.last_page" @click="load(pagination.current_page + 1)">Older</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { ActivityEntry } from '~/composables/useAdmin'

definePageMeta({ layout: 'admin' })

const admin = useAdmin()
const filters = reactive({ search: '', actor: '', from: '', to: '' })
const items = ref<ActivityEntry[]>([])
const pagination = ref<{ current_page: number, last_page: number } | null>(null)
const loading = ref(false)
const error = ref('')
const open = ref<string | null>(null)
const actorLabel: Record<string, string> = { admin: 'admin', business: 'organizer', client: 'client', system: 'system' }

async function load(page = 1) {
	loading.value = true
	error.value = ''
	try {
		const r = await admin.activity({ ...filters, search: filters.search.trim(), page })
		items.value = r.items
		pagination.value = r.pagination
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		loading.value = false
	}
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.search, () => {
	clearTimeout(timer)
	timer = setTimeout(() => load(), 350)
})
onMounted(() => load())
</script>
