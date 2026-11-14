<template>
	<div class="space-y-4">
		<Alert v-if="error" type="danger" :text="error" />

		<div class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
			<!-- Floats above the page: this card clips what overflows it and sits at the very bottom. -->
			<FormsSelect v-model="type" :options="typeOptions" placeholder="Choose a document type" :can-clear="false" append-to-body close-on-scroll />
			<label class="inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1f4a54]"
				:class="{ 'pointer-events-none opacity-60': uploading }">
				<IconBase name="file-text" class="h-4 w-4" />
				{{ uploading ? 'Uploading…' : 'Choose a file' }}
				<input ref="input" type="file" accept="application/pdf,image/jpeg,image/png" class="sr-only" :disabled="uploading" @change="onPick">
			</label>
		</div>
		<p class="text-xs text-gray-500">
			PDF, JPG or PNG, up to 10 MB. We use these only to confirm your business is real. Only you and NaSeRy’s reviewers can open them, and they’re never shown publicly.
			By uploading, you agree to this as described in our <NuxtLink to="/privacy" class="font-semibold text-primary-700 underline">Privacy Policy</NuxtLink>.
		</p>

		<p v-if="loaded && !documents.length" class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500">Nothing uploaded yet.</p>
		<ul v-else class="divide-y divide-gray-100 rounded-xl border border-gray-200">
			<li v-for="doc in documents" :key="doc.id" class="flex items-center gap-3 px-4 py-3">
				<div class="min-w-0 flex-1">
					<p class="truncate text-sm font-semibold text-gray-900">{{ doc.original_name }}</p>
					<p class="text-xs text-gray-500">{{ documentTypeLabels[doc.doc_type] }} · {{ formatFileSize(doc.size_bytes) }}</p>
				</div>
				<button type="button" class="tap-target text-sm font-semibold text-primary-700 hover:underline" @click="viewing = doc">View</button>
				<button v-if="canRemove" type="button" class="tap-target text-sm font-semibold text-rose-700 hover:underline" @click="remove(doc)">Remove</button>
			</li>
		</ul>

		<OrganizerDocumentViewer :organization-id="organizationId" :document="viewing" @close="viewing = null" />
	</div>
</template>

<script setup lang="ts">
import { documentTypeLabels, formatFileSize, type DocumentType, type OrganizationDocument } from '~/composables/useOrganizationDocuments'

// The owner's proof of business. Files can be removed until the organization is approved.
const props = defineProps<{ organizationId: string, canRemove: boolean }>()
const docs = useOrganizationDocuments()

const documents = ref<OrganizationDocument[]>([])
const loaded = ref(false)
const uploading = ref(false)
const error = ref('')
const type = ref<DocumentType>('business_permit')
const viewing = ref<OrganizationDocument | null>(null)
const input = ref<HTMLInputElement | null>(null)
const typeOptions = Object.entries(documentTypeLabels).map(([value, label]) => ({ value, label }))

async function load() {
	try {
		documents.value = await docs.list(props.organizationId)
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loaded.value = true
	}
}

async function onPick(event: Event) {
	const file = (event.target as HTMLInputElement).files?.[0]
	if (input.value) input.value.value = ''
	if (!file) return
	error.value = ''
	if (file.size > 10 * 1024 * 1024) {
		error.value = 'Choose a file under 10 MB.'
		return
	}
	uploading.value = true
	try {
		const added = await docs.upload(props.organizationId, file, type.value)
		documents.value = [added, ...documents.value]
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		uploading.value = false
	}
}

async function remove(doc: OrganizationDocument) {
	if (!(await confirmDialog(`Remove ${doc.original_name}?`, { confirmLabel: 'Remove' }))) return
	error.value = ''
	try {
		await docs.remove(props.organizationId, doc.id)
		documents.value = documents.value.filter(d => d.id !== doc.id)
	} catch (e) {
		error.value = authMessage(e)
	}
}

onMounted(load)
</script>
