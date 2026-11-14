<template>
	<div class="relative" :class="wrapperClass">
		<input :id="id" :value="modelValue" type="search" :placeholder="placeholder" :class="inputClass" autocomplete="off"
			role="combobox" aria-autocomplete="list" :aria-expanded="showList" :aria-controls="listId"
			:aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined" v-bind="ariaLabel ? { 'aria-label': ariaLabel } : {}"
			@input="onInput" @focus="open = true" @blur="open = false" @keydown="onKey">
		<ul v-if="showList" :id="listId" role="listbox" class="absolute inset-x-0 top-full z-40 mt-1 max-h-72 overflow-auto rounded-xl border border-gray-200 bg-white py-1 text-left shadow-lg">
			<li v-for="(s, i) in matches" :id="`${listId}-${i}`" :key="`${s.kind}-${s.label}`" role="option" :aria-selected="i === active"
				class="flex min-h-11 cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm text-gray-800"
				:class="i === active ? 'bg-primary-50' : 'hover:bg-gray-50'" @mousedown.prevent="pick(s)" @mouseenter="active = i">
				<span class="min-w-0 break-words font-medium">{{ s.label }}</span>
				<span class="shrink-0 text-xs text-gray-500">{{ s.kind }}</span>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
// A search box that suggests matches while typing. The page passes what it
// already knows (names, services, places); nothing is fetched per keystroke.
export interface SearchSuggestion { label: string, kind: string }

const LIMIT = 6

const props = defineProps<{
	modelValue?: string | null
	suggestions: SearchSuggestion[]
	id?: string
	placeholder?: string
	ariaLabel?: string
	inputClass?: string
	wrapperClass?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string], pick: [value: string] }>()

const listId = useId()
const open = ref(false)
const active = ref(-1)

const matches = computed(() => {
	const query = (props.modelValue ?? '').trim().toLowerCase()
	if (!query) return []
	const seen = new Set<string>()
	return props.suggestions
		.filter((s) => {
			const key = s.label.trim().toLowerCase()
			if (!key || key === query || seen.has(key) || !key.includes(query)) return false
			seen.add(key)
			return true
		})
		// Names that start with the typed text first, then words that do.
		.map(s => ({ s, rank: rankOf(s.label.toLowerCase(), query) }))
		.sort((a, b) => a.rank - b.rank || a.s.label.localeCompare(b.s.label))
		.slice(0, LIMIT)
		.map(({ s }) => s)
})
const showList = computed(() => open.value && matches.value.length > 0)

function rankOf(text: string, query: string) {
	if (text.startsWith(query)) return 0
	return text.split(/[\s,&/-]+/).some(word => word.startsWith(query)) ? 1 : 2
}

function onInput(e: Event) {
	open.value = true
	active.value = -1
	emit('update:modelValue', (e.target as HTMLInputElement).value)
}

function pick(s: SearchSuggestion) {
	open.value = false
	active.value = -1
	emit('update:modelValue', s.label)
	emit('pick', s.label)
}

function onKey(e: KeyboardEvent) {
	if (e.key === 'Escape') {
		open.value = false
		return
	}
	if (!showList.value) return
	const n = matches.value.length
	if (e.key === 'ArrowDown') {
		e.preventDefault()
		active.value = (active.value + 1) % n
	} else if (e.key === 'ArrowUp') {
		e.preventDefault()
		active.value = (active.value - 1 + n) % n
	} else if (e.key === 'Enter') {
		const chosen = matches.value[active.value]
		if (chosen) {
			e.preventDefault()
			pick(chosen)
		} else {
			open.value = false
		}
	}
}
</script>
