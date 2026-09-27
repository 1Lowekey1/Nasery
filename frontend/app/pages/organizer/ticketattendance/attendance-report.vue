<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />
		<div v-if="!eventId" class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-10 text-center text-sm text-gray-500">Choose an event above.</div>
		<div v-else-if="!report && !error" class="py-12 text-center text-sm text-gray-500">Building the report…</div>

		<template v-if="report">
			<div class="mb-5 flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-6">
				<div>
					<h2 class="text-lg font-bold text-gray-900">Post-event attendance report</h2>
					<p class="mt-1 text-sm text-gray-500">{{ report.event.sessions.map(s => s.when).join(' · ') }}</p>
					<span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="report.final ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-800'">
						{{ report.final ? 'Final: the event is completed' : 'Preliminary: numbers update until the event is completed' }}
					</span>
				</div>
				<div class="flex gap-2">
					<button type="button" :disabled="busy" class="flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="download('pdf')">
						<IconBase name="download" class="h-4 w-4" /> PDF
					</button>
					<button type="button" :disabled="busy" class="flex items-center gap-2 rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold hover:bg-gray-50 disabled:opacity-50" @click="download('csv')">
						<IconBase name="download" class="h-4 w-4" /> CSV
					</button>
				</div>
			</div>

			<ReportsReportView :report="report" />

			<!-- Review and share with the client (paper) -->
			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h3 class="text-base font-bold text-gray-900">Share with the client</h3>
				<p v-if="!report.can.has_client" class="mt-1 text-sm text-gray-500">This event wasn't created from a client's inquiry, so there's no client to send the report to. Download the PDF instead.</p>
				<template v-else>
					<p class="mt-1 text-sm text-gray-500">Review the report above, then send the client a copy. They get exactly these numbers, even if check-ins are corrected later; share again to send an update.</p>
					<p v-if="!report.final" class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">You can share once the event is marked completed (Event Management → event details).</p>
					<form v-else-if="report.can.share" class="mt-4 space-y-3" @submit.prevent="share">
						<div>
							<FormsLabel text="Note to the client (optional)" />
							<textarea v-model="note" rows="3" maxlength="2000" class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm" placeholder="e.g. Thank you for trusting us! Most guests arrived before 5 PM." />
						</div>
						<label v-if="report.can.revenue" class="flex items-center gap-2 text-sm text-gray-700"><input v-model="includeRevenue" type="checkbox"> Include ticket sales and revenue in the client's copy</label>
						<p class="text-xs text-gray-500">{{ report.event.visibility === 'private' ? "The guest list is included: they're the client's own guests." : 'Public event: the client gets the numbers, not the ticket buyers\' names.' }}</p>
						<button type="submit" :disabled="busy" class="rounded-xl bg-primary-700 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">
							{{ report.shares.length ? `Share version ${report.shares[0]!.version + 1}` : 'Approve and share' }}
						</button>
					</form>
					<p v-else class="mt-3 text-sm text-gray-500">Only someone who can edit the event can share the report.</p>

					<ul v-if="report.shares.length" class="mt-5 divide-y divide-gray-100 border-t border-gray-100 text-sm">
						<li v-for="s in report.shares" :key="s.version" class="py-2.5">
							<span class="font-semibold text-gray-900">Version {{ s.version }}</span>
							<span class="text-gray-500"> · shared {{ new Date(s.shared_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) }}<template v-if="s.shared_by"> by {{ s.shared_by }}</template>{{ s.includes_revenue ? ' · with revenue' : '' }}</span>
							<p v-if="s.note" class="mt-0.5 text-gray-600">“{{ s.note }}”</p>
						</li>
					</ul>
				</template>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import { saveBlob, type OrganizerReport } from '~/composables/useReports'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useReports()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const report = ref<OrganizerReport | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const note = ref('')
const includeRevenue = ref(false)

async function orgId() {
	return (organization.value ?? await fetchOrganization())!.id
}

async function load() {
	if (!eventId.value) return
	error.value = ''
	try {
		report.value = await api.report(await orgId(), eventId.value)
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function download(kind: 'pdf' | 'csv') {
	busy.value = true
	try {
		const blob = kind === 'pdf' ? await api.pdf(await orgId(), eventId.value!) : await api.csv(await orgId(), eventId.value!)
		const slug = report.value!.event.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')
		saveBlob(blob, kind === 'pdf' ? `${slug}-attendance-report.pdf` : `${slug}-attendance.csv`)
	} catch (e: any) {
		// File downloads come back as blobs, so the plan message isn't readable here (B18).
		error.value = (e?.status ?? e?.statusCode) === 402
			? 'CSV exports are part of the Starter and Pro plans. The PDF stays free. See Plan & Billing to upgrade.'
			: authMessage(e)
	} finally {
		busy.value = false
	}
}

async function share() {
	if (!confirm('Send this report to the client? They will be notified.')) return
	busy.value = true
	error.value = ''
	try {
		const v = await api.share(await orgId(), eventId.value!, { note: note.value.trim() || null, include_revenue: includeRevenue.value })
		notice.value = `Version ${v.version} shared with the client.`
		note.value = ''
		await load()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

watch(eventId, () => {
	report.value = null
	load()
})
onMounted(load)
</script>
