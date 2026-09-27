<template>
	<div>
		<OrganizerEventPicker :model-value="eventId" />

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="eventId && !prep && !error" class="py-16 text-center text-sm text-gray-500">Loading preparation…</div>

		<template v-if="prep && eventId">
			<section class="mb-5 rounded-2xl border border-gray-200 bg-white p-6">
				<EventStageTrack :readiness="prep.readiness_percent" :stage="prep.stage" :stages="prep.stages"
					:manual="prep.readiness_manual" :cancelled="prep.event_status === 'cancelled'" />
				<p class="mt-3 text-xs text-gray-500">
					Readiness counts finished tasks out of all tasks that are still needed. The client sees the percentage and stage, never the task list.
				</p>
				<div v-if="open && prep.can.override_readiness" class="mt-3 flex flex-wrap items-center gap-2 text-sm">
					<template v-if="prep.readiness_manual">
						<span class="text-gray-600">Set manually. Checklist says {{ prep.computed_readiness_percent ?? 0 }}%.</span>
						<button type="button" class="font-semibold text-primary-700" :disabled="busy" @click="setReadiness(null)">Use the checklist again</button>
					</template>
					<form v-else-if="overriding" class="flex items-center gap-2" @submit.prevent="setReadiness(Number(overrideValue))">
						<FormsTextField v-model="overrideValue" type="number" min="0" max="100" class="w-24" aria-label="Readiness percent" />
						<span>%</span>
						<button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 font-semibold text-white" :disabled="busy">Set</button>
						<button type="button" class="text-gray-500" @click="overriding = false">Cancel</button>
					</form>
					<button v-else type="button" class="text-xs font-semibold text-gray-500 underline" @click="overriding = true">
						Set readiness manually (for work tracked outside NaSeRy)
					</button>
				</div>
			</section>

			<div v-if="!prep.checklists.length && open && prep.can.manage_checklists" class="mb-5 rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-8 text-center">
				<h3 class="text-base font-bold text-gray-900">No checklist yet</h3>
				<p class="mt-1 text-sm text-gray-500">Start with the usual preparation tasks, or build your own.</p>
				<button type="button" :disabled="busy" class="mt-4 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1f4a54]"
					@click="run(() => api.addChecklist(orgId, eventId!, { starter: true }))">Use the starter checklist</button>
			</div>

			<section v-for="c in prep.checklists" :key="c.id" class="mb-5 rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-start justify-between gap-2">
					<div>
						<h3 class="text-base font-bold text-gray-900">{{ c.name }}</h3>
						<p class="text-xs text-gray-500">{{ c.done }} of {{ c.total }} done</p>
					</div>
					<button v-if="open && prep.can.manage_checklists" type="button" class="text-xs font-semibold text-rose-700"
						@click="confirmRemove(`the “${c.name}” checklist and its tasks`, () => api.removeChecklist(orgId, eventId!, c.id))">Remove checklist</button>
				</div>

				<ul class="mt-3 divide-y divide-gray-100">
					<li v-for="t in c.tasks" :key="t.id" class="flex flex-wrap items-center gap-3 py-2.5 text-sm">
						<input type="checkbox" class="h-4 w-4" :checked="t.status === 'done'" :disabled="!open || !canMove(t) || pendingTasks.has(t.id) || t.status === 'cancelled'"
							:aria-label="`Mark ${t.name} done`"
							@change="updateTask(t.id, { status: t.status === 'done' ? 'pending' : 'done' })">
						<span class="min-w-0 flex-1" :class="t.status === 'done' ? 'text-gray-400 line-through' : t.status === 'cancelled' ? 'text-gray-400' : 'text-gray-900'">
							{{ t.name }}
							<span v-if="t.due_at" class="block text-xs" :class="isOverdue(t) ? 'text-rose-700' : 'text-gray-400'">
								Due {{ dueLabel(t.due_at) }}<template v-if="isOverdue(t)"> · overdue</template>
							</span>
						</span>
						<span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="priorityClasses[t.priority]">{{ t.priority }}</span>
						<select v-if="open && prep.can.manage_tasks && prep.team.length" :value="t.assignee_ids[0] ?? ''" :aria-label="`Assign ${t.name}`" :disabled="pendingTasks.has(t.id)"
							class="max-w-[9rem] rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs"
							@change="assign(t.id, ($event.target as HTMLSelectElement).value)">
							<option value="">Unassigned</option>
							<option v-for="m in prep.team" :key="m.id" :value="m.id">{{ m.name }}</option>
						</select>
						<span v-else-if="t.assignee_ids.length" class="text-xs text-gray-500">{{ assigneeNames(t) }}</span>
						<select v-if="open && canMove(t)" :value="t.status" :aria-label="`Status of ${t.name}`" :disabled="pendingTasks.has(t.id)"
							class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs"
							@change="updateTask(t.id, { status: ($event.target as HTMLSelectElement).value as TaskStatus })">
							<option v-for="(label, key) in taskStatusLabels" :key="key" :value="key">{{ label }}</option>
						</select>
						<span v-else class="text-xs text-gray-500">{{ taskStatusLabels[t.status] }}</span>
						<button v-if="open && prep.can.manage_tasks" type="button" class="text-xs text-rose-700" :aria-label="`Remove ${t.name}`"
							@click="confirmRemove(`“${t.name}”`, () => api.removeTask(orgId, eventId!, t.id))">×</button>
					</li>
					<li v-if="!c.tasks.length" class="py-2 text-sm text-gray-400">No tasks yet.</li>
				</ul>

				<form v-if="open && prep.can.manage_tasks" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_130px_190px_auto]" @submit.prevent="addTask(c.id)">
					<FormsTextField v-model="drafts[c.id].name" placeholder="New task" :aria-label="`New task in ${c.name}`" />
					<select v-model="drafts[c.id].priority" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Priority">
						<option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option>
					</select>
					<input v-model="drafts[c.id].due_at" type="datetime-local" aria-label="Due" class="rounded-lg border border-gray-200 px-2 py-2 text-sm">
					<button type="submit" :disabled="busy || drafts[c.id].name.trim().length < 2" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add</button>
				</form>
			</section>

			<form v-if="open && prep.can.manage_checklists" class="flex max-w-md gap-2" @submit.prevent="addChecklist">
				<FormsTextField v-model="newChecklist" placeholder="New checklist, e.g. Styling & décor" class="flex-1" aria-label="New checklist name" />
				<button type="submit" :disabled="busy || newChecklist.trim().length < 2" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add checklist</button>
			</form>
			<p v-if="!open" class="text-sm text-gray-500">This event has ended; its checklist is kept as a record.</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { PrepTask, Preparation, TaskPriority, TaskStatus } from '~/composables/useEvents'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerEvents()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const orgId = computed(() => organization.value?.id ?? '')
const prep = ref<Preparation | null>(null)
const error = ref('')
const busy = ref(false)
const overriding = ref(false)
const overrideValue = ref('')
const newChecklist = ref('')
const pendingTasks = reactive(new Set<string>())
const drafts = reactive<Record<string, { name: string, priority: TaskPriority, due_at: string }>>({})

const open = computed(() => prep.value !== null && !['completed', 'cancelled'].includes(prep.value.event_status))

function apply(p: Preparation) {
	prep.value = p
	for (const c of p.checklists) drafts[c.id] ??= { name: '', priority: 'medium', due_at: '' }
}

async function run(fn: () => Promise<Preparation>) {
	error.value = ''
	busy.value = true
	try {
		apply(await fn())
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function load() {
	if (!eventId.value) return
	prep.value = null
	const org = organization.value ?? await fetchOrganization()
	if (org) await run(() => api.preparation(org.id, eventId.value!))
}

// Ticking several tasks quickly: each task is locked only while its own
// request runs. Responses can finish out of order, so while others are still
// pending we ignore the response and reload once the last one is done.
const teamApi = useTeam()

// Task managers move any task; assignees move their own (B8).
function canMove(t: PrepTask) {
	return Boolean(prep.value?.can.manage_tasks || t.mine)
}

function assigneeNames(t: PrepTask) {
	return t.assignee_ids.map(id => prep.value?.team.find(m => m.id === id)?.name).filter(Boolean).join(', ')
}

// One assignee per task from this page (the API accepts several).
async function assign(taskId: string, eventMemberId: string) {
	error.value = ''
	pendingTasks.add(taskId)
	try {
		await teamApi.assignTask(orgId.value, eventId.value!, taskId, eventMemberId ? [eventMemberId] : [])
		apply(await api.preparation(orgId.value, eventId.value!))
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		pendingTasks.delete(taskId)
	}
}

let overlapped = false

async function updateTask(taskId: string, body: { status: TaskStatus }) {
	error.value = ''
	overlapped ||= pendingTasks.size > 0
	pendingTasks.add(taskId)
	let result: Preparation | null = null
	try {
		result = await api.updateTask(orgId.value, eventId.value!, taskId, body)
	} catch (e) {
		error.value = authMessage(e)
	}
	pendingTasks.delete(taskId)
	if (pendingTasks.size > 0) return
	// Overlapping requests may have read before each other committed: reload once.
	const fresh = overlapped || result === null
	overlapped = false
	apply(fresh ? await api.preparation(orgId.value, eventId.value!) : result!)
}

function confirmRemove(what: string, fn: () => Promise<Preparation>) {
	if (confirm(`Remove ${what}?`)) run(fn)
}

async function addTask(checklistId: string) {
	const d = drafts[checklistId]
	await run(() => api.addTask(orgId.value, eventId.value!, checklistId, { name: d.name.trim(), priority: d.priority, due_at: d.due_at || null }))
	if (!error.value) Object.assign(d, { name: '', due_at: '' })
}

async function addChecklist() {
	await run(() => api.addChecklist(orgId.value, eventId.value!, { name: newChecklist.value.trim() }))
	if (!error.value) newChecklist.value = ''
}

async function setReadiness(percent: number | null) {
	await run(() => api.setReadiness(orgId.value, eventId.value!, percent))
	overriding.value = false
}

function dueLabel(at: string) {
	return new Date(at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' })
}

function isOverdue(t: PrepTask) {
	return t.due_at !== null && !['done', 'cancelled'].includes(t.status) && new Date(t.due_at).getTime() < Date.now()
}

watch(eventId, load)
onMounted(load)
</script>
