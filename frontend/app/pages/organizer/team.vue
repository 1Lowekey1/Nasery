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
				<p class="mt-1 text-sm text-gray-500">They need a NaSeRy business account. After signing in they'll see your invitation and can accept it.</p>
				<form class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_220px_auto]" @submit.prevent="invite">
					<FormsTextField v-model="inviteForm.email" type="email" placeholder="teammate@example.com" aria-label="Teammate email" />
					<RoleSelect v-model="inviteForm.role" :team="team" />
					<button type="submit" :disabled="busy || !inviteForm.email.trim()" class="rounded-lg bg-[#285F6b] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Send invite</button>
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
							:class="m.status === 'active' ? 'bg-green-50 text-green-700' : m.status === 'invited' ? 'bg-amber-50 text-amber-700' : 'bg-gray-100 text-gray-500'">
							{{ m.status === 'invited' ? 'Invited' : m.status === 'active' ? 'Active' : 'Deactivated' }}
						</span>
						<template v-if="team.can_manage && !m.is_owner && m.user_id !== myUserId && m.status !== 'deactivated'">
							<select :value="m.role_id ?? ''" class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs" :aria-label="`Role of ${m.name}`"
								:disabled="busy" @change="changeRole(m, ($event.target as HTMLSelectElement).value)">
								<option v-for="r in team.roles" :key="r.id" :value="r.id">{{ r.name }}</option>
							</select>
							<button type="button" class="text-xs font-semibold text-rose-700" :disabled="busy" @click="remove(m)">
								{{ m.status === 'invited' ? 'Cancel invite' : 'Deactivate' }}
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
	</div>
</template>

<script setup lang="ts">
import type { RoleChoice, Team, TeamMember } from '~/composables/useTeam'

definePageMeta({ layout: 'dashboard' })

const { organization, fetchOrganization } = useOrganization()
const { user } = useAuth()
const api = useTeam()

const team = ref<Team | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const inviteForm = reactive({ email: '', role: 'preset:coordinator' })
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
	await act(() => api.invite(orgId.value, inviteForm.email.trim(), roleChoice(inviteForm.role)), `Invitation sent to ${inviteForm.email.trim()}.`, true)
	if (!error.value) inviteForm.email = ''
}

function changeRole(m: TeamMember, roleId: string) {
	act(() => api.changeRole(orgId.value, m.id, { role_id: roleId }), `${m.name}'s role changed.`, true)
}

async function remove(m: TeamMember) {
	const what = m.status === 'invited' ? `Cancel the invitation for ${m.name}?` : `Deactivate ${m.name}? They lose access right away and are removed from event teams.`
	if (await confirmDialog(what, { confirmLabel: m.status === 'invited' ? 'Cancel invitation' : 'Deactivate' })) act(() => api.remove(orgId.value, m.id), m.status === 'invited' ? 'Invitation cancelled.' : `${m.name} was deactivated.`, true)
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
