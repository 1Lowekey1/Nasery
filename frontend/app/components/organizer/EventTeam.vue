<template>
	<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
		<h3 class="text-base font-bold text-gray-900">Event team</h3>
		<p class="text-xs text-gray-500">People working on this event. They can be assigned its tasks; teammates without organization-wide access only see events they're on.</p>
		<Alert v-if="error" type="danger" :text="error" class="mt-3" />

		<ul class="mt-3 divide-y divide-gray-100">
			<li v-for="m in members" :key="m.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-sm">
				<div>
					<p class="font-semibold text-gray-900">{{ m.name }}<span v-if="m.label" class="font-normal text-gray-500"> · {{ m.label }}</span></p>
					<p class="text-xs text-gray-500">{{ m.permissions.length ? m.permissions.map(p => eventPermissionLabels[p] ?? p).join(', ') : 'Can view the event and work on assigned tasks' }}</p>
				</div>
				<button v-if="canManage && editable" type="button" class="text-xs font-semibold text-rose-700" :disabled="busy" @click="remove(m.id, m.name)">Remove</button>
			</li>
			<li v-if="!members.length" class="py-2 text-sm text-gray-400">No one assigned yet.</li>
		</ul>

		<form v-if="canManage && editable && candidates.length" class="mt-4 rounded-xl bg-gray-50 p-4" @submit.prevent="add">
			<div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
				<select v-model="form.organization_member_id" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Team member">
					<option value="" disabled>Choose a teammate</option>
					<option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.name }} ({{ c.role }})</option>
				</select>
				<FormsTextField v-model="form.label" placeholder="Their job here, e.g. Stage manager" aria-label="Label" />
			</div>
			<fieldset class="mt-3 grid grid-cols-1 gap-1 sm:grid-cols-2">
				<legend class="mb-1 text-xs font-semibold text-gray-500">Extra permissions on this event only</legend>
				<label v-for="(label, key) in eventPermissionLabels" :key="key" class="flex items-center gap-2 text-sm">
					<input v-model="form.permissions" type="checkbox" :value="key"> {{ label }}
				</label>
			</fieldset>
			<button type="submit" :disabled="busy || !form.organization_member_id" class="mt-3 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add to event</button>
		</form>
		<p v-else-if="canManage && editable && rosterLoaded" class="mt-3 text-xs text-gray-500">
			Everyone active on your team is already here. <NuxtLink to="/organizer/team" class="font-semibold text-primary-700">Invite teammates</NuxtLink>
		</p>
	</section>
</template>

<script setup lang="ts">
import type { EventTeamMember, TeamMember } from '~/composables/useTeam'

const props = defineProps<{ organizationId: string, eventId: string, editable: boolean }>()

const { organization } = useOrganization()
const api = useTeam()

const members = ref<EventTeamMember[]>([])
const roster = ref<TeamMember[]>([])
const rosterLoaded = ref(false)
const busy = ref(false)
const error = ref('')
const form = reactive({ organization_member_id: '', label: '', permissions: [] as string[] })

const canManage = computed(() => {
	const m = organization.value?.my_membership
	return Boolean(m && (m.is_owner || m.permissions.includes('staff.manage')))
})
// Owners already see and do everything, so they're not offered.
const candidates = computed(() => roster.value.filter(r => r.status === 'active' && !r.is_owner && !members.value.some(m => m.organization_member_id === r.id)))

async function run(fn: () => Promise<EventTeamMember[]>) {
	error.value = ''
	busy.value = true
	try {
		members.value = await fn()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function add() {
	await run(() => api.addToEvent(props.organizationId, props.eventId, {
		organization_member_id: form.organization_member_id, label: form.label.trim() || null, permissions: form.permissions,
	}))
	if (!error.value) Object.assign(form, { organization_member_id: '', label: '', permissions: [] })
}

function remove(id: string, name: string) {
	if (confirm(`Remove ${name} from this event? Their tasks here are unassigned.`)) run(() => api.removeFromEvent(props.organizationId, props.eventId, id))
}

onMounted(async () => {
	await run(() => api.eventTeam(props.organizationId, props.eventId))
	if (canManage.value) {
		try {
			roster.value = (await api.team(props.organizationId)).members
		} catch {
			// Without the roster, the team list is still shown.
		} finally {
			rosterLoaded.value = true
		}
	}
})
</script>
