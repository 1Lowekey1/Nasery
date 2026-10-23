<template>
	<div class="w-full space-y-6 pb-8">
		<!-- Header -->
		<header class="flex flex-wrap items-end justify-between gap-4">
			<div>
				<h1 class="text-2xl font-extrabold tracking-tight text-gray-950 sm:text-[28px]">Activity timeline</h1>
				<p class="mt-1 text-sm text-gray-500">Every milestone, update and task across the events you manage.</p>
			</div>
			<button type="button" class="at-btn-primary h-11" :disabled="!realEvents.length" @click="openAddActivity">
				<IconBase name="plus" class="h-4 w-4" /> Add activity
			</button>
		</header>

		<p v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

		<!-- Status summary = filters -->
		<div class="grid grid-cols-2 gap-3 lg:grid-cols-4" role="tablist" aria-label="Filter by status">
			<button v-for="(card, i) in statusCards" :key="card.value" type="button" role="tab" :aria-selected="selectedStatus === card.value" class="at-stat group" :class="selectedStatus === card.value ? 'is-on' : ''" :style="{ '--i': i }" @click="selectedStatus = card.value">
				<div class="flex items-center justify-between">
					<span class="flex items-center gap-2 text-sm font-medium text-gray-600"><span class="at-node-mini" :class="card.node" />{{ card.label }}</span>
					<IconBase v-if="selectedStatus === card.value" name="check" class="h-4 w-4 text-primary-700" />
				</div>
				<p class="mt-2 text-2xl font-extrabold tabular-nums text-gray-950">{{ card.count }}</p>
				<div class="mt-2 h-1 overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full transition-[width] duration-700 ease-out" :class="card.bar" :style="{ width: (ready ? card.percent : 0) + '%' }" /></div>
			</button>
		</div>

		<div class="grid items-start gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
			<!-- Events -->
			<aside class="min-w-0 lg:sticky lg:top-0">
				<div class="rounded-2xl border border-gray-200 bg-white">
					<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
						<p class="text-sm font-bold text-gray-950">Events</p>
						<span class="text-xs font-medium text-gray-400">{{ timelineEvents.length }}</span>
					</div>
					<div class="max-h-[calc(100vh-320px)] min-h-[200px] space-y-1 overflow-y-auto p-2">
						<button type="button" class="at-event" :class="!selectedEventKey ? 'is-on' : ''" @click="selectAllEvents">
							<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="!selectedEventKey ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-500'"><IconBase name="grid" class="h-4 w-4" /></span>
							<span class="min-w-0 flex-1">
								<span class="block text-sm font-semibold text-gray-950">All events</span>
								<span class="block text-xs text-gray-500">{{ totalActivities }} {{ totalActivities === 1 ? 'activity' : 'activities' }}</span>
							</span>
						</button>

						<div v-if="isLoading" class="space-y-2 p-1">
							<div v-for="n in 4" :key="n" class="h-[72px] animate-pulse rounded-xl bg-gray-50" />
						</div>

						<button v-for="event in timelineEvents" v-else :key="event.key" type="button" class="at-event" :class="selectedEventKey === event.key ? 'is-on' : ''" @click="selectEvent(event)">
							<span class="flex h-9 w-9 shrink-0 flex-col items-center justify-center rounded-xl text-center leading-none" :class="selectedEventKey === event.key ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-600'">
								<span class="text-[9px] font-bold uppercase">{{ monthOf(event.eventDate) }}</span>
								<span class="text-sm font-extrabold">{{ dayOf(event.eventDate) }}</span>
							</span>
							<span class="min-w-0 flex-1">
								<span class="flex items-center gap-2">
									<span class="truncate text-sm font-semibold text-gray-950">{{ event.name }}</span>
									<span class="ml-auto shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold" :class="eventBadgeClass(event.status)">{{ formatEventStatus(event.status) }}</span>
								</span>
								<span class="mt-0.5 flex items-center gap-1 text-xs text-gray-500" :title="event.location || undefined"><IconBase name="map-pin" class="h-3 w-3 shrink-0" /><span class="truncate">{{ shortLocation(event.location) }}</span></span>
								<span class="mt-2 flex items-center gap-2">
									<span class="h-1 flex-1 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full bg-primary-600 transition-[width] duration-700" :style="{ width: (ready ? eventProgress(event).percent : 0) + '%' }" /></span>
									<span class="shrink-0 text-[11px] tabular-nums text-gray-400">{{ eventProgress(event).done }}/{{ eventProgress(event).total }}</span>
								</span>
							</span>
						</button>

						<p v-if="!isLoading && !timelineEvents.length" class="px-3 py-8 text-center text-sm text-gray-500">No events yet. Events you win show up here.</p>
					</div>
				</div>
			</aside>

			<!-- Timeline -->
			<section class="min-w-0 space-y-4">
				<!-- Selected event header -->
				<Transition name="at-swap" mode="out-in">
					<div v-if="selectedEventData" :key="selectedEventData.key" class="relative overflow-hidden rounded-2xl bg-primary-800 p-5 text-white sm:p-6">
						<div class="at-dots" aria-hidden="true" />
						<div class="relative flex flex-wrap items-center gap-5">
							<div class="relative h-16 w-16 shrink-0">
								<svg viewBox="0 0 64 64" class="h-full w-full -rotate-90" aria-hidden="true">
									<circle cx="32" cy="32" r="27" fill="none" stroke="rgba(255,255,255,.15)" stroke-width="6" />
									<circle cx="32" cy="32" r="27" fill="none" stroke="white" stroke-width="6" stroke-linecap="round" :stroke-dasharray="RING" :stroke-dashoffset="RING * (1 - selectedProgress.percent / 100)" class="transition-[stroke-dashoffset] duration-700 ease-out" />
								</svg>
								<span class="absolute inset-0 flex items-center justify-center text-sm font-extrabold tabular-nums">{{ selectedProgress.percent }}%</span>
							</div>
							<div class="min-w-0 flex-1">
								<p class="truncate text-xl font-extrabold tracking-tight">{{ selectedEventData.name }}</p>
								<p class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-white/70">
									<span class="flex items-center gap-1.5"><IconBase name="calendar" class="h-4 w-4" />{{ formatDate(selectedEventData.eventDate) }}</span>
									<span class="flex min-w-0 items-center gap-1.5"><IconBase name="map-pin" class="h-4 w-4 shrink-0" /><span class="truncate">{{ shortLocation(selectedEventData.location) }}</span></span>
								</p>
							</div>
							<p class="text-sm text-white/80"><strong class="text-white">{{ selectedProgress.done }}</strong> of {{ selectedProgress.total }} done</p>
						</div>
					</div>
				</Transition>

				<!-- Search -->
				<div class="flex flex-wrap items-center gap-3">
					<div class="relative min-w-0 flex-1">
						<IconBase name="search" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
						<input v-model="searchQuery" type="search" placeholder="Search activities, people or tags" aria-label="Search activities" class="at-field !pl-10" />
					</div>
					<p class="shrink-0 text-sm text-gray-500"><strong class="font-semibold text-gray-900">{{ filteredActivities.length }}</strong> {{ filteredActivities.length === 1 ? 'activity' : 'activities' }}</p>
				</div>

				<!-- Loading -->
				<div v-if="isLoading" class="space-y-3">
					<div v-for="n in 3" :key="n" class="h-24 animate-pulse rounded-2xl bg-white ring-1 ring-gray-200" />
				</div>

				<!-- Grouped timeline -->
				<Transition v-else name="at-swap" mode="out-in">
					<div v-if="groupedActivities.length" :key="listKey" class="space-y-6">
						<div v-for="group in groupedActivities" :key="group.label">
							<p class="mb-3 flex items-center gap-3 text-xs font-semibold text-gray-500">
								{{ group.label }}<span class="h-px flex-1 bg-gray-200" />
							</p>
							<ol class="relative">
								<li v-for="(activity, i) in group.items" :key="activity.id" class="at-item group relative flex gap-4 pb-3 last:pb-0" :style="{ '--i': i }">
									<span v-if="i < group.items.length - 1" class="absolute left-[15px] top-9 h-[calc(100%-1.5rem)] w-px bg-gray-200" aria-hidden="true" />
									<span class="at-node" :class="nodeClass(activity.status)" :aria-label="formatActivityStatus(activity.status)">
										<IconBase v-if="activity.status === 'completed'" name="check" class="h-3.5 w-3.5" />
									</span>
									<article class="min-w-0 flex-1 rounded-2xl border border-gray-200 bg-white p-4 transition hover:border-primary-200 hover:shadow-[0_12px_28px_-20px_rgba(16,63,77,0.45)]" :class="activity.status === 'completed' ? 'bg-gray-50/40' : ''">
										<div class="flex items-start justify-between gap-3">
											<div class="min-w-0">
												<p class="text-sm font-semibold text-gray-950" :class="activity.status === 'completed' ? 'text-gray-600' : ''">{{ activity.title }}</p>
												<p v-if="activity.description" class="mt-1 line-clamp-2 text-sm text-gray-500">{{ activity.description }}</p>
											</div>
											<span class="shrink-0 text-xs tabular-nums text-gray-400">{{ timeOf(activity.activity_at) }}</span>
										</div>
										<div class="mt-3 flex flex-wrap items-center gap-1.5">
											<span class="at-chip"><span class="at-node-mini" :class="miniClass(activity.status)" />{{ formatActivityStatus(activity.status) }}</span>
											<span v-if="!selectedEventKey && activity.event?.name" class="at-chip"><IconBase name="calendar" class="h-3 w-3" />{{ activity.event.name }}</span>
											<span v-if="activity.category" class="at-chip">{{ activity.category }}</span>
											<span v-if="activity.tag" class="at-chip">{{ activity.tag }}</span>
											<span v-if="activity.person" class="at-chip"><IconBase name="user" class="h-3 w-3" />{{ activity.person }}</span>
											<button type="button" class="at-chip transition" :class="activity.visible_to_client ? '!border-primary-200 !bg-primary-50 !text-primary-700' : ''"
												:disabled="togglingId === activity.id"
												:title="activity.visible_to_client ? 'The client sees this on their timeline. Click to hide it.' : 'Only your team sees this. Click to show it to the client.'"
												@click="toggleClientVisibility(activity)">
												<IconBase :name="activity.visible_to_client ? 'users' : 'shield-check'" class="h-3 w-3" />{{ activity.visible_to_client ? 'Client can see' : 'Team only' }}
											</button>

											<!-- Quick actions -->
											<span class="ml-auto flex items-center gap-1 opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 max-md:opacity-100">
												<button v-for="s in statusOptions" :key="s.value" type="button" class="at-quick" :class="activity.status === s.value ? 'is-on' : ''" :disabled="busyId === activity.id || activity.status === s.value" :title="`Mark as ${s.label.toLowerCase()}`" :aria-label="`Mark ${activity.title} as ${s.label.toLowerCase()}`" @click="setStatus(activity, s.value)">
													<span class="at-node-mini" :class="s.mini" />
												</button>
												<button type="button" class="at-quick hover:!bg-red-50 hover:!text-red-600" :disabled="busyId === activity.id" :aria-label="`Delete ${activity.title}`" title="Delete" @click="confirmDelete = activity"><IconBase name="trash" class="h-3.5 w-3.5" /></button>
											</span>
										</div>
									</article>
								</li>
							</ol>
						</div>
					</div>

					<!-- Empty -->
					<div v-else key="empty" class="rounded-2xl border border-dashed border-gray-300 bg-white px-6 py-14 text-center">
						<span class="at-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700"><IconBase name="clipboard-list" class="h-7 w-7" /></span>
						<p class="mt-4 font-semibold text-gray-900">{{ emptyTitle }}</p>
						<p class="mx-auto mt-1 max-w-md text-sm text-gray-500">{{ emptyText }}</p>
						<button v-if="canAddHere && !hasFilters" type="button" class="at-btn-primary mx-auto mt-5 h-11" @click="openAddActivity"><IconBase name="plus" class="h-4 w-4" /> Add the first activity</button>
						<button v-else-if="hasFilters" type="button" class="mt-4 text-sm font-semibold text-primary-700 hover:underline" @click="clearFilters">Clear filters</button>
					</div>
				</Transition>
			</section>
		</div>

		<!-- Add activity panel -->
		<Teleport to="body">
			<Transition name="at-fade"><div v-if="showAddActivity" class="fixed inset-0 z-[110] bg-slate-950/40 backdrop-blur-[2px]" @click="closeAddActivity" /></Transition>
			<Transition name="at-slide">
				<aside v-if="showAddActivity" class="fixed inset-y-0 right-0 z-[115] flex w-full max-w-md flex-col bg-white shadow-2xl" role="dialog" aria-modal="true" aria-label="Add activity">
					<div class="flex items-start justify-between border-b border-gray-100 px-6 py-5">
						<div>
							<h2 class="text-lg font-bold text-gray-950">Add activity</h2>
							<p class="mt-0.5 text-sm text-gray-500">Log a milestone, update or task on an event.</p>
						</div>
						<button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-gray-400 transition hover:bg-gray-100 hover:text-gray-700" aria-label="Close" @click="closeAddActivity"><IconBase name="x" class="h-5 w-5" /></button>
					</div>

					<form class="flex min-h-0 flex-1 flex-col" novalidate @submit.prevent="saveActivity">
						<fieldset :disabled="isSavingActivity" class="flex-1 divide-y divide-gray-100 overflow-y-auto">
							<div class="at-group">
								<p class="at-group-title">What happened?</p>
								<div>
									<span class="at-label">Event</span>
									<FormsSelect v-model="activityForm.eventId" :options="eventOptions" placeholder="Choose an event" :can-clear="false" />
								</div>
								<div>
									<label for="at-title" class="at-label">Title</label>
									<input id="at-title" v-model="activityForm.title" type="text" maxlength="255" class="at-field" :class="activityFormError && !activityForm.title.trim() ? 'at-invalid' : ''" placeholder="e.g. Venue walkthrough" />
								</div>
								<div>
									<label for="at-desc" class="at-label">Details <span class="at-optional">Optional</span></label>
									<textarea id="at-desc" v-model="activityForm.description" rows="3" class="at-field min-h-[88px] resize-y py-2.5 leading-6" placeholder="Anything the team should know" />
								</div>
							</div>

							<div class="at-group">
								<p class="at-group-title">Status</p>
								<div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Status">
									<button v-for="s in statusOptions" :key="s.value" type="button" role="radio" class="at-option" :class="activityForm.status === s.value ? 'is-on' : ''" :aria-checked="activityForm.status === s.value" @click="activityForm.status = s.value">
										<span class="at-node-mini" :class="s.mini" />{{ s.label }}
									</button>
								</div>
							</div>

							<div class="at-group">
								<p class="at-group-title">Details</p>
								<div class="grid grid-cols-2 gap-3">
									<div>
										<span class="at-label">Category</span>
										<FormsSelect v-model="activityForm.category" :options="categoryOptions" placeholder="Choose" />
									</div>
									<div>
										<span class="at-label">Tag</span>
										<FormsSelect v-model="activityForm.tag" :options="tagOptions" placeholder="Choose" />
									</div>
								</div>
								<div>
									<label for="at-person" class="at-label">Person in charge <span class="at-optional">Optional</span></label>
									<div class="relative">
										<IconBase name="user" class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
										<input id="at-person" v-model="activityForm.person" type="text" maxlength="255" class="at-field !pl-10" placeholder="e.g. Venue coordinator" />
									</div>
								</div>
								<div>
									<span class="at-label">Date and time <span class="at-optional">Optional</span></span>
									<FormsDateTimeField v-model="activityForm.activityAt" name="activity_at" placeholder="Choose date and time" />
								</div>
								<label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3.5 transition hover:border-primary-200">
									<input v-model="activityForm.visibleToClient" type="checkbox" class="mt-0.5 h-4 w-4 accent-[#285F6b]">
									<span>
										<span class="block text-sm font-semibold text-gray-800">Show this on the client's timeline</span>
										<span class="block text-xs text-gray-500">The client sees the title, description, time and status. The person in charge and the tag stay with your team.</span>
									</span>
								</label>
							</div>
							<p v-if="activityFormError" class="mx-6 my-4 rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-700" role="alert">{{ activityFormError }}</p>
						</fieldset>

						<div class="grid grid-cols-[auto_1fr] gap-2 border-t border-gray-100 px-6 py-4">
							<button type="button" class="at-btn-secondary h-11 px-5" :disabled="isSavingActivity" @click="closeAddActivity">Cancel</button>
							<button type="submit" class="at-btn-primary h-11 justify-center" :disabled="isSavingActivity">
								<IconBase :name="isSavingActivity ? 'refresh-cw' : 'plus'" class="h-4 w-4" :class="isSavingActivity ? 'animate-spin' : ''" />
								{{ isSavingActivity ? 'Adding…' : 'Add activity' }}
							</button>
						</div>
					</form>
				</aside>
			</Transition>
		</Teleport>

		<FormsConfirmationModal :open="!!confirmDelete" :title="`Delete “${confirmDelete?.title ?? ''}”?`" description="This removes the activity from the timeline for good." eyebrow="Activity timeline" icon="trash" confirm-icon="trash" confirm-label="Delete activity" cancel-label="Keep it" loading-label="Deleting…" :loading="!!confirmDelete && busyId === confirmDelete.id" @confirm="deleteActivity" @cancel="confirmDelete = null" />

		<Teleport to="body">
			<Transition name="at-toast">
				<div v-if="toast" class="fixed bottom-6 left-1/2 z-[130] flex -translate-x-1/2 items-center gap-2.5 rounded-xl bg-gray-900 px-4 py-3 text-sm font-medium text-white shadow-xl" role="status">
					<IconBase :name="toast.error ? 'x-circle' : 'check-circle'" class="h-4 w-4" :class="toast.error ? 'text-red-400' : 'text-green-400'" />{{ toast.message }}
				</div>
			</Transition>
		</Teleport>
	</div>
</template>

<script setup lang="ts">
interface OrganizerEvent { id: number, inquiry_id: number | null, quotation_id: number | null, name: string, event_type: string, event_date: string | null, location: string | null, status: string }
interface OfferInquiry { id: number, event_title: string | null, event_type: string, event_date: string | null, location: string | null }
interface OrganizerQuotation { id: number, inquiry_id: number, organizer_id: number, quotation_status: 'pending' | 'accepted' | 'rejected' | 'withdrawn', inquiry?: OfferInquiry, event?: OrganizerEvent | null }
interface ActivityTimelineEvent { key: string, eventId: number | null, quotationId: number | null, name: string, eventType: string, eventDate: string | null, location: string | null, status: string, hasRealEvent: boolean }
interface ActivityEvent { id: number, name: string, event_type: string }
type ActivityStatus = 'pending' | 'in_progress' | 'completed'
interface EventActivity { id: number, event_id: number, title: string, description: string | null, category: string | null, tag: string | null, person: string | null, status: ActivityStatus, activity_at: string | null, created_at?: string, event?: ActivityEvent, visible_to_client?: boolean }
interface ApiErrorResponse { data?: { message?: string, errors?: Record<string, string[]> } }

definePageMeta({ layout: 'dashboard', middleware: ['organizer'] })


const timelineEvents = useState<ActivityTimelineEvent[]>('organizerActivityTimelineEvents', () => [])
const organizerEvents = useState<OrganizerEvent[]>('organizerEvents', () => [])
const quotations = useState<OrganizerQuotation[]>('organizerEventQuotations', () => [])
const activities = ref<EventActivity[]>([])
const selectedEventId = ref<number | null>(null)
const selectedEventKey = ref<string | null>(null)
const selectedStatus = ref<'All' | ActivityStatus>('All')
const searchQuery = ref('')
const isLoading = ref(false)
const errorMessage = ref('')
const ready = ref(false)

const statusOptions: Array<{ value: ActivityStatus, label: string, mini: string }> = [
	{ value: 'pending', label: 'Pending', mini: 'is-pending' },
	{ value: 'in_progress', label: 'In progress', mini: 'is-progress' },
	{ value: 'completed', label: 'Completed', mini: 'is-done' },
]
const categoryOptions = ['Planning', 'Venue', 'Catering', 'Staff', 'Logistics', 'Marketing', 'Registration', 'Other']
const tagOptions = ['Milestone', 'Update', 'Action', 'Reminder']
const RING = 2 * Math.PI * 27

function getApiErrorMessage(error: unknown, fallback: string): string {
	return Object.values(fieldErrors(error))[0] ?? (authMessage(error) || fallback)
}
let orgId = ''

// ---------- Load ----------
function syncTimelineEvents() {
	const items: ActivityTimelineEvent[] = organizerEvents.value.map(event => ({
		key: `event-${event.id}`, eventId: event.id, quotationId: event.quotation_id, name: event.name, eventType: event.event_type,
		eventDate: event.event_date, location: event.location, status: event.status, hasRealEvent: true,
	}))
	for (const q of quotations.value) {
		if (q.quotation_status !== 'accepted' || organizerEvents.value.some(e => e.quotation_id === q.id)) continue
		items.push({
			key: `quotation-${q.id}`, eventId: null, quotationId: q.id, name: q.inquiry?.event_title || `${q.inquiry?.event_type || 'Event'} Event`,
			eventType: q.inquiry?.event_type || 'Event', eventDate: q.inquiry?.event_date ?? null, location: q.inquiry?.location ?? null, status: 'awarded', hasRealEvent: false,
		})
	}
	timelineEvents.value = items
}

async function loadTimeline() {
	isLoading.value = true
	errorMessage.value = ''
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId = org.id
		const [data, list] = await Promise.all([
			loadLegacyOrganizerData(orgId),
			useApi().get<EventActivity[]>(`/organizations/${orgId}/activities`),
		])
		organizerEvents.value = data.events as unknown as OrganizerEvent[]
		quotations.value = data.quotations as unknown as OrganizerQuotation[]
		activities.value = list.data
		syncTimelineEvents()
	} catch (error: unknown) {
		errorMessage.value = getApiErrorMessage(error, 'Your timeline couldn’t be loaded. Refresh the page to try again.')
	} finally {
		isLoading.value = false
		requestAnimationFrame(() => { ready.value = true })
	}
}

// ---------- Selection & filters ----------
function selectAllEvents() { selectedEventId.value = null; selectedEventKey.value = null }
function selectEvent(event: ActivityTimelineEvent) { selectedEventKey.value = event.key; selectedEventId.value = event.eventId }
const selectedEventData = computed(() => selectedEventKey.value ? timelineEvents.value.find(e => e.key === selectedEventKey.value) ?? null : null)
const realEvents = computed(() => timelineEvents.value.filter(e => e.hasRealEvent && e.eventId))
const eventOptions = computed(() => realEvents.value.map(e => ({ value: e.eventId as number, label: e.name })))
const canAddHere = computed(() => selectedEventData.value ? selectedEventData.value.hasRealEvent : realEvents.value.length > 0)
const hasFilters = computed(() => selectedStatus.value !== 'All' || !!searchQuery.value.trim())
function clearFilters() { selectedStatus.value = 'All'; searchQuery.value = '' }

const scopedActivities = computed(() => selectedEventKey.value
	? (selectedEventId.value === null ? [] : activities.value.filter(a => a.event_id === selectedEventId.value))
	: activities.value)

const filteredActivities = computed(() => {
	const query = searchQuery.value.trim().toLowerCase()
	return scopedActivities.value.filter((a) => {
		if (selectedStatus.value !== 'All' && a.status !== selectedStatus.value) return false
		if (!query) return true
		return [a.title, a.description, a.category, a.person, a.tag, a.event?.name].filter(Boolean).join(' ').toLowerCase().includes(query)
	})
})
const listKey = computed(() => `${selectedEventKey.value ?? 'all'}|${selectedStatus.value}`)

const totalActivities = computed(() => activities.value.length)
const statusCards = computed(() => {
	const scope = scopedActivities.value
	const total = scope.length || 1
	const count = (s: ActivityStatus) => scope.filter(a => a.status === s).length
	return [
		{ value: 'All' as const, label: 'All activities', count: scope.length, percent: scope.length ? 100 : 0, node: 'is-all', bar: 'bg-gray-800' },
		{ value: 'completed' as const, label: 'Completed', count: count('completed'), percent: Math.round((count('completed') / total) * 100), node: 'is-done', bar: 'bg-primary-700' },
		{ value: 'in_progress' as const, label: 'In progress', count: count('in_progress'), percent: Math.round((count('in_progress') / total) * 100), node: 'is-progress', bar: 'bg-amber-500' },
		{ value: 'pending' as const, label: 'Pending', count: count('pending'), percent: Math.round((count('pending') / total) * 100), node: 'is-pending', bar: 'bg-gray-400' },
	]
})

function eventProgress(event: ActivityTimelineEvent) {
	const list = event.eventId ? activities.value.filter(a => a.event_id === event.eventId) : []
	const done = list.filter(a => a.status === 'completed').length
	return { done, total: list.length, percent: list.length ? Math.round((done / list.length) * 100) : 0 }
}
const selectedProgress = computed(() => selectedEventData.value ? eventProgress(selectedEventData.value) : { done: 0, total: 0, percent: 0 })

// Group newest first by day.
const groupedActivities = computed(() => {
	const sorted = [...filteredActivities.value].sort((a, b) => stamp(b) - stamp(a))
	const groups: Array<{ label: string, items: EventActivity[] }> = []
	for (const a of sorted) {
		const label = dayLabel(a.activity_at ?? a.created_at ?? null)
		const last = groups[groups.length - 1]
		if (last && last.label === label) last.items.push(a)
		else groups.push({ label, items: [a] })
	}
	return groups
})
function stamp(a: EventActivity) { const v = a.activity_at ?? a.created_at; return v ? new Date(v).getTime() : 0 }

const emptyTitle = computed(() => {
	if (hasFilters.value) return 'Nothing matches'
	if (selectedEventData.value && !selectedEventData.value.hasRealEvent) return 'Event setup hasn’t started'
	return 'No activities yet'
})
const emptyText = computed(() => {
	if (hasFilters.value) return 'Try another search or status.'
	if (selectedEventData.value && !selectedEventData.value.hasRealEvent) return `Set up ${selectedEventData.value.name} in Event Management, then log its milestones here.`
	return 'Log milestones, updates and tasks as you plan, and your whole team can see progress at a glance.'
})

// ---------- Add activity ----------
const showAddActivity = ref(false)
const isSavingActivity = ref(false)
const activityFormError = ref('')
const activityForm = reactive({ eventId: null as number | null, title: '', description: '', category: '', tag: '', person: '', status: 'pending' as ActivityStatus, activityAt: '', visibleToClient: false })

function resetActivityForm() {
	Object.assign(activityForm, { eventId: selectedEventData.value?.eventId ?? realEvents.value[0]?.eventId ?? null, title: '', description: '', category: '', tag: '', person: '', status: 'pending', activityAt: '', visibleToClient: false })
	activityFormError.value = ''
}
function openAddActivity() { if (!realEvents.value.length) return; resetActivityForm(); showAddActivity.value = true }
function closeAddActivity() { if (!isSavingActivity.value) showAddActivity.value = false }

async function saveActivity() {
	if (!activityForm.eventId) { activityFormError.value = 'Choose the event this activity belongs to.'; return }
	if (!activityForm.title.trim()) { activityFormError.value = 'Give the activity a title.'; return }
	isSavingActivity.value = true
	activityFormError.value = ''
	try {
		const response = await useApi().post<EventActivity>(`/organizations/${orgId}/events/${activityForm.eventId}/activities`, {
			title: activityForm.title.trim(), description: activityForm.description.trim() || null, category: activityForm.category || null,
			tag: activityForm.tag || null, person: activityForm.person.trim() || null, status: activityForm.status, activity_at: activityForm.activityAt || null,
			visible_to_client: activityForm.visibleToClient,
		})
		const event = organizerEvents.value.find(e => e.id === activityForm.eventId)
		activities.value.unshift({ ...response.data, event: response.data.event ?? (event ? { id: event.id, name: event.name, event_type: event.event_type } : undefined) })
		showAddActivity.value = false
		showToast('Activity added')
	} catch (error: unknown) {
		activityFormError.value = getApiErrorMessage(error, 'The activity wasn’t added. Check your connection and try again.')
	} finally {
		isSavingActivity.value = false
	}
}

// ---------- Quick status / delete ----------
const busyId = ref<number | null>(null)
const confirmDelete = ref<EventActivity | null>(null)
async function setStatus(activity: EventActivity, status: ActivityStatus) {
	const before = activity.status
	activity.status = status
	busyId.value = activity.id
	try {
		await useApi().patch(`/organizations/${orgId}/events/${activity.event_id}/activities/${activity.id}`, { status })
		showToast(`Marked as ${formatActivityStatus(status).toLowerCase()}`)
	} catch (error) {
		activity.status = before
		showToast(getApiErrorMessage(error, 'That change wasn’t saved. Try again.'), true)
	} finally {
		busyId.value = null
	}
}
async function deleteActivity() {
	const target = confirmDelete.value
	if (!target) return
	busyId.value = target.id
	try {
		await useApi().delete(`/organizations/${orgId}/events/${target.event_id}/activities/${target.id}`)
		activities.value = activities.value.filter(a => a.id !== target.id)
		confirmDelete.value = null
		showToast('Activity deleted')
	} catch (error) {
		showToast(getApiErrorMessage(error, 'The activity wasn’t deleted. Try again.'), true)
	} finally {
		busyId.value = null
	}
}

// ---------- Who sees an activity (0049) ----------
const togglingId = ref<EventActivity['id'] | null>(null)
async function toggleClientVisibility(activity: EventActivity) {
	togglingId.value = activity.id
	try {
		const updated = (await useApi().patch<EventActivity>(`/organizations/${orgId}/events/${activity.event_id}/activities/${activity.id}`, {
			visible_to_client: !activity.visible_to_client,
		})).data
		activity.visible_to_client = updated.visible_to_client
		showToast(updated.visible_to_client ? 'The client can see this now' : 'Only your team sees this now')
	} catch (error: unknown) {
		showToast(getApiErrorMessage(error, 'Could not change who sees this activity.'), true)
	} finally {
		togglingId.value = null
	}
}

// ---------- Display helpers ----------
function parse(value: string) { return new Date(value.includes('T') || value.includes(' ') ? value : `${value}T00:00:00`) }
function formatDate(value: string | null) { return value ? parse(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Date not set' }
function monthOf(value: string | null) { return value ? parse(value).toLocaleDateString('en-US', { month: 'short' }) : '—' }
function dayOf(value: string | null) { return value ? String(parse(value).getDate()) : '' }
function timeOf(value: string | null) { return value ? parse(value).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }) : '' }
function dayLabel(value: string | null) {
	if (!value) return 'No date'
	const d = parse(value)
	const today = new Date(); today.setHours(0, 0, 0, 0)
	const day = new Date(d); day.setHours(0, 0, 0, 0)
	const diff = Math.round((today.getTime() - day.getTime()) / 86400000)
	if (diff === 0) return 'Today'
	if (diff === 1) return 'Yesterday'
	if (diff === -1) return 'Tomorrow'
	return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' })
}
function shortLocation(value: string | null) { return value ? value.split(',').map(p => p.trim()).filter(Boolean).slice(0, 2).join(', ') : 'No location yet' }
function formatEventStatus(status: string) {
	return ({ published: 'Published', confirmed: 'Confirmed', ongoing: 'Ongoing', completed: 'Completed', cancelled: 'Cancelled', awarded: 'Awarded' } as Record<string, string>)[status] ?? 'Draft'
}
function eventBadgeClass(status: string) {
	if (['published', 'confirmed', 'awarded'].includes(status)) return 'bg-green-50 text-green-700'
	if (status === 'ongoing') return 'bg-primary-50 text-primary-700'
	if (status === 'cancelled') return 'bg-red-50 text-red-600'
	return 'bg-gray-100 text-gray-500'
}
function formatActivityStatus(status: ActivityStatus) { return status === 'completed' ? 'Completed' : status === 'in_progress' ? 'In progress' : 'Pending' }
function nodeClass(status: ActivityStatus) { return status === 'completed' ? 'is-done' : status === 'in_progress' ? 'is-progress' : 'is-pending' }
function miniClass(status: ActivityStatus) { return nodeClass(status) }

const toast = ref<{ message: string, error?: boolean } | null>(null)
let toastTimer: ReturnType<typeof setTimeout> | null = null
function showToast(message: string, error = false) { toast.value = { message, error }; if (toastTimer) clearTimeout(toastTimer); toastTimer = setTimeout(() => { toast.value = null }, 2400) }

function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && showAddActivity.value) closeAddActivity() }
onMounted(() => { loadTimeline(); window.addEventListener('keydown', onKey) })
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<style scoped>
.at-label { @apply mb-1.5 block text-sm font-semibold text-gray-800; }
.at-optional { @apply ml-1 rounded-md bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold text-gray-500; }
.at-group { @apply space-y-4 px-6 py-5; }
.at-group-title { @apply text-xs font-bold text-primary-800; }
.at-field { @apply h-11 w-full rounded-xl border border-gray-200 bg-white px-3.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10; }
.at-invalid { @apply border-red-300; }
.at-btn-primary { @apply inline-flex items-center gap-2 rounded-xl bg-primary-700 px-5 text-sm font-bold text-white shadow-sm transition hover:bg-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50; }
.at-btn-secondary { @apply inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50; }
.at-option { @apply flex h-11 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-gray-200 bg-white px-1.5 text-[13px] font-medium sm:gap-2 sm:px-2 sm:text-sm text-gray-700 transition hover:border-gray-300 hover:bg-gray-50; }
.at-option.is-on { @apply border-primary-600 bg-primary-25 text-primary-800 ring-1 ring-primary-600; }
.at-chip { @apply inline-flex max-w-full items-center gap-1.5 truncate rounded-full border border-gray-200 bg-white px-2.5 py-0.5 text-xs font-medium text-gray-600; }
.at-quick { @apply flex h-9 w-9 sm:h-7 sm:w-7 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 disabled:cursor-default; }
.at-quick.is-on { @apply bg-gray-100; }

/* Status cards */
.at-stat { @apply rounded-2xl border border-gray-200 bg-white p-4 text-left transition hover:border-gray-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600; animation: at-rise 0.45s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 50ms); }
.at-stat.is-on { @apply border-primary-600 bg-primary-25 ring-1 ring-primary-600; }

/* Event list */
.at-event { @apply flex w-full items-start gap-3 rounded-xl border border-transparent p-2.5 text-left transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600; }
.at-event.is-on { @apply border-primary-200 bg-primary-25; }

/* Timeline nodes */
.at-node { @apply relative z-10 mt-3 flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full ring-4 ring-gray-50 transition-colors duration-300; }
.at-node.is-done { @apply bg-primary-700 text-white; }
.at-node.is-progress { @apply border-2 border-amber-500 bg-white; }
.at-node.is-progress::after { content: ''; @apply absolute inset-[-2px] rounded-full border-2 border-amber-400; animation: at-pulse 1.8s ease-out infinite; }
.at-node.is-pending { @apply border-2 border-gray-300 bg-white; }
.at-node-mini { @apply inline-block h-2 w-2 shrink-0 rounded-full; }
.at-node-mini.is-done { @apply bg-primary-700; }
.at-node-mini.is-progress { @apply bg-amber-500; }
.at-node-mini.is-pending { @apply border-[1.5px] border-gray-400 bg-white; }
.at-node-mini.is-all { @apply bg-gray-800; }

.at-dots { position: absolute; inset: 0; pointer-events: none; background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1px, transparent 1.3px); background-size: 16px 16px; mask-image: linear-gradient(105deg, transparent 20%, black 75%); }

.at-item { animation: at-rise 0.4s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 45ms); }
.at-float { animation: at-float 3.5s ease-in-out infinite; }
@keyframes at-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
@keyframes at-pulse { 0% { transform: scale(1); opacity: 0.8; } 100% { transform: scale(1.6); opacity: 0; } }
@keyframes at-float { 50% { transform: translateY(-4px); } }

.at-swap-enter-active, .at-swap-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.at-swap-enter-from { opacity: 0; transform: translateY(6px); }
.at-swap-leave-to { opacity: 0; transform: translateY(-4px); }
.at-fade-enter-active, .at-fade-leave-active { transition: opacity 0.2s ease; }
.at-fade-enter-from, .at-fade-leave-to { opacity: 0; }
.at-slide-enter-active, .at-slide-leave-active { transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1); }
.at-slide-enter-from, .at-slide-leave-to { transform: translateX(100%); }
.at-toast-enter-active, .at-toast-leave-active { transition: opacity 0.2s ease, transform 0.2s ease; }
.at-toast-enter-from, .at-toast-leave-to { opacity: 0; transform: translate(-50%, 10px); }

@media (prefers-reduced-motion: reduce) {
	.at-stat, .at-item, .at-float, .at-node.is-progress::after { animation: none; }
	.at-swap-enter-active, .at-swap-leave-active, .at-slide-enter-active, .at-slide-leave-active { transition: none; }
}
</style>
