<template>
	<!-- 0066: choosing where to sit. The organizer's floor plan picture when there is one; otherwise a
	     simple layout drawn here: round tables/spaces, or rows of seats, with the stage at the front.
	     The dropdown below is the main control; tapping a table or row in the layout picks it too. -->
	<div class="space-y-3">
		<button v-if="image" type="button" class="block w-full overflow-hidden rounded-2xl border border-gray-200 bg-gray-50" :aria-label="`Open the floor plan (${name})`" @click="zoom = true">
			<img :src="image" :alt="`Floor plan for ${name}`" class="mx-auto max-h-72 w-auto object-contain" loading="lazy">
			<span class="flex items-center justify-center gap-1.5 border-t border-gray-200 bg-white py-1.5 text-xs font-semibold text-gray-500"><IconBase name="search" class="h-3.5 w-3.5" />Tap to enlarge the floor plan</span>
		</button>

		<div v-else class="rounded-2xl border border-gray-200 bg-gray-50 p-3">
			<div class="mx-auto mb-3 w-2/3 rounded-lg bg-gray-800 py-1.5 text-center text-[11px] font-bold uppercase tracking-widest text-white">Stage / front</div>

			<!-- Rows of seats: one line per row, taken seats greyed. -->
			<div v-if="mode === 'rows'" class="max-h-72 space-y-1 overflow-auto p-1" role="radiogroup" :aria-label="`Rows for ${name}`">
				<button v-for="row in tables" :key="row.number" type="button" role="radio" :aria-checked="modelValue === row.number" :disabled="row.left < need"
					class="mx-auto flex w-max items-center gap-2 rounded-lg px-2 py-1 transition"
					:class="modelValue === row.number ? 'bg-[#285F6b]/10 ring-2 ring-[#285F6b]' : row.left < need ? 'cursor-not-allowed opacity-50' : 'hover:bg-white'"
					:aria-label="`${row.name}: ${row.left ? `${row.left} of ${size} seats left` : 'full'}`" @click="emit('update:modelValue', modelValue === row.number ? 0 : row.number)">
					<span class="w-7 text-right text-xs font-extrabold text-gray-700">{{ row.name.replace('Row ', '') }}</span>
					<span class="flex gap-[3px]" aria-hidden="true">
						<span v-for="seat in size" :key="seat" class="h-3 w-3 rounded-[3px]"
							:class="takenSet(row).has(seat) ? 'bg-gray-300' : modelValue === row.number ? 'bg-[#285F6b]' : 'border border-[#285F6b]/50 bg-white'" />
					</span>
					<span class="w-14 text-left text-[10px] font-semibold text-gray-500">{{ row.left ? `${row.left} left` : 'Full' }}</span>
				</button>
			</div>

			<!-- Tables or spaces: round, numbered. -->
			<div v-else class="grid max-h-72 grid-cols-[repeat(auto-fill,minmax(3.75rem,1fr))] justify-items-center gap-2 overflow-y-auto p-1" role="radiogroup" :aria-label="`Choices for ${name}`">
				<button v-for="t in tables" :key="t.number" type="button" role="radio" :aria-checked="modelValue === t.number" :disabled="t.left < need"
					class="flex h-14 w-14 flex-col items-center justify-center rounded-full border-2 text-center transition"
					:class="modelValue === t.number ? 'border-[#285F6b] bg-[#285F6b] text-white shadow'
						: t.left < need ? 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400'
							: 'border-[#285F6b]/30 bg-white text-gray-800 hover:border-[#285F6b]'"
					:aria-label="`${t.name}: ${t.left ? (size > 1 ? `${t.left} of ${size} seats left` : 'available') : 'taken'}`" @click="emit('update:modelValue', modelValue === t.number ? 0 : t.number)">
					<span class="text-sm font-extrabold leading-none">{{ t.number }}</span>
					<span class="mt-0.5 text-[10px] font-semibold leading-none">{{ t.left ? (size > 1 ? `${t.left} left` : 'Free') : (size > 1 ? 'Full' : 'Taken') }}</span>
				</button>
			</div>
			<p class="mt-2 text-center text-[11px] text-gray-400">{{ mode === 'rows' ? 'A simple layout, not to scale: Row A is nearest the stage.' : 'A simple layout, not to scale: lower numbers are nearest the front.' }}</p>
		</div>

		<FormsSelect :model-value="modelValue" :options="options" :can-clear="false" :aria-label="`Choice for ${name}`" @update:model-value="emit('update:modelValue', Number($event) || 0)" />
		<p v-if="mode === 'rows' && modelValue" class="text-xs text-gray-500">We give you {{ need > 1 ? 'seats next to each other' : 'the best seat' }} in {{ tables.find(r => r.number === modelValue)?.name }}{{ need > 1 ? ' when the row has them' : '' }}. Your seat numbers are on your ticket.</p>

		<Teleport v-if="zoom && image" to="body">
			<div class="fixed inset-0 z-[300] flex items-center justify-center bg-gray-950/85 p-4" role="dialog" aria-modal="true" aria-label="Floor plan" @click.self="zoom = false" @keydown.esc="zoom = false">
				<img :src="image" :alt="`Floor plan for ${name}`" class="max-h-[90dvh] max-w-full rounded-xl object-contain">
				<button type="button" class="absolute right-4 top-4 flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-gray-900" aria-label="Close" @click="zoom = false"><IconBase name="x" class="h-5 w-5" /></button>
			</div>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
import type { TableChoice } from '~/composables/useTicketing'

const props = defineProps<{
	/** The ticket type's name, for labels. */
	name: string
	mode: TableChoice['mode']
	/** The organizer's name for a table or space, e.g. "VIP Table", "Booth" (rows: "Row"). */
	label: string
	tables: TableChoice['tables']
	/** Seats per table/space, or per row. */
	size: number
	/** Places this order needs together. */
	need: number
	/** Chosen table/row number; 0 = any (we choose). */
	modelValue: number
	image?: string | null
}>()
const emit = defineEmits<{ 'update:modelValue': [value: number] }>()
const zoom = ref(false)

const takenSet = (row: TableChoice['tables'][number]) => new Set(row.taken ?? [])
const options = computed(() => {
	const any = props.mode === 'rows'
		? 'Best available: front rows first, seats together'
		: props.need > 1 ? `Any ${props.label}: keep us together (first come, first served)` : `Any ${props.label} (first come, first served)`
	const left = (t: TableChoice['tables'][number]) => props.mode === 'rows' || props.size > 1 ? `${t.left} of ${props.size} seats left` : 'available'
	return [{ value: 0, label: any }, ...props.tables.filter(t => t.left >= props.need).map(t => ({ value: t.number, label: `${t.name} · ${left(t)}` }))]
})
</script>
