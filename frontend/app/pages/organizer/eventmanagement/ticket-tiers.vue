<template>
	<div>
		<OrganizerEventPicker :model-value="eventId" />

		<Alert v-if="error" type="danger" :text="error" />
		<Alert v-if="notice" type="success" :text="notice" />
		<div v-if="eventId && !data && !error" class="py-16 text-center text-sm text-gray-500">Loading tiers…</div>

		<template v-if="data && eventId">
			<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
				<p class="text-sm text-gray-500">
					{{ data.summary.total }} tickets issued<template v-if="data.overall_capacity"> of {{ data.overall_capacity }} event capacity</template>
					· {{ data.summary.used }} checked in
				</p>
				<button v-if="data.can_manage" type="button"
					class="flex shrink-0 items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#1f4a54]"
					@click="edit(null)">
					<IconBase name="plus" class="h-4 w-4" /> Add Tier
				</button>
			</div>

			<div v-if="!data.tiers.length" class="rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/60 p-10 text-center">
				<h3 class="text-base font-bold text-gray-900">No tiers yet</h3>
				<p class="mt-1 text-sm text-gray-500">Add a tier for each kind of ticket or invitation: e.g. “Guest” (free, private events) or “VIP – Front Stage”.</p>
			</div>

			<div class="space-y-5">
				<div v-for="tier in data.tiers" :key="tier.id" class="rounded-2xl border border-gray-200 bg-white p-6">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div>
							<h3 class="text-lg font-bold text-gray-900">{{ tier.name }}<span v-if="tier.zone_name" class="text-sm font-semibold text-primary-700"> · {{ tier.zone_name }}</span></h3>
							<p class="mt-1 text-sm text-gray-500">{{ priceLabel(tier.price) }} · {{ tier.sold }} / {{ tier.capacity }} issued<template v-if="tier.held"> · {{ tier.held }} being paid for</template></p>
						</div>
						<div class="flex shrink-0 items-center gap-2">
							<span class="rounded-full px-3 py-1 text-xs font-semibold" :class="tier.status === 'on_sale' ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'">{{ tierStatusLabels[tier.status] }}</span>
							<button v-if="data.can_manage" type="button" class="rounded-lg border border-gray-200 p-2 text-gray-600 hover:bg-gray-50" :aria-label="`Edit ${tier.name}`" @click="edit(tier)">
								<IconBase name="edit" class="h-4 w-4" />
							</button>
							<button v-if="data.can_manage && !tier.sold && !tier.held" type="button" class="rounded-lg border border-gray-200 p-2 text-rose-600 hover:bg-rose-50" :aria-label="`Delete ${tier.name}`" @click="remove(tier)">
								<IconBase name="trash" class="h-4 w-4" />
							</button>
						</div>
					</div>
					<div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-gray-100">
						<div class="h-full rounded-full bg-[#285F6b]" :style="{ width: `${Math.min(100, (tier.sold + tier.held) / tier.capacity * 100)}%` }" />
					</div>
					<p v-if="tier.description" class="mt-3 text-sm text-gray-600">{{ tier.description }}</p>
					<ul v-if="tier.benefits.length" class="mt-3 flex flex-wrap gap-2">
						<li v-for="b in tier.benefits" :key="b" class="rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-600">{{ b }}</li>
					</ul>
					<p v-if="tier.sales_start_at || tier.sales_end_at" class="mt-3 text-xs text-gray-500">
						Sales {{ tier.sales_start_at ? `from ${when(tier.sales_start_at)}` : '' }} {{ tier.sales_end_at ? `until ${when(tier.sales_end_at)}` : '' }}
					</p>
				</div>
			</div>
			<p class="mt-4 text-xs text-gray-400">“On sale” tiers of a public, published event appear on its public page. Guest lists can use any tier.</p>
		</template>

		<div v-if="editing" class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" @click.self="editing = null">
			<form class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6" @submit.prevent="save">
				<h2 class="text-lg font-bold text-gray-900">{{ editing.id ? 'Edit tier' : 'New tier' }}</h2>
				<Alert v-if="formError" type="danger" :text="formError" class="mt-3" />
				<div class="mt-4 grid grid-cols-2 gap-3">
					<div class="col-span-2">
						<FormsLabel text="Name" required />
						<FormsTextField v-model="editing.name" placeholder="e.g. VIP" />
					</div>
					<div>
						<FormsLabel text="Access Zone" />
						<FormsTextField v-model="editing.zone_name" placeholder="e.g. Front Stage" />
					</div>
					<div>
						<FormsLabel text="Status" />
						<select v-model="editing.status" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm" aria-label="Status">
							<option v-for="(label, key) in tierStatusLabels" :key="key" :value="key">{{ label }}</option>
						</select>
					</div>
					<div>
						<FormsLabel text="Price (₱)" required />
						<FormsTextField v-model="editing.price" type="number" min="0" step="0.01" />
					</div>
					<div>
						<FormsLabel text="Capacity" required />
						<FormsTextField v-model="editing.capacity" type="number" min="1" />
					</div>
					<div>
						<FormsLabel text="Sales open" />
						<input v-model="editing.sales_start_at" type="datetime-local" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-2 py-2 text-sm" aria-label="Sales open">
					</div>
					<div>
						<FormsLabel text="Sales close" />
						<input v-model="editing.sales_end_at" type="datetime-local" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-2 py-2 text-sm" aria-label="Sales close">
					</div>
					<div class="col-span-2">
						<FormsLabel text="Description" />
						<textarea v-model="editing.description" rows="2" maxlength="1000" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm" />
					</div>
					<div class="col-span-2">
						<FormsLabel text="Benefits (one per line)" />
						<textarea v-model="editing.benefitsText" rows="3" placeholder="Meet & greet&#10;Free drink" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm" />
					</div>
				</div>
				<p v-if="Number(editing.price) > 0" class="mt-3 text-xs text-amber-700">Paid tiers reserve places at checkout; online payment is enabled in the next release.</p>
				<div class="mt-5 flex justify-end gap-2">
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-semibold" @click="editing = null">Cancel</button>
					<button type="submit" :disabled="busy" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save tier</button>
				</div>
			</form>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { TicketSummary, TicketTier, TierStatus } from '~/composables/useTicketing'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const api = useOrganizerTicketing()

const eventId = computed(() => typeof route.query.event === 'string' ? route.query.event : null)
const data = ref<{ tiers: TicketTier[], summary: TicketSummary, overall_capacity: number | null, can_manage: boolean } | null>(null)
const error = ref('')
const notice = ref('')
const formError = ref('')
const busy = ref(false)
type Draft = { id: string | null, name: string, zone_name: string, status: TierStatus, price: string | number, capacity: string | number, sales_start_at: string, sales_end_at: string, description: string, benefitsText: string }
const editing = ref<Draft | null>(null)

const orgId = computed(() => organization.value?.id ?? '')

function when(at: string) {
	return new Date(at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' })
}

function edit(tier: TicketTier | null) {
	formError.value = ''
	editing.value = tier
		? { id: tier.id, name: tier.name, zone_name: tier.zone_name ?? '', status: tier.status, price: Number(tier.price), capacity: tier.capacity,
			sales_start_at: tier.sales_start_local ?? '', sales_end_at: tier.sales_end_local ?? '', description: tier.description ?? '', benefitsText: tier.benefits.join('\n') }
		: { id: null, name: '', zone_name: '', status: 'draft', price: 0, capacity: 100, sales_start_at: '', sales_end_at: '', description: '', benefitsText: '' }
}

async function load() {
	if (!eventId.value) return
	data.value = null
	error.value = ''
	try {
		const org = organization.value ?? await fetchOrganization()
		if (org) data.value = await api.tiers(org.id, eventId.value)
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function save() {
	const d = editing.value!
	formError.value = ''
	busy.value = true
	const body = {
		name: d.name.trim(), zone_name: d.zone_name.trim() || null, status: d.status, price: Number(d.price), capacity: Number(d.capacity),
		sales_start_at: d.sales_start_at || null, sales_end_at: d.sales_end_at || null, description: d.description.trim() || null,
		benefits: d.benefitsText.split('\n').map(b => b.trim()).filter(Boolean),
	}
	try {
		d.id ? await api.updateTier(orgId.value, eventId.value!, d.id, body) : await api.createTier(orgId.value, eventId.value!, body)
		editing.value = null
		notice.value = 'Tier saved.'
		await load()
	} catch (e) {
		formError.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		busy.value = false
	}
}

async function remove(tier: TicketTier) {
	if (!confirm(`Delete the ${tier.name} tier?`)) return
	try {
		await api.removeTier(orgId.value, eventId.value!, tier.id)
		notice.value = 'Tier deleted.'
		await load()
	} catch (e) {
		error.value = authMessage(e)
	}
}

watch(eventId, load)
onMounted(load)
</script>
