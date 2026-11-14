<template>
	<section v-if="inquiry" class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
		<button type="button" class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left sm:px-6"
			:class="collapsible ? 'hover:bg-gray-50' : 'cursor-default'" :aria-expanded="open" @click="collapsible && (open = !open)">
			<div class="flex min-w-0 items-center gap-3">
				<span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700"><IconBase name="file-text" class="h-5 w-5" /></span>
				<div class="min-w-0">
					<h3 class="text-base font-bold text-gray-950">What {{ firstName }} asked for</h3>
					<p class="truncate text-xs text-gray-500">{{ summary }}</p>
				</div>
			</div>
			<IconBase v-if="collapsible" name="chevron-down" class="h-5 w-5 shrink-0 text-gray-400 transition-transform" :class="open ? 'rotate-180' : ''" />
		</button>

		<div v-if="open" class="space-y-5 border-t border-gray-100 px-5 py-5 sm:px-6">
			<p v-if="inquiry.revised_at" class="rounded-xl bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">The client changed this request on {{ date(inquiry.revised_at) }}.</p>

			<dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm" :class="compact ? '' : 'sm:grid-cols-4'">
				<div><dt class="text-xs font-medium text-gray-500">Event</dt><dd class="font-semibold text-gray-900">{{ inquiry.event_type || 'Event' }}</dd></div>
				<div><dt class="text-xs font-medium text-gray-500">Date</dt><dd class="font-semibold text-gray-900">{{ inquiry.requested_date ? date(inquiry.requested_date) : 'Not set' }}</dd></div>
				<div><dt class="text-xs font-medium text-gray-500">Guests</dt><dd class="font-semibold text-gray-900">{{ inquiry.guest_count ?? '—' }}</dd></div>
				<div><dt class="text-xs font-medium text-gray-500">Budget</dt><dd class="font-semibold text-gray-900">{{ budgetLabel(inquiry.budget_min, inquiry.budget_max) }}</dd></div>
				<div v-if="inquiry.location_text" class="col-span-2" :class="compact ? '' : 'sm:col-span-4'"><dt class="text-xs font-medium text-gray-500">Location</dt><dd class="font-semibold text-gray-900">{{ inquiry.location_text }}</dd></div>
			</dl>

			<div v-if="inquiry.services.length">
				<p class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">Services they need</p>
				<ul class="space-y-2">
					<li v-for="(s, i) in inquiry.services" :key="i" class="rounded-xl bg-gray-50 px-3 py-2.5 text-sm">
						<span class="font-semibold text-gray-900">{{ s.name || 'Service' }}</span>
						<span v-if="s.notes" class="mt-0.5 block whitespace-pre-line text-gray-600">{{ s.notes }}</span>
					</li>
				</ul>
			</div>

			<div v-if="inquiry.additional_requirements">
				<p class="mb-1 text-xs font-bold uppercase tracking-wide text-gray-400">Their notes</p>
				<p class="whitespace-pre-line text-sm leading-6 text-gray-700">{{ inquiry.additional_requirements }}</p>
			</div>

			<div v-if="quotation">
				<p class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">Your accepted offer<template v-if="quotation.package_name"> · {{ quotation.package_name }}</template></p>
				<div class="rounded-xl border border-gray-100">
					<div v-for="(line, i) in receipt.items" :key="i" class="flex items-start justify-between gap-3 border-b border-gray-100 px-3 py-2 text-sm">
						<span class="min-w-0 text-gray-700">{{ line.description }}<span v-if="line.quantity !== 1" class="text-gray-400"> × {{ line.quantity.toLocaleString('en-PH') }}</span></span>
						<span class="shrink-0 font-semibold text-gray-900">{{ formatPeso(line.amount) }}</span>
					</div>
					<template v-if="receipt.charges.length">
						<div class="flex justify-between gap-3 border-b border-gray-100 bg-gray-50/60 px-3 py-2 text-sm text-gray-600"><span>Subtotal</span><span>{{ formatPeso(receipt.subtotal) }}</span></div>
						<div v-for="(c, i) in receipt.charges" :key="`c${i}`" class="flex justify-between gap-3 border-b border-gray-100 px-3 py-2 text-sm text-gray-600">
							<span>{{ c.label }} <span class="text-gray-400">({{ c.percent }}%)</span></span><span>{{ formatPeso(c.amount) }}</span>
						</div>
					</template>
					<div class="flex justify-between gap-3 bg-gray-50 px-3 py-2.5 text-sm font-extrabold text-gray-950">
						<span>Total</span><span>{{ formatPeso(quotation.total_amount) }}</span>
					</div>
				</div>
				<p v-if="quotation.terms" class="mt-2 text-xs text-gray-500"><span class="font-semibold">Terms:</span> {{ quotation.terms }}</p>
			</div>

			<div v-if="!compact" class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
				<button type="button" class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-primary-700 px-4 text-sm font-bold text-white transition hover:bg-primary-800 disabled:opacity-60"
					:disabled="opening" @click="message">
					<IconBase name="message" class="h-4 w-4" /> {{ opening ? 'Opening…' : `Message ${firstName}` }}
				</button>
				<a v-if="quotation?.client?.phone" :href="`tel:${quotation.client.phone}`" class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
					<IconBase name="phone" class="h-4 w-4" /> Call
				</a>
				<a v-if="quotation?.client?.email" :href="`mailto:${quotation.client.email}`" class="inline-flex min-h-10 items-center gap-2 rounded-xl border border-gray-200 px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50">
					<IconBase name="send" class="h-4 w-4" /> E-mail
				</a>
				<p v-if="messageError" class="w-full text-xs text-amber-700">{{ messageError }}</p>
			</div>
		</div>

		<OrganizerThreadDrawer :thread-id="threads.open.value?.id ?? null" :title="threads.open.value?.title ?? ''"
			@close="threads.open.value = null" @read="threads.markRead()" />
	</section>
</template>

<script setup lang="ts">
// What the client asked for, next to the event the organizer is preparing (review
// finding #3): the request, the accepted offer and a way to reach the client.
// Hidden for events without a request, and for members who can't see requests.
import type { OrganizerInquiry } from '~/composables/useInquiries'
import type { OrganizerQuotation } from '~/composables/useQuotations'
import { budgetLabel } from '~/composables/useInquiries'
import { formatPeso } from '~/composables/useMarketplace'

const props = defineProps<{ organizationId: string, inquiryId: string | null, quotationId?: string | null, collapsible?: boolean, compact?: boolean, startOpen?: boolean }>()

const inquiry = ref<OrganizerInquiry | null>(null)
const quotation = ref<OrganizerQuotation | null>(null)
const open = ref(!props.collapsible || Boolean(props.startOpen))
const threads = useOrganizerThreads()
const opening = ref(false)
const messageError = ref('')

const firstName = computed(() => (quotation.value?.client?.name ?? inquiry.value?.client ?? 'the client').split(/\s+/)[0])
const summary = computed(() => {
	const i = inquiry.value
	if (!i) return ''
	return [i.event_type, i.guest_count ? `${i.guest_count} guests` : null, i.budget_min || i.budget_max ? budgetLabel(i.budget_min, i.budget_max) : null, quotation.value ? `offer ${formatPeso(quotation.value.total_amount)}` : null].filter(Boolean).join(' · ')
})
// The accepted offer as a receipt: items, subtotal, then service charge / VAT.
const receipt = computed(() => {
	const charge = /^(Service charge|VAT) \((\d+(?:\.\d+)?)%\)$/
	const lines = (quotation.value?.items ?? []).map(l => ({ description: l.description, quantity: Number(l.quantity), amount: Number(l.subtotal ?? Number(l.quantity) * Number(l.unit_price)) }))
	const items = lines.filter(l => !charge.test(l.description))
	const charges = lines.flatMap((l) => { const m = l.description.match(charge); return m ? [{ label: m[1]!, percent: m[2]!, amount: l.amount }] : [] })
	return { items, charges, subtotal: items.reduce((sum, l) => sum + l.amount, 0) }
})
const date = (at: string) => new Date(at.length === 10 ? `${at}T00:00:00` : at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })

async function message() {
	if (!inquiry.value) return
	opening.value = true
	messageError.value = ''
	try {
		await threads.openFor(props.organizationId, inquiry.value.inquiry_id, `${firstName.value} · ${inquiry.value.event_type ?? 'Event'}`)
		if (!threads.open.value) messageError.value = 'There is no conversation with this client yet.'
	} catch (e) {
		messageError.value = authMessage(e) || 'Unable to open messages.'
	} finally {
		opening.value = false
	}
}

async function load() {
	inquiry.value = null
	quotation.value = null
	if (!props.organizationId || !props.inquiryId) return
	try {
		const [i, q] = await Promise.all([
			useOrganizerInquiries().show(props.organizationId, props.inquiryId),
			props.quotationId ? useOrganizerQuotations().show(props.organizationId, props.quotationId).catch(() => null) : Promise.resolve(null),
		])
		inquiry.value = i
		quotation.value = q
	} catch {
		// No access to requests (e.g. check-in staff) or no request behind this event: show nothing.
	}
}

watch(() => [props.organizationId, props.inquiryId, props.quotationId], load, { immediate: true })
</script>
