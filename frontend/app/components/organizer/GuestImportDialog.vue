<template>
	<Teleport to="body">
		<div v-if="open" class="fixed inset-0 z-[120] flex items-start justify-center overflow-y-auto bg-slate-950/55 p-4 backdrop-blur-sm sm:items-center"
			role="dialog" aria-modal="true" :aria-labelledby="titleId" @click.self="close">
			<div class="my-6 w-full max-w-2xl overflow-hidden rounded-3xl bg-white shadow-2xl">
				<div class="flex items-start justify-between gap-4 border-b border-gray-100 px-6 py-5">
					<div>
						<p class="text-[11px] font-bold uppercase tracking-[0.18em] text-primary-700">Attendee management</p>
						<h2 :id="titleId" class="mt-1 text-xl font-extrabold text-gray-900">Bulk import guests</h2>
						<p class="mt-1 text-sm text-gray-500">Add many guests at once from a spreadsheet. Each guest gets a QR ticket.</p>
					</div>
					<button type="button" aria-label="Close" class="rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" @click="close">
						<IconBase name="x" class="h-4 w-4" />
					</button>
				</div>

				<div v-if="done !== null" class="px-6 py-10 text-center">
					<div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-50">
						<IconBase name="check-circle" class="h-7 w-7 text-green-600" />
					</div>
					<p class="mt-4 text-lg font-bold text-gray-900">{{ done }} {{ done === 1 ? 'guest' : 'guests' }} imported</p>
					<p class="mt-1 text-sm text-gray-500">{{ sendEmail ? 'Guests with an e-mail address are being sent their QR tickets.' : 'No e-mails were sent. You can e-mail tickets later from the guest list.' }}</p>
					<button type="button" class="mt-6 rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-bold text-white" @click="close">Done</button>
				</div>

				<div v-else class="space-y-6 px-6 py-5">
					<!-- 1. Guide -->
					<section aria-labelledby="import-guide">
						<div class="flex flex-wrap items-center justify-between gap-3">
							<h3 id="import-guide" class="text-sm font-bold text-gray-900">1. Prepare your file</h3>
							<button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-bold text-primary-700 hover:bg-primary-100"
								@click="downloadTemplate">
								<IconBase name="download" class="h-3.5 w-3.5" />Download template
							</button>
						</div>
						<ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-600">
							<li>Open the template in Excel or Google Sheets (or use your own sheet with the same column names).</li>
							<li>Keep the <strong>first row as the column names</strong>, then put <strong>one guest per row</strong>.</li>
							<li>Save it as <strong>CSV</strong>: Excel → File → Save As → “CSV UTF-8 (Comma delimited)”; Google Sheets → File → Download → “Comma-separated values (.csv)”.</li>
						</ol>
						<div class="mt-3 overflow-x-auto rounded-xl border border-gray-200">
							<table class="w-full text-left text-xs">
								<thead class="bg-gray-50 text-gray-500">
									<tr><th class="px-3 py-2 font-semibold">Column</th><th class="px-3 py-2 font-semibold">Needed?</th><th class="px-3 py-2 font-semibold">What to put</th></tr>
								</thead>
								<tbody class="divide-y divide-gray-100 text-gray-700">
									<tr><td class="px-3 py-2 font-mono font-semibold">name</td><td class="px-3 py-2 font-semibold text-rose-600">Required</td>
										<td class="px-3 py-2">Guest's full name, 2–120 characters. Or use two columns, <span class="font-mono">first name</span> and <span class="font-mono">last name</span>.</td></tr>
									<tr><td class="px-3 py-2 font-mono font-semibold">email</td><td class="px-3 py-2 text-gray-500">Optional</td>
										<td class="px-3 py-2">Where their QR ticket is e-mailed. Leave blank to hand out tickets yourself.</td></tr>
									<tr><td class="px-3 py-2 font-mono font-semibold">contact</td><td class="px-3 py-2 text-gray-500">Optional</td>
										<td class="px-3 py-2">Mobile number, e.g. 0917 123 4567 (up to 40 characters).</td></tr>
									<tr><td class="px-3 py-2 font-mono font-semibold">tier</td><td class="px-3 py-2 text-gray-500">Optional</td>
										<td class="px-3 py-2">A ticket tier name of this event: <span class="font-semibold">{{ tierNames || 'none yet' }}</span>. Blank uses the default tier below.</td></tr>
								</tbody>
							</table>
						</div>
						<p class="mt-2 text-xs text-gray-400">Column names aren't case-sensitive, and common names work too (“Full name”, “E-mail”, “Phone”, “Mobile”, “Ticket type”). Up to {{ limit }} guests and 1 MB per file. If any row has a problem, <strong>nothing is imported</strong>: fix the rows listed and upload again.</p>
					</section>

					<!-- 2. File -->
					<section>
						<h3 class="text-sm font-bold text-gray-900">2. Choose the CSV file</h3>
						<label class="mt-2 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-6 text-center transition"
							:class="dragging ? 'border-primary-400 bg-primary-50' : 'border-gray-200 hover:border-primary-300'"
							@dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop">
							<IconBase name="upload" class="h-6 w-6 text-primary-700" />
							<span class="text-sm font-semibold text-gray-700">{{ file ? file.name : 'Click to choose a .csv file, or drop it here' }}</span>
							<span v-if="file" class="text-xs text-gray-400">{{ (file.size / 1024).toFixed(1) }} KB · click to choose another</span>
							<input ref="input" type="file" accept=".csv,text/csv" class="sr-only" @change="onPick">
						</label>

						<div v-if="preview" class="mt-3">
							<p class="text-sm" :class="problems.length ? 'text-rose-700' : 'text-green-700'">
								<span class="font-semibold">{{ preview.rows.length }} {{ preview.rows.length === 1 ? 'guest' : 'guests' }} found.</span>
								{{ problems.length ? `${problems.length} ${problems.length === 1 ? 'row needs' : 'rows need'} fixing before you can import.` : 'Everything looks good.' }}
							</p>
							<ul v-if="problems.length" class="mt-2 max-h-36 space-y-1 overflow-y-auto rounded-xl bg-rose-50 px-4 py-3 text-xs text-rose-700" role="alert">
								<li v-for="p in problems.slice(0, 50)" :key="p">{{ p }}</li>
							</ul>
							<div v-if="preview.rows.length" class="mt-3 overflow-x-auto rounded-xl border border-gray-200">
								<table class="w-full text-left text-xs">
									<thead class="bg-gray-50 text-gray-500"><tr><th class="px-3 py-2">#</th><th class="px-3 py-2">Name</th><th class="px-3 py-2">E-mail</th><th class="px-3 py-2">Contact</th><th class="px-3 py-2">Tier</th></tr></thead>
									<tbody class="divide-y divide-gray-100 text-gray-700">
										<tr v-for="(r, i) in preview.rows.slice(0, 5)" :key="i">
											<td class="px-3 py-1.5 text-gray-400">{{ i + 2 }}</td><td class="px-3 py-1.5 font-semibold">{{ r.name }}</td>
											<td class="px-3 py-1.5">{{ r.email }}</td><td class="px-3 py-1.5">{{ r.contact }}</td><td class="px-3 py-1.5">{{ r.tier || defaultTierName }}</td>
										</tr>
									</tbody>
								</table>
								<p v-if="preview.rows.length > 5" class="border-t border-gray-100 px-3 py-1.5 text-xs text-gray-400">…and {{ preview.rows.length - 5 }} more</p>
							</div>
						</div>
					</section>

					<!-- 3. Options -->
					<section class="grid gap-4 sm:grid-cols-2">
						<div>
							<h3 class="text-sm font-bold text-gray-900">3. Default ticket tier <span class="text-rose-500">*</span></h3>
							<select v-model="tierId" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm" :class="attempted && !tierId && 'border-rose-400'">
								<option value="" disabled>Choose a tier</option>
								<option v-for="t in tiers" :key="t.id" :value="String(t.id)">{{ t.name }}</option>
							</select>
							<p class="mt-1 text-xs" :class="attempted && !tierId ? 'font-semibold text-rose-600' : 'text-gray-400'">
								{{ !tiers.length ? 'Create a ticket tier for this event first.' : 'Used for rows with a blank “tier”.' }}</p>
						</div>
						<div>
							<h3 class="text-sm font-bold text-gray-900">Admission</h3>
							<div class="mt-2 flex gap-2">
								<label v-for="a in admissions" :key="a.value" class="flex-1 cursor-pointer rounded-xl border px-3 py-2 text-sm"
									:class="admission === a.value ? 'border-primary-600 bg-primary-50 font-semibold text-primary-800' : 'border-gray-200 text-gray-600'">
									<input v-model="admission" type="radio" :value="a.value" class="sr-only">{{ a.label }}
								</label>
							</div>
							<p class="mt-1 text-xs text-gray-400">{{ admissions.find(a => a.value === admission)?.hint }}</p>
						</div>
						<label class="flex items-start gap-2 text-sm text-gray-700 sm:col-span-2">
							<input v-model="sendEmail" type="checkbox" class="mt-0.5 accent-[#285F6b]">
							<span>E-mail each guest their QR ticket now <span class="text-gray-400">(only guests with an e-mail address)</span></span>
						</label>
					</section>

					<div v-if="serverErrors.length" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">
						<p class="font-semibold">Nothing was imported. Fix these and upload again:</p>
						<ul class="mt-1 max-h-36 list-disc space-y-0.5 overflow-y-auto pl-5 text-xs">
							<li v-for="e in serverErrors" :key="e">{{ e }}</li>
						</ul>
					</div>

					<div class="flex flex-col-reverse gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:justify-end">
						<button type="button" class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="close">Cancel</button>
						<button type="button" class="rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#1f4a54] disabled:opacity-60"
							:disabled="busy" @click="submit">
							{{ busy ? 'Importing…' : preview?.rows.length ? `Import ${preview.rows.length} ${preview.rows.length === 1 ? 'guest' : 'guests'}` : 'Import guests' }}
						</button>
					</div>
					<p v-if="attempted && blocker" class="-mt-3 text-right text-xs font-semibold text-rose-600">{{ blocker }}</p>
				</div>
			</div>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
// Bulk guest import (CSV). Checks the file in the browser first (same rules as
// the server: GuestList::import), so problems show before anything is sent.
import { ApiError } from '~/composables/useApi'

type Tier = { id: string | number, name: string }
type Row = { name: string, email: string, contact: string, tier: string }

const props = defineProps<{
	open: boolean
	tiers: Tier[]
	eventName?: string
	upload: (file: File, tierId: string, admission: 'invited' | 'complimentary', sendEmail: boolean) => Promise<number>
}>()
const emit = defineEmits<{ close: [], imported: [count: number] }>()

const titleId = useId()
const limit = 1000
const admissions = [
	{ value: 'invited' as const, label: 'Invited', hint: 'Guests on your invitation list.' },
	{ value: 'complimentary' as const, label: 'Complimentary', hint: 'Free passes (sponsors, media, VIPs); shown separately in reports.' },
]

const input = ref<HTMLInputElement | null>(null)
const file = ref<File | null>(null)
const preview = ref<{ rows: Row[], headerOk: boolean } | null>(null)
const tierId = ref('')
const admission = ref<'invited' | 'complimentary'>('invited')
const sendEmail = ref(true)
const dragging = ref(false)
const busy = ref(false)
const attempted = ref(false)
const serverErrors = ref<string[]>([])
const done = ref<number | null>(null)

const tierNames = computed(() => props.tiers.map(t => t.name).join(', '))
const defaultTierName = computed(() => props.tiers.find(t => String(t.id) === tierId.value)?.name ?? '(default)')

watch(() => props.open, (open) => {
	if (!open) return
	file.value = null; preview.value = null; serverErrors.value = []; done.value = null; attempted.value = false; busy.value = false
	tierId.value = props.tiers.length === 1 ? String(props.tiers[0]!.id) : ''
})

// ---- CSV reading (quotes, Excel BOM, comma / semicolon / tab) -------------------

const aliases: Record<string, string> = {
	'full name': 'name', 'full_name': 'name', 'guest': 'name', 'guest name': 'name', 'attendee': 'name',
	'e-mail': 'email', 'email address': 'email', 'phone': 'contact', 'contact number': 'contact', 'mobile': 'contact',
	'mobile number': 'contact', 'phone number': 'contact', 'ticket': 'tier', 'ticket type': 'tier', 'ticket tier': 'tier', 'zone': 'tier',
	'first name': 'first_name', 'firstname': 'first_name', 'given name': 'first_name',
	'last name': 'last_name', 'lastname': 'last_name', 'surname': 'last_name', 'family name': 'last_name',
}

function splitLine(line: string, d: string): string[] {
	const out: string[] = []
	let cur = ''
	let quoted = false
	for (let i = 0; i < line.length; i++) {
		const c = line[i]
		if (quoted) {
			if (c === '"' && line[i + 1] === '"') { cur += '"'; i++ }
			else if (c === '"') quoted = false
			else cur += c
		} else if (c === '"') quoted = true
		else if (c === d) { out.push(cur); cur = '' }
		else cur += c
	}
	out.push(cur)
	return out.map(v => v.trim())
}

function parse(text: string) {
	const lines = text.replace(/^﻿/, '').split(/\r\n|\n|\r/).filter(l => l.trim() !== '')
	if (!lines.length) return { rows: [], headerOk: false }
	const d = [',', ';', '\t'].sort((a, b) => lines[0]!.split(b).length - lines[0]!.split(a).length)[0]!
	const header = splitLine(lines[0]!, d).map(h => aliases[h.toLowerCase()] ?? h.toLowerCase())
	const headerOk = header.includes('name') || header.includes('first_name') || header.includes('last_name')
	const rows = lines.slice(1).map((line) => {
		const cells = splitLine(line, d)
		const get = (k: string) => { const i = header.indexOf(k); return i >= 0 ? cells[i] ?? '' : '' }
		const name = get('name') || `${get('first_name')} ${get('last_name')}`.trim()
		return { name, email: get('email'), contact: get('contact'), tier: get('tier') }
	})
	return { rows, headerOk }
}

const problems = computed(() => {
	if (!preview.value) return []
	if (!preview.value.headerOk) return ['The first row needs a “name” column (or “first name” and “last name”). Download the template to see the format.']
	if (!preview.value.rows.length) return ['The file has column names but no guests below them.']
	const list: string[] = []
	if (preview.value.rows.length > limit) list.push(`The file has ${preview.value.rows.length} guests; import at most ${limit} at a time.`)
	const known = new Set(props.tiers.map(t => t.name.toLowerCase()))
	preview.value.rows.forEach((r, i) => {
		const line = i + 2
		if (r.name.length < 2) list.push(`Row ${line}: the name is missing or too short.`)
		else if (r.name.length > 120) list.push(`Row ${line}: the name is longer than 120 characters.`)
		if (r.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(r.email)) list.push(`Row ${line}: “${r.email}” isn't a valid e-mail address.`)
		if (r.contact.length > 40) list.push(`Row ${line}: the contact number is too long.`)
		if (r.tier && !known.has(r.tier.toLowerCase())) list.push(`Row ${line}: there's no ticket tier called “${r.tier}”${tierNames.value ? ` (tiers: ${tierNames.value})` : ''}.`)
	})
	return list
})

const blocker = computed(() => !file.value ? 'Choose a CSV file first.'
	: problems.value.length ? 'Fix the rows listed above, save the file, and choose it again.'
		: !tierId.value ? 'Choose the default ticket tier.' : '')

async function load(f: File | undefined | null) {
	serverErrors.value = []
	if (!f) return
	if (!/\.(csv|txt)$/i.test(f.name)) {
		file.value = null
		preview.value = null
		serverErrors.value = [`“${f.name}” isn't a CSV file. In Excel use File → Save As → “CSV UTF-8 (Comma delimited)”, then choose that file.`]
		return
	}
	file.value = f
	preview.value = parse(await f.text())
}
function onPick(e: Event) { void load((e.target as HTMLInputElement).files?.[0]); (e.target as HTMLInputElement).value = '' }
function onDrop(e: DragEvent) { dragging.value = false; void load(e.dataTransfer?.files?.[0]) }

function downloadTemplate() {
	const [first, second] = [props.tiers[0]?.name ?? '', props.tiers[1]?.name ?? props.tiers[0]?.name ?? '']
	const rows = [['name', 'email', 'contact', 'tier'], ['Juan Dela Cruz', 'juan@example.com', '0917 123 4567', first], ['Maria Santos', '', '0918 765 4321', second], ['Pedro Reyes', 'pedro@example.com', '', '']]
	const csv = '﻿' + rows.map(r => r.map(v => (/[",;\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v)).join(',')).join('\r\n')
	const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
	const a = document.createElement('a')
	a.href = url
	a.download = `${(props.eventName || 'guest').replace(/[^\w-]+/g, '-').toLowerCase()}-guest-import-template.csv`
	a.click()
	URL.revokeObjectURL(url)
}

async function submit() {
	attempted.value = true
	if (blocker.value || !file.value) return
	busy.value = true
	serverErrors.value = []
	try {
		done.value = await props.upload(file.value, tierId.value, admission.value, sendEmail.value)
		emit('imported', done.value)
	} catch (error: unknown) {
		if (error instanceof ApiError) {
			const list = Object.values(error.fields).flat()
			serverErrors.value = list.length ? list : [error.message]
		} else serverErrors.value = ['The import failed. Check your connection and try again.']
	} finally {
		busy.value = false
	}
}

function close() {
	if (!busy.value) emit('close')
}
</script>
