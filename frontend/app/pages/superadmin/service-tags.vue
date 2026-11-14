<template>
	<div>
		<SuperadminPageHeader title="Service Tags" description="The services organizers offer and clients ask for in their requests. Requests are matched against these." icon="briefcase" />

		<div v-if="errorMessage" class="mb-6 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<!-- Summary -->
		<div class="stat-grid mb-6 grid grid-cols-1 gap-5 sm:grid-cols-3">
			<SuperadminStatCard label="Services" :value="active.length" icon="briefcase" :hint="`${archivedCount} archived`" />
			<SuperadminStatCard label="In use" :value="inUse" icon="check-circle" :hint="`${active.length - inUse} not offered by any organizer yet`" />
			<SuperadminStatCard label="Most offered" :value="mostOffered?.name ?? '—'" icon="star"
				:hint="mostOffered ? `${mostOffered.uses} organizer${mostOffered.uses === 1 ? '' : 's'} offer it` : 'No services in use yet'" />
		</div>

		<!-- Organizers' suggestions waiting for a decision (one row per name, every organization that asked) -->
		<section v-if="suggestions.length" class="sa-rise mb-6 rounded-3xl border-2 border-amber-200 bg-white p-6 sm:p-8" style="--d: 120ms">
			<h2 class="text-base font-extrabold text-gray-950">Suggested by organizers <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">{{ suggestions.length }}</span></h2>
			<p class="mt-1 text-sm text-gray-500">They already offer it as their own service. Approve it for the catalog (fix its spelling, pick a category), merge it into one that exists, or reject it (it stays theirs). The organizers who asked are told.</p>
			<p v-if="suggestionError" class="mt-3 rounded-xl bg-red-50 px-4 py-2.5 text-sm text-red-700" role="alert">{{ suggestionError }}</p>
			<ul class="mt-4 divide-y divide-gray-100">
				<li v-for="s in suggestions" :key="s.id" class="py-4">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div class="min-w-0">
							<p class="text-sm font-bold text-gray-950">{{ s.name }}</p>
							<p class="mt-0.5 text-xs text-gray-500">From {{ s.organizations.map(o => o.name).join(', ') }}</p>
							<p v-if="s.similar_to" class="mt-1 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">
								<IconBase name="sparkles" class="h-3 w-3" /> Looks like “{{ s.similar_to }}”
							</p>
						</div>
						<div v-if="deciding?.id !== s.id" class="flex flex-wrap gap-2">
							<button type="button" class="min-h-9 rounded-lg bg-[#285F6b] px-3 text-xs font-bold text-white" @click="startDecision(s, 'approve')">Approve</button>
							<button type="button" class="min-h-9 rounded-lg border border-gray-200 px-3 text-xs font-bold text-gray-700" @click="startDecision(s, 'merge')">Merge into…</button>
							<button type="button" class="min-h-9 rounded-lg border border-gray-200 px-3 text-xs font-bold text-red-600" @click="startDecision(s, 'reject')">Reject</button>
						</div>
					</div>
					<form v-if="deciding?.id === s.id" class="mt-3 flex flex-col gap-2 rounded-2xl bg-gray-50 p-3 sm:flex-row sm:flex-wrap sm:items-end" @submit.prevent="submitDecision">
						<template v-if="deciding.action === 'approve'">
							<label class="min-w-0 flex-1 text-xs font-semibold text-gray-600">Name in the catalog
								<input v-model="deciding.name" required minlength="2" maxlength="80" class="mt-1 h-10 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm font-normal text-gray-900">
							</label>
							<label class="min-w-0 flex-1 text-xs font-semibold text-gray-600">Category (optional)
								<input v-model="deciding.category" maxlength="60" list="st-cats" class="mt-1 h-10 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm font-normal text-gray-900">
							</label>
						</template>
						<label v-else-if="deciding.action === 'merge'" class="min-w-0 flex-1 text-xs font-semibold text-gray-600">Existing service
							<FormsSelect v-model="deciding.serviceId" class="mt-1" :can-clear="false" :options="active.map(t => ({ value: t.id, label: t.name }))" placeholder="Choose…" />
						</label>
						<label class="min-w-0 flex-1 text-xs font-semibold text-gray-600">{{ deciding.action === 'reject' ? 'Reason (sent to the organizers)' : 'Note to the organizers (optional)' }}
							<input v-model="deciding.note" :required="deciding.action === 'reject'" :minlength="deciding.action === 'reject' ? 3 : undefined" maxlength="500" class="mt-1 h-10 w-full rounded-xl border border-gray-200 bg-white px-3 text-sm font-normal text-gray-900">
						</label>
						<div class="flex gap-2">
							<button type="button" class="h-10 rounded-xl border border-gray-200 bg-white px-3 text-xs font-bold text-gray-600" @click="deciding = null">Cancel</button>
							<button type="submit" class="h-10 rounded-xl px-4 text-xs font-bold text-white disabled:opacity-60" :class="deciding.action === 'reject' ? 'bg-red-600' : 'bg-[#285F6b]'" :disabled="isDeciding">
								{{ isDeciding ? 'Saving…' : deciding.action === 'approve' ? 'Add to the catalog' : deciding.action === 'merge' ? 'Merge' : 'Reject' }}
							</button>
						</div>
					</form>
				</li>
			</ul>
		</section>

		<div class="sa-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 200ms">
			<!-- Add + search -->
			<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
				<form class="w-full lg:max-w-2xl" @submit.prevent="createService">
					<label class="mb-1.5 block text-xs font-bold uppercase tracking-[0.1em] text-gray-400">Add a service</label>
					<div class="flex flex-col gap-3 sm:flex-row">
						<div class="flex-1">
							<FormsTextField v-model="newName" icon="briefcase" placeholder="e.g. Lights & Sound" :disabled="isCreating" />
						</div>
						<input v-model="newCategory" maxlength="60" list="st-cats" placeholder="Category (optional)" aria-label="Category"
							class="h-11 rounded-xl border border-gray-200 px-3.5 text-sm sm:w-48">
						<button type="submit"
							class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-bold text-white shadow-sm transition duration-200 hover:bg-[#1f4a54] disabled:cursor-not-allowed disabled:opacity-50"
							:disabled="isCreating || newName.trim().length < 2">
							<IconBase :name="isCreating ? 'refresh-cw' : 'plus'" class="h-4 w-4" :class="{ 'animate-spin': isCreating }" />
							Add service
						</button>
					</div>
					<p v-if="formError" class="mt-2 text-sm font-semibold text-red-600">{{ formError }}</p>
				</form>

				<div class="w-full lg:max-w-xs">
					<FormsTextField v-model="search" icon="search" placeholder="Search services..." />
				</div>
			</div>
			<datalist id="st-cats"><option v-for="c in categories" :key="c" :value="c" /></datalist>

			<div class="my-6 h-px bg-gray-100" />

			<div v-if="isLoading" class="py-12 text-center text-sm text-gray-500">Loading services...</div>
			<p v-else-if="!services.length" class="rounded-2xl border border-dashed border-gray-300 px-6 py-14 text-center text-sm text-gray-500">The catalog is empty. Add the first service above.</p>
			<p v-else-if="!filtered.length" class="py-10 text-center text-sm text-gray-400">No services match "{{ search }}".</p>

			<div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
				<article v-for="svc in filtered" :key="svc.id"
					class="sa-tag sa-row group relative rounded-2xl border border-gray-200 bg-white p-5"
					:class="{ 'sa-editing': editing?.id === svc.id, 'opacity-60': svc.archived }">
					<span class="sa-line absolute inset-x-0 top-0 h-[2px] rounded-t-2xl bg-primary-700" />

					<div class="flex items-start gap-3">
						<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
							<IconBase name="briefcase" class="h-5 w-5" />
						</span>

						<div class="min-w-0 flex-1">
							<div v-if="editing?.id === svc.id" class="space-y-2">
								<FormsTextField v-model="editing.name" :disabled="isSavingEdit" aria-label="Service name" @keydown.enter.prevent="saveEdit" @keydown.esc="editing = null" />
								<input v-model="editing.category" maxlength="60" list="st-cats" placeholder="Category (optional)" aria-label="Category" class="h-10 w-full rounded-xl border border-gray-200 px-3 text-sm">
							</div>
							<template v-else>
								<h3 class="break-words text-base font-extrabold tracking-tight text-gray-950" :class="{ 'line-through': svc.archived }">{{ svc.name }}</h3>
								<p class="mt-0.5 text-xs text-gray-400">{{ svc.archived ? 'Archived: hidden from new picks' : (svc.category || 'No category') }}</p>
							</template>
						</div>

						<div v-if="editing?.id !== svc.id" class="flex shrink-0 gap-1 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100">
							<button v-if="!svc.archived" type="button" :aria-label="`Edit ${svc.name}`" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-primary-50 hover:text-primary-700" @click="editing = { id: svc.id, name: svc.name, category: svc.category ?? '' }">
								<IconBase name="edit" class="h-4 w-4" />
							</button>
							<button type="button" :aria-label="svc.archived ? `Restore ${svc.name}` : `Archive ${svc.name}`" :title="svc.archived ? 'Restore' : 'Archive'"
								class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition" :class="svc.archived ? 'hover:bg-green-50 hover:text-green-700' : 'hover:bg-red-50 hover:text-red-600'"
								:disabled="busyId === svc.id" @click="toggleArchive(svc)">
								<IconBase :name="busyId === svc.id ? 'refresh-cw' : svc.archived ? 'refresh-cw' : 'trash'" class="h-4 w-4" :class="{ 'animate-spin': busyId === svc.id }" />
							</button>
						</div>
					</div>

					<div v-if="editing?.id === svc.id" class="mt-4 flex justify-end gap-2">
						<button type="button" class="rounded-lg border border-gray-200 px-3.5 py-1.5 text-xs font-bold text-gray-600 transition hover:bg-gray-50" :disabled="isSavingEdit" @click="editing = null">Cancel</button>
						<button type="button" class="rounded-lg bg-[#285F6b] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#1f4a54] disabled:opacity-60" :disabled="isSavingEdit || editing.name.trim().length < 2" @click="saveEdit">
							{{ isSavingEdit ? 'Saving...' : 'Save' }}
						</button>
					</div>

					<div v-else class="mt-4 grid grid-cols-2 gap-2">
						<div class="rounded-xl bg-gray-50 px-3 py-2.5">
							<p class="text-lg font-extrabold leading-none text-gray-950">{{ svc.uses }}</p>
							<p class="mt-1 text-[11px] font-semibold text-gray-400">Organizers</p>
						</div>
						<div class="rounded-xl bg-gray-50 px-3 py-2.5">
							<p class="text-lg font-extrabold leading-none text-gray-950">{{ svc.requests ?? 0 }}</p>
							<p class="mt-1 text-[11px] font-semibold text-gray-400">Requests</p>
						</div>
					</div>
				</article>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { CatalogService } from '~/composables/useAdmin'

definePageMeta({
	layout: 'superadmin',
	middleware: ['superadmin'],
})

const admin = useAdmin()

const services = ref<CatalogService[]>([])
const isLoading = ref(false)
const errorMessage = ref('')
const search = ref('')
const newName = ref('')
const newCategory = ref('')
const isCreating = ref(false)
const formError = ref('')
const editing = ref<{ id: string, name: string, category: string } | null>(null)
const isSavingEdit = ref(false)
const busyId = ref<string | null>(null)

const active = computed(() => services.value.filter(s => !s.archived))
const archivedCount = computed(() => services.value.length - active.value.length)
const inUse = computed(() => active.value.filter(s => s.uses > 0).length)
const mostOffered = computed(() => [...active.value].filter(s => s.uses > 0).sort((a, b) => b.uses - a.uses)[0] ?? null)
const categories = computed(() => [...new Set(services.value.map(s => s.category).filter(Boolean))] as string[])
const filtered = computed(() => {
	const q = search.value.trim().toLowerCase()
	return q ? services.value.filter(s => s.name.toLowerCase().includes(q) || (s.category ?? '').toLowerCase().includes(q)) : services.value
})

function errorOf(error: unknown, fallback: string) {
	return Object.values(fieldErrors(error))[0] || authMessage(error) || fallback
}

async function loadServices(quiet = false) {
	if (!quiet) isLoading.value = true
	errorMessage.value = ''
	try {
		services.value = await admin.services()
	} catch (error: unknown) {
		errorMessage.value = errorOf(error, 'Unable to load services.')
	} finally {
		isLoading.value = false
	}
}

async function createService() {
	if (newName.value.trim().length < 2) return
	isCreating.value = true
	formError.value = ''
	try {
		await admin.saveService({ name: newName.value.trim(), category: newCategory.value.trim() || null })
		newName.value = ''
		newCategory.value = ''
		await loadServices(true)
	} catch (error: unknown) {
		formError.value = errorOf(error, 'Unable to add the service.')
	} finally {
		isCreating.value = false
	}
}

/** Renaming keeps every organizer's link (they point at the service, not its name). */
async function saveEdit() {
	const e = editing.value
	if (!e || e.name.trim().length < 2) return
	isSavingEdit.value = true
	errorMessage.value = ''
	try {
		await admin.saveService({ name: e.name.trim(), category: e.category.trim() || null }, e.id)
		editing.value = null
		await loadServices(true)
	} catch (error: unknown) {
		errorMessage.value = errorOf(error, 'Unable to save the service.')
	} finally {
		isSavingEdit.value = false
	}
}

async function toggleArchive(svc: CatalogService) {
	const archive = !svc.archived
	if (archive && !(await confirmDialog(`Archive “${svc.name}”? It's hidden from new picks.${svc.uses ? ` The ${svc.uses} organizer${svc.uses === 1 ? '' : 's'} who offer it keep it.` : ''}`, { confirmLabel: 'Archive' }))) return
	busyId.value = svc.id
	errorMessage.value = ''
	try {
		await admin.archiveService(svc.id, archive)
		await loadServices(true)
	} catch (error: unknown) {
		errorMessage.value = errorOf(error, archive ? 'Unable to archive the service.' : 'Unable to restore the service.')
	} finally {
		busyId.value = null
	}
}

// ---- Organizers' suggestions (0056) ----------------------------------------------
interface ServiceSuggestionRow { id: string, name: string, organizations: { id: string, name: string }[], similar_to: string | null }
const suggestions = ref<ServiceSuggestionRow[]>([])
const suggestionError = ref('')
const deciding = ref<{ id: string, action: 'approve' | 'merge' | 'reject', name: string, category: string, serviceId: string, note: string } | null>(null)
const isDeciding = ref(false)

async function loadSuggestions() {
	try {
		suggestions.value = (await useApi().get<ServiceSuggestionRow[]>('/admin/service-suggestions')).data
	} catch (error: unknown) {
		suggestionError.value = errorOf(error, 'Unable to load suggestions.')
	}
}
function startDecision(s: ServiceSuggestionRow, action: 'approve' | 'merge' | 'reject') {
	const similar = action === 'merge' && s.similar_to ? active.value.find(t => t.name === s.similar_to) : undefined
	deciding.value = { id: s.id, action, name: s.name, category: '', serviceId: similar?.id ?? '', note: '' }
	suggestionError.value = ''
}
async function submitDecision() {
	const d = deciding.value
	if (!d) return
	isDeciding.value = true
	suggestionError.value = ''
	try {
		const body = d.action === 'approve' ? { name: d.name.trim(), category: d.category.trim() || null, note: d.note.trim() || null }
			: d.action === 'merge' ? { service_id: d.serviceId, note: d.note.trim() || null }
				: { note: d.note.trim() }
		suggestions.value = (await useApi().post<ServiceSuggestionRow[]>(`/admin/service-suggestions/${d.id}/${d.action}`, body)).data
		deciding.value = null
		await loadServices(true) // approved services show up below, with their organizers
	} catch (error: unknown) {
		suggestionError.value = errorOf(error, 'That didn’t work. Try again.')
	} finally {
		isDeciding.value = false
	}
}

onMounted(() => {
	loadServices()
	loadSuggestions()
})
// Organizers' new suggestions appear on their own (never while one is being decided or a service edited).
useLiveRefresh(() => loadSuggestions(), { interval: 60000, paused: () => !!deciding.value || isDeciding.value || editing.value !== null })
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

@keyframes sa-rise {
	from { opacity: 0; transform: translateY(12px); }
	to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
	.sa-rise, .sa-row { animation: none; }
	.sa-tag, .sa-line { transition: none; }
}
</style>
