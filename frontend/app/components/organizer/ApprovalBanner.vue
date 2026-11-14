<script setup lang="ts">
import type { ApprovalHistoryEntry } from '~/composables/useOrganization'

const { organization, approval, resubmit } = useOrganization()

const lastDecision = ref<ApprovalHistoryEntry | null>(null)
const lastNote = ref<ApprovalHistoryEntry | null>(null)
const busy = ref(false)
const error = ref('')

const status = computed(() => organization.value?.approval_status)
const visible = computed(() => organization.value && !organization.value.is_operational)

async function loadReason() {
	if (!organization.value || organization.value.is_operational) return
	try {
		const state = await approval()
		lastDecision.value = state.history.find(h => h.kind === 'decision' && h.status === organization.value?.approval_status) ?? null
		lastNote.value = state.history.find(h => h.kind === 'note') ?? null
	} catch {
		lastDecision.value = null
	}
}

watch(status, loadReason, { immediate: true })

async function handleResubmit() {
	busy.value = true
	error.value = ''
	try {
		await resubmit()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div v-if="visible" class="mb-5 rounded-2xl border p-4 text-sm"
		:class="status === 'rejected' || status === 'suspended' ? 'border-rose-200 bg-rose-50 text-rose-900' : 'border-amber-200 bg-amber-50 text-amber-900'">
		<template v-if="status === 'pending' || status === 'under_review'">
			<p class="font-semibold">Your organizer application is {{ status === 'pending' ? 'waiting for review' : 'being reviewed' }}.</p>
			<p class="mt-1">
				You can set up your profile and services now. Bidding, events and ticket sales unlock once NaSeRy approves
				your organization. Upload your business permit and other documents so our team can review them.
			</p>
			<p v-if="lastNote" class="mt-2 rounded-lg bg-white/70 px-3 py-2"><span class="font-semibold">Note from NaSeRy:</span> {{ lastNote.decision_reason }}</p>
			<NuxtLink to="/organizer/business-profile#documents" class="mt-3 inline-block rounded-lg bg-amber-700 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-800">
				Upload documents
			</NuxtLink>
		</template>
		<template v-else-if="status === 'rejected'">
			<p class="font-semibold">Your application needs changes.</p>
			<p v-if="lastDecision?.decision_reason" class="mt-1">Reviewer's note: {{ lastDecision.decision_reason }}</p>
			<p class="mt-1">Update your profile or <NuxtLink to="/organizer/business-profile#documents" class="font-semibold underline">your documents</NuxtLink>, then send it back for review.</p>
			<button type="button" :disabled="busy"
				class="mt-3 rounded-lg bg-rose-700 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-800 disabled:opacity-60"
				@click="handleResubmit">
				{{ busy ? 'Sending…' : 'Resubmit for review' }}
			</button>
			<p v-if="error" class="mt-2 text-xs">{{ error }}</p>
		</template>
		<template v-else-if="status === 'suspended'">
			<p class="font-semibold">Your organization is suspended.</p>
			<p v-if="organization?.suspended_reason" class="mt-1">Reason: {{ organization.suspended_reason }}</p>
			<p class="mt-1">Contact NaSeRy support to resolve this.</p>
		</template>
	</div>
</template>
