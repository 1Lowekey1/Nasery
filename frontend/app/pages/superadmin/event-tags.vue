<template>
	<div>
		<SuperadminPageHeader title="Event Tags" description="The event types organizers can offer and clients can request. Inquiries are matched against these tags." icon="tag" />

		<div v-if="errorMessage" class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<!-- Summary -->
		<div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<SuperadminStatCard label="Total tags" :value="tags.length" icon="tag" hint="Selectable by organizers" />
			<SuperadminStatCard label="In use" :value="tagsInUse" icon="check-circle" :hint="`${tags.length - tagsInUse} not chosen by any organizer yet`" />
			<SuperadminStatCard label="Most popular" :value="mostPopular?.name ?? '—'" icon="star"
				:hint="mostPopular ? `${mostPopular.organizers ?? 0} organizer${mostPopular.organizers === 1 ? '' : 's'} offer it` : 'No tags in use yet'" />
		</div>

		<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 200ms">
			<!-- Add + search -->
			<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
				<form class="w-full lg:max-w-xl" @submit.prevent="createTag">
					<label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Add a new tag</label>
					<div class="flex gap-3">
						<div class="flex-1">
							<FormsTextField v-model="newTagName" icon="tag" placeholder="e.g. Gala Night" :disabled="isCreating" />
						</div>
						<button type="submit"
							class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md active:translate-y-0 active:scale-[0.98] disabled:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50"
							:disabled="isCreating || !newTagName.trim()">
							<IconBase :name="isCreating ? 'refresh-cw' : 'plus'" class="h-4 w-4" :class="{ 'animate-spin': isCreating }" />
							Add tag
						</button>
					</div>
					<p v-if="formError" class="mt-2 text-sm font-semibold text-red-600">{{ formError }}</p>
				</form>

				<div class="w-full lg:max-w-xs">
					<FormsTextField v-model="search" icon="search" placeholder="Search tags..." />
				</div>
			</div>

			<div class="my-6 h-px bg-gray-100" />

			<div v-if="isLoading" class="py-12 text-center text-sm text-gray-500">Loading event tags...</div>

			<div v-else-if="!tags.length" class="rounded-2xl border border-dashed border-gray-300 px-6 py-14 text-center">
				<span class="sa-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
					<IconBase name="tag" class="h-7 w-7" />
				</span>
				<p class="mt-4 text-sm font-bold text-gray-900">No event tags yet</p>
				<p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-gray-500">Add your first tag above. Organizers pick from these on their profile.</p>
			</div>

			<p v-else-if="!filteredTags.length" class="py-10 text-center text-sm text-gray-400">No tags match "{{ search }}".</p>

			<div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
				<article v-for="tag in filteredTags" :key="tag.id"
					class="sa-tag sa-row group relative rounded-2xl border border-gray-200 bg-white p-5"
					:class="{ 'sa-editing': editingId === tag.id }">
					<span class="sa-line absolute inset-x-0 top-0 h-[2px] rounded-t-2xl bg-primary-700" />

					<div class="flex items-start gap-3">
						<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 transition duration-200 group-hover:scale-105">
							<IconBase name="tag" class="h-5 w-5" />
						</span>

						<div class="min-w-0 flex-1">
							<FormsTextField v-if="editingId === tag.id" v-model="editingName" :disabled="isSavingEdit" @keydown.enter.prevent="saveEdit(tag)" @keydown.esc="cancelEdit" />
							<template v-else>
								<h3 class="truncate text-base font-extrabold tracking-tight text-gray-950">{{ tag.name }}</h3>
								<p class="mt-0.5 text-xs text-gray-400">{{ usageLabel(tag) }}</p>
							</template>
						</div>

						<div v-if="editingId !== tag.id" class="flex shrink-0 gap-1 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100">
							<button type="button" :aria-label="`Edit ${tag.name}`" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-primary-50 hover:text-primary-700" @click="startEdit(tag)">
								<IconBase name="edit" class="h-4 w-4" />
							</button>
							<button type="button" :aria-label="`Remove ${tag.name}`" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600" :disabled="deletingId === tag.id" @click="removeTag(tag)">
								<IconBase :name="deletingId === tag.id ? 'refresh-cw' : 'trash'" class="h-4 w-4" :class="{ 'animate-spin': deletingId === tag.id }" />
							</button>
						</div>
					</div>

					<div v-if="editingId === tag.id" class="mt-4 flex justify-end gap-2">
						<button type="button" class="rounded-lg border border-gray-200 px-3.5 py-1.5 text-xs font-bold text-gray-600 transition hover:bg-gray-50" :disabled="isSavingEdit" @click="cancelEdit">Cancel</button>
						<button type="button" class="rounded-lg bg-[#285F6b] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#1f4a54] disabled:opacity-60" :disabled="isSavingEdit || !editingName.trim()" @click="saveEdit(tag)">
							{{ isSavingEdit ? 'Saving...' : 'Save' }}
						</button>
					</div>

					<div v-else class="mt-4 grid grid-cols-2 gap-2">
						<div class="rounded-xl bg-gray-50 px-3 py-2.5">
							<p class="text-lg font-extrabold leading-none text-gray-950">{{ tag.organizers ?? 0 }}</p>
							<p class="mt-1 text-[11px] font-semibold text-gray-400">Organizers</p>
						</div>
						<div class="rounded-xl bg-gray-50 px-3 py-2.5">
							<p class="text-lg font-extrabold leading-none text-gray-950">{{ tag.inquiries ?? 0 }}</p>
							<p class="mt-1 text-[11px] font-semibold text-gray-400">Inquiries</p>
						</div>
					</div>
				</article>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { superAdminService, type AdminEventTag } from '~/services/superAdminService'

definePageMeta({
	layout: 'superadmin',
	middleware: ['superadmin'],
})

const { token } = useAuth('superadmin')

const tags = ref<AdminEventTag[]>([])
const isLoading = ref(false)
const errorMessage = ref('')

const newTagName = ref('')
const search = ref('')
const isCreating = ref(false)
const formError = ref('')

const editingId = ref<number | null>(null)
const editingName = ref('')
const isSavingEdit = ref(false)

const deletingId = ref<number | null>(null)

const filteredTags = computed(() => {
	const query = search.value.trim().toLowerCase()
	return query ? tags.value.filter(tag => tag.name.toLowerCase().includes(query)) : tags.value
})

const tagsInUse = computed(() => tags.value.filter(tag => (tag.organizers ?? 0) > 0).length)

const mostPopular = computed(() =>
	[...tags.value]
		.filter(tag => (tag.organizers ?? 0) > 0)
		.sort((a, b) => (b.organizers ?? 0) - (a.organizers ?? 0))[0] ?? null,
)

function usageLabel(tag: AdminEventTag): string {
	const organizers = tag.organizers ?? 0
	return organizers > 0 ? `Offered by ${organizers} organizer${organizers === 1 ? '' : 's'}` : 'No organizers yet'
}

function getApiErrorMessage(error: unknown, fallback: string): string {
	const apiError = error as { data?: { message?: string, errors?: Record<string, string[]> } }
	const validationMessage = Object.values(apiError?.data?.errors ?? {})[0]?.[0]
	return validationMessage ?? apiError?.data?.message ?? fallback
}

async function loadTags() {
	if (!token.value) return

	isLoading.value = true
	errorMessage.value = ''

	try {
		const response = await superAdminService.getEventTags(token.value)
		tags.value = response.data
	} catch (error: unknown) {
		errorMessage.value = getApiErrorMessage(error, 'Unable to load event tags.')
	} finally {
		isLoading.value = false
	}
}

async function createTag() {
	if (!token.value || !newTagName.value.trim()) return

	isCreating.value = true
	formError.value = ''

	try {
		const response = await superAdminService.createEventTag(token.value, newTagName.value.trim())
		tags.value = [...tags.value, response.data].sort((a, b) => a.name.localeCompare(b.name))
		newTagName.value = ''
	} catch (error: unknown) {
		formError.value = getApiErrorMessage(error, 'Unable to create event tag.')
	} finally {
		isCreating.value = false
	}
}

function startEdit(tag: AdminEventTag) {
	editingId.value = tag.id
	editingName.value = tag.name
}

function cancelEdit() {
	editingId.value = null
	editingName.value = ''
}

async function saveEdit(tag: AdminEventTag) {
	if (!token.value || !editingName.value.trim()) return

	isSavingEdit.value = true
	errorMessage.value = ''

	try {
		const response = await superAdminService.updateEventTag(token.value, tag.id, editingName.value.trim())
		const index = tags.value.findIndex(item => item.id === tag.id)
		if (index !== -1) tags.value[index] = response.data
		tags.value.sort((a, b) => a.name.localeCompare(b.name))
		cancelEdit()
	} catch (error: unknown) {
		errorMessage.value = getApiErrorMessage(error, 'Unable to update event tag.')
	} finally {
		isSavingEdit.value = false
	}
}

async function removeTag(tag: AdminEventTag) {
	if (!token.value || deletingId.value !== null) return

	const inUse = tag.organizers ?? 0
	const confirmed = await confirmDialog(`Remove the "${tag.name}" tag?${inUse ? ` ${inUse} organizer${inUse === 1 ? ' has' : 's have'} selected it. They keep it on their profile, but it will no longer be selectable.` : ' Organizers who already selected it will keep it on their profile, but it will no longer be selectable.'}`, { confirmLabel: 'Remove tag' })
	if (!confirmed) return

	deletingId.value = tag.id
	errorMessage.value = ''

	try {
		await superAdminService.deleteEventTag(token.value, tag.id)
		tags.value = tags.value.filter(item => item.id !== tag.id)
	} catch (error: unknown) {
		errorMessage.value = getApiErrorMessage(error, 'Unable to remove event tag.')
	} finally {
		deletingId.value = null
	}
}

onMounted(() => {
	loadTags()
})
</script>

<style scoped>
.sa-rise {
	animation: sa-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards;
	animation-delay: var(--d, 0ms);
}

.sa-row {
	animation: sa-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) backwards;
}

.sa-row:nth-child(2) { animation-delay: 40ms; }
.sa-row:nth-child(3) { animation-delay: 80ms; }
.sa-row:nth-child(4) { animation-delay: 120ms; }
.sa-row:nth-child(5) { animation-delay: 160ms; }
.sa-row:nth-child(n + 6) { animation-delay: 200ms; }

.sa-tag {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.sa-tag:hover, .sa-tag.sa-editing {
	border-color: rgba(40, 95, 107, 0.3);
	box-shadow: 0 16px 32px -24px rgba(17, 24, 39, 0.4);
}

.sa-tag:hover {
	transform: translateY(-3px);
}

.sa-line {
	transform: scaleX(0);
	transform-origin: left;
	transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.sa-tag:hover .sa-line, .sa-tag.sa-editing .sa-line {
	transform: scaleX(1);
}

.sa-float {
	animation: sa-float 3.2s ease-in-out infinite;
}

@keyframes sa-rise {
	from { opacity: 0; transform: translateY(12px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes sa-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
	.sa-rise, .sa-row, .sa-float { animation: none; }
	.sa-tag, .sa-line { transition: none; }
}
</style>
