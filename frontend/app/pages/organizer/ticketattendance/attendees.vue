<template>
	<div>
		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />

		<div v-if="!eventId" class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-10 text-center text-sm text-gray-500">Choose an event above.</div>

		<template v-else>
			<section v-if="canManage" class="mb-5 grid gap-5 lg:grid-cols-2">
				<form class="rounded-2xl border border-gray-200 bg-white p-5" @submit.prevent="addGuest">
					<h2 class="text-base font-bold text-gray-900">Add a guest</h2>
					<div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
						<FormsTextField v-model="guest.full_name" placeholder="Full name" aria-label="Guest name" class="sm:col-span-2" />
						<FormsTextField v-model="guest.email" type="email" placeholder="Email (optional)" aria-label="Guest email" />
						<FormsTextField v-model="guest.contact_number" placeholder="Contact (optional)" aria-label="Guest contact" />
						<select v-model="guest.ticket_type_id" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Tier">
							<option value="" disabled>Choose a tier</option>
							<option v-for="t in tiers" :key="t.id" :value="t.id">{{ t.name }} ({{ t.remaining }} left)</option>
						</select>
						<select v-model="guest.admission_type" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Admission">
							<option value="invited">Invited guest</option>
							<option value="complimentary">Complimentary</option>
						</select>
					</div>
					<label class="mt-3 flex items-center gap-2 text-sm text-gray-700"><input v-model="guest.send_email" type="checkbox" :disabled="!guest.email.trim()"> E-mail the QR ticket to the guest</label>
					<button type="submit" :disabled="busy || guest.full_name.trim().length < 2 || !guest.ticket_type_id"
						class="mt-3 rounded-lg bg-[#285F6b] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Add with QR ticket</button>
				</form>

				<form class="rounded-2xl border border-gray-200 bg-white p-5" @submit.prevent="importFile">
					<h2 class="text-base font-bold text-gray-900">Import a guest list</h2>
					<p class="mt-1 text-xs text-gray-500">CSV with a header row: <code>name, email, contact, tier</code> (email, contact and tier optional). Export from Excel or Google Sheets as CSV. Every guest gets a QR ticket; if any row has a problem, nothing is imported.</p>
					<input ref="fileInput" type="file" accept=".csv,text/csv" class="mt-3 block w-full text-sm" aria-label="Guest list CSV" @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null">
					<div class="mt-2 grid grid-cols-2 gap-2">
						<select v-model="importTier" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Default tier">
							<option value="" disabled>Default tier</option>
							<option v-for="t in tiers" :key="t.id" :value="t.id">{{ t.name }}</option>
						</select>
						<select v-model="importAdmission" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Import admission">
							<option value="invited">Invited guests</option>
							<option value="complimentary">Complimentary</option>
						</select>
					</div>
					<label class="mt-2 flex items-center gap-2 text-sm text-gray-700"><input v-model="importSendEmail" type="checkbox"> E-mail each guest who has an address their QR ticket</label>
					<button type="submit" :disabled="busy || !file || !importTier" class="mt-3 rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
						<IconBase name="upload" class="mr-1 inline h-4 w-4" /> Import
					</button>
					<ul v-if="importErrors.length" class="mt-3 list-disc pl-5 text-xs text-rose-700">
						<li v-for="e in importErrors" :key="e">{{ e }}</li>
					</ul>
				</form>
			</section>

			<p v-if="!tiers.length && canManage" class="mb-5 text-sm text-gray-500">
				Add a ticket tier first on <NuxtLink :to="`/organizer/eventmanagement/ticket-tiers?event=${eventId}`" class="font-semibold text-primary-700">Ticket Tiers</NuxtLink>.
			</p>

			<div class="rounded-2xl border border-gray-200 bg-white p-6">
				<div class="mb-4 flex flex-wrap items-center gap-2">
					<h3 class="mr-auto text-base font-bold text-gray-900">Attendee list <span class="font-normal text-gray-400">({{ pagination?.total ?? 0 }})</span></h3>
					<FormsTextField v-model="search" placeholder="Search name or email" class="w-56" aria-label="Search attendees" @keyup.enter="load(1)" />
					<select v-model="statusFilter" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Status filter" @change="load(1)">
						<option value="">All statuses</option>
						<option v-for="(label, key) in guestStatusLabels" :key="key" :value="key">{{ label }}</option>
					</select>
					<button type="button" class="flex items-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold hover:bg-gray-50" @click="exportCsv">
						<IconBase name="download" class="h-4 w-4" /> Export CSV
					</button>
				</div>

				<div class="overflow-x-auto">
					<table class="w-full min-w-[640px] text-left text-sm">
						<thead class="text-xs uppercase text-gray-400">
							<tr><th class="py-2">Name</th><th>Tier</th><th>Admission</th><th>Status</th><th>Ticket e-mail</th><th class="text-right">Actions</th></tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="g in guests" :key="g.id">
								<td class="py-2.5">
									<p class="font-semibold text-gray-900">{{ g.name }}</p>
									<p class="text-xs text-gray-500">{{ [g.email, g.contact_number].filter(Boolean).join(' · ') }}</p>
								</td>
								<td>{{ g.tier }}<span v-if="g.zone" class="block text-xs text-gray-400">{{ g.zone }}</span></td>
								<td class="capitalize text-gray-600">{{ g.admission_type }}</td>
								<td>
									<span class="rounded-full px-2 py-0.5 text-xs font-semibold"
										:class="g.status === 'valid' ? 'bg-green-50 text-green-700' : g.status === 'used' ? 'bg-primary-50 text-primary-700' : 'bg-gray-100 text-gray-500'">{{ guestStatusLabels[g.status] }}</span>
								</td>
								<td class="text-xs">
									<span v-if="!g.email_delivery" class="text-gray-400">Not sent</span>
									<span v-else :title="g.email_delivery.error ?? g.email_delivery.recipient ?? ''" :class="emailTone[g.email_delivery.status]">
										{{ emailLabel(g.email_delivery) }}
									</span>
								</td>
								<td class="whitespace-nowrap text-right">
									<template v-if="canManage && ['valid', 'used'].includes(g.status)">
										<button v-if="g.status === 'valid'" type="button" class="text-xs font-semibold text-primary-700" @click="showQr(g)">QR</button>
										<button type="button" class="ml-3 text-xs font-semibold text-primary-700" @click="downloadPdf(g)">PDF</button>
										<button v-if="g.email" type="button" :disabled="busy" class="ml-3 text-xs font-semibold text-primary-700" @click="emailTicket(g)">{{ g.email_delivery?.status === 'sent' ? 'Resend' : 'E-mail' }}</button>
										<button v-if="g.status === 'valid'" type="button" class="ml-3 text-xs font-semibold text-rose-700" @click="cancel(g)">Cancel</button>
									</template>
								</td>
							</tr>
							<tr v-if="!guests.length"><td colspan="6" class="py-6 text-center text-gray-400">{{ loading ? 'Loading…' : 'No attendees yet.' }}</td></tr>
						</tbody>
					</table>
				</div>

				<div v-if="pagination && pagination.last_page > 1" class="mt-4 flex justify-center gap-2">
					<button type="button" :disabled="page <= 1" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page - 1)">Previous</button>
					<span class="px-2 py-1.5 text-sm text-gray-500">Page {{ page }} of {{ pagination.last_page }}</span>
					<button type="button" :disabled="page >= pagination.last_page" class="rounded-lg border px-3 py-1.5 text-sm disabled:opacity-40" @click="load(page + 1)">Next</button>
				</div>
			</div>
		</template>

		<div v-if="qrFor" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="qrFor = null">
			<div class="w-full max-w-sm rounded-2xl bg-white p-6 text-center print:shadow-none">
				<h2 class="text-lg font-bold text-gray-900">{{ qrFor.guest.name }}</h2>
				<p class="text-sm text-gray-500">{{ qrFor.guest.tier }}<template v-if="qrFor.guest.zone"> · {{ qrFor.guest.zone }}</template></p>
				<QrCode v-if="qrFor.token" :value="qrFor.token" :label="`QR ticket for ${qrFor.guest.name}`" class="mx-auto mt-4 w-56" />
				<p class="mt-3 text-xs text-gray-400">Show this at the entrance. Use “E-mail” or “PDF” in the list to send or print it.</p>
				<div class="mt-4 flex justify-center gap-2 print:hidden">
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold" @click="printQr">Print</button>
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-rose-700" :disabled="busy" @click="reissue">New QR code</button>
					<button type="button" class="rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white" @click="qrFor = null">Close</button>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { GuestRow, TicketTier } from '~/composables/useTicketing'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerTicketing()
const statsVersion = useState('ticketStatsVersion', () => 0)

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const orgId = computed(() => organization.value?.id ?? '')
const tiers = ref<TicketTier[]>([])
const canManage = ref(false)
const guests = ref<GuestRow[]>([])
const pagination = ref<{ current_page: number, last_page: number, total: number } | null>(null)
const page = ref(1)
const search = ref('')
const statusFilter = ref('')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const guest = reactive({ full_name: '', email: '', contact_number: '', ticket_type_id: '', admission_type: 'invited' as 'invited' | 'complimentary', send_email: true })
const importSendEmail = ref(true)
const emailTone: Record<NonNullable<GuestRow['email_delivery']>['status'], string> = {
	sent: 'font-semibold text-green-700', queued: 'text-gray-500', failed: 'font-semibold text-rose-700', suppressed: 'text-amber-700',
}
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const importTier = ref('')
const importAdmission = ref<'invited' | 'complimentary'>('invited')
const importErrors = ref<string[]>([])
const qrFor = ref<{ guest: GuestRow, token: string | null } | null>(null)

async function loadTiers() {
	const r = await api.tiers(orgId.value, eventId.value!)
	tiers.value = r.tiers
	canManage.value = r.can_manage_guests
	if (!guest.ticket_type_id && r.tiers.length) guest.ticket_type_id = r.tiers[0]!.id
	if (!importTier.value && r.tiers.length) importTier.value = r.tiers[0]!.id
}

async function load(p = page.value) {
	if (!eventId.value) return
	loading.value = true
	error.value = ''
	try {
		const org = organization.value ?? await fetchOrganization()
		if (!org) return
		if (!tiers.value.length) await loadTiers()
		const r = await api.guests(org.id, eventId.value, { search: search.value.trim(), status: statusFilter.value, page: p })
		guests.value = r.items
		pagination.value = r.pagination
		page.value = p
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

async function act(fn: () => Promise<unknown>, success: string) {
	error.value = ''
	notice.value = ''
	busy.value = true
	try {
		await fn()
		notice.value = success
		statsVersion.value++
		await loadTiers()
		await load(1)
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function addGuest() {
	await act(() => api.addGuest(orgId.value, eventId.value!, {
		full_name: guest.full_name.trim(), email: guest.email.trim() || null, contact_number: guest.contact_number.trim() || null,
		ticket_type_id: guest.ticket_type_id, admission_type: guest.admission_type, send_email: guest.send_email && !!guest.email.trim(),
	}), `${guest.full_name.trim()} added with a QR ticket${guest.send_email && guest.email.trim() ? ' (e-mail on its way)' : ''}.`)
	if (!error.value) Object.assign(guest, { full_name: '', email: '', contact_number: '' })
}

async function importFile() {
	importErrors.value = []
	error.value = ''
	busy.value = true
	try {
		const r = await api.importGuests(orgId.value, eventId.value!, file.value!, importTier.value, importAdmission.value, importSendEmail.value)
		notice.value = `${r.imported} guests imported with QR tickets.`
		file.value = null
		if (fileInput.value) fileInput.value.value = ''
		statsVersion.value++
		await loadTiers()
		await load(1)
	} catch (e) {
		const details = e instanceof ApiError ? (e.fields?.file as string[] | undefined) : undefined
		importErrors.value = details ?? []
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

function emailLabel(d: NonNullable<GuestRow['email_delivery']>) {
	const at = new Date(d.at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
	return { sent: `Sent ${at}`, queued: 'Sending…', failed: 'Failed', suppressed: d.recipient ? 'Not sent (test address)' : 'No e-mail' }[d.status]
}

async function emailTicket(g: GuestRow) {
	error.value = ''
	notice.value = ''
	busy.value = true
	try {
		const r = await api.emailTicket(orgId.value, eventId.value!, g.id)
		notice.value = r.message ?? 'Ticket e-mail on its way.'
		g.email_delivery = r.data.email_delivery
		setTimeout(() => load(page.value), 4000) // show "Sent" once the queue delivers it
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function downloadPdf(g: GuestRow) {
	try {
		const url = URL.createObjectURL(await api.ticketPdf(orgId.value, eventId.value!, g.id))
		const a = document.createElement('a')
		a.href = url
		a.download = `ticket-${g.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.pdf`
		a.click()
		URL.revokeObjectURL(url)
	} catch (e) {
		error.value = authMessage(e)
	}
}

function cancel(g: GuestRow) {
	const reason = prompt(`Cancel ${g.name}'s ticket? Their QR code stops working. Reason:`)
	if (reason && reason.trim().length >= 3) act(() => api.cancelTicket(orgId.value, eventId.value!, g.id, reason.trim()), 'Ticket cancelled.')
}

async function showQr(g: GuestRow) {
	try {
		const r = await api.qr(orgId.value, eventId.value!, g.id)
		qrFor.value = { guest: g, token: r.qr_token }
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function reissue() {
	if (!qrFor.value || !confirm('Issue a new QR code? The current one will stop working.')) return
	busy.value = true
	try {
		qrFor.value.token = (await api.reissueQr(orgId.value, eventId.value!, qrFor.value.guest.id)).qr_token
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

function printQr() {
	window.print()
}

async function exportCsv() {
	try {
		const blob = await api.exportCsv(orgId.value, eventId.value!)
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = 'guest-list.csv'
		a.click()
		URL.revokeObjectURL(url)
	} catch (e) {
		error.value = authMessage(e)
	}
}

watch(eventId, () => { tiers.value = []; guests.value = []; load(1) })
onMounted(() => load(1))
</script>
