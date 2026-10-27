<template>
	<div>
		<label class="block max-w-xs">
			<span class="text-sm font-semibold text-gray-700">Expected guests</span>
			<!-- Whole guests, 1–100,000 (the inquiry limit), so the estimate and the request agree. -->
			<input :value="guests" type="number" min="1" :max="MAX_GUESTS" step="1" inputmode="numeric"
				class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm focus:border-primary-500 focus:outline-none"
				@input="guests = clampGuests(($event.target as HTMLInputElement).value)"
				@blur="($event.target as HTMLInputElement).value = String(guests)">
		</label>

		<p v-if="!groups.length" class="mt-5 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500">No price options yet.</p>

		<fieldset v-for="group in groups" :key="group.key" class="mt-5">
			<legend class="text-sm font-bold text-gray-900">{{ group.label }}
				<span class="font-normal text-gray-400">· {{ group.single ? 'choose one' : 'choose any' }}</span></legend>
			<div class="mt-2 grid gap-2" :class="compact ? '' : 'sm:grid-cols-2'">
				<div v-for="o in group.options" :key="o.id"
					class="min-w-0 rounded-xl border text-sm transition" :class="[compact ? 'px-3 py-2' : 'px-3.5 py-2.5', o.id in chosen ? 'border-primary-600 bg-primary-50' : 'border-gray-200 hover:border-primary-200']">
					<!-- Compact (narrow panels): price goes under the name so nothing spills out. -->
					<label class="flex cursor-pointer items-start justify-between gap-3" :class="compact ? 'flex-wrap gap-y-0.5' : ''">
						<span class="flex min-w-0 items-start gap-2.5">
							<input :type="group.single ? 'radio' : 'checkbox'" :name="`${uid}-${group.key}`" :checked="o.id in chosen" class="mt-1 accent-[#285F6b]"
								:aria-label="`${group.label}: ${o.name}`" @click="toggle(group, o)">
							<span class="min-w-0 break-words">
								<span class="font-semibold">{{ o.name }}</span>
								<span v-if="o.description" class="block text-xs text-gray-500">{{ o.description }}</span>
								<span v-if="o.pricing_unit === 'per_guest' && o.min_guests" class="block text-xs text-gray-400">Minimum {{ o.min_guests }} guests</span>
								<span v-else-if="picksQuantity(o) && (o.min_quantity || o.max_quantity)" class="block text-xs text-gray-400">{{ rangeText(o) }}</span>
							</span>
						</span>
						<span class="font-semibold text-gray-700" :class="compact ? 'basis-full pl-6' : 'shrink-0 whitespace-nowrap text-right'">{{ formatPeso(o.price) }}<span class="font-normal text-gray-500">{{ priceSuffix(o) }}</span></span>
					</label>
					<div v-if="o.id in chosen && picksQuantity(o)" class="mt-2 flex items-center gap-2 pl-6">
						<span class="text-xs text-gray-500">How many {{ unitName(o, 2) }}?</span>
						<button type="button" class="h-7 w-7 rounded-lg border border-gray-200 bg-white font-bold text-gray-700 disabled:opacity-40"
							:disabled="chosen[o.id]! <= quantityBounds(o).min" :aria-label="`Fewer ${unitName(o, 2)}`" @click="setQty(o, chosen[o.id]! - 1)">−</button>
						<input :value="chosen[o.id]" type="number" :min="quantityBounds(o).min" :max="quantityBounds(o).max ?? undefined"
							class="w-16 rounded-lg border border-gray-200 bg-white px-2 py-1 text-center text-sm" :aria-label="`Number of ${unitName(o, 2)}`"
							@change="setQty(o, Number(($event.target as HTMLInputElement).value))">
						<button type="button" class="h-7 w-7 rounded-lg border border-gray-200 bg-white font-bold text-gray-700 disabled:opacity-40"
							:disabled="quantityBounds(o).max !== null && chosen[o.id]! >= quantityBounds(o).max!" :aria-label="`More ${unitName(o, 2)}`" @click="setQty(o, chosen[o.id]! + 1)">+</button>
					</div>
				</div>
			</div>
		</fieldset>

		<!-- Breakdown -->
		<div class="mt-6 rounded-xl bg-primary-50 p-4">
			<div class="text-xs font-bold uppercase tracking-wide text-primary-700">Estimate</div>
			<p v-if="!estimate.lines.length" class="mt-2 text-sm text-gray-500">Pick the options you need to see a breakdown.</p>
			<table v-else class="mt-2 w-full text-sm">
				<tbody>
					<tr v-for="line in estimate.lines" :key="line.id" class="align-top">
						<td class="py-1 pr-3">
							<div class="font-semibold text-gray-800">{{ line.label }}</div>
							<div class="text-xs text-gray-500">{{ line.detail }}</div>
						</td>
						<td class="py-1 text-right font-semibold text-gray-800">{{ formatPeso(line.subtotal) }}</td>
					</tr>
				</tbody>
				<tfoot class="border-t border-primary-200">
					<tr v-if="estimate.serviceCharge || estimate.tax">
						<td class="pt-2 text-gray-600">Subtotal</td>
						<td class="pt-2 text-right text-gray-700">{{ formatPeso(estimate.subtotal) }}</td>
					</tr>
					<tr v-if="estimate.serviceCharge">
						<td class="text-gray-600">Service charge ({{ estimate.serviceChargePercent }}%)</td>
						<td class="text-right text-gray-700">{{ formatPeso(estimate.serviceCharge) }}</td>
					</tr>
					<tr v-if="estimate.tax">
						<td class="text-gray-600">VAT ({{ estimate.taxPercent }}%)</td>
						<td class="text-right text-gray-700">{{ formatPeso(estimate.tax) }}</td>
					</tr>
					<tr>
						<td class="pt-2 font-bold text-primary-900">Estimated total</td>
						<td class="pt-2 text-right text-xl font-extrabold text-primary-900">{{ formatPeso(estimate.total) }}</td>
					</tr>
				</tfoot>
			</table>
			<slot name="action" :estimate="estimate" :picks="picksParam" :guests="guests" />
		</div>
		<p class="mt-2 text-xs text-gray-400">
			{{ settings?.note ? `${settings.note} ` : '' }}An estimate only; the organizer confirms the final price in their quotation.
		</p>
	</div>
</template>

<script setup lang="ts">
// Client-facing calculator: one pick per service group (alternatives), add-ons
// freely; hour/day/item options get a quantity within the organizer's limits.
import type { PriceOption } from '~/composables/useQuotations'
import type { EstimateSettings } from '~/utils/priceCalc'
import { MAX_GUESTS, clampGuests } from '~/utils/priceCalc'

const props = withDefaults(defineProps<{
	options: PriceOption[]
	services?: { id: string, name: string }[]
	settings?: EstimateSettings | null
	initialGuests?: number
	/** Narrow side panel: one option per row, price under the name. */
	compact?: boolean
}>(), { services: () => [], settings: null, initialGuests: 100, compact: false })

const uid = useId()
const guests = ref(clampGuests(props.initialGuests))
const chosen = reactive<Record<string, number>>({})

const groups = computed(() => {
	const sorted = [...props.options].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
	const byService = new Map<string | null, PriceOption[]>()
	for (const o of sorted) byService.set(o.organization_service_id, [...(byService.get(o.organization_service_id) ?? []), o])
	return [...byService.entries()]
		.map(([serviceId, options]) => ({
			key: serviceId ?? 'addons',
			label: serviceId ? props.services.find(s => s.id === serviceId)?.name ?? 'Options' : 'Add-ons',
			single: serviceId !== null,
			options,
		}))
		.sort((a, b) => Number(!a.single) - Number(!b.single))
})

function toggle(group: { single: boolean, options: PriceOption[] }, o: PriceOption) {
	if (o.id in chosen) {
		delete chosen[o.id]
		return
	}
	if (group.single) group.options.forEach(x => delete chosen[x.id])
	chosen[o.id] = quantityBounds(o).min
}

function setQty(o: PriceOption, n: number) {
	const { min, max } = quantityBounds(o)
	chosen[o.id] = Math.min(max ?? 100000, Math.max(min, Math.floor(n) || min))
}

function rangeText(o: PriceOption) {
	const { min, max } = quantityBounds(o)
	const unit = unitName(o, 2)
	return max ? `${min}–${max} ${unit}` : `At least ${min} ${unitName(o, min)}`
}

const estimate = computed(() => buildEstimate(props.options, chosen, guests.value, props.settings ?? {}))
const picksParam = computed(() => encodePicks(props.options, chosen))
</script>
