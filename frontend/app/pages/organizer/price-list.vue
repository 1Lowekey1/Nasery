<template>
	<div class="mx-auto max-w-7xl">
		<div class="mb-6 flex flex-wrap items-end justify-between gap-3">
			<div>
				<h1 class="text-2xl font-extrabold tracking-tight text-gray-950">Price List &amp; Calculator</h1>
				<p class="mt-1 max-w-2xl text-gray-500">The prices behind the quotation calculator on your public page. Clients use it to get an instant estimate and send you a request with their picks; you can also build offers from it.</p>
			</div>
			<button type="button" class="rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-900" @click="startNew()">+ Add a price option</button>
		</div>

		<div v-if="error" class="mb-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">{{ error }}</div>
		<div v-if="notice" class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ notice }}</div>
		<div v-if="loading" class="rounded-2xl border border-gray-100 bg-white p-10 text-center text-sm text-gray-500">Loading your price list…</div>

		<div v-else class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
			<div class="space-y-6">
				<!-- Add / edit -->
				<section v-if="editing" ref="editorEl" class="rounded-2xl border border-primary-200 bg-white p-5 shadow-sm sm:p-6">
					<h2 class="text-base font-bold text-gray-900">{{ editing.id ? 'Edit price option' : 'New price option' }}</h2>
					<form class="mt-4 space-y-4" novalidate @submit.prevent="save">
						<div class="grid gap-4 sm:grid-cols-2">
							<div data-field="name">
								<FormsLabel text="Name" required />
								<FormsTextField v-model="editing.name" placeholder="e.g. Buffet catering" :error="check.err('name')" />
							</div>
							<div>
								<FormsLabel text="Group" />
								<select v-model="editing.organization_service_id" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm">
									<option :value="null">Add-ons (clients may pick any)</option>
									<option v-for="s in services" :key="s.id" :value="s.id">{{ s.name }} (clients pick one)</option>
								</select>
							</div>
						</div>

						<div>
							<FormsLabel text="How is it priced?" required />
							<div class="grid gap-2 sm:grid-cols-5" role="radiogroup" aria-label="Pricing type">
								<button v-for="(label, unit) in pricingUnitLabels" :key="unit" type="button" role="radio" :aria-checked="editing.pricing_unit === unit"
									class="rounded-xl border px-3 py-2 text-left text-sm transition"
									:class="editing.pricing_unit === unit ? 'border-primary-700 bg-primary-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'"
									@click="editing.pricing_unit = unit">
									<span class="block font-semibold">{{ label }}</span>
									<span class="block text-[11px] opacity-75">{{ unitHelp[unit] }}</span>
								</button>
							</div>
						</div>

						<div class="grid gap-4 sm:grid-cols-3">
							<div data-field="price">
								<FormsLabel :text="`Price${priceSuffix(editing)}`" required />
								<FormsTextField v-model="editing.price" type="number" min="0" step="0.01" icon="peso" placeholder="0.00" :error="check.err('price')" />
							</div>
							<div v-if="editing.pricing_unit === 'per_unit'" data-field="unit_label">
								<FormsLabel text="What is one item?" required />
								<FormsTextField v-model="editing.unit_label" placeholder="e.g. table, photographer" :error="check.err('unit_label')" />
							</div>
							<div v-if="editing.pricing_unit === 'per_guest'" data-field="min_guests">
								<FormsLabel text="Minimum guests" />
								<FormsTextField v-model="editing.min_guests" type="number" min="1" placeholder="none" :error="check.err('min_guests')" />
							</div>
							<template v-if="picksQuantity(editing)">
								<div data-field="min_quantity">
									<FormsLabel :text="`Least ${unitName(editing, 2)} a client can pick`" />
									<FormsTextField v-model="editing.min_quantity" type="number" min="1" placeholder="1" :error="check.err('min_quantity')" />
								</div>
								<div data-field="max_quantity">
									<FormsLabel :text="`Most ${unitName(editing, 2)}`" />
									<FormsTextField v-model="editing.max_quantity" type="number" min="1" placeholder="no limit" :error="check.err('max_quantity')" />
								</div>
							</template>
						</div>

						<div>
							<FormsLabel text="Description" />
							<textarea v-model="editing.description" rows="2" maxlength="500" placeholder="What's included (shown to clients)"
								class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
						</div>

						<label class="flex items-center gap-2 text-sm text-gray-700">
							<input v-model="editing.is_active" type="checkbox" class="accent-[#285F6b]"> Show this option to clients
						</label>

						<p v-if="check.summary.value" class="text-sm font-semibold text-rose-600" role="alert">{{ check.summary.value }}</p>
						<div class="flex gap-2">
							<button type="submit" :disabled="saving" class="rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ saving ? 'Saving…' : editing.id ? 'Save changes' : 'Add option' }}</button>
							<button type="button" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600" @click="cancelEdit">Cancel</button>
						</div>
					</form>
				</section>

				<!-- The list -->
				<section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
					<h2 class="text-base font-bold text-gray-900">Your options</h2>
					<p class="mt-1 text-sm text-gray-500">Options in a service group are alternatives (clients pick one). Add-ons can be combined.</p>
					<p v-if="!options.length" class="mt-5 rounded-xl bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">No price options yet. Add your first one to turn on the calculator.</p>

					<div v-for="group in groups" :key="group.key" class="mt-5">
						<h3 class="text-xs font-bold uppercase tracking-wide text-gray-500">{{ group.label }} <span class="font-normal normal-case">· {{ group.single ? 'clients pick one' : 'clients pick any' }}</span></h3>
						<ul class="mt-2 divide-y divide-gray-100 rounded-xl border border-gray-100">
							<li v-for="(o, i) in group.options" :key="o.id" class="flex flex-wrap items-center gap-3 px-4 py-3" :class="o.is_active ? '' : 'bg-gray-50'">
								<div class="min-w-0 flex-1">
									<div class="flex flex-wrap items-center gap-2">
										<span class="font-semibold text-gray-900">{{ o.name }}</span>
										<span v-if="!o.is_active" class="rounded-full bg-gray-200 px-2 py-0.5 text-[11px] font-semibold text-gray-600">Hidden</span>
									</div>
									<div class="text-sm text-gray-600">{{ formatPeso(o.price) }}{{ priceSuffix(o) }}
										<span v-if="o.pricing_unit === 'per_guest' && o.min_guests" class="text-gray-400">· min {{ o.min_guests }} guests</span>
										<span v-else-if="picksQuantity(o) && (o.min_quantity || o.max_quantity)" class="text-gray-400">· {{ quantityBounds(o).min }}{{ quantityBounds(o).max ? `–${quantityBounds(o).max}` : '+' }} {{ unitName(o, 2) }}</span>
									</div>
								</div>
								<div class="flex items-center gap-1">
									<button type="button" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 disabled:opacity-30" :disabled="i === 0 || busy" :aria-label="`Move ${o.name} up`" @click="move(group.options, i, -1)">▲</button>
									<button type="button" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 disabled:opacity-30" :disabled="i === group.options.length - 1 || busy" :aria-label="`Move ${o.name} down`" @click="move(group.options, i, 1)">▼</button>
									<button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-gray-600 hover:bg-gray-100" :disabled="busy" @click="toggleActive(o)">{{ o.is_active ? 'Hide' : 'Show' }}</button>
									<button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-50" @click="startEdit(o)">Edit</button>
									<button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-50" :disabled="busy" @click="remove(o)">Delete</button>
								</div>
							</li>
						</ul>
					</div>
				</section>

				<!-- Estimate settings -->
				<section class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm sm:p-6">
					<h2 class="text-base font-bold text-gray-900">Estimate settings</h2>
					<p class="mt-1 text-sm text-gray-500">Added to every estimate's breakdown. Leave blank if you don't charge them.</p>
					<form class="mt-4 grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="saveSettings">
						<div data-field="service_charge">
							<FormsLabel text="Service charge (%)" />
							<FormsTextField v-model="settings.service_charge" type="number" min="0" max="30" step="0.5" placeholder="e.g. 10" :error="settingsCheck.err('service_charge')" />
						</div>
						<div data-field="tax">
							<FormsLabel text="VAT (%)" />
							<FormsTextField v-model="settings.tax" type="number" min="0" max="20" step="0.5" placeholder="e.g. 12" :error="settingsCheck.err('tax')" />
						</div>
						<div class="sm:col-span-2">
							<FormsLabel text="Note shown under every estimate" />
							<FormsTextField v-model="settings.note" maxlength="500" placeholder="e.g. Final price confirmed after a site visit." />
						</div>
						<div class="sm:col-span-2">
							<button type="submit" :disabled="savingSettings" class="rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60">{{ savingSettings ? 'Saving…' : 'Save settings' }}</button>
						</div>
					</form>
				</section>
			</div>

			<!-- Live preview -->
			<aside class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm xl:sticky xl:top-6">
				<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Live preview</p>
				<h2 class="mt-1 text-base font-bold text-gray-900">What clients see</h2>
				<p class="mt-1 text-xs text-gray-500">Only options you show. Try it like a client would.</p>
				<PricingPriceCalculator :key="previewKey" class="mt-4" :options="visibleOptions" :services="services" :settings="previewSettings" />
			</aside>
		</div>
	</div>
</template>

<script setup lang="ts">
// Organizer price list (0033 + 0048): build the options behind the public
// quotation calculator, set estimate charges, and preview it live.
import type { PriceOption, PricingUnit } from '~/composables/useQuotations'

definePageMeta({ layout: 'dashboard' })
useSeoMeta({ title: 'Price List | NaSeRy' })

type Draft = { id: string | null, name: string, organization_service_id: string | null, pricing_unit: PricingUnit, price: string, unit_label: string, min_guests: string, min_quantity: string, max_quantity: string, description: string, is_active: boolean }

const api = usePriceOptions()
const orgApi = useOrganization()
const orgId = ref('')
const options = ref<PriceOption[]>([])
const services = ref<{ id: string, name: string }[]>([])
const loading = ref(true)
const busy = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')
const editing = ref<Draft | null>(null)
const editorEl = ref<HTMLElement | null>(null)

const unitHelp: Record<PricingUnit, string> = {
	flat: 'One price',
	per_guest: '× guest count',
	per_hour: 'Client picks hours',
	per_day: 'Client picks days',
	per_unit: 'Client picks how many',
}

const groups = computed(() => {
	const sorted = [...options.value].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0) || a.name.localeCompare(b.name))
	const byService = new Map<string | null, PriceOption[]>()
	for (const o of sorted) byService.set(o.organization_service_id, [...(byService.get(o.organization_service_id) ?? []), o])
	return [...byService.entries()]
		.map(([serviceId, list]) => ({ key: serviceId ?? 'addons', label: serviceId ? services.value.find(s => s.id === serviceId)?.name ?? 'Service' : 'Add-ons', single: serviceId !== null, options: list }))
		.sort((a, b) => Number(!a.single) - Number(!b.single))
})
const visibleOptions = computed(() => options.value.filter(o => o.is_active))

// ---- add / edit ------------------------------------------------------------
const blank = (): Draft => ({ id: null, name: '', organization_service_id: null, pricing_unit: 'flat', price: '', unit_label: '', min_guests: '', min_quantity: '', max_quantity: '', description: '', is_active: true })
const num = (v: string) => (String(v).trim() === '' ? null : Number(v))

const check = useFormCheck(() => {
	const d = editing.value
	if (!d) return {}
	const minQ = num(d.min_quantity)
	const maxQ = num(d.max_quantity)
	return {
		name: d.name.trim().length < 2 ? 'Give the option a name (at least 2 characters).' : '',
		price: String(d.price).trim() === '' ? 'Enter the price.' : Number(d.price) < 0 ? 'The price can’t be negative.' : '',
		unit_label: d.pricing_unit === 'per_unit' && !d.unit_label.trim() ? 'Say what one item is (e.g. table).' : '',
		min_guests: d.pricing_unit === 'per_guest' && num(d.min_guests) !== null && num(d.min_guests)! < 1 ? 'Use 1 or more, or leave it blank.' : '',
		min_quantity: picksQuantity(d) && minQ !== null && minQ < 1 ? 'Use 1 or more, or leave it blank.' : '',
		max_quantity: picksQuantity(d) && maxQ !== null && (maxQ < 1 || (minQ !== null && maxQ < minQ)) ? 'Must be at least the least amount.' : '',
	}
})

function startNew() {
	editing.value = blank()
	check.reset()
	nextTick(() => editorEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

function startEdit(o: PriceOption) {
	editing.value = {
		id: o.id, name: o.name, organization_service_id: o.organization_service_id, pricing_unit: o.pricing_unit, price: String(o.price),
		unit_label: o.unit_label ?? '', min_guests: o.min_guests ? String(o.min_guests) : '', min_quantity: o.min_quantity ? String(o.min_quantity) : '',
		max_quantity: o.max_quantity ? String(o.max_quantity) : '', description: o.description ?? '', is_active: o.is_active ?? true,
	}
	check.reset()
	nextTick(() => editorEl.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

function cancelEdit() {
	editing.value = null
	check.reset()
}

async function save() {
	const d = editing.value
	if (!d || !check.ok()) return
	saving.value = true
	error.value = ''
	notice.value = ''
	const qty = picksQuantity(d)
	const payload: Partial<PriceOption> = {
		name: d.name.trim(),
		organization_service_id: d.organization_service_id,
		pricing_unit: d.pricing_unit,
		price: String(Number(d.price)),
		description: d.description.trim() || null,
		is_active: d.is_active,
		unit_label: d.pricing_unit === 'per_unit' ? d.unit_label.trim() : null,
		min_guests: d.pricing_unit === 'per_guest' ? num(d.min_guests) : null,
		min_quantity: qty ? num(d.min_quantity) : null,
		max_quantity: qty ? num(d.max_quantity) : null,
	}
	try {
		if (d.id) {
			await api.update(orgId.value, d.id, payload)
			notice.value = `“${payload.name}” was updated.`
		} else {
			await api.create(orgId.value, { ...payload, sort_order: (Math.max(0, ...options.value.map(o => o.sort_order ?? 0)) + 1) })
			notice.value = `“${payload.name}” was added.`
		}
		editing.value = null
		await loadOptions()
	} catch (e: unknown) {
		if (!check.fromServer(e)) error.value = authMessage(e) || 'Unable to save this option.'
	} finally {
		saving.value = false
	}
}

async function run(fn: () => Promise<unknown>) {
	busy.value = true
	error.value = ''
	try {
		await fn()
		await loadOptions()
	} catch (e: unknown) {
		error.value = authMessage(e) || 'Something went wrong. Please try again.'
	} finally {
		busy.value = false
	}
}

const toggleActive = (o: PriceOption) => run(() => api.update(orgId.value, o.id, { is_active: !o.is_active }))

async function remove(o: PriceOption) {
	if (!(await confirmDialog(`Delete “${o.name}” from your price list? Clients won't see it any more.`, { confirmLabel: 'Delete option' }))) return
	await run(() => api.remove(orgId.value, o.id))
}

/** Swap with the neighbour in the same group (positions are re-numbered so ties can't stick). */
function move(list: PriceOption[], i: number, dir: -1 | 1) {
	const order = [...list]
	const [item] = order.splice(i, 1)
	order.splice(i + dir, 0, item!)
	return run(() => Promise.all(order.map((o, n) => (o.sort_order === n ? null : api.update(orgId.value, o.id, { sort_order: n }))).filter(Boolean)))
}

// ---- estimate settings ----------------------------------------------------------
const settings = reactive({ service_charge: '', tax: '', note: '' })
const savedSettings = ref<EstimateSettings>({})
const savingSettings = ref(false)
const previewKey = ref(0)
const previewSettings = computed<EstimateSettings>(() => ({
	service_charge_percent: num(settings.service_charge),
	tax_percent: num(settings.tax),
	note: settings.note.trim() || null,
}))
const settingsCheck = useFormCheck(() => ({
	service_charge: settings.service_charge !== '' && (Number(settings.service_charge) < 0 || Number(settings.service_charge) > 30) ? 'Use 0 to 30 %.' : '',
	tax: settings.tax !== '' && (Number(settings.tax) < 0 || Number(settings.tax) > 20) ? 'Use 0 to 20 %.' : '',
}))

async function saveSettings() {
	if (!settingsCheck.ok()) return
	savingSettings.value = true
	error.value = ''
	notice.value = ''
	try {
		await orgApi.updateProfile({
			estimate_service_charge_percent: num(settings.service_charge),
			estimate_tax_percent: num(settings.tax),
			estimate_note: settings.note.trim() || null,
		} as never)
		savedSettings.value = { ...previewSettings.value }
		notice.value = 'Estimate settings saved.'
	} catch (e: unknown) {
		if (!settingsCheck.fromServer(e, { estimate_service_charge_percent: 'service_charge', estimate_tax_percent: 'tax' })) error.value = authMessage(e) || 'Unable to save the settings.'
	} finally {
		savingSettings.value = false
	}
}

// ---- load ------------------------------------------------------------------------
async function loadOptions() {
	options.value = await api.list(orgId.value)
	previewKey.value++
}

onMounted(async () => {
	try {
		const org = await orgApi.fetchOrganization(true)
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId.value = org.id
		const [, list] = await Promise.all([loadOptions(), orgApi.services()])
		services.value = list.filter(s => s.is_active).map(s => ({ id: s.id, name: s.custom_name || s.name }))
		const p = org.profile as (typeof org.profile & { estimate_service_charge_percent?: number | null, estimate_tax_percent?: number | null, estimate_note?: string | null }) | undefined
		settings.service_charge = p?.estimate_service_charge_percent != null ? String(p.estimate_service_charge_percent) : ''
		settings.tax = p?.estimate_tax_percent != null ? String(p.estimate_tax_percent) : ''
		settings.note = p?.estimate_note ?? ''
	} catch (e: unknown) {
		error.value = authMessage(e) || (e as Error).message || 'Unable to load your price list.'
	} finally {
		loading.value = false
	}
})
</script>
