<template>
	<div class="space-y-6">
		<div v-if="isLoading" class="space-y-4">
			<div class="h-40 animate-pulse rounded-3xl bg-white ring-1 ring-gray-200" />
			<div class="h-64 animate-pulse rounded-2xl bg-white ring-1 ring-gray-200" />
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">{{ errorMessage }}</div>

		<template v-else-if="event">
			<!-- Banner with readiness -->
			<section class="relative overflow-hidden rounded-3xl bg-primary-700 px-5 py-5 text-white sm:px-8 sm:py-7">
				<div class="pt-dots" aria-hidden="true" />
				<div class="pt-glow" aria-hidden="true" />
				<div class="relative flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
					<div class="min-w-0">
						<p class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.16em] text-white/60"><span class="h-1.5 w-1.5 rounded-full bg-white/60" />Preparation tracking</p>
						<h2 class="mt-2 line-clamp-2 break-words text-2xl font-black tracking-tight sm:text-3xl">{{ event.name }}</h2>
						<p class="mt-1 text-sm text-white/70">Track tasks, assignments, and readiness before the big day.</p>
						<Transition name="pt-fade">
							<p v-if="checklist.length && readinessPercent === 100" class="mt-3 inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold ring-1 ring-white/20"><IconBase name="sparkles" class="h-3.5 w-3.5" /> All set. Every task is done.</p>
						</Transition>
					</div>
					<div class="flex items-center gap-4">
						<div class="relative h-24 w-24 shrink-0">
							<svg viewBox="0 0 96 96" class="h-full w-full -rotate-90" aria-hidden="true">
								<circle cx="48" cy="48" r="40" fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="8" />
								<circle cx="48" cy="48" r="40" fill="none" stroke="white" stroke-width="8" stroke-linecap="round" :stroke-dasharray="PT_RING" :stroke-dashoffset="PT_RING * (1 - (ringReady ? readinessPercent : 0) / 100)" class="transition-[stroke-dashoffset] duration-700 ease-out" />
							</svg>
							<span class="absolute inset-0 flex flex-col items-center justify-center"><span class="text-xl font-extrabold tabular-nums">{{ readinessPercent }}%</span><span class="text-[10px] text-white/60">ready</span></span>
						</div>
						<div class="text-sm">
							<p><strong class="text-lg font-extrabold tabular-nums">{{ completedCount }}</strong><span class="text-white/60"> / {{ checklist.length }} done</span></p>
							<p class="text-xs text-white/60">{{ reviewCount }} waiting for your review</p>
						</div>
					</div>
				</div>
			</section>

			<p v-if="actionError" class="flex items-start justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
				<span>{{ actionError }}</span>
				<button type="button" class="tap-target shrink-0 font-semibold" aria-label="Dismiss" @click="actionError = ''"><IconBase name="x" class="h-4 w-4" /></button>
			</p>

			<!-- Where the event is: the same stage bar the client sees (review #14) -->
			<EventStageBar :stages="stageInfo.stages" :stage="stageInfo.stage" :event-status="stageInfo.status" :readiness="readinessPercent" />

			<!-- The client's request, one tap away while preparing -->
			<OrganizerClientRequest v-if="requestRef.inquiry && requestRef.org" :organization-id="requestRef.org"
				:inquiry-id="requestRef.inquiry" :quotation-id="requestRef.quotation" collapsible />

			<div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
				<!-- Checklist -->
				<section class="rounded-2xl border border-gray-200 bg-white">
					<div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
						<h3 class="text-base font-bold text-gray-950">Checklist</h3>
						<div class="pt-seg" role="tablist" aria-label="Filter tasks">
							<span class="pt-seg-thumb" aria-hidden="true" :style="{ transform: `translateX(${filterIndex * 100}%)` }" />
							<button v-for="f in taskFilters" :key="f.value" type="button" role="tab" :aria-selected="taskFilter === f.value" :class="taskFilter === f.value ? 'is-on' : ''" @click="taskFilter = f.value">{{ f.label }} <span class="tabular-nums opacity-50">{{ f.count }}</span></button>
						</div>
					</div>

					<!-- Quick add -->
					<form v-if="!isFinished" class="flex flex-col gap-2 border-b border-gray-100 bg-gray-50/60 px-5 py-4 sm:flex-row" @submit.prevent="quickAdd">
						<div class="relative min-w-0 flex-1">
							<IconBase name="plus" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
							<input ref="quickInput" v-model="newItemLabel" type="text" maxlength="255" class="pt-field !pl-10" placeholder="Add a task, e.g. Confirm sound system with supplier" :disabled="isAddingItem" aria-label="New task" />
						</div>
						<div class="sm:w-56"><FormsSelect v-model="newItemAssignee" :options="assigneeOptions" placeholder="Assign to" /></div>
						<button type="submit" class="pt-btn-primary shrink-0" :disabled="isAddingItem || !newItemLabel.trim()">
							<IconBase :name="isAddingItem ? 'refresh-cw' : 'plus'" class="h-4 w-4" :class="isAddingItem ? 'animate-spin' : ''" />{{ isAddingItem ? 'Adding…' : 'Add' }}
						</button>
					</form>

					<TransitionGroup v-if="visibleTasks.length" tag="ul" name="pt-item" class="relative divide-y divide-gray-100">
						<li v-for="item in visibleTasks" :key="item.id" class="group flex flex-wrap items-start gap-x-3 gap-y-2 px-4 py-4 transition-colors hover:bg-gray-50/70 sm:flex-nowrap sm:px-5">
							<button type="button" class="pt-check" :class="item.is_completed ? 'is-done' : ''" :disabled="updatingItemId === item.id" :aria-label="item.is_completed ? `Mark ${item.label} as not done` : `Mark ${item.label} as done`" @click="toggleItem(item)">
								<IconBase v-if="updatingItemId === item.id" name="refresh-cw" class="h-3 w-3 animate-spin" />
								<svg v-else-if="item.is_completed" viewBox="0 0 20 20" class="h-3.5 w-3.5" aria-hidden="true"><path class="pt-tick" d="M5 10.5 8.5 14 15 6.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
							</button>
							<div class="min-w-0 flex-1">
								<p class="text-sm font-medium transition" :class="item.is_completed ? 'text-gray-400 line-through decoration-gray-300' : 'text-gray-950'">{{ item.label }}</p>
								<div class="mt-1.5 flex flex-wrap items-center gap-1.5">
									<label class="pt-chip min-h-9 cursor-pointer !py-1.5" :class="item.assigned_team_member ? '' : (item.is_completed ? 'text-gray-400' : 'border-amber-200 bg-amber-50 text-amber-800')">
										<span v-if="item.assigned_team_member" class="pt-avatar h-4 w-4 text-[8px]">{{ initials(memberName(item.assigned_team_member)) }}</span>
										<select class="min-h-7 cursor-pointer appearance-none bg-transparent pr-1 outline-none" :value="item.assigned_team_member_id ?? ''"
											:disabled="updatingItemId === item.id || (!teamMembers.length && !availableMembers.length)" :aria-label="`Assign ${item.label}`"
											@change="assignItem(item, ($event.target as HTMLSelectElement).value)">
											<option value="">{{ item.is_completed ? 'Unassigned' : 'Needs an assignee' }}</option>
											<optgroup v-if="teamMembers.length" label="On this event">
												<option v-for="member in teamMembers" :key="member.id" :value="member.id">{{ memberName(member) }}</option>
											</optgroup>
											<optgroup v-if="availableMembers.length && !isFinished" label="Rest of your team (adds them)">
												<option v-for="m in availableMembers" :key="m.id" :value="`org:${m.id}`">{{ m.name }}</option>
											</optgroup>
										</select>
									</label>
									<span v-if="item.completed_by" class="text-xs text-gray-500">Done by {{ userName(item.completed_by) }}<template v-if="item.completion_note"> · “{{ item.completion_note }}”</template></span>
								</div>
								<form v-if="changesFor === item.id" class="mt-2.5 flex flex-wrap items-center gap-2 rounded-xl bg-red-50/60 px-3 py-2.5"
									@submit.prevent="reviewItem(item, 'changes_requested')">
									<input v-model="changesNote" required minlength="3" maxlength="1000" placeholder="What should be changed? (sent to the assignee)"
										class="pt-field !h-9 min-w-60 flex-1 !border-red-200">
									<button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white disabled:opacity-50" :disabled="changesNote.trim().length < 3">Send back</button>
									<button type="button" class="text-xs font-semibold text-gray-500" @click="changesFor = null">Cancel</button>
								</form>
							</div>
							<div class="flex w-full shrink-0 items-center justify-end gap-1.5 pl-9 sm:w-auto sm:pl-0">
								<template v-if="item.is_completed && item.review_status === 'pending' && item.completed_by">
									<button type="button" class="pt-review is-ok" @click="reviewItem(item, 'verified')"><IconBase name="check" class="h-3.5 w-3.5" /> Verify</button>
									<button type="button" class="pt-review" @click="startChanges(item)">Request changes</button>
								</template>
								<span v-else-if="item.is_completed && item.review_status !== 'pending'" class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
									<span class="h-1.5 w-1.5 rounded-full" :class="item.review_status === 'verified' ? 'bg-green-600' : 'bg-red-500'" />{{ item.review_status === 'verified' ? 'Verified' : 'Changes requested' }}
								</span>
								<button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 opacity-100 transition hover:bg-red-50 hover:text-red-600 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100" :disabled="deletingItemId === item.id" :aria-label="`Delete ${item.label}`" @click="confirmRemove(item)">
									<IconBase :name="deletingItemId === item.id ? 'refresh-cw' : 'trash'" class="h-4 w-4" :class="{ 'animate-spin': deletingItemId === item.id }" />
								</button>
							</div>
						</li>
					</TransitionGroup>
					<div v-else class="px-6 py-12 text-center">
						<p class="text-sm font-semibold text-gray-800">{{ checklist.length ? 'Nothing here' : 'No tasks yet' }}</p>
						<p class="mt-1 text-sm text-gray-500">{{ checklist.length ? 'Try another filter.' : 'Add the first task above. Assign it to a teammate and they’ll see it in their Team portal.' }}</p>
					</div>
				</section>

				<!-- Team -->
				<aside class="space-y-4 xl:sticky xl:top-0">
					<section class="rounded-2xl border border-gray-200 bg-white">
						<div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
							<div>
								<h3 class="text-base font-bold text-gray-950">Event team</h3>
								<p class="text-xs text-gray-500">{{ teamMembers.length }} {{ teamMembers.length === 1 ? 'person' : 'people' }} you can assign</p>
							</div>
							<NuxtLink to="/organizer/team" class="text-xs font-semibold text-primary-700 hover:underline">Manage</NuxtLink>
						</div>
						<ul v-if="teamMembers.length" class="divide-y divide-gray-100">
							<li v-for="member in teamMembers" :key="member.id" class="flex items-center gap-3 px-5 py-3">
								<span class="pt-avatar h-9 w-9 text-xs">{{ initials(memberName(member)) }}</span>
								<div class="min-w-0 flex-1">
									<p class="truncate text-sm font-semibold text-gray-950">{{ memberName(member) }}</p>
									<p class="truncate text-xs text-gray-500">{{ memberRole(member) }}</p>
								</div>
								<span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-gray-600" :title="`${openFor(member)} open tasks`">{{ openFor(member) }} open</span>
							</li>
						</ul>
						<p v-else class="px-5 py-6 text-sm text-gray-500">No teammates yet. Add one to start assigning tasks.</p>

						<div class="border-t border-gray-100 p-4">
							<template v-if="availableMembers.length && !isFinished">
								<p class="mb-2 text-xs font-semibold text-gray-500">Add to this event (one tap)</p>
								<div class="flex flex-wrap gap-2">
									<button v-for="m in availableMembers" :key="m.id" type="button" class="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-dashed border-gray-300 px-3 text-xs font-semibold text-gray-700 transition hover:border-primary-300 hover:bg-primary-25 hover:text-primary-800 disabled:opacity-50"
										:disabled="creatingMember" @click="addMember(m.id)">
										<IconBase name="plus" class="h-3.5 w-3.5" />{{ m.name }}<span class="font-normal text-gray-400">· {{ m.role }}</span>
									</button>
								</div>
							</template>
							<p class="text-xs leading-5 text-gray-500" :class="availableMembers.length ? 'mt-3' : ''">
								<template v-if="!availableMembers.length">Everyone on your team is on this event. </template>
								New person? <NuxtLink to="/organizer/team" class="font-semibold text-primary-700">Invite them from Team</NuxtLink>; they choose their own password.
							</p>
						</div>
					</section>
				</aside>
			</div>

			<!-- What's happened: the event's journey (moved here from the Activity Timeline's History) -->
			<EventJourneyLog :entries="journey" />
		</template>
	</div>
</template>

<script setup lang="ts">
interface OrganizerEvent {
	id: number
	name: string
	event_type?: string
	event_date?: string | null
	location?: string | null
	expected_guests?: number | null
	start_time?: string | null
	end_time?: string | null
	status?: string
}

interface PreparationItem {
	id: number
	event_id: number
	label: string
	is_completed: boolean
	assigned_team_member_id: number | null
	assigned_team_member?: TeamMember | null
	completed_by?: TeamUser | null
	completion_note?: string | null
	review_status: 'pending' | 'verified' | 'changes_requested'
	created_at?: string
	updated_at?: string
}

interface TeamUser { firstname: string; lastname: string; email?: string }
interface TeamMember { id: number; position: string | null; user: TeamUser }

type EventResponse =
	| OrganizerEvent
	| {
		data: OrganizerEvent
	}

interface PreparationListResponse {
	data: PreparationItem[]
}

interface PreparationItemResponse {
	message?: string
	data: PreparationItem
}

interface ApiErrorResponse {
	data?: {
		message?: string
		errors?: Record<string, string[]>
	}
}

function getApiErrorMessage(
	error: unknown,
	fallback: string,
): string {
	const apiError =
		error as ApiErrorResponse

	const validationError =
		Object.values(
			apiError.data?.errors ?? {},
		)[0]?.[0]

	return (
		validationError ??
		apiError.data?.message ??
		fallback
	)
}

definePageMeta({
	layout: 'dashboard',
	middleware: ['organizer'],
})

const route =
	useRoute()

const config =
	useRuntimeConfig()

const {
	token,
} = useAuth()

const event =
	ref<OrganizerEvent | null>(null)

const checklist =
	ref<PreparationItem[]>([])

const isLoading =
	ref(false)

const isAddingItem =
	ref(false)

const updatingItemId =
	ref<number | null>(null)

const deletingItemId =
	ref<number | null>(null)

// Action errors show as a banner; only a failed load replaces the page.
const actionError = ref('')
const errorMessage =
	ref('')

const showAddForm =
	ref(false)

const newItemLabel =
	ref('')

const newItemAssignee = ref<number | null>(null)
const teamMembers = ref<TeamMember[]>([])
/** Organization members not on this event's team yet (to add them). */
const orgMembers = ref<{ id: string, name: string, role: string }[]>([])
const availableMembers = computed(() => orgMembers.value.filter(m => !teamMembers.value.some(t => (t as unknown as { organization_member_id: string }).organization_member_id === m.id)))
let orgId = ''
// The request this event was booked from (for the client's-request panel).
const requestRef = computed(() => {
	const e = event.value as unknown as { inquiry_id?: string | null, quotation_id?: string | null } | null
	return { org: useOrganization().organization.value?.id ?? '', inquiry: e?.inquiry_id ? String(e.inquiry_id) : null, quotation: e?.quotation_id ? String(e.quotation_id) : null }
})
const showMemberForm = ref(false)
const creatingMember = ref(false)
const memberForm = reactive({ organization_member_id: '' })

/*
 * ============================================================
 * SELECTED EVENT
 * ============================================================
 */

const eventId =
	computed(() => routeId(route.query.event))

/*
 * ============================================================
 * READINESS
 * ============================================================
 */

const completedCount =
	computed(() =>
		checklist.value.filter(
			item =>
				item.is_completed,
		).length,
	)

const readinessPercent =
	computed(() => {
		if (
			!checklist.value.length
		) {
			return 0
		}

		return Math.round(
			(
				completedCount.value /
				checklist.value.length
			) *
			100,
		)
	})

/*
 * ============================================================
 * RESPONSE HELPER
 * ============================================================
 */

function extractEvent(
	response: EventResponse,
): OrganizerEvent {
	if (
		typeof response === 'object' &&
		response !== null &&
		'data' in response
	) {
		return (
			response as {
				data: OrganizerEvent
			}
		).data
	}

	return response as OrganizerEvent
}

/*
 * ============================================================
 * LOAD EVENT + PREPARATION ITEMS
 * ============================================================
 */

type ApiPreparation = {
	stage?: string | null
	stages?: string[]
	event_status?: string
	team: { id: string, organization_member_id: string, name: string, email: string | null, label: string | null }[]
	checklists: { id: string, name: string, tasks: {
		id: string, name: string, status: string, assignee_ids: string[], completion_note: string | null,
		review_status: 'pending' | 'verified' | 'changes_requested' | null, review_note: string | null,
	}[] }[]
}

/** The API's preparation (checklists of tasks + the event team), as this page's flat list. */
const isFinished = computed(() => ['completed', 'cancelled'].includes(stageInfo.value.status ?? ''))
const stageInfo = ref<{ stage: string | null, stages: string[], status: string | null }>({ stage: null, stages: [], status: null })
const journey = ref<{ id: string, at: string, message: string, actor?: string | null }[]>([])
async function loadJourney() {
	journey.value = await useOrganizerEvents().timeline(orgId, String(eventId.value)).catch(() => [])
}

function applyPreparation(prep: ApiPreparation) {
	stageInfo.value = { stage: prep.stage ?? null, stages: prep.stages ?? [], status: prep.event_status ?? null }
	teamMembers.value = prep.team.map(m => {
		const [firstname, ...rest] = m.name.split(' ')
		return { id: m.id as unknown as number, position: m.label, organization_member_id: m.organization_member_id,
			user: { firstname: firstname ?? m.name, lastname: rest.join(' '), email: m.email ?? undefined } } as unknown as TeamMember
	})
	checklist.value = prep.checklists.flatMap(c => c.tasks.filter(t => t.status !== 'cancelled').map(t => ({
		id: t.id as unknown as number,
		event_id: eventId.value as unknown as number,
		label: t.name,
		is_completed: t.status === 'done',
		assigned_team_member_id: (t.assignee_ids[0] ?? null) as unknown as number | null,
		assigned_team_member: teamMembers.value.find(m => String(m.id) === t.assignee_ids[0]) ?? null,
		// The template shows the note under "Completed by …".
		completed_by: (t.completion_note || t.review_status === 'changes_requested')
			? (teamMembers.value.find(m => String(m.id) === t.assignee_ids[0])?.user ?? { firstname: 'Your team', lastname: '' })
			: null,
		completion_note: t.review_status === 'changes_requested' && t.review_note ? `Changes requested: ${t.review_note}` : t.completion_note,
		review_status: (t.review_status ?? 'pending') as PreparationItem['review_status'],
	})))
}

async function loadPreparation(quiet = false) {
	errorMessage.value = ''
	if (!eventId.value) {
		await navigateTo('/organizer/eventmanagement/all-events', { replace: true })
		return
	}
	if (!quiet) isLoading.value = true
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId = org.id
		const [apiEvent, prep, team] = await Promise.all([
			useOrganizerEvents().show(orgId, eventId.value),
			useOrganizerEvents().preparation(orgId, eventId.value),
			useTeam().team(orgId).catch(() => null),
		])
		event.value = toLegacyEvent(apiEvent) as unknown as OrganizerEvent
		applyPreparation(prep as unknown as ApiPreparation)
		void loadJourney()
		orgMembers.value = (team?.members ?? []).filter(m => m.status === 'active').map(m => ({ id: m.id, name: m.name, role: m.role }))
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load preparation tracking.'
	} finally {
		isLoading.value = false
	}
}

const tasksBase = () => `/organizations/${orgId}/events/${eventId.value}`

async function toggleItem(item: PreparationItem) {
	if (!eventId.value || updatingItemId.value !== null) return
	updatingItemId.value = item.id
	actionError.value = ''
	try {
		const { data } = await useApi().patch<ApiPreparation>(`${tasksBase()}/tasks/${item.id}`, { status: item.is_completed ? 'pending' : 'done' })
		applyPreparation(data)
	} catch (error: unknown) {
		actionError.value = authMessage(error) || 'Unable to update checklist item.'
	} finally {
		updatingItemId.value = null
	}
}

/** New items go into the event's first checklist (created as "Preparation" if there is none). */
async function addChecklistItem() {
	if (!eventId.value) return
	const label = newItemLabel.value.trim()
	if (!label) return
	isAddingItem.value = true
	actionError.value = ''
	try {
		let prep = await useOrganizerEvents().preparation(orgId, eventId.value) as unknown as ApiPreparation
		if (!prep.checklists.length) {
			prep = (await useApi().post<ApiPreparation>(`${tasksBase()}/checklists`, { name: 'Preparation' })).data
		}
		prep = (await useApi().post<ApiPreparation>(`${tasksBase()}/checklists/${prep.checklists[0]!.id}/tasks`, { name: label })).data
		const assignee = await resolveAssignee(newItemAssignee.value as string | number | null)
		if (assignee) {
			const created = [...prep.checklists[0]!.tasks].reverse().find(t => t.name === label) ?? prep.checklists[0]!.tasks.at(-1)!
			// Assigning returns only the ids; reload the checklist.
			await useApi().put(`${tasksBase()}/tasks/${created.id}/assignees`, { event_member_ids: [assignee] })
			prep = await useOrganizerEvents().preparation(orgId, String(eventId.value)) as unknown as ApiPreparation
		}
		applyPreparation(prep)
		newItemLabel.value = ''
		newItemAssignee.value = null
		showAddForm.value = false
	} catch (error: unknown) {
		actionError.value = authMessage(error) || 'Unable to add checklist item.'
	} finally {
		isAddingItem.value = false
	}
}

function cancelAddItem() {
	if (isAddingItem.value) return
	newItemLabel.value = ''
	newItemAssignee.value = null
	showAddForm.value = false
}
function userName(user: TeamUser) { return `${user.firstname} ${user.lastname}`.trim() }
function memberName(member: TeamMember) { return userName(member.user) }

/** Put an organization member on this event's team, so tasks can be assigned to them. */
// One tap adds a teammate to the event (review #13: was 4 taps).
async function addMember(organizationMemberId: string) {
	creatingMember.value = true
	actionError.value = ''
	try {
		await useTeam().addToEvent(orgId, String(eventId.value), { organization_member_id: organizationMemberId, label: null, permissions: [] })
		applyPreparation(await useOrganizerEvents().preparation(orgId, String(eventId.value)) as unknown as ApiPreparation)
	} catch (error: unknown) {
		actionError.value = authMessage(error) || 'Unable to add this person to the event.'
	} finally {
		creatingMember.value = false
	}
}
async function createTeamMember() {
	if (memberForm.organization_member_id) await addMember(memberForm.organization_member_id)
	memberForm.organization_member_id = ''
	showMemberForm.value = false
}
/** An assignee pick: an event member id, or "org:<id>" for a teammate not on the event yet (added first). */
async function resolveAssignee(value: string | number | null): Promise<string | null> {
	const pick = value === null ? '' : String(value)
	if (!pick.startsWith('org:')) return pick || null
	const orgMemberId = pick.slice(4)
	await addMember(orgMemberId)
	const member = teamMembers.value.find(m => (m as unknown as { organization_member_id: string }).organization_member_id === orgMemberId)
	return member ? String(member.id) : null
}

const changesFor = ref<number | null>(null)
const changesNote = ref('')
const confirmDeleteId = ref<number | null>(null)

function startChanges(item: PreparationItem) {
	changesFor.value = item.id
	changesNote.value = ''
}

/** Assign (or unassign) someone from this event's team. */
async function assignItem(item: PreparationItem, pick: string) {
	updatingItemId.value = item.id
	actionError.value = ''
	try {
		const memberId = await resolveAssignee(pick)
		await useApi().put(`${tasksBase()}/tasks/${item.id}/assignees`, { event_member_ids: memberId ? [memberId] : [] })
		applyPreparation(await useOrganizerEvents().preparation(orgId, String(eventId.value)) as unknown as ApiPreparation)
	} catch (error: unknown) {
		actionError.value = authMessage(error) || 'Unable to assign this item.'
	} finally {
		updatingItemId.value = null
	}
}

async function reviewItem(item: PreparationItem, status: 'verified' | 'changes_requested') {
	if (!eventId.value) return
	const note = status === 'changes_requested' ? changesNote.value.trim() : null
	if (status === 'changes_requested' && !note) return
	try {
		applyPreparation((await useApi().post<ApiPreparation>(`${tasksBase()}/tasks/${item.id}/review`, { decision: status, note })).data)
		changesFor.value = null
	} catch (error: unknown) {
		actionError.value = authMessage(error) || 'Unable to review completed work.'
	}
}

async function removeChecklistItem(item: PreparationItem) {
	if (!eventId.value || deletingItemId.value !== null) return
	deletingItemId.value = item.id
	actionError.value = ''
	try {
		applyPreparation((await useApi().delete<ApiPreparation>(`${tasksBase()}/tasks/${item.id}`)).data)
		confirmDeleteId.value = null
	} catch (error: unknown) {
		actionError.value = authMessage(error) || 'Unable to delete checklist item.'
	} finally {
		deletingItemId.value = null
	}
}

watch(
	eventId,
	() => {
		showAddForm.value =
			false

		newItemLabel.value =
			''

		loadPreparation()
	},
)

onMounted(() => {
	loadPreparation()
})
// Close to live (review #12): other people's changes appear without a reload.
useLiveRefresh(() => loadPreparation(true), { interval: 60000 })
// ---------- Redesign helpers ----------
const PT_RING = 2 * Math.PI * 40
const ringReady = ref(false)
watch(() => isLoading.value, (loading) => { if (!loading) setTimeout(() => { ringReady.value = true }, 120) }, { immediate: true })
type TaskFilter = 'all' | 'todo' | 'done' | 'review'
const taskFilter = ref<TaskFilter>('all')
const quickInput = ref<HTMLInputElement | null>(null)
function needsReview(item: PreparationItem) { return item.is_completed && item.review_status === 'pending' && !!item.completed_by }
const reviewCount = computed(() => checklist.value.filter(needsReview).length)
const taskFilters = computed(() => [
	{ value: 'all' as const, label: 'All', count: checklist.value.length },
	{ value: 'todo' as const, label: 'To do', count: checklist.value.filter(i => !i.is_completed).length },
	{ value: 'done' as const, label: 'Done', count: checklist.value.filter(i => i.is_completed).length },
	{ value: 'review' as const, label: 'Review', count: reviewCount.value },
])
const filterIndex = computed(() => Math.max(0, taskFilters.value.findIndex(f => f.value === taskFilter.value)))
const visibleTasks = computed(() => checklist.value.filter((i) => {
	if (taskFilter.value === 'todo') return !i.is_completed
	if (taskFilter.value === 'done') return i.is_completed
	if (taskFilter.value === 'review') return needsReview(i)
	return true
}))
// Their event position, else their role in the organization (as in the "Add team member" list).
function memberRole(m: { position?: string | null }) {
	const orgId = (m as unknown as { organization_member_id?: string }).organization_member_id
	return m.position || orgMembers.value.find(o => o.id === orgId)?.role || 'Team member'
}
const assigneeOptions = computed(() => [
	{ value: null, label: 'Unassigned' },
	...teamMembers.value.map(m => ({ value: m.id, label: `${memberName(m)} · ${memberRole(m)}` })),
	// Teammates not on the event yet: picking one adds them, then assigns (#13).
	...availableMembers.value.map(m => ({ value: `org:${m.id}` as unknown as number, label: `${m.name} · ${m.role} (adds to event)` })),
])
function openFor(member: TeamMember) { return checklist.value.filter(i => !i.is_completed && i.assigned_team_member_id === member.id).length }
function initials(name: string) { const w = name.split(/\s+/).filter(Boolean); return ((w[0]?.[0] ?? '') + (w.length > 1 ? w[w.length - 1]?.[0] ?? '' : '')).toUpperCase() || '?' }
async function quickAdd() {
	if (!newItemLabel.value.trim() || isAddingItem.value) return
	showAddForm.value = true
	await addChecklistItem()
	nextTick(() => quickInput.value?.focus())
}

async function confirmRemove(item: PreparationItem) {
	if (await confirmDialog(`Remove "${item.label}" from the checklist?`, { confirmLabel: 'Remove' })) await removeChecklistItem(item)
}
</script>

<style scoped>
.pt-field { @apply h-11 w-full rounded-xl border border-gray-200 bg-white px-3.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10; }
.pt-btn { @apply inline-flex h-11 items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50; }
.pt-btn-primary { @apply inline-flex h-11 items-center gap-2 rounded-xl bg-primary-700 px-4 text-sm font-semibold text-white transition hover:bg-primary-800 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50; }
.pt-chip { @apply inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white py-0.5 pl-0.5 pr-2.5 text-xs font-medium text-gray-700; }
.pt-avatar { @apply inline-flex shrink-0 items-center justify-center rounded-full bg-primary-700 font-bold text-white; }
.pt-review { @apply inline-flex h-8 items-center gap-1 rounded-lg border border-gray-200 bg-white px-2.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-50; }
.pt-review.is-ok { @apply border-primary-700 bg-primary-700 text-white hover:bg-primary-800; }

/* Check circle */
.pt-check { @apply mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 text-white transition hover:border-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600; }
.pt-check.is-done { @apply border-primary-700 bg-primary-700; animation: pt-pop 0.35s cubic-bezier(0.3, 1.6, 0.5, 1); }
.pt-tick { stroke-dasharray: 16; stroke-dashoffset: 16; animation: pt-draw 0.3s 0.05s ease-out forwards; }
@keyframes pt-pop { 0% { transform: scale(0.7); } 100% { transform: scale(1); } }
@keyframes pt-draw { to { stroke-dashoffset: 0; } }

/* Segmented filter */
.pt-seg { position: relative; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); border-radius: 0.75rem; background: #f3f4f6; padding: 0.25rem; }
.pt-seg > button { position: relative; z-index: 1; height: 2.25rem; padding: 0 0.7rem; border-radius: 0.5rem; font-size: 0.8rem; font-weight: 600; color: #6b7280; white-space: nowrap; transition: color 0.2s; }
.pt-seg > button.is-on { color: #1c4f5c; }
.pt-seg-thumb { position: absolute; top: 0.25rem; bottom: 0.25rem; left: 0.25rem; width: calc((100% - 0.5rem) / 4); border-radius: 0.5rem; background: white; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08); transition: transform 0.3s cubic-bezier(0.3, 1.3, 0.5, 1); }

.pt-dots { position: absolute; inset: 0; pointer-events: none; background-image: radial-gradient(rgba(255, 255, 255, 0.14) 1px, transparent 1.3px); background-size: 16px 16px; mask-image: linear-gradient(105deg, transparent 25%, black 80%); }
.pt-glow { position: absolute; right: -4rem; top: -5rem; width: 14rem; height: 14rem; border-radius: 9999px; background: rgba(255, 255, 255, 0.1); filter: blur(48px); pointer-events: none; animation: pt-drift 9s ease-in-out infinite; }
@keyframes pt-drift { 50% { transform: translate(-24px, 18px); } }

.pt-item-enter-active, .pt-item-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.pt-item-enter-from { opacity: 0; transform: translateY(-6px); }
.pt-item-leave-to { opacity: 0; transform: translateX(12px); }
.pt-item-leave-active { position: absolute; left: 0; right: 0; }
.pt-item-move { transition: transform 0.3s ease; }
.pt-fade-enter-active, .pt-fade-leave-active { transition: opacity 0.2s ease; }
.pt-fade-enter-from, .pt-fade-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
	.pt-check.is-done, .pt-tick, .pt-glow { animation: none; stroke-dashoffset: 0; }
	.pt-item-enter-active, .pt-item-leave-active, .pt-item-move, .pt-seg-thumb { transition: none; }
}
</style>
