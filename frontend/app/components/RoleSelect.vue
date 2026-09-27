<template>
	<select :value="modelValue" class="rounded-lg border border-gray-200 bg-white px-2 py-2 text-sm" aria-label="Role"
		@change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)">
		<optgroup v-if="team.roles.length" label="Your roles">
			<option v-for="r in team.roles" :key="r.id" :value="r.id">{{ r.name }}</option>
		</optgroup>
		<optgroup label="Suggested roles">
			<option v-for="p in newPresets" :key="p.key" :value="`preset:${p.key}`">{{ p.name }}</option>
		</optgroup>
	</select>
</template>

<script setup lang="ts">
import type { Team } from '~/composables/useTeam'

/** Role picker: the organization's roles, plus presets it hasn't created yet ("preset:key"). */
const props = defineProps<{ modelValue: string, team: Team }>()
defineEmits<{ 'update:modelValue': [value: string] }>()

const newPresets = computed(() => props.team.presets.filter(p => !props.team.roles.some(r => r.name === p.name)))
</script>
