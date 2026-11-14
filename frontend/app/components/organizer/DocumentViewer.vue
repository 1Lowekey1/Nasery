<template>
	<Teleport to="body">
		<div v-if="document" class="fixed inset-0 z-[70] flex items-center justify-center bg-gray-900/60 p-3 sm:p-6" role="dialog" aria-modal="true"
			:aria-label="document.original_name" @click.self="close">
			<div class="flex h-full max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-white">
				<header class="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
					<div class="min-w-0 flex-1">
						<p class="truncate text-sm font-bold text-gray-900">{{ document.original_name }}</p>
						<p class="text-xs text-gray-500">{{ documentTypeLabels[document.doc_type] }} · {{ formatFileSize(document.size_bytes) }}</p>
					</div>
					<button type="button" :disabled="!url" class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" @click="download">Download</button>
					<button type="button" class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50" @click="close">Close</button>
				</header>
				<div class="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-gray-100">
					<p v-if="error" class="p-6 text-sm text-rose-700">{{ error }}</p>
					<p v-else-if="!url" class="p-6 text-sm text-gray-500">Loading…</p>
					<img v-else-if="document.mime_type.startsWith('image/')" :src="url" :alt="document.original_name" class="max-h-full max-w-full object-contain">
					<iframe v-else :src="url" :title="document.original_name" class="h-full w-full border-0" />
				</div>
				<p v-if="url && !document.mime_type.startsWith('image/')" class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 sm:hidden">
					If the file doesn’t show on your phone, use Download.
				</p>
			</div>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
import { documentTypeLabels, formatFileSize, type OrganizationDocument } from '~/composables/useOrganizationDocuments'

const props = defineProps<{ organizationId: string, document: OrganizationDocument | null }>()
const emit = defineEmits<{ close: [] }>()
const docs = useOrganizationDocuments()
const url = ref('')
const error = ref('')

function release() {
	if (url.value) URL.revokeObjectURL(url.value)
	url.value = ''
}

watch(() => props.document, async (doc) => {
	release()
	error.value = ''
	if (!doc) return
	try {
		// Fresh Blob with the stored type, so the browser previews instead of downloading.
		const file = await docs.file(props.organizationId, doc.id)
		url.value = URL.createObjectURL(new Blob([file], { type: doc.mime_type }))
	} catch (e) {
		error.value = authMessage(e) || 'This document could not be opened.'
	}
}, { immediate: true })

function close() { emit('close') }
function download() {
	const a = window.document.createElement('a')
	a.href = url.value
	a.download = props.document?.original_name ?? 'document'
	a.click()
}

onBeforeUnmount(release)
</script>
