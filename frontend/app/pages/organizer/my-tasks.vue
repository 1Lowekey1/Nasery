<template>
	<div>
		<p class="text-xs font-bold uppercase tracking-[0.16em] text-[#285F6b]">Organizer workspace</p>
		<h1 class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl">My Tasks</h1>
		<p class="mt-1 mb-6 max-w-2xl text-sm text-gray-500">Preparation tasks and program items assigned to you across your team's upcoming events.</p>

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading your tasks…</div>

		<div v-else-if="!tasks.length && !program.length" class="rounded-2xl border border-gray-200 bg-white px-6 py-16 text-center">
			<h2 class="text-lg font-bold text-gray-900">Nothing assigned to you</h2>
			<p class="mt-1 text-sm text-gray-500">When your team assigns you a task on an event, it shows up here.</p>
		</div>

		<!-- Wide screens: tasks on the left, your program items alongside. -->
		<div v-else class="grid items-start gap-6" :class="tasks.length && program.length ? 'xl:grid-cols-[minmax(0,1fr)_24rem]' : ''">
		<div v-if="tasks.length">
		<div class="mb-3 flex flex-wrap items-center justify-between gap-3">
			<p class="text-sm text-gray-500">{{ openCount }} open<template v-if="overdueCount"> · <span class="font-semibold text-rose-700">{{ overdueCount }} overdue</span></template></p>
			<div class="flex items-center gap-2">
				<label for="task-sort" class="text-xs font-semibold text-gray-500">Sort by</label>
				<div class="w-48"><FormsSelect id="task-sort" v-model="sortBy" :options="sortOptions" :can-clear="false" /></div>
			</div>
		</div>
		<ul class="space-y-3">
			<li v-for="t in sortedTasks" :key="t.id" :id="`task-${t.id}`" class="rounded-2xl border bg-white p-4"
				:class="highlighted === t.id ? 'border-primary-400 ring-2 ring-primary-100' : 'border-gray-200'">
				<div class="flex flex-wrap items-start justify-between gap-3">
					<div class="min-w-0">
						<p class="font-semibold" :class="t.status === 'done' ? 'text-gray-400 line-through' : 'text-gray-900'">{{ t.name }}</p>
						<p class="text-xs text-gray-500">
							<NuxtLink :to="`/organizer/eventmanagement/preparation-tracking?event=${t.event_id}`" class="tap-target font-semibold text-primary-700">{{ t.event }}</NuxtLink>
							· {{ t.checklist }}
							<template v-if="t.due_at"> · <span :class="overdue(t) ? 'font-semibold text-rose-700' : ''">due {{ due(t.due_at) }}{{ overdue(t) ? ' (overdue)' : '' }}</span></template>
						</p>
						<p v-if="t.description" class="mt-1 text-sm text-gray-600">{{ t.description }}</p>
					</div>
					<div class="flex items-center gap-2">
						<span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="priorityClasses[t.priority]">{{ t.priority }}</span>
						<div class="w-36"><FormsSelect :model-value="t.status" :can-clear="false" :disabled="pending.has(t.id)" :options="Object.entries(taskStatusLabels).map(([value, label]) => ({ value, label }))" append-to-body close-on-scroll @update:model-value="value => value && setStatus(t, value as TaskStatus)" /></div>
					</div>
				</div>
			</li>
		</ul>
		</div>

		<!-- Program items where you're the person in charge -->
		<section v-if="program.length" class="xl:sticky xl:top-0">
			<h2 class="text-base font-bold text-gray-900">Your program items</h2>
			<p class="mt-0.5 text-sm text-gray-500">Parts of the event program with your name as the person in charge.</p>
			<ul class="mt-3 space-y-3">
				<li v-for="p in program" :key="p.id" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-4">
					<div class="min-w-0">
						<p class="font-semibold" :class="p.status === 'completed' ? 'text-gray-400 line-through' : 'text-gray-900'">{{ p.title }}</p>
						<p class="text-xs text-gray-500">
							<NuxtLink to="/organizer/activity-timeline" class="tap-target font-semibold text-primary-700">{{ p.event }}</NuxtLink>
							<template v-if="p.activity_at"> · {{ due(p.activity_at) }}</template>
						</p>
					</div>
					<span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">{{ programStatus[p.status] }}</span>
				</li>
			</ul>
		</section>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { TaskStatus } from '~/composables/useEvents'
import type { MyProgramItem, MyTask } from '~/composables/useTeam'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const teamApi = useTeam()
const eventsApi = useOrganizerEvents()

const tasks = ref<MyTask[]>([])
const program = ref<MyProgramItem[]>([])
const programStatus = { pending: 'Pending', in_progress: 'In progress', completed: 'Completed' }
const loading = ref(true)
const error = ref('')
const pending = reactive(new Set<string>())
const highlighted = computed(() => typeof route.query.task === 'string' ? route.query.task : null)

// Sorting (kept per browser). Finished and cancelled tasks always sink to the bottom.
type SortKey = 'due' | 'newest' | 'priority' | 'status' | 'event'
const sortOptions: { value: SortKey, label: string }[] = [
	{ value: 'due', label: 'Due date (soonest)' },
	{ value: 'newest', label: 'Newest assigned' },
	{ value: 'priority', label: 'Importance (highest)' },
	{ value: 'status', label: 'Status' },
	{ value: 'event', label: 'Event' },
]
const sortBy = ref<SortKey>('due')
onMounted(() => {
	try {
		const saved = localStorage.getItem('nasery.myTasksSort') as SortKey | null
		if (saved && sortOptions.some(o => o.value === saved)) sortBy.value = saved
	} catch {}
})
watch(sortBy, (v) => { try { localStorage.setItem('nasery.myTasksSort', v) } catch {} })

const priorityRank: Record<string, number> = { urgent: 0, high: 1, medium: 2, low: 3 }
const statusRank: Record<string, number> = { blocked: 0, in_progress: 1, todo: 2, done: 3, cancelled: 4 }
const closed = (t: MyTask) => t.status === 'done' || t.status === 'cancelled'
const dueTime = (t: MyTask) => (t.due_at ? new Date(t.due_at).getTime() : Number.POSITIVE_INFINITY)
const sortedTasks = computed(() => [...tasks.value].sort((a, b) => {
	if (closed(a) !== closed(b)) return closed(a) ? 1 : -1
	const byDue = dueTime(a) - dueTime(b)
	switch (sortBy.value) {
		case 'newest': return (b.assigned_at ?? '').localeCompare(a.assigned_at ?? '') || byDue
		case 'priority': return (priorityRank[a.priority] ?? 9) - (priorityRank[b.priority] ?? 9) || byDue
		case 'status': return (statusRank[a.status] ?? 9) - (statusRank[b.status] ?? 9) || byDue
		case 'event': return a.event.localeCompare(b.event) || byDue
		default: return byDue
	}
}))
const openCount = computed(() => tasks.value.filter(t => !closed(t)).length)
const overdueCount = computed(() => tasks.value.filter(overdue).length)

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

// Close to live (review #12): newly assigned or reviewed tasks appear on their own.
useLiveRefresh(async () => {
	const org = organization.value ?? await fetchOrganization()
	if (org) [tasks.value, program.value] = await Promise.all([teamApi.myTasks(org.id), teamApi.myProgram(org.id)])
})

onMounted(async () => {
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) [tasks.value, program.value] = await Promise.all([teamApi.myTasks(org.id), teamApi.myProgram(org.id)])
		if (highlighted.value) nextTick(() => scrollToElement(document.getElementById(`task-${highlighted.value}`), { block: 'center', behavior: 'auto' }))
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
})
</script>
