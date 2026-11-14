<template>
	<section class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-5">
		<div class="min-w-0">
			<p class="text-sm font-bold text-gray-950">Event summary</p>
			<p class="mt-0.5 text-sm text-gray-500">
				{{ lastShare ? `Shared with the client on ${formatDate(lastShare.shared_at)}.` : 'The full rundown of this event as a PDF: overview, attendance, program, preparation, pricing and the client’s review.' }}
			</p>
			<p v-if="error" class="mt-1 text-sm font-semibold text-red-600" role="alert">{{ error }}</p>
		</div>
		<div class="flex shrink-0 flex-wrap gap-2">
			<button v-if="report?.can.share && report.can.has_client" type="button" :disabled="busy" :aria-expanded="sharing"
				class="rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60" @click="sharing = !sharing">
				{{ lastShare ? 'Share again' : 'Share with client' }}
			</button>
			<button type="button" :disabled="busy" class="inline-flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-900 disabled:opacity-60" @click="download">
				<IconBase name="file-text" class="h-4 w-4" /> Download PDF
			</button>
		</div>

		<form v-if="sharing" class="w-full border-t border-gray-100 pt-4" @submit.prevent="share">
			<label class="block text-sm font-semibold text-gray-700" for="summary-note">Note to the client <span class="font-normal text-gray-400">(optional)</span></label>
			<textarea id="summary-note" v-model="note" rows="2" maxlength="1000"
				class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none" />
			<label v-if="report?.can.revenue" class="mt-2 flex items-center gap-2 text-sm text-gray-700">
				<input v-model="includeRevenue" type="checkbox" class="accent-[#285F6b]"> Include ticket revenue
			</label>
			<div class="mt-3 flex gap-2">
				<button type="submit" :disabled="busy" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900 disabled:opacity-60">Share</button>
				<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700" @click="sharing = false">Cancel</button>
			</div>
		</form>
	</section>
</template>

<script setup lang="ts">
import type { OrganizerReport } from '~/composables/useReports'

// Shown on a completed event: the post-event PDF, and sending a copy to the client.
const props = defineProps<{ organizationId: string, eventId: string }>()
const api = useReports()
const report = ref<OrganizerReport | null>(null)
const busy = ref(false)
const error = ref('')
const sharing = ref(false)
const note = ref('')
const includeRevenue = ref(false)
const lastShare = computed(() => report.value?.shares?.[0] ?? null)
const formatDate = (iso: string) => new Date(iso).toLocaleDateString('en-PH', { dateStyle: 'medium' })

async function load() {
	try {
		report.value = await api.report(props.organizationId, props.eventId)
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function download() {
	busy.value = true
	error.value = ''
	try {
		const name = (report.value?.event.name ?? 'event').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'event'
		saveBlob(await api.pdf(props.organizationId, props.eventId), `${name}-event-summary.pdf`)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function share() {
	busy.value = true
	error.value = ''
	try {
		await api.share(props.organizationId, props.eventId, { note: note.value.trim() || null, include_revenue: includeRevenue.value })
		sharing.value = false
		note.value = ''
		includeRevenue.value = false
		await load()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

onMounted(load)
</script>
