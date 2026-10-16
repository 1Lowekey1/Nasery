<template>
	<div class="mx-auto max-w-4xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Team</h1>
		<p class="mt-1 mb-6 text-gray-500">Invite teammates, give them a role, and add them to the events they work on.</p>

		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />
		<div v-if="!team && !error" class="py-16 text-center text-sm text-gray-500">Loading team…</div>

		<template v-if="team">
			<section v-if="team.can_manage" class="mb-6 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Invite a teammate</h2>
				<p class="mt-1 text-sm text-gray-500">NaSeRy e-mails them a link to join. New to NaSeRy? The link creates their account, so they only choose a password. Already have a business account? They accept after signing in.</p>
				<form class="mt-4 space-y-2" novalidate @submit.prevent="invite">
					<div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
						<div data-field="firstName"><FormsTextField v-model="inviteForm.firstName" placeholder="First name" aria-label="Teammate first name" :error="inviteCheck.err('firstName')" /></div>
						<div data-field="lastName"><FormsTextField v-model="inviteForm.lastName" placeholder="Last name" aria-label="Teammate last name" :error="inviteCheck.err('lastName')" /></div>
					</div>
					<div class="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_220px_auto]">
						<div data-field="email"><FormsTextField v-model="inviteForm.email" type="email" placeholder="teammate@example.com" aria-label="Teammate email" :error="inviteCheck.err('email')" /></div>
						<RoleSelect v-model="inviteForm.role" :team="team" />
						<button type="submit" :disabled="busy" class="h-fit rounded-lg bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Send invite</button>
					</div>
				</form>
				<p v-if="selectedRoleSummary" class="mt-2 text-xs text-gray-500">{{ selectedRoleSummary }}</p>
			</section>

			<section class="mb-6 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-3 text-base font-bold text-gray-900">Members</h2>
				<ul class="divide-y divide-gray-100">
					<li v-for="m in team.members" :key="m.id" class="flex flex-wrap items-center gap-3 py-3 text-sm">
						<div class="min-w-0 flex-1">
							<p class="font-semibold text-gray-900" :class="m.status === 'deactivated' ? 'text-gray-400 line-through' : ''">{{ m.name }}</p>
							<p class="text-xs text-gray-500">{{ m.email }}</p>
						</div>
						<span class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
							:class="m.status === 'active' ? 'bg-green-50 text-green-700' : m.status === 'invited' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500'"
							:title="m.account_pending ? pendingHint : undefined">
							{{ m.status === 'invited' ? (m.account_pending ? 'Waiting for set-up' : 'Invited') : m.status === 'active' ? 'Active' : 'Removed' }}
						</span>
						<button v-if="team.can_manage && m.status === 'invited'" type="button" class="text-xs font-semibold text-primary-700" :disabled="busy"
							@click="act(() => api.resend(orgId, m.id), `Invitation sent again to ${m.email}.`, true)">Resend</button>
						<template v-if="team.can_manage && !m.is_owner && m.user_id !== myUserId && m.status !== 'deactivated'">
							<select :value="m.role_id ?? ''" class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs" :aria-label="`Role of ${m.name}`"
								:disabled="busy" @change="changeRole(m, ($event.target as HTMLSelectElement).value)">
								<option v-for="r in team.roles" :key="r.id" :value="r.id">{{ r.name }}</option>
							</select>
							<button type="button" class="text-xs font-semibold text-rose-700" :disabled="busy" @click="remove(m)">
								{{ m.status === 'invited' ? 'Cancel invite' : 'Remove' }}
							</button>
						</template>
						<template v-else-if="team.can_manage && m.status === 'deactivated'">
							<span class="text-xs text-gray-500">{{ m.role }}</span>
							<button type="button" class="text-xs font-semibold text-primary-700" :disabled="busy" @click="act(() => api.reactivate(orgId, m.id), 'Reactivated.', true)">Reactivate</button>
						</template>
						<span v-else class="text-xs text-gray-500">{{ m.role }}</span>
					</li>
				</ul>
			</section>

			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Roles</h2>
				<p class="mt-1 text-sm text-gray-500">A role is a set of permissions across your whole organization. Owners always have every permission. For event-only access, add people to an event's team instead.</p>
				<ul class="mt-3 divide-y divide-gray-100">
					<li v-for="r in team.roles" :key="r.id" class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
						<div>
							<p class="font-semibold text-gray-900">{{ r.name }} <span class="font-normal text-gray-400">· {{ r.members }} member{{ r.members === 1 ? '' : 's' }}</span></p>
							<p class="text-xs text-gray-500">{{ r.permissions.length ? r.permissions.map(permLabel).join(', ') : 'Only what they are given on assigned events' }}</p>
						</div>
						<button v-if="team.can_manage && !r.members" type="button" class="text-xs font-semibold text-rose-700" :disabled="busy"
							@click="act(() => api.removeRole(orgId, r.id), 'Role removed.', true)">Remove</button>
					</li>
					<li v-if="!team.roles.length" class="py-2 text-sm text-gray-400">No roles yet. Invite someone with one of the suggested roles, or create your own.</li>
				</ul>

				<form v-if="team.can_manage" class="mt-4 rounded-xl bg-gray-50 p-4" @submit.prevent="createRole">
					<h3 class="text-sm font-bold text-gray-900">Create a role</h3>
					<FormsTextField v-model="roleForm.name" placeholder="Role name, e.g. Stage Crew" class="mt-2" aria-label="Role name" />
					<fieldset class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2">
						<legend class="sr-only">Permissions</legend>
						<label v-for="p in team.permissions" :key="p.key" class="flex items-start gap-2 text-sm">
							<input v-model="roleForm.permissions" type="checkbox" :value="p.key" class="mt-1">
							<span>{{ p.description }} <span class="text-xs text-gray-400">({{ p.key }})</span></span>
						</label>
					</fieldset>
					<button type="submit" :disabled="busy || roleForm.name.trim().length < 2" class="mt-3 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Create role</button>
				</form>
			</section>
		</template>

		<!-- Remove a member: their open tasks go to someone else, or stay unassigned. -->
		<div v-if="removing" class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="remove-title" @click.self="removing = null">
			<div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl">
				<h2 id="remove-title" class="text-lg font-bold text-gray-900">Remove {{ removing.member.name }}?</h2>
				<p class="mt-1 text-sm text-gray-500">They lose access to {{ organization?.display_name }} right away and are taken off every event team. Their account stays theirs, and their finished work keeps their name. You can reactivate them later.</p>
				<div v-if="removing.loading" class="py-6 text-center text-sm text-gray-500">Checking their tasks…</div>
				<template v-else>
					<div v-if="removing.tasks.length" class="mt-4">
						<p class="text-sm font-semibold text-gray-800">{{ removing.tasks.length }} open {{ removing.tasks.length === 1 ? 'task' : 'tasks' }}</p>
						<ul class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-xl bg-gray-50 px-3 py-2 text-xs text-gray-600">
							<li v-for="t in removing.tasks" :key="t.id"><span class="font-semibold text-gray-800">{{ t.name }}</span> · {{ t.event.name }}<template v-if="t.due_at"> · due {{ new Date(t.due_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) }}</template></li>
						</ul>
						<label class="mt-3 block text-sm font-semibold text-gray-800" for="reassign">Hand them over to</label>
						<select id="reassign" v-model="removing.reassignTo" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm">
							<option value="">Nobody yet (mark them "Needs a new assignee")</option>
							<option v-for="o in reassignOptions" :key="o.id" :value="o.id">{{ o.name }} ({{ o.role }})</option>
						</select>
					</div>
					<p v-else class="mt-4 rounded-xl bg-gray-50 px-3 py-2 text-sm text-gray-600">They have no open tasks.</p>
					<div class="mt-6 flex justify-end gap-2">
						<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700" @click="removing = null">Keep them</button>
						<button type="button" class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-bold text-white disabled:opacity-60" :disabled="busy" @click="confirmRemove">Remove from team</button>
					</div>
				</template>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { OpenTask, RoleChoice, Team, TeamMember } from '~/composables/useTeam'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const { user } = useAuth()
const api = useTeam()

const team = ref<Team | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const inviteForm = reactive({ firstName: '', lastName: '', email: '', role: 'preset:coordinator' })
const inviteCheck = useFormCheck(() => ({
	firstName: inviteForm.firstName.trim() ? '' : 'Add their first name.',
	lastName: inviteForm.lastName.trim() ? '' : 'Add their last name.',
	email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inviteForm.email.trim()) ? '' : 'Enter their e-mail address.',
}))
const pendingHint = "They haven't opened the link in their e-mail yet (it works for 72 hours). Use Resend if they lost it."
const removing = ref<{ member: TeamMember, tasks: OpenTask[], loading: boolean, reassignTo: string } | null>(null)
const reassignOptions = computed(() => (team.value?.members ?? []).filter(m => m.status === 'active' && m.id !== removing.value?.member.id))
const roleForm = reactive({ name: '', permissions: [] as string[] })

const orgId = computed(() => organization.value?.id ?? '')
const myUserId = computed(() => user.value?.id)

function roleChoice(value: string): RoleChoice {
	return value.startsWith('preset:') ? { preset: value.slice(7) } : { role_id: value }
}

const selectedRoleSummary = computed(() => {
	if (!team.value) return ''
	const v = inviteForm.role
	const perms = v.startsWith('preset:')
		? team.value.presets.find(p => p.key === v.slice(7))?.permissions
		: team.value.roles.find(r => r.id === v)?.permissions
	return perms ? (perms.length ? `Can: ${perms.map(permLabel).join(', ')}.` : 'Works only on the events you add them to.') : ''
})

function permLabel(key: string) {
	return team.value?.permissions.find(p => p.key === key)?.description ?? key
}

async function act<T>(fn: () => Promise<T>, success: string, reload = false) {
	error.value = ''
	notice.value = ''
	busy.value = true
	try {
		await fn()
		notice.value = success
		if (reload) team.value = await api.team(orgId.value)
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function invite() {
	if (!inviteCheck.ok()) return
	const email = inviteForm.email.trim()
	await act(() => api.invite(orgId.value, email, roleChoice(inviteForm.role), { first_name: inviteForm.firstName.trim(), last_name: inviteForm.lastName.trim() }),
		`Invitation sent to ${email}. They join with the link in their e-mail.`, true)
	if (!error.value) {
		Object.assign(inviteForm, { firstName: '', lastName: '', email: '' })
		inviteCheck.reset()
	}
}

function changeRole(m: TeamMember, roleId: string) {
	act(() => api.changeRole(orgId.value, m.id, { role_id: roleId }), `${m.name}'s role changed.`, true)
}

async function remove(m: TeamMember) {
	if (m.status === 'invited') {
		if (await confirmDialog(`Cancel the invitation for ${m.name}?`, { confirmLabel: 'Cancel invitation' })) {
			act(() => api.remove(orgId.value, m.id), 'Invitation cancelled.', true)
		}
		return
	}
	removing.value = { member: m, tasks: [], loading: true, reassignTo: '' }
	try {
		removing.value.tasks = await api.openTasks(orgId.value, m.id)
		removing.value.loading = false
	} catch (e) {
		error.value = authMessage(e)
		removing.value = null
	}
}

async function confirmRemove() {
	const r = removing.value
	if (!r) return
	let message = `${r.member.name} was removed.`
	await act(async () => { message = (await api.remove(orgId.value, r.member.id, r.reassignTo || null)).message ?? message }, '', true)
	if (!error.value) notice.value = message
	removing.value = null
}

async function createRole() {
	await act(() => api.createRole(orgId.value, { name: roleForm.name.trim(), permissions: roleForm.permissions }), 'Role created.', true)
	if (!error.value) Object.assign(roleForm, { name: '', permissions: [] })
}

onMounted(async () => {
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) team.value = await api.team(org.id)
	} catch (e) {
		error.value = authMessage(e)
	}
})
</script>
