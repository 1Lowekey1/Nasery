<template>
	<!-- A compact picker for long tag lists (event types, services): one field that opens a panel with search and filter buttons. -->
	<div ref="root" class="relative">
		<button type="button" :id="id" class="flex min-h-12 w-full items-center gap-2 rounded-xl border bg-white px-3 py-2 text-left transition"
			:class="open ? 'border-primary-500 ring-4 ring-primary-500/10' : invalid ? 'border-rose-300' : 'border-gray-200 hover:border-primary-300'"
			:aria-expanded="open" :aria-controls="`${id}-panel`" @click="toggle">
			<span class="flex min-w-0 flex-1 flex-wrap gap-1.5">
				<template v-if="selectedOptions.length">
					<span v-for="o in selectedOptions" :key="o.value" class="inline-flex max-w-full items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-xs font-bold text-primary-800">
						<IconBase v-if="o.icon" :name="o.icon" class="h-3.5 w-3.5 shrink-0" />
						<span class="truncate">{{ o.label }}</span>
					</span>
				</template>
				<span v-else class="py-1 text-sm text-gray-400">{{ placeholder }}</span>
			</span>
			<IconBase name="chevron-down" class="h-4 w-4 shrink-0 text-gray-400 transition" :class="{ 'rotate-180': open }" />
		</button>

		<div v-if="open" :id="`${id}-panel`" class="absolute left-0 right-0 z-30 mt-2 rounded-2xl border border-gray-200 bg-white p-3 shadow-xl" role="dialog" :aria-label="placeholder" @keydown.esc.stop="close">
			<div class="relative">
				<IconBase name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
				<input ref="searchInput" v-model="query" type="search" :placeholder="searchPlaceholder" :aria-label="searchPlaceholder"
					class="h-10 w-full rounded-xl border border-gray-200 bg-gray-50 pl-9 pr-3 text-sm outline-none focus:border-primary-500 focus:bg-white">
			</div>

			<div v-if="groupNames.length > 1" class="chip-row mt-2 flex flex-wrap gap-1.5" role="tablist" aria-label="Filter">
				<button v-for="g in ['All', ...groupNames]" :key="g" type="button" role="tab" :aria-selected="group === g"
					class="min-h-8 rounded-full px-3 text-xs font-bold transition" :class="group === g ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
					@click="group = g">{{ g }}</button>
			</div>

			<div class="mt-3 max-h-64 overflow-y-auto pr-1">
				<div v-if="shown.length" class="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
					<button v-for="o in shown" :key="o.value" type="button" :aria-pressed="isSelected(o.value)"
						class="flex min-h-11 items-center gap-2.5 rounded-xl border px-3 py-2 text-left transition"
						:class="isSelected(o.value) ? 'border-primary-700 bg-primary-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300 hover:bg-primary-50/40'"
						@click="pick(o.value)">
						<span v-if="o.icon" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg" :class="isSelected(o.value) ? 'bg-white/15' : 'bg-gray-100 text-gray-500'">
							<IconBase :name="o.icon" class="h-3.5 w-3.5" />
						</span>
						<span class="min-w-0 flex-1">
							<span class="block break-words text-sm font-bold">{{ o.label }}</span>
							<span v-if="o.hint" class="block text-xs" :class="isSelected(o.value) ? 'text-white/75' : 'text-gray-500'">{{ o.hint }}</span>
						</span>
						<IconBase v-if="isSelected(o.value)" name="check" class="h-4 w-4 shrink-0" />
					</button>
				</div>
				<p v-else class="px-1 py-6 text-center text-sm text-gray-500">Nothing matches “{{ query }}”.</p>
			</div>

			<div class="mt-3 flex items-center justify-between gap-3 border-t border-gray-100 pt-3">
				<span class="text-xs text-gray-500">{{ multiple ? `${selectedOptions.length} selected` : (selectedOptions[0] ? `${selectedOptions[0].label} selected · click it again to clear` : 'Pick one') }}</span>
				<button type="button" class="min-h-9 rounded-lg bg-primary-700 px-4 text-xs font-bold text-white" @click="close">Done</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
export interface TagOption { value: string, label: string, hint?: string, group?: string, icon?: string }

const props = withDefaults(defineProps<{
	options: TagOption[]
	modelValue: string | string[]
	multiple?: boolean
	/** Filter buttons in this order; groups not listed follow alphabetically. */
	groupOrder?: string[]
	placeholder?: string
	searchPlaceholder?: string
	invalid?: boolean
	id?: string
}>(), { multiple: false, groupOrder: () => [], placeholder: 'Choose…', searchPlaceholder: 'Search', invalid: false, id: () => `tag-picker-${Math.random().toString(36).slice(2, 8)}` })
const emit = defineEmits<{ 'update:modelValue': [value: string | string[]] }>()

const root = ref<HTMLElement | null>(null)
const searchInput = ref<HTMLInputElement | null>(null)
const open = ref(false)
const query = ref('')
const group = ref('All')

const selected = computed(() => Array.isArray(props.modelValue) ? props.modelValue : (props.modelValue ? [props.modelValue] : []))
const isSelected = (v: string) => selected.value.includes(v)
const selectedOptions = computed(() => selected.value.map(v => props.options.find(o => o.value === v) ?? { value: v, label: v }))
const groupNames = computed(() => {
	const present = [...new Set(props.options.map(o => o.group).filter((g): g is string => !!g))]
	return [...props.groupOrder.filter(g => present.includes(g)), ...present.filter(g => !props.groupOrder.includes(g)).sort()]
})
const shown = computed(() => {
	const q = query.value.trim().toLowerCase()
	return props.options.filter(o => (group.value === 'All' || o.group === group.value)
		&& (!q || o.label.toLowerCase().includes(q) || (o.group ?? '').toLowerCase().includes(q)))
})

function pick(value: string) {
	if (props.multiple) {
		emit('update:modelValue', isSelected(value) ? selected.value.filter(v => v !== value) : [...selected.value, value])
	} else {
		// One choice: picking it again clears it; a new pick closes the panel.
		emit('update:modelValue', isSelected(value) ? '' : value)
		if (!isSelected(value)) close()
	}
}
function toggle() {
	open.value ? close() : openPanel()
}
function openPanel() {
	open.value = true
	nextTick(() => searchInput.value?.focus())
}
function close() {
	open.value = false
	query.value = ''
}
function onOutside(event: PointerEvent) {
	if (open.value && root.value && !root.value.contains(event.target as Node)) close()
}
onMounted(() => document.addEventListener('pointerdown', onOutside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside))
</script>
