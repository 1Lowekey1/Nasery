<template>
	<div>
		<div class="fixed inset-0 z-40 bg-gray-900/40" @click="$emit('close')" />
		<div class="fixed inset-y-0 right-0 z-50 flex w-full max-w-xl flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" :aria-label="title">
			<div class="flex shrink-0 items-start justify-between border-b border-gray-200 px-6 py-5">
				<div>
					<h2 class="text-lg font-bold text-gray-900">{{ title }}</h2>
					<p class="mt-0.5 text-sm text-gray-500">{{ context }}</p>
				</div>
				<button type="button" class="tap-target rounded-lg p-1.5 text-gray-400 hover:bg-gray-100" aria-label="Close" @click="$emit('close')">
					<IconBase name="x" class="h-5 w-5" />
				</button>
			</div>

			<form novalidate class="flex-1 space-y-5 overflow-y-auto px-6 py-5" @submit.prevent="save(true)">
				<FormsDraftNotice :draft="draft" />
				<Alert v-if="error" type="danger" :text="error" />
				<p v-if="mode === 'revise'" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
					Sending replaces your current quotation. The client will only see this new version.
				</p>

				<div>
					<FormsLabel text="Package Name" />
					<FormsTextField v-model="form.package_name" maxlength="150" placeholder="e.g. Garden Wedding Package" />
				</div>

				<div>
					<div class="mb-2 flex items-center justify-between">
						<FormsLabel text="Cost Breakdown" required />
						<button v-if="priceOptions.length" type="button" class="text-sm font-semibold text-primary-700" @click="showPriceList = !showPriceList">
							{{ showPriceList ? 'Hide price list' : 'Add from my price list' }}
						</button>
					</div>

					<div v-if="showPriceList" class="mb-3 max-h-48 overflow-y-auto rounded-xl border border-gray-200">
						<button v-for="o in priceOptions" :key="o.id" type="button"
							class="flex w-full items-center justify-between gap-3 border-b border-gray-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-gray-50"
							@click="form.items.push(optionAsLine(o, guestCount))">
							<span>{{ o.name }}</span>
							<span class="shrink-0 text-gray-500">{{ formatPeso(o.price) }}{{ o.pricing_unit === 'per_guest' ? ' / guest' : '' }}</span>
						</button>
					</div>

					<div class="space-y-2">
						<div class="hidden grid-cols-[1fr_70px_110px_28px] gap-2 text-xs font-semibold text-gray-400 sm:grid">
							<span>Description</span><span>Qty</span><span>Unit price (₱)</span><span />
						</div>
						<div v-for="(line, i) in form.items" :key="i" class="grid grid-cols-[1fr_70px_110px_28px] items-center gap-2">
							<FormsTextField v-model="line.description" :aria-label="`Line ${i + 1} description`" placeholder="e.g. Coordination team" />
							<FormsTextField v-model="line.quantity" :aria-label="`Line ${i + 1} quantity`" type="number" min="0.01" step="0.01" />
							<FormsTextField v-model="line.unit_price" :aria-label="`Line ${i + 1} unit price`" type="number" min="0" step="0.01" />
							<button type="button" class="text-rose-700" :aria-label="`Remove line ${i + 1}`" @click="form.items.splice(i, 1)">×</button>
						</div>
					</div>
					<button type="button" class="mt-2 text-sm font-semibold text-primary-700" @click="form.items.push(blankLine())">+ Add a line</button>
					<!-- VAT is added by NaSeRy at its rate (organizers who charge VAT); it can't be typed or edited. -->
					<div v-if="vatRate" class="mt-3 flex items-center justify-between text-sm text-gray-600">
						<span>VAT ({{ vatRate }}%) <span class="text-xs text-gray-400">added automatically</span></span><span>{{ formatPeso(vatAmount) || '₱0' }}</span>
					</div>
					<div class="mt-3 flex items-center justify-between border-t border-gray-200 pt-3 text-base font-bold text-gray-900">
						<span>Total</span><span>{{ formatPeso(total + vatAmount) || '₱0' }}</span>
					</div>
				</div>

				<div>
					<FormsLabel text="Proposal" />
					<textarea v-model="form.proposal" rows="5" maxlength="5000"
						placeholder="Introduce your team, your plan for this event and why you're the right fit."
						class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
				</div>

				<div>
					<FormsLabel text="Terms" />
					<textarea v-model="form.terms" rows="3" maxlength="3000"
						placeholder="Payment schedule, what's included or excluded, validity…"
						class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none" />
				</div>
				<p class="text-xs text-gray-400">Quotations are proposals, not contracts; final terms are agreed with the client directly.</p>
			</form>

			<div class="flex shrink-0 justify-end gap-2 border-t border-gray-200 px-6 py-4">
				<button v-if="mode !== 'revise'" type="button" :disabled="busy"
					class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
					@click="save(false)">Save draft</button>
				<button type="button" :disabled="busy"
					class="rounded-xl bg-primary-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-900 disabled:opacity-50"
					@click="save(true)">{{ busy ? 'Sending…' : mode === 'revise' ? 'Send revised quotation' : 'Send quotation' }}</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { OrganizerQuotation, PriceOption, QuotationContent, QuotationLine } from '~/composables/useQuotations'

const props = defineProps<{
	organizationId: string
	inquiryId: string
	/** For the header and per-guest price-list lines. */
	eventLabel: string
	guestCount: number | null
	/** Existing draft (edit) or sent quotation (revise). */
	quotation?: OrganizerQuotation | null
}>()
const emit = defineEmits<{ close: [], saved: [quotation: OrganizerQuotation] }>()

const api = useOrganizerQuotations()
const { list: listPriceOptions } = usePriceOptions()

const mode = computed(() => !props.quotation ? 'create' : props.quotation.status === 'draft' ? 'edit' : 'revise')
const title = computed(() => ({ create: 'New quotation', edit: 'Edit draft quotation', revise: 'Revise quotation' })[mode.value])
const context = computed(() => props.guestCount ? `${props.eventLabel} · ${props.guestCount} guests` : props.eventLabel)

function blankLine(): QuotationLine {
	return { description: '', quantity: 1, unit_price: '' }
}

const form = reactive<QuotationContent>({
	package_name: props.quotation?.package_name ?? '',
	proposal: props.quotation?.proposal ?? '',
	terms: props.quotation?.terms ?? '',
	// The VAT line is NaSeRy's, recalculated on save: not an editable line.
	items: props.quotation?.items.filter(i => !isVatLine(i.description)).map(i => ({ organization_service_id: i.organization_service_id, description: i.description, quantity: Number(i.quantity), unit_price: Number(i.unit_price) })) ?? [blankLine()],
})
const total = computed(() => linesTotal(form.items.filter(i => !isVatLine(String(i.description)))))
const vatRate = ref<number | null>(null)
const vatAmount = computed(() => (vatRate.value ? Math.round(total.value * vatRate.value) / 100 : 0))
const draft = useDraft(() => (props.quotation ? null : `quotation:${props.inquiryId}`), form, {
	isEmpty: v => !v.package_name?.trim() && !v.proposal?.trim() && !v.terms?.trim() && (v.items ?? []).every(i => !String(i.description ?? '').trim() && !i.unit_price),
})
const priceOptions = ref<PriceOption[]>([])
const showPriceList = ref(false)
const busy = ref(false)
const error = ref('')

function payload(): QuotationContent {
	return {
		package_name: form.package_name?.trim() || null,
		proposal: form.proposal?.trim() || null,
		terms: form.terms?.trim() || null,
		items: form.items.filter(i => (String(i.description).trim() || i.unit_price !== '') && !isVatLine(String(i.description))).map(i => ({
			organization_service_id: i.organization_service_id ?? null,
			description: String(i.description).trim(),
			quantity: i.quantity,
			unit_price: i.unit_price === '' ? 0 : i.unit_price,
		})),
	}
}

async function save(send: boolean) {
	error.value = ''
	busy.value = true
	try {
		const q = props.quotation
		let saved: OrganizerQuotation
		if (!q) saved = await api.create(props.organizationId, props.inquiryId, payload(), send)
		else if (q.status === 'draft') {
			saved = await api.update(props.organizationId, q.id, payload())
			if (send) saved = await api.submit(props.organizationId, q.id)
		}
		else saved = await api.revise(props.organizationId, q.id, payload())
		draft.clear()
		emit('saved', saved)
	} catch (e) {
		const fields = fieldErrors(e)
		error.value = Object.values(fields)[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

onMounted(async () => {
	try {
		const org = await useOrganization().fetchOrganization()
		vatRate.value = (org?.profile as { estimate_tax_percent?: number | null } | undefined)?.estimate_tax_percent || null
		// 0062: no VAT for this request's event type when the organizer exempts it.
		const request = await useOrganizerInquiries().show(props.organizationId, props.inquiryId).catch(() => null)
		if (request && request.vat_applies === false) vatRate.value = null
	} catch {
		// Without the profile the form still works; the server adds VAT anyway.
	}
	try {
		priceOptions.value = (await listPriceOptions(props.organizationId)).filter(o => o.is_active !== false)
	} catch {
		// The price list is a convenience; the form works without it.
	}
})
</script>
