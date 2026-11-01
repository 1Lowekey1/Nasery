<template>
	<!-- Side card on the event page: who works this event, their job here and any extra rights -->
	<section class="rounded-2xl border border-gray-200 bg-white p-5">
		<div class="flex items-center justify-between gap-2">
			<p class="flex items-center gap-2 text-sm font-bold text-gray-950"><IconBase name="users" class="h-4 w-4 text-primary-700" />Event team</p>
			<span class="text-xs font-medium text-gray-400">{{ members.length }}</span>
		</div>
		<p class="mt-1 text-xs text-gray-500">People working on this event. Teammates without organization-wide access only see events they're on.</p>
		<Alert v-if="error" type="danger" :text="error" class="mt-3" />

		<ul class="mt-3 space-y-1">
			<li v-for="m in members" :key="m.id" class="flex items-start gap-3 rounded-xl px-2 py-2 hover:bg-gray-50">
				<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-[11px] font-bold text-primary-700" aria-hidden="true">{{ initialsOf(m.name) }}</span>
				<div class="min-w-0 flex-1">
					<p class="break-words text-sm font-semibold text-gray-900">{{ m.name }}<span v-if="m.label" class="font-normal text-gray-500"> · {{ m.label }}</span></p>
					<p class="text-xs text-gray-500">{{ m.permissions.length ? m.permissions.map(p => eventPermissionLabels[p] ?? p).join(', ') : 'Views the event, works on assigned tasks' }}</p>
				</div>
				<button v-if="canManage && editable" type="button" :aria-label="`Remove ${m.name} from this event`" title="Remove from event"
					class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600 disabled:opacity-40"
					:disabled="busy" @click="remove(m.id, m.name)">
					<IconBase name="x" class="h-4 w-4" />
				</button>
			</li>
			<li v-if="!members.length" class="px-2 py-2 text-sm text-gray-400">No one on this event yet.</li>
		</ul>

		<template v-if="canManage && editable">
			<button v-if="!adding && candidates.length" type="button" class="mt-3 w-full inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50" @click="adding = true">
				<IconBase name="user-plus" class="h-4 w-4" /> Add teammate
			</button>
			<form v-else-if="adding && candidates.length" class="mt-3 space-y-3 rounded-xl bg-gray-50 p-3" @submit.prevent="add">
				<select v-model="form.organization_member_id" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm" aria-label="Team member">
					<option value="" disabled>Choose a teammate</option>
					<option v-for="c in candidates" :key="c.id" :value="c.id">{{ c.name }} ({{ c.role }})</option>
				</select>
				<FormsTextField v-model="form.label" placeholder="Their job here, e.g. Stage manager" aria-label="Their job on this event" />
				<fieldset>
					<legend class="mb-1 text-xs font-semibold text-gray-500">Extra permissions on this event only</legend>
					<label v-for="(label, key) in eventPermissionLabels" :key="key" class="flex min-h-9 cursor-pointer items-center gap-2.5 text-sm text-gray-700">
						<input v-model="form.permissions" type="checkbox" :value="key" class="accent-[#285F6b]"> {{ label }}
					</label>
				</fieldset>
				<div class="flex gap-2">
					<button type="button" class="flex-1 inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50" @click="adding = false">Cancel</button>
					<button type="submit" :disabled="busy || !form.organization_member_id" class="flex-1 inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-primary-700 px-4 text-sm font-semibold text-white transition hover:bg-primary-800 disabled:opacity-50">Add to event</button>
				</div>
			</form>
			<p v-else-if="rosterLoaded" class="mt-3 text-xs text-gray-500">
				Everyone active on your team is on this event. <NuxtLink to="/organizer/team" class="font-semibold text-primary-700 hover:underline">Invite teammates</NuxtLink>
			</p>
		</template>
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
const adding = ref(false)
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
	if (!error.value) {
		Object.assign(form, { organization_member_id: '', label: '', permissions: [] })
		adding.value = false
	}
}

function initialsOf(name: string) {
	return name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]!.toUpperCase()).join('')
}

async function remove(id: string, name: string) {
	if (await confirmDialog(`Remove ${name} from this event? Their tasks here are unassigned.`, { confirmLabel: 'Remove' })) run(() => api.removeFromEvent(props.organizationId, props.eventId, id))
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
