<template>
	<div class="space-y-6">
		<div v-if="isLoading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
			Loading preparation checklist...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else-if="event">
			<section class="relative overflow-hidden rounded-3xl bg-primary-700 px-6 py-7 text-white shadow-sm sm:px-8">
				<div class="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-white/10 blur-3xl" />
				<div class="relative">
					<p class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.16em] text-white/60">
						<span class="h-1.5 w-1.5 rounded-full bg-white/60" />
						Preparation tracking
					</p>
					<h2 class="mt-2 text-2xl font-black tracking-tight sm:text-3xl">{{ event.name }}</h2>
					<p class="mt-1 text-sm text-white/70">Track tasks, assignments, and readiness before the big day.</p>
				</div>
			</section>

			<div class="rounded-2xl border border-gray-200 bg-white p-6">
			<div class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-[#285F6b]/5 p-4">
				<div>
					<p class="text-sm font-bold text-[#285F6b]">Event Team</p>
					<p class="text-xs text-gray-500">
						{{ teamMembers.length }} active member{{ teamMembers.length === 1 ?
							'' : 's' }} available for assignment
					</p>
				</div>
				<button
					class="rounded-xl border border-[#285F6b]/20 bg-white px-4 py-2 text-sm font-bold text-[#285F6b]"
					@click="showMemberForm = !showMemberForm">
					{{ showMemberForm ? 'Close' : '+ Add Team Member' }}
				</button>
			</div>
			<div v-if="showMemberForm"
				class="mb-6 grid gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 sm:grid-cols-[1fr_auto]">
				<select v-model="memberForm.organization_member_id"
					class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm">
					<option value="">Choose someone from your team…</option>
					<option v-for="m in availableMembers" :key="m.id" :value="m.id">{{ m.name }} — {{ m.role }}</option>
				</select>
				<button class="rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-bold text-white disabled:opacity-50"
					:disabled="creatingMember || !memberForm.organization_member_id" @click="createTeamMember">
					{{ creatingMember ? 'Adding...' : 'Add to this event' }}
				</button>
				<p class="text-xs text-gray-500 sm:col-span-2">
					Only people on this event's team can be assigned tasks.
					<template v-if="!availableMembers.length">Everyone on your team is already here.</template>
					New person? <NuxtLink to="/organizer/team" class="font-semibold text-[#285F6b]">Invite them from Team</NuxtLink>;
					they choose their own password.
				</p>
			</div>
			<div class="mb-1 flex items-center justify-between">
				<span class="text-base font-bold text-gray-900">
					Overall Event Readiness
				</span>

				<span class="text-2xl font-extrabold text-gray-900">
					{{ readinessPercent }}%
				</span>
			</div>

			<div class="relative mb-6 h-8 w-full overflow-hidden rounded-full bg-gray-100">
				<div class="h-full rounded-full bg-primary-700 transition-all duration-200" :style="{
					width:
						readinessPercent +
						'%',
				}" />

				<span class="absolute inset-0 flex items-center justify-center text-sm font-semibold" :class="readinessPercent >= 45
					? 'text-white'
					: 'text-gray-700'
					">
					{{ completedCount }}/{{ checklist.length }}
					completed
				</span>
			</div>

			<div v-if="checklist.length" class="space-y-3">
				<template v-for="item in checklist" :key="item.id">
				<div
					class="flex w-full items-start gap-3 rounded-xl border border-gray-200 px-4 py-3.5 transition hover:bg-gray-50">
					<button type="button" class="flex flex-1 items-center gap-3 text-left" :disabled="updatingItemId === item.id
						" @click="toggleItem(item)">
						<IconBase v-if="item.is_completed" name="check-circle"
							class="h-5 w-5 shrink-0 text-green-600" />

						<span v-else class="h-5 w-5 shrink-0 rounded-full border-2 border-gray-300" />

						<span class="flex-1 text-sm font-medium" :class="item.is_completed
							? 'text-gray-400 line-through'
							: 'text-gray-900'
							">
							{{ item.label }}
							<span v-if="item.assigned_team_member"
								class="mt-1 block text-xs font-semibold text-[#285F6b]">
								Assigned to {{ memberName(item.assigned_team_member) }}
							</span>
							<span v-if="item.completed_by" class="mt-1 block text-xs text-gray-500">
								Completed by {{ userName(item.completed_by) }}
								<template v-if="item.completion_note"> —
									{{
										item.completion_note
									}}
								</template>
							</span>
						</span>

						<IconBase v-if="
							updatingItemId ===
							item.id
						" name="refresh-cw" class="h-4 w-4 animate-spin text-gray-400" />
					</button>
					<div v-if="item.is_completed && item.review_status === 'pending'" class="flex gap-1">
						<button class="rounded-lg bg-emerald-50 px-2.5 py-1.5 text-xs font-bold text-emerald-700"
							@click="reviewItem(item, 'verified')">
							Verify
						</button>
						<button class="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-bold text-red-700"
							@click="startChanges(item)">
							Request changes
						</button>
					</div>
					<span v-else-if="item.is_completed || item.review_status === 'changes_requested'" class="rounded-full px-2.5 py-1 text-xs font-bold"
						:class="item.review_status === 'verified' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'">
						{{
							item.review_status === 'verified' ? 'Verified' : 'Changes requested'
						}}
					</span>

					<select v-if="teamMembers.length" :value="item.assigned_team_member_id ?? ''"
						class="max-w-40 rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-xs text-gray-700"
						:disabled="updatingItemId === item.id" :aria-label="`Assign ${item.label}`"
						@change="assignItem(item, ($event.target as HTMLSelectElement).value)">
						<option value="">Unassigned</option>
						<option v-for="member in teamMembers" :key="member.id" :value="member.id">{{ memberName(member) }}</option>
					</select>

					<template v-if="confirmDeleteId === item.id">
						<button type="button" class="rounded-lg bg-red-600 px-2.5 py-1.5 text-xs font-bold text-white disabled:opacity-50"
							:disabled="deletingItemId === item.id" @click="removeChecklistItem(item)">
							{{ deletingItemId === item.id ? 'Removing…' : 'Remove' }}
						</button>
						<button type="button" class="px-1 text-xs font-semibold text-gray-500" @click="confirmDeleteId = null">Cancel</button>
					</template>
					<button v-else type="button"
						class="rounded-lg p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600"
						:aria-label="`Remove ${item.label}`" @click="confirmDeleteId = item.id">
						<IconBase name="trash" class="h-4 w-4" />
					</button>
				</div>
				<form v-if="changesFor === item.id" class="-mt-1 mb-2 flex flex-wrap items-center gap-2 rounded-xl bg-red-50/60 px-3 py-2.5"
					@submit.prevent="reviewItem(item, 'changes_requested')">
					<input v-model="changesNote" required minlength="3" maxlength="1000" placeholder="What should be changed? (sent to the assignee)"
						class="min-w-60 flex-1 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-sm">
					<button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-bold text-white disabled:opacity-50" :disabled="changesNote.trim().length < 3">Send back</button>
					<button type="button" class="text-xs font-semibold text-gray-500" @click="changesFor = null">Cancel</button>
				</form>
				</template>
			</div>

			<div v-else class="rounded-xl border border-dashed border-gray-300 p-8 text-center">
				<p class="text-sm text-gray-500">
					No preparation items yet.
				</p>
			</div>

			<div class="mt-5">
				<button v-if="!showAddForm" type="button"
					class="flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-gray-800"
					@click="
						showAddForm =
						true
						">
					<IconBase name="plus" class="h-4 w-4" />

					Add Checklist Item
				</button>

				<div v-else class="rounded-xl border border-gray-200 bg-gray-50 p-4">
					<label class="mb-1.5 block text-sm font-semibold text-gray-700">
						Checklist Item
					</label>

					<input v-model="newItemLabel" type="text" placeholder="e.g. Final supplier confirmation"
						class="w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15"
						:disabled="isAddingItem" @keyup.enter="
							addChecklistItem
						" />

					<FormsSelect v-model="newItemAssignee" class="mt-3"
						:options="[{ value: null, label: 'Unassigned' }, ...teamMembers.map(member => ({ value: member.id, label: `${memberName(member)} — ${member.position || 'Team member'}` }))]"
						placeholder="Select assignee" />

					<div class="mt-3 flex justify-end gap-2">
						<button type="button"
							class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
							:disabled="isAddingItem" @click="cancelAddItem">
							Cancel
						</button>

						<button type="button"
							class="flex items-center gap-2 rounded-lg bg-[#285F6b] px-3 py-2 text-sm font-semibold text-white hover:bg-[#1f4a54] disabled:cursor-not-allowed disabled:opacity-60"
							:disabled="isAddingItem ||
								!newItemLabel.trim()
								" @click="
									addChecklistItem
								">
							<IconBase v-if="isAddingItem" name="refresh-cw" class="h-4 w-4 animate-spin" />

							{{
								isAddingItem
									? 'Adding...'
									: 'Add Item'
							}}
						</button>
					</div>
				</div>
			</div>
			</div>
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
	team: { id: string, organization_member_id: string, name: string, email: string | null, label: string | null }[]
	checklists: { id: string, name: string, tasks: {
		id: string, name: string, status: string, assignee_ids: string[], completion_note: string | null,
		review_status: 'pending' | 'verified' | 'changes_requested' | null, review_note: string | null,
	}[] }[]
}

/** The API's preparation (checklists of tasks + the event team), as this page's flat list. */
function applyPreparation(prep: ApiPreparation) {
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

async function loadPreparation() {
	errorMessage.value = ''
	if (!eventId.value) {
		await navigateTo('/organizer/eventmanagement/all-events', { replace: true })
		return
	}
	isLoading.value = true
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
	errorMessage.value = ''
	try {
		const { data } = await useApi().patch<ApiPreparation>(`${tasksBase()}/tasks/${item.id}`, { status: item.is_completed ? 'pending' : 'done' })
		applyPreparation(data)
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to update checklist item.'
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
	errorMessage.value = ''
	try {
		let prep = await useOrganizerEvents().preparation(orgId, eventId.value) as unknown as ApiPreparation
		if (!prep.checklists.length) {
			prep = (await useApi().post<ApiPreparation>(`${tasksBase()}/checklists`, { name: 'Preparation' })).data
		}
		prep = (await useApi().post<ApiPreparation>(`${tasksBase()}/checklists/${prep.checklists[0]!.id}/tasks`, { name: label })).data
		if (newItemAssignee.value) {
			const created = [...prep.checklists[0]!.tasks].reverse().find(t => t.name === label) ?? prep.checklists[0]!.tasks.at(-1)!
			// Assigning returns only the ids; reload the checklist.
			await useApi().put(`${tasksBase()}/tasks/${created.id}/assignees`, { event_member_ids: [String(newItemAssignee.value)] })
			prep = await useOrganizerEvents().preparation(orgId, String(eventId.value)) as unknown as ApiPreparation
		}
		applyPreparation(prep)
		newItemLabel.value = ''
		newItemAssignee.value = null
		showAddForm.value = false
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to add checklist item.'
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
async function createTeamMember() {
	creatingMember.value = true
	errorMessage.value = ''
	try {
		await useTeam().addToEvent(orgId, String(eventId.value), { organization_member_id: memberForm.organization_member_id, label: null, permissions: [] })
		applyPreparation(await useOrganizerEvents().preparation(orgId, String(eventId.value)) as unknown as ApiPreparation)
		memberForm.organization_member_id = ''
		showMemberForm.value = false
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to add this person to the event.'
	} finally {
		creatingMember.value = false
	}
}

const changesFor = ref<number | null>(null)
const changesNote = ref('')
const confirmDeleteId = ref<number | null>(null)

function startChanges(item: PreparationItem) {
	changesFor.value = item.id
	changesNote.value = ''
}

/** Assign (or unassign) someone from this event's team. */
async function assignItem(item: PreparationItem, memberId: string) {
	updatingItemId.value = item.id
	errorMessage.value = ''
	try {
		await useApi().put(`${tasksBase()}/tasks/${item.id}/assignees`, { event_member_ids: memberId ? [memberId] : [] })
		applyPreparation(await useOrganizerEvents().preparation(orgId, String(eventId.value)) as unknown as ApiPreparation)
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to assign this item.'
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
		errorMessage.value = authMessage(error) || 'Unable to review completed work.'
	}
}

async function removeChecklistItem(item: PreparationItem) {
	if (!eventId.value || deletingItemId.value !== null) return
	deletingItemId.value = item.id
	errorMessage.value = ''
	try {
		applyPreparation((await useApi().delete<ApiPreparation>(`${tasksBase()}/tasks/${item.id}`)).data)
		confirmDeleteId.value = null
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to delete checklist item.'
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
</script>
