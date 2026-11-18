<template>
    <div>
        <div class="mb-7">
            <p class="text-sm font-semibold text-primary-700">Team Member Portal</p>
            <h1 class="mt-1 text-2xl font-extrabold text-gray-900">Hi there, {{ firstName }}</h1>
            <p class="mt-1 text-gray-500">View your assigned event tasks and update your progress.</p>
        </div>
        <div class="grid grid-cols-2 gap-2.5 sm:gap-5 xl:grid-cols-4">
            <div v-for="card in stats" :key="card.label" class="rounded-2xl border border-gray-200 bg-white p-3.5 sm:p-5">
                <div class="flex items-start justify-between">
                    <div>
                        <p class="text-xs text-gray-500 sm:text-sm">{{ card.label }}</p>
                        <p class="mt-1 text-xl font-extrabold text-gray-900 sm:mt-4 sm:text-3xl">{{ card.value }}</p>
                    </div>
                    <div class="hidden h-10 w-10 items-center justify-center rounded-xl sm:flex" :class="card.iconClass">
                        <IconBase :name="card.icon" class="h-5 w-5" />
                    </div>
                </div>
            </div>
        </div>
        <div v-if="errorMessage" class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{
            errorMessage }}</div>
        <div v-if="loading"
            class="mt-6 rounded-2xl border border-gray-200 bg-white p-12 text-center text-sm text-gray-500">Loading your
            assignments...</div>
        <div v-else class="mt-6 space-y-5">
            <article v-for="group in groupedTasks" :key="group.event.id"
                class="rounded-2xl border border-gray-200 bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 pb-5">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-50 text-primary-700">
                            <IconBase name="calendar" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-900">{{ group.event.name }}</h2>
                            <p class="mt-0.5 text-sm text-gray-500">{{ group.event.event_type }} · {{
                                formatDate(group.event.event_date) }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- 0062 (organizer field test): the client's mood board, one tap away while working. -->
                        <button v-if="group.event.mood_board.length" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-primary-200 bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-800 hover:bg-primary-100"
                            @click="moodFor = group.event">
                            <IconBase name="camera" class="h-3.5 w-3.5" />Mood board ({{ group.event.mood_board.length }})
                        </button>
                        <span class="rounded-full bg-primary-50 px-3 py-1 text-xs font-semibold text-primary-700">{{
                        group.tasks.length }} task{{ group.tasks.length === 1 ? '' : 's' }}</span>
                    </div>
                </div>
                <div class="divide-y divide-gray-100">
                    <div v-for="task in group.tasks" :key="task.id" class="py-5 last:pb-0">
                        <div class="flex items-start gap-3">
                            <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                :class="task.is_completed ? 'bg-green-50 text-green-600' : 'bg-amber-50 text-amber-600'">
                                <IconBase :name="task.is_completed ? 'check-circle' : 'clock'" class="h-5 w-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-900">{{ task.label }}</h3>
                                        <p v-if="task.completion_note" class="mt-1 text-xs text-gray-500">{{
                                            task.completion_note }}</p>
                                    </div><span v-if="task.is_completed"
                                        class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="reviewClass(task.review_status)">{{ reviewLabel(task.review_status)
                                        }}</span>
                                </div>
                                <p v-if="task.review_status === 'changes_requested'"
                                    class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">The
                                    organizer requested changes<template v-if="task.review_note">: “{{ task.review_note.replace(/[.!?]+$/, '') }}”</template>. Update the work and submit it again.</p>
                                <div v-if="!task.is_completed" class="mt-3 flex flex-col gap-2 sm:flex-row"><input
                                        v-model="notes[task.id]"
                                        class="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm outline-none focus:border-primary-500"
                                        placeholder="Completion note (optional)"><button
                                        class="rounded-lg bg-primary-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
                                        :disabled="completingId === task.id" @click="completeTask(task)">{{
                                            completingId ===task.id?'Saving...':'Mark Completed' }}</button></div>
                            </div>
                        </div>
                    </div>
                </div>
            </article>
            <div v-if="!tasks.length"
                class="rounded-2xl border border-dashed border-gray-300 bg-white p-12 text-center">
                <IconBase name="check-circle" class="mx-auto h-8 w-8 text-primary-700" />
                <h2 class="mt-3 font-bold text-gray-900">You’re all caught up</h2>
                <p class="mt-1 text-sm text-gray-500">New assignments from your organizer will appear here.</p>
            </div>
        </div>

        <!-- The client's mood board for this event. -->
        <div v-if="moodFor" class="fixed inset-0 z-[120] flex items-end justify-center bg-gray-900/40 p-4 sm:items-center" @click.self="moodFor = null" @keydown.esc="moodFor = null">
            <section class="max-h-[85dvh] w-full max-w-3xl overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl" role="dialog" aria-modal="true" :aria-label="`Mood board for ${moodFor.name}`">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-wide text-primary-700">Client's mood board</p>
                        <h2 class="truncate text-lg font-bold text-gray-900">{{ moodFor.name }}</h2>
                    </div>
                    <button type="button" class="tap-target rounded-lg p-1.5 text-gray-400 hover:bg-gray-100" aria-label="Close" @click="moodFor = null"><IconBase name="x" class="h-5 w-5" /></button>
                </div>
                <MoodBoard :images="moodFor.mood_board" />
            </section>
        </div>
    </div>
</template>
<script setup lang="ts">
// My Assignments: tasks assigned to me across my organization's events (our /my-tasks),
// finished with a note for the organizer to verify (0044 reviews).
interface Task { id: string; label: string; is_completed: boolean; completion_note: string | null; review_status: string; review_note: string | null; event: { id: string; name: string; event_type: string; event_date: string | null; mood_board: MoodImage[] } }
type MoodImage = { id: string, url: string | null, caption: string | null }
const moodFor = ref<Task['event'] | null>(null)
definePageMeta({ layout: 'team', middleware: ['team'] })
const { firstName } = useAuth('team_member')
const tasks = ref<Task[]>([]), loading = ref(true), errorMessage = ref(''), completingId = ref<string | null>(null), notes = reactive<Record<string, string>>({})
const orgId = ref<string | null>(null)
// Soonest event first; past events after upcoming ones (most recent first).
const groupedTasks = computed(() => {
  const groups = Object.values(tasks.value.reduce<Record<string, { event: Task['event']; tasks: Task[] }>>((acc, task) => { (acc[task.event.id] ??= { event: task.event, tasks: [] }).tasks.push(task); return acc }, {}))
  const today = new Date().toISOString().slice(0, 10)
  const day = (g: { event: Task['event'] }) => g.event.event_date ?? '9999-12-31'
  return groups.sort((a, b) => {
    const pastA = day(a) < today, pastB = day(b) < today
    if (pastA !== pastB) return pastA ? 1 : -1
    return pastA ? day(b).localeCompare(day(a)) : day(a).localeCompare(day(b))
  })
})
const stats = computed(() => [{ label: 'Assigned Tasks', value: tasks.value.length, icon: 'clipboard-list', iconClass: 'bg-gray-100 text-gray-500' }, { label: 'Assigned Events', value: groupedTasks.value.length, icon: 'calendar', iconClass: 'bg-blue-50 text-blue-600' }, { label: 'Ready to Work', value: tasks.value.filter(t => !t.is_completed).length, icon: 'clock', iconClass: 'bg-amber-50 text-amber-600' }, { label: 'Completed', value: tasks.value.filter(t => t.is_completed).length, icon: 'check-circle', iconClass: 'bg-green-50 text-green-600' }])
async function load(quiet = false) {
  if (!quiet) loading.value = true
  errorMessage.value = ''
  try {
    orgId.value ??= (await useOrganization().fetchOrganization())?.id ?? null
    if (!orgId.value) { tasks.value = []; return }
    tasks.value = (await useTeam().myTasks(orgId.value))
      .filter(t => t.status !== 'cancelled')
      .map(t => ({
        id: t.id,
        label: t.name,
        is_completed: t.status === 'done',
        completion_note: t.completion_note ?? null,
        review_status: t.review_status ?? 'pending',
        review_note: t.review_note ?? null,
        event: { id: t.event_id, name: t.event, event_type: t.event_type ?? 'Event', event_date: t.event_starts_at ?? null, mood_board: t.mood_board ?? [] },
      }))
  } catch (error: unknown) {
    errorMessage.value = authMessage(error) || 'Unable to load assignments.'
  } finally {
    loading.value = false
  }
}
async function completeTask(task: Task) {
  if (!orgId.value) return
  completingId.value = task.id
  errorMessage.value = ''
  try {
    await useApi().patch(`/organizations/${orgId.value}/events/${task.event.id}/tasks/${task.id}`, { status: 'done', completion_note: notes[task.id]?.trim() || null })
    await load()
  } catch (error: unknown) {
    errorMessage.value = authMessage(error) || 'Unable to complete task.'
  } finally {
    completingId.value = null
  }
}
const formatDate = (value: string | null) => value ? new Date(value).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : 'Date not set'; const reviewLabel = (status: string) => status === 'verified' ? 'Verified' : status === 'changes_requested' ? 'Changes Requested' : 'Awaiting Review'; const reviewClass = (status: string) => status === 'verified' ? 'bg-green-50 text-green-700' : status === 'changes_requested' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'; onMounted(load)
// Close to live (review #12): other people's changes appear without a reload.
useLiveRefresh(() => load(true), { interval: 60000 })
</script>
