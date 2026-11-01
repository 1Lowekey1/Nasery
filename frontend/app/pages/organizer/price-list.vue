<template>
	<div class="mx-auto max-w-7xl space-y-6">
		<!-- Header -->
		<section class="pl-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8">
			<div class="flex flex-wrap items-start justify-between gap-5">
				<div class="max-w-2xl">
					<h1 class="text-2xl font-extrabold tracking-tight text-gray-950">Price List &amp; Calculator</h1>
					<p class="mt-1 text-sm leading-6 text-gray-500">The prices behind the quotation calculator on your public page. Clients use it to get an instant estimate and send you a request with their picks; you can also build offers from it.</p>
				</div>
				<div class="flex items-center gap-4">
					<div class="hidden text-right sm:block">
						<p class="text-2xl font-extrabold tabular-nums text-gray-950">{{ options.length }}</p>
						<p class="text-xs font-semibold text-gray-400">{{ visibleOptions.length }} shown to clients</p>
					</div>
					<button type="button" class="flex shrink-0 items-center gap-2 rounded-xl bg-[#285F6b] px-5 py-3 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md active:translate-y-0 active:scale-[0.98]" @click="startNew()">
						<IconBase name="plus" class="h-4 w-4" /> Add a price option
					</button>
				</div>
			</div>
			<p v-if="error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ error }}</p>
			<p v-if="notice" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">{{ notice }}</p>
		</section>

		<div v-if="loading" class="rounded-3xl border border-gray-200 bg-white py-16 text-center text-sm text-gray-500">Loading your price list…</div>

		<div v-else class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
			<div class="space-y-6">
				<!-- Add / edit -->
				<section v-if="editing" ref="editorEl" class="pl-rise rounded-3xl border-2 border-primary-200 bg-white p-6 sm:p-7">
					<div class="flex items-center gap-3"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#285F6b] text-white"><IconBase name="tag" class="h-5 w-5" /></span><div><h2 class="text-base font-extrabold text-gray-950">{{ editing.id ? 'Edit price option' : 'New price option' }}</h2><p class="text-sm text-gray-500">Used by your calculator and your offers</p></div></div>
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

						<!-- Which events it's for: "Build from my price list" on an offer shows only matching options. -->
						<div>
							<FormsLabel text="For which events?" hint="(none picked = every event)" />
							<div class="flex flex-wrap gap-1.5" role="group" aria-label="Event types for this option">
								<button v-for="t in tagChoices" :key="t" type="button" :aria-pressed="editing.event_tags.includes(t)"
									class="inline-flex min-h-9 items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition"
									:class="editing.event_tags.includes(t) ? 'border-primary-700 bg-primary-700 text-white' : 'border-gray-200 bg-white text-gray-600 hover:border-primary-300'"
									@click="toggleTag(t)">
									<IconBase v-if="editing.event_tags.includes(t)" name="check" class="h-3 w-3" />{{ t }}
								</button>
							</div>
							<p class="mt-1 text-xs text-gray-400">Offers for other kinds of events won't list this option.</p>
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
							<button type="submit" :disabled="saving" class="rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-bold text-white transition hover:bg-[#1f4a54] disabled:opacity-60">{{ saving ? 'Saving…' : editing.id ? 'Save changes' : 'Add option' }}</button>
							<button type="button" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600" @click="cancelEdit">Cancel</button>
						</div>
					</form>
				</section>

				<!-- The list -->
				<p class="px-1 text-sm text-gray-500">Options in a service group are alternatives (clients pick one). Add-ons can be combined.</p>
				<section v-if="!options.length" class="pl-rise rounded-3xl border border-dashed border-gray-300 bg-white px-6 py-16 text-center">
					<span class="pl-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700"><IconBase name="tag" class="h-7 w-7" /></span>
					<h3 class="mt-4 text-lg font-bold text-gray-900">No price options yet</h3>
					<p class="mx-auto mt-1 max-w-md text-sm text-gray-500">Add your first one to turn on the calculator on your public page.</p>
					<button type="button" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-[#285F6b] px-5 py-3 text-sm font-bold text-white transition hover:-translate-y-0.5 hover:bg-[#1f4a54]" @click="startNew()"><IconBase name="plus" class="h-4 w-4" /> Add your first option</button>
				</section>

				<section v-for="(group, gIndex) in groups" :key="group.key" class="pl-rise pl-group overflow-hidden rounded-3xl border border-gray-200 bg-white" :style="{ '--d': 60 + gIndex * 60 + 'ms' }">
					<div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
						<div class="flex min-w-0 items-center gap-3">
							<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700"><IconBase :name="group.single ? 'tag' : 'grid'" class="h-[18px] w-[18px]" /></span>
							<div class="min-w-0">
								<h3 class="truncate text-sm font-extrabold text-gray-950">{{ group.label }}</h3>
								<p class="truncate text-xs text-gray-400">{{ group.single ? 'Clients pick one' : 'Clients pick any' }}</p>
							</div>
						</div>
						<span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-bold text-gray-600">{{ group.options.length }}</span>
					</div>
					<ul class="divide-y divide-gray-100">
						<li v-for="(o, i) in group.options" :key="o.id" class="pl-row group flex flex-wrap items-center gap-3 px-6 py-4 transition-colors hover:bg-gray-50/70" :class="o.is_active ? '' : 'bg-gray-50/80'">
							<div class="min-w-0 flex-1">
								<p class="flex flex-wrap items-center gap-2 text-sm font-bold" :class="o.is_active ? 'text-gray-900' : 'text-gray-400'">
									{{ o.name }}
									<span v-if="!o.is_active" class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-bold text-gray-600">Hidden</span>
								</p>
								<p v-if="o.description" class="mt-0.5 truncate text-xs text-gray-500">{{ o.description }}</p>
								<p v-if="o.pricing_unit === 'per_guest' && o.min_guests" class="mt-0.5 text-xs text-gray-400">min {{ o.min_guests }} guests</p>
								<p v-else-if="picksQuantity(o) && (o.min_quantity || o.max_quantity)" class="mt-0.5 text-xs text-gray-400">{{ quantityBounds(o).min }}{{ quantityBounds(o).max ? `–${quantityBounds(o).max}` : '+' }} {{ unitName(o, 2) }}</p>
								<p class="mt-0.5 truncate text-xs text-primary-700/80">{{ o.event_tags?.length ? o.event_tags.join(' · ') : 'All events' }}</p>
							</div>
							<div class="shrink-0 text-right">
								<p class="text-sm font-extrabold tabular-nums text-gray-950">{{ formatPeso(o.price) }}</p>
								<span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-bold" :class="o.pricing_unit === 'flat' ? 'bg-gray-100 text-gray-500' : 'bg-primary-50 text-primary-700'">{{ o.pricing_unit === 'flat' ? 'flat' : `per ${unitName(o)}` }}</span>
							</div>
							<div class="flex shrink-0 items-center gap-1 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100">
								<button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 disabled:opacity-30" :disabled="i === 0 || busy" :aria-label="`Move ${o.name} up`" @click="move(group.options, i, -1)"><IconBase name="chevron-down" class="h-4 w-4 rotate-180" /></button>
								<button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 disabled:opacity-30" :disabled="i === group.options.length - 1 || busy" :aria-label="`Move ${o.name} down`" @click="move(group.options, i, 1)"><IconBase name="chevron-down" class="h-4 w-4" /></button>
								<button type="button" class="rounded-lg px-2.5 py-1.5 text-xs font-bold text-gray-500 transition hover:bg-gray-100" :disabled="busy" @click="toggleActive(o)">{{ o.is_active ? 'Hide' : 'Show' }}</button>
								<button type="button" :aria-label="`Edit ${o.name}`" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-primary-50 hover:text-primary-700" @click="startEdit(o)"><IconBase name="edit" class="h-4 w-4" /></button>
								<button type="button" :aria-label="`Delete ${o.name}`" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600" :disabled="busy" @click="remove(o)"><IconBase name="trash" class="h-4 w-4" /></button>
							</div>
						</li>
					</ul>
				</section>

				<!-- Estimate settings -->
				<section class="pl-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-7">
					<h2 class="text-base font-extrabold text-gray-950">Estimate settings</h2>
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
							<button type="submit" :disabled="savingSettings" class="rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-bold text-white transition hover:bg-[#1f4a54] disabled:opacity-60">{{ savingSettings ? 'Saving…' : 'Save settings' }}</button>
						</div>
					</form>
				</section>
			</div>

			<!-- Live preview -->
			<!-- Small and sticky: stays beside the editor and scrolls inside itself when long. -->
			<aside class="pl-rise rounded-3xl border border-gray-200 bg-white p-4 xl:sticky xl:top-0 xl:max-h-[calc(100dvh-7rem)] xl:overflow-y-auto xl:overscroll-contain">
				<p class="text-[11px] font-bold uppercase tracking-widest text-primary-700">Live preview</p>
				<h2 class="mt-0.5 text-sm font-bold text-gray-900">What clients see</h2>
				<p class="mt-0.5 text-xs text-gray-500">Only options you show. Try it like a client would.</p>
				<PricingPriceCalculator :key="previewKey" compact class="mt-3" :options="visibleOptions" :services="services" :settings="previewSettings" />
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

type Draft = { id: string | null, name: string, organization_service_id: string | null, pricing_unit: PricingUnit, price: string, unit_label: string, min_guests: string, min_quantity: string, max_quantity: string, description: string, is_active: boolean, event_tags: string[] }

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
const blank = (): Draft => ({ id: null, name: '', organization_service_id: null, pricing_unit: 'flat', price: '', unit_label: '', min_guests: '', min_quantity: '', max_quantity: '', description: '', is_active: true, event_tags: [] })
// The event types this organizer handles (profile's event tags); every type until they pick some.
const eventTypeChoices = ref<string[]>([])
// Tags already on the option stay visible (and removable) even if the profile dropped them.
const tagChoices = computed(() => [...new Set([...eventTypeChoices.value, ...(editing.value?.event_tags ?? [])])])
function toggleTag(t: string) {
	const d = editing.value
	if (!d) return
	d.event_tags = d.event_tags.includes(t) ? d.event_tags.filter(x => x !== t) : [...d.event_tags, t]
}
const num = (v: string) => (String(v).trim() === '' ? null : Number(v))

const check = useFormCheck((): Record<string, string> => {
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
	nextTick(() => scrollToElement(editorEl.value))
}

function startEdit(o: PriceOption) {
	editing.value = {
		id: o.id, name: o.name, organization_service_id: o.organization_service_id, pricing_unit: o.pricing_unit, price: String(o.price),
		unit_label: o.unit_label ?? '', min_guests: o.min_guests ? String(o.min_guests) : '', min_quantity: o.min_quantity ? String(o.min_quantity) : '',
		max_quantity: o.max_quantity ? String(o.max_quantity) : '', description: o.description ?? '', is_active: o.is_active ?? true,
		event_tags: [...(o.event_tags ?? [])],
	}
	check.reset()
	nextTick(() => scrollToElement(editorEl.value))
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
		event_tags: d.event_tags,
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
		const [, list, types] = await Promise.all([loadOptions(), orgApi.services(), useMarketplace().eventTypes().catch(() => [])])
		services.value = list.filter(s => s.is_active).map(s => ({ id: s.id, name: s.custom_name || s.name }))
		const profileTags = ((org.profile as { event_tags?: string[] } | undefined)?.event_tags ?? []).filter(Boolean)
		eventTypeChoices.value = profileTags.length ? profileTags : types.map(t => t.name).filter(n => n !== 'Other')
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

<style scoped>
.pl-rise {
	animation: pl-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.pl-float {
	animation: pl-float 3.2s ease-in-out infinite;
}

.pl-group {
	transition: box-shadow 0.25s ease, border-color 0.25s ease;
}

.pl-group:hover {
	border-color: rgba(40, 95, 107, 0.25);
	box-shadow: 0 16px 32px -24px rgba(17, 24, 39, 0.35);
}

.pl-row {
	animation: pl-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.pl-row:nth-child(2) { animation-delay: 50ms; }
.pl-row:nth-child(3) { animation-delay: 100ms; }
.pl-row:nth-child(n + 4) { animation-delay: 150ms; }

.slide-enter-active, .slide-leave-active { transition: transform 0.25s ease; }
.slide-enter-from, .slide-leave-to { transform: translateX(100%); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@keyframes pl-rise {
	from { opacity: 0; transform: translateY(14px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes pl-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
	.pl-rise, .pl-float, .pl-row { animation: none; }
	.pl-group { transition: none; }
}
</style>
