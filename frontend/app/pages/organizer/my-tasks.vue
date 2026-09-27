<template>
	<div class="mx-auto max-w-3xl">
		<h1 class="text-2xl font-extrabold text-gray-900">My Tasks</h1>
		<p class="mt-1 mb-6 text-gray-500">Preparation tasks assigned to you across your team's upcoming events.</p>

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading your tasks…</div>

		<div v-else-if="!tasks.length" class="rounded-2xl border border-gray-200 bg-white px-6 py-16 text-center">
			<h2 class="text-lg font-bold text-gray-900">Nothing assigned to you</h2>
			<p class="mt-1 text-sm text-gray-500">When your team assigns you a task on an event, it shows up here.</p>
		</div>

		<ul v-else class="space-y-3">
			<li v-for="t in tasks" :key="t.id" :id="`task-${t.id}`" class="rounded-2xl border bg-white p-4"
				:class="highlighted === t.id ? 'border-primary-400 ring-2 ring-primary-100' : 'border-gray-200'">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div class="min-w-0">
						<p class="font-semibold" :class="t.status === 'done' ? 'text-gray-400 line-through' : 'text-gray-900'">{{ t.name }}</p>
						<p class="text-xs text-gray-500">
							<NuxtLink :to="`/organizer/eventmanagement/preparation-tracking?event=${t.event_id}`" class="font-semibold text-primary-700">{{ t.event }}</NuxtLink>
							· {{ t.checklist }}
							<template v-if="t.due_at"> · <span :class="overdue(t) ? 'font-semibold text-rose-700' : ''">due {{ due(t.due_at) }}{{ overdue(t) ? ' (overdue)' : '' }}</span></template>
						</p>
						<p v-if="t.description" class="mt-1 text-sm text-gray-600">{{ t.description }}</p>
					</div>
					<div class="flex items-center gap-2">
						<span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="priorityClasses[t.priority]">{{ t.priority }}</span>
						<select :value="t.status" :disabled="pending.has(t.id)" :aria-label="`Status of ${t.name}`"
							class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs" @change="setStatus(t, ($event.target as HTMLSelectElement).value as TaskStatus)">
							<option v-for="(label, key) in taskStatusLabels" :key="key" :value="key">{{ label }}</option>
						</select>
					</div>
				</div>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import type { TaskStatus } from '~/composables/useEvents'
import type { MyTask } from '~/composables/useTeam'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const teamApi = useTeam()
const eventsApi = useOrganizerEvents()

const tasks = ref<MyTask[]>([])
const loading = ref(true)
const error = ref('')
const pending = reactive(new Set<string>())
const highlighted = computed(() => typeof route.query.task === 'string' ? route.query.task : null)

function due(at: string) {
	return new Date(at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' })
}

function overdue(t: MyTask) {
	return t.due_at !== null && !['done', 'cancelled'].includes(t.status) && new Date(t.due_at).getTime() < Date.now()
}

async function setStatus(t: MyTask, status: TaskStatus) {
	error.value = ''
	pending.add(t.id)
	try {
		await eventsApi.updateTask(organization.value!.id, t.event_id, t.id, { status })
		t.status = status
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		pending.delete(t.id)
	}
}

onMounted(async () => {
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) tasks.value = await teamApi.myTasks(org.id)
		if (highlighted.value) nextTick(() => document.getElementById(`task-${highlighted.value}`)?.scrollIntoView({ block: 'center' }))
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
})
</script>
