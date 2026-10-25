<template>
	<div v-if="draft.restored.value" class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-primary-200 bg-primary-50 px-4 py-2.5 text-sm text-primary-900" role="status">
		<span class="flex items-center gap-2">
			<IconBase name="refresh-cw" class="h-4 w-4 shrink-0 text-primary-700" />
			<span>We restored your unsaved draft<template v-if="ago"> from {{ ago }}</template>.</span>
		</span>
		<button type="button" class="text-xs font-bold text-primary-700 hover:underline" @click="discardDraft">Discard and start over</button>
	</div>
</template>

<script setup lang="ts">
import type { DraftHandle } from '~/composables/useDraft'

const props = defineProps<{ draft: DraftHandle }>()

const ago = computed(() => {
	const at = props.draft.savedAt.value
	if (!at) return ''
	const minutes = Math.round((Date.now() - at) / 60000)
	if (minutes < 1) return 'just now'
	if (minutes < 60) return `${minutes} min ago`
	const hours = Math.round(minutes / 60)
	if (hours < 24) return `${hours} h ago`
	return new Date(at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
})

async function discardDraft() {
	if (await confirmDialog('Discard this draft and clear the form?', { confirmLabel: 'Discard draft' })) props.draft.discard()
}
</script>
