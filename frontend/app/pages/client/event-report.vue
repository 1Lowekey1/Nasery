<template>
	<div class="mx-auto max-w-5xl">
		<NuxtLink :to="`/client/event-details?event=${eventId}`" class="text-sm font-semibold text-primary-700">← Your events</NuxtLink>
		<Alert v-if="error" type="danger" :text="error" class="mt-3" />
		<div v-if="loading" class="py-16 text-center text-sm text-gray-500">Loading the report…</div>

		<div v-else-if="data && !data.shared" class="mt-4 rounded-2xl border border-gray-200 bg-white px-6 py-16 text-center">
			<h1 class="text-lg font-bold text-gray-900">No report yet</h1>
			<p class="mt-1 text-sm text-gray-500">Your organizer shares the attendance report after the event. You'll get a notification when it's ready.</p>
		</div>

		<template v-else-if="data?.shared">
			<div class="mt-3 mb-5 flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-gray-200 bg-white p-6">
				<div>
					<h1 class="text-2xl font-extrabold text-gray-900">{{ data.shared.report.event.name }}</h1>
					<p class="text-sm text-gray-500">Attendance report from {{ data.shared.report.event.organizer }} · {{ data.shared.report.event.sessions.map(s => s.when).join(' · ') }}</p>
					<p class="mt-1 text-xs text-gray-400">Version {{ data.shared.version }}, shared {{ new Date(data.shared.shared_at).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) }}</p>
				</div>
				<div class="flex items-center gap-2">
					<select v-if="data.versions.length > 1" v-model.number="version" aria-label="Report version" class="rounded-xl border border-gray-200 px-3 py-2 text-sm" @change="load">
						<option v-for="v in data.versions" :key="v.version" :value="v.version">Version {{ v.version }}{{ v.version === data.versions[0]!.version ? ' (latest)' : '' }}</option>
					</select>
					<button type="button" :disabled="busy" class="flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="downloadPdf">
						<IconBase name="download" class="h-4 w-4" /> Download PDF
					</button>
				</div>
			</div>
			<blockquote v-if="data.shared.note" class="mb-5 whitespace-pre-line rounded-2xl border-l-4 border-primary-700 bg-white p-5 text-sm text-gray-700">
				<p class="mb-1 text-xs font-bold uppercase tracking-wide text-gray-400">Note from your organizer</p>{{ data.shared.note }}
			</blockquote>
			<ReportsReportView :report="data.shared.report" />
		</template>
	</div>
</template>

<script setup lang="ts">
import { saveBlob, type SharedReport } from '~/composables/useReports'

definePageMeta({ layout: 'client' })
useSeoMeta({ title: 'Event report | NaSeRy', robots: 'noindex' })

const route = useRoute()
const api = useReports()
const eventId = computed(() => String(route.query.event ?? ''))
const data = ref<SharedReport | null>(null)
const version = ref<number | undefined>(undefined)
const loading = ref(true)
const busy = ref(false)
const error = ref('')

async function load() {
	error.value = ''
	try {
		data.value = await api.clientReport(eventId.value, version.value)
		version.value = data.value.shared?.version
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

async function downloadPdf() {
	busy.value = true
	try {
		const r = data.value!.shared!
		saveBlob(await api.clientPdf(eventId.value, r.version), `${r.report.event.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}-report-v${r.version}.pdf`)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

onMounted(load)
</script>
