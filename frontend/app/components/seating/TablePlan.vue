<template>
	<!-- 0066/0067: choosing where to sit. The organizer's floor plan picture when there is one (it shows the
	     real room; NaSeRy doesn't read it). Rows of seats always show the seat grid below it, and buyers can
	     tap exact seats; tables/spaces show the drawn tables only without a picture. The dropdown below is the
	     main control for "any" or a whole row/table. -->
	<div class="space-y-3">
		<button v-if="image" type="button" class="block w-full overflow-hidden rounded-2xl border border-gray-200 bg-gray-50" :aria-label="`Open the floor plan (${name})`" @click="zoom = true">
			<img :src="image" :alt="`Floor plan for ${name}`" class="mx-auto max-h-72 w-auto object-contain" loading="lazy">
			<span class="flex items-center justify-center gap-1.5 border-t border-gray-200 bg-white py-1.5 text-xs font-semibold text-gray-500"><IconBase name="search" class="h-3.5 w-3.5" />Tap to enlarge the floor plan</span>
		</button>

		<div class="rounded-2xl border border-gray-200 bg-gray-50 px-1.5 py-3 sm:p-3">

			<!-- Big venues: step 1, every row as a bar (fuller = more taken); step 2, the chosen row's seats, wrapping. -->
			<div v-if="mode === 'rows' && big" :aria-label="`Seats for ${name}`">
				<p class="mb-2 text-center text-[11px] text-gray-500">Step 1: choose a row. Step 2: tap your seats.</p>
				<div class="grid max-h-64 grid-cols-1 gap-x-3 gap-y-0.5 overflow-y-auto sm:grid-cols-2">
					<button v-for="row in tables" :key="row.number" type="button" :aria-pressed="openRow === row.number"
						class="flex items-center gap-2 rounded-lg border px-2 py-1.5 text-left transition"
						:class="openRow === row.number ? 'border-[#285F6b] bg-[#285F6b]/10' : 'border-transparent hover:bg-white'"
						:aria-label="`${row.name}: ${row.left} of ${row.seats ?? size} seats left`" @click="openRow = row.number">
						<span class="w-14 shrink-0 text-xs font-extrabold text-gray-800">{{ row.name }}</span>
						<span class="h-2 flex-1 overflow-hidden rounded-full bg-white ring-1 ring-gray-200" aria-hidden="true">
							<span class="block h-full rounded-full bg-gray-300" :style="{ width: `${Math.round(100 * ((row.seats ?? size) - row.left) / (row.seats ?? size))}%` }" />
						</span>
						<span class="w-14 shrink-0 text-right text-[11px] font-semibold" :class="row.left ? 'text-gray-500' : 'text-gray-400'">{{ row.left ? `${row.left} left` : 'Full' }}</span>
						<span v-if="pickedIn(row.number)" class="shrink-0 rounded-full bg-[#285F6b] px-1.5 text-[10px] font-bold text-white">{{ pickedIn(row.number) }}</span>
					</button>
				</div>
				<div v-if="current" class="mt-3 border-t border-gray-200 pt-3">
					<p class="mb-2 text-xs font-semibold text-gray-600">{{ current.name }} · {{ current.left }} of {{ current.seats ?? size }} seats left</p>
					<div class="flex flex-wrap gap-1.5">
						<button v-for="seat in (current.seats ?? size)" :key="seat" type="button" :disabled="takenSet(current).has(seat)"
							class="flex h-8 w-8 items-center justify-center rounded-md border text-xs font-semibold transition"
							:class="takenSet(current).has(seat) ? 'cursor-not-allowed border-gray-200 bg-gray-200 text-gray-400'
								: isPicked(current.number, seat) ? 'border-[#285F6b] bg-[#285F6b] text-white'
									: 'border-[#285F6b]/40 bg-white text-gray-700 hover:border-[#285F6b]'"
							:aria-label="`${current.name}, Seat ${seat}${takenSet(current).has(seat) ? ' (taken)' : ''}`" :aria-pressed="isPicked(current.number, seat)"
							@click="toggle(current.number, seat)">{{ seat }}</button>
					</div>
				</div>
			</div>

			<!-- Rows of seats: tap exact seats; taken seats are grey. -->
			<div v-else-if="mode === 'rows'" class="max-h-80 space-y-1 overflow-auto py-1 sm:p-1" :aria-label="`Seats for ${name}`">
				<div v-for="row in tables" :key="row.number" class="mx-auto flex w-max items-center gap-[3px] sm:gap-1.5">
					<span class="w-4 shrink-0 text-right text-[11px] font-extrabold text-gray-700 sm:w-12 sm:text-xs"><span class="sm:hidden">{{ row.name.replace(/^Row /, '') }}</span><span class="hidden sm:inline">{{ row.name }}</span></span>
					<button v-for="seat in (row.seats ?? size)" :key="seat" type="button" :disabled="takenSet(row).has(seat)"
						class="flex h-[18px] w-[18px] items-center justify-center rounded-[4px] border text-[9px] font-semibold transition sm:h-6 sm:w-6 sm:rounded-[5px] sm:text-[10px]"
						:class="takenSet(row).has(seat) ? 'cursor-not-allowed border-gray-200 bg-gray-200 text-gray-400'
							: isPicked(row.number, seat) ? 'border-[#285F6b] bg-[#285F6b] text-white'
								: 'border-[#285F6b]/40 bg-white text-gray-600 hover:border-[#285F6b]'"
						:aria-label="`${row.name}, Seat ${seat}${takenSet(row).has(seat) ? ' (taken)' : ''}`" :aria-pressed="isPicked(row.number, seat)"
						@click="toggle(row.number, seat)">{{ seat }}</button>
					<span class="hidden w-12 shrink-0 text-[10px] font-semibold text-gray-500 sm:inline">{{ row.left ? `${row.left} left` : 'Full' }}</span>
				</div>
			</div>

			<!-- Tables or spaces: round, numbered. -->
			<!-- Advisor's follow-up (Nov 2026): a table per ticket, so a group can split up or be bigger than one table. -->
			<div v-else class="grid max-h-72 grid-cols-[repeat(auto-fill,minmax(3.75rem,1fr))] justify-items-center gap-2 overflow-y-auto p-1" :aria-label="`Choices for ${name}`">
				<button v-for="t in tables" :key="t.number" type="button" :aria-pressed="pickedIn(t.number) > 0 || modelValue === t.number" :disabled="!pickedIn(t.number) && roomAt(t) <= 0"
					class="relative flex h-14 w-14 flex-col items-center justify-center rounded-full border-2 text-center transition"
					:class="pickedIn(t.number) || modelValue === t.number ? 'border-[#285F6b] bg-[#285F6b] text-white shadow'
						: roomAt(t) <= 0 ? 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400'
							: 'border-[#285F6b]/30 bg-white text-gray-800 hover:border-[#285F6b]'"
					:aria-label="`${t.name}: ${t.left ? (size > 1 ? `${roomAt(t)} of ${size} seats left` : 'available') : 'taken'}${pickedIn(t.number) ? `, ${pickedIn(t.number)} of yours` : ''}`" @click="tapTable(t)">
					<span class="text-sm font-extrabold leading-none">{{ t.number }}</span>
					<span class="mt-0.5 text-[10px] font-semibold leading-none">{{ roomAt(t) > 0 ? (size > 1 ? `${roomAt(t)} left` : 'Free') : (size > 1 ? 'Full' : 'Taken') }}</span>
					<span v-if="pickedIn(t.number)" class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-amber-400 px-1 text-[10px] font-bold text-gray-900 ring-2 ring-white">{{ pickedIn(t.number) }}</span>
				</button>
			</div>
			<p v-if="!picks.length && who.length > 1" class="mt-2 text-center text-xs font-semibold text-[#285F6b]">Tap {{ mode === 'rows' ? 'seats' : `a ${label} for each ticket` }} in this order: {{ who.map((n, i) => `${i + 1}. ${n}`).join(', ') }}</p>
			<p class="mt-2 text-center text-[11px] text-gray-400">{{ mode === 'rows' ? `Rows in the organizer's order, not to scale.${image ? ' See the floor plan for where each row is.' : ''} Tap seats to pick them.` : `In number order, not to scale.${image ? ' See the floor plan for where each one is.' : ''} Tap again to add another ticket to the same one.` }}</p>
		</div>

		<!-- Seats tapped: what goes on the tickets. -->
		<!-- One line per ticket (Advisor's follow-up, Nov 2026): who sits where, and whose seat the next tap picks. -->
		<div v-if="picks.length" class="rounded-xl border border-[#285F6b]/20 bg-[#285F6b]/5 p-3">
			<div class="mb-2 flex items-center justify-between gap-2">
				<p class="text-xs font-bold uppercase tracking-[0.1em] text-gray-500">{{ mode === 'rows' ? 'Your seats' : `Your ${label}s` }}</p>
				<button type="button" class="text-xs font-semibold text-[#285F6b] hover:underline" @click="emit('update:picks', [])">Clear all</button>
			</div>
			<ol class="space-y-1.5">
				<li v-for="(row, i) in seatRows" :key="i" class="flex items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm"
					:class="row.next ? 'bg-white ring-2 ring-[#285F6b]/40' : row.seat ? 'bg-white' : ''">
					<span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold"
						:class="row.seat ? 'bg-[#285F6b] text-white' : 'bg-gray-200 text-gray-600'">{{ i + 1 }}</span>
					<span class="min-w-0 flex-1 truncate font-semibold text-gray-900">{{ row.name }}</span>
					<span class="shrink-0 text-right text-xs" :class="row.seat ? 'font-bold text-[#285F6b]' : row.next ? 'font-semibold text-gray-700' : 'text-gray-400'">
						{{ row.seat || (row.next ? (mode === 'rows' ? 'Tap a seat now' : `Tap a ${label} now`) : 'We choose') }}
					</span>
				</li>
			</ol>
			<p v-if="picks.length < need && mode !== 'rows'" class="mt-2 text-xs leading-5 text-gray-500">Tickets without a pick are placed for you: together at one {{ label }} with room when possible, otherwise at the next ones in number order.</p>
			<p v-if="picks.length < need && mode === 'rows'" class="mt-2 text-xs leading-5 text-gray-500">Tickets without a picked seat get one automatically, in the same row as your first pick, side by side when there's room. They may not be right next to your picks, and if that row is full they go to the first row with free seats. Pick every seat to choose exactly.</p>
		</div>
		<FormsSelect v-else :model-value="modelValue" :options="options" :can-clear="false" :aria-label="`Choice for ${name}`" @update:model-value="emit('update:modelValue', Number($event) || 0)" />
		<!-- Tables/spaces: who sits at the chosen one. -->
		<p v-if="mode !== 'rows' && modelValue && !picks.length && who.length" class="flex items-center gap-2 rounded-xl border border-[#285F6b]/20 bg-[#285F6b]/5 px-3 py-2 text-sm">
			<IconBase name="check" class="h-4 w-4 shrink-0 text-[#285F6b]" />
			<span><span class="font-bold text-gray-900">{{ tables.find(t => t.number === modelValue)?.name }}</span><span class="text-gray-600"> · {{ who.join(', ') }}</span></span>
		</p>
		<p v-if="mode === 'rows' && modelValue && !picks.length" class="text-xs text-gray-500">We give you {{ need > 1 ? 'seats next to each other' : 'the best seat' }} in {{ tables.find(r => r.number === modelValue)?.name }}{{ need > 1 ? ' when the row has them; otherwise the free seats closest to the middle of that row' : '' }}. Your seat numbers are on your ticket.</p>

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

const props = withDefaults(defineProps<{
	/** The ticket type's name (its section), for labels. */
	name: string
	mode: TableChoice['mode']
	/** The organizer's name for a table or space, e.g. "VIP Table", "Booth" (rows: "Row"). */
	label: string
	tables: TableChoice['tables']
	/** Seats per table/space, or the longest row. */
	size: number
	/** Places this order needs together. */
	need: number
	/** Chosen table/row number; 0 = any (we choose). */
	modelValue: number
	/** 0067: exact seats tapped, [row, seat]. */
	picks?: [number, number][]
	image?: string | null
	/** Advisor's fix (Nov 2026): attendee names in ticket order; seat N goes to name N. */
	who?: string[]
}>(), { picks: () => [], image: null, who: () => [] })
const emit = defineEmits<{ 'update:modelValue': [value: number], 'update:picks': [value: [number, number][]] }>()
const zoom = ref(false)

const takenSet = (row: TableChoice['tables'][number]) => new Set(row.taken ?? [])
// Big venues (more than 12 rows, or a row over 16 seats) pick a row first, then its seats.
const big = computed(() => props.mode === 'rows' && (props.tables.length > 12 || props.tables.some(t => (t.seats ?? props.size) > 16)))
const openRow = ref(props.tables.find(t => t.left > 0)?.number ?? props.tables[0]?.number ?? 1)
const current = computed(() => props.tables.find(t => t.number === openRow.value) ?? null)
const pickedIn = (row: number) => props.picks.filter(([r]) => r === row).length
const isPicked = (row: number, seat: number) => props.picks.some(([r, s]) => r === row && s === seat)
/** Places still free at a table/row, after this order's own picks there. */
const roomAt = (t: TableChoice['tables'][number]) => t.left - pickedIn(t.number)
/** Tables/spaces: each tap seats the next ticket there; with one ticket, a tap moves it. */
function tapTable(t: TableChoice['tables'][number]) {
	if (props.need === 1) {
		emit('update:picks', pickedIn(t.number) ? [] : [[t.number, 0]])
	} else if (roomAt(t) > 0) {
		emit('update:picks', [...props.picks, [t.number, 0] as [number, number]].slice(-props.need))
	} else return
	if (props.modelValue) emit('update:modelValue', 0)
}
function toggle(row: number, seat: number) {
	if (isPicked(row, seat)) {
		emit('update:picks', props.picks.filter(([r, s]) => !(r === row && s === seat)))
		return
	}
	// One seat per ticket: when they're all picked, the oldest pick moves.
	const next: [number, number][] = [...props.picks, [row, seat]]
	emit('update:picks', next.slice(-props.need))
	if (props.modelValue) emit('update:modelValue', 0)
}
/** One line per ticket in this section: its name, its tapped seat, and whether the next tap is for it. */
const seatRows = computed(() => Array.from({ length: props.need }, (_, i) => {
	const pick = props.picks[i]
	return {
		name: props.who[i] || `Ticket ${i + 1}`,
		seat: pick ? (props.mode === 'rows' ? `${props.tables.find(t => t.number === pick[0])?.name ?? `Row ${pick[0]}`}, Seat ${pick[1]}` : props.tables.find(t => t.number === pick[0])?.name ?? `${props.label} ${pick[0]}`) : '',
		next: i === props.picks.length,
	}
}))
const options = computed(() => {
	const any = props.mode === 'rows'
		? 'Best available: seats together, from the first row'
		: props.need > props.size ? `Any ${props.label}s: we fill them in order (first come, first served)`
			: props.need > 1 ? `Any ${props.label}: keep us together (first come, first served)` : `Any ${props.label} (first come, first served)`
	const left = (t: TableChoice['tables'][number]) => props.mode === 'rows' ? `${t.left} of ${t.seats ?? props.size} seats left` : props.size > 1 ? `${t.left} of ${props.size} seats left` : 'available'
	return [{ value: 0, label: any }, ...props.tables.filter(t => t.left >= props.need).map(t => ({ value: t.number, label: `${t.name} · ${left(t)}` }))]
})
</script>
