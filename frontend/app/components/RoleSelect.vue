<template>
	<div class="min-w-44"><FormsSelect :model-value="modelValue" :can-clear="false" :options="options" placeholder="Role" append-to-body close-on-scroll @update:model-value="value => $emit('update:modelValue', String(value))" /></div>
</template>

<script setup lang="ts">
import type { Team } from '~/composables/useTeam'

/** Role picker: the organization's roles, plus presets it hasn't created yet ("preset:key"). */
const props = defineProps<{ modelValue: string, team: Team }>()
defineEmits<{ 'update:modelValue': [value: string] }>()

const newPresets = computed(() => props.team.presets.filter(p => !props.team.roles.some(r => r.name === p.name)))
const options = computed(() => [
	...props.team.roles.map(r => ({ value: r.id, label: r.name })),
	...newPresets.value.map(p => ({ value: `preset:${p.key}`, label: `${p.name} (suggested)` })),
])
</script>
