<template>
	<div>
		<!-- Header: title on the left, the request picker on the right (it keeps the list short). -->
		<div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
			<div class="min-w-0">
				<p class="text-xs font-bold uppercase tracking-[0.16em] text-[#285F6b]">Client Portal</p>
				<h1 class="mt-1 text-2xl font-black tracking-tight text-gray-900 sm:text-3xl">Messages</h1>
				<p class="mt-1 text-sm text-gray-500">Private conversations with organizers, one per organizer for each request.</p>
			</div>
			<div v-if="requests.length > 1" class="w-full sm:w-80">
				<label for="msg-request" class="mb-1 block text-xs font-semibold text-gray-500">Request</label>
				<FormsSelect id="msg-request" v-model="requestFilter" :options="requestOptions" :can-clear="false" :searchable="requests.length > 8" />
			</div>
		</div>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<div v-if="loading && !threads.length" class="mt-6 rounded-3xl border border-gray-200 bg-white p-10 text-center">
			<IconBase name="refresh-cw" class="mx-auto h-5 w-5 animate-spin text-gray-400" />
			<p class="mt-2 text-sm text-gray-500">Loading your conversations…</p>
		</div>

		<div v-else-if="!threads.length" class="mt-6 rounded-3xl border-2 border-dashed border-gray-200 p-10 text-center">
			<p class="font-semibold text-gray-800">No conversations yet</p>
			<p class="mt-1 text-sm text-gray-500">When you send a request, you can message each organizer it reaches from here.</p>
			<NuxtLink to="/client/post-event" class="mt-4 inline-block rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-bold text-white">Post an event</NuxtLink>
		</div>

		<!-- Fills the screen's height on desktop, so the chat has room and the page doesn't scroll. -->
		<section v-else class="mt-5 grid overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm md:h-[calc(100dvh-19rem)] md:min-h-[28rem] md:grid-cols-[19rem_1fr]">
			<!-- Conversations. On phones the list hides while a conversation is open. -->
			<nav class="overflow-y-auto border-b border-gray-100 md:border-b-0 md:border-r" :class="selected ? 'hidden md:block' : ''" aria-label="Conversations">
				<div v-for="group in groups" :key="group.inquiryId" class="border-b border-gray-100 last:border-b-0">
					<p v-if="requestFilter === ALL" class="px-4 pb-1 pt-3 text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ group.title }}</p>
					<button v-for="t in group.threads" :key="t.id" type="button"
						class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm transition"
						:class="[selected === t.id ? 'bg-primary-50 font-semibold text-primary-800' : 'hover:bg-gray-50', { 'live-fresh': isFresh(t) }]"
						:aria-current="selected === t.id" @click="selected = t.id">
						<span class="min-w-0">
							<span class="block truncate">{{ t.organization.name }}</span>
							<span class="block truncate text-xs font-normal text-gray-500">{{ t.last ? t.last.body : (t.can_send ? 'No messages yet' : 'Closed') }}</span>
						</span>
						<span v-if="t.unread" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-600 px-1.5 text-[11px] font-bold text-white" :aria-label="`${t.unread} unread`">{{ t.unread }}</span>
					</button>
				</div>
			</nav>

			<div class="flex min-h-[28rem] min-w-0 flex-col md:min-h-0" :class="selected ? '' : 'hidden md:flex'">
				<template v-if="current">
					<!-- Who you're talking to, and about which request. -->
					<header class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3">
						<div class="flex min-w-0 items-center gap-3">
							<button type="button" class="tap-target text-sm font-semibold text-primary-700 md:hidden" aria-label="All conversations" @click="selected = null">←</button>
							<div class="min-w-0">
								<p class="truncate text-sm font-bold text-gray-900">{{ current.organization.name }}</p>
								<p class="truncate text-xs text-gray-500">{{ titleOf(current) }}</p>
							</div>
						</div>
						<NuxtLink :to="`/client/inquiries/${current.inquiry_id}`" class="shrink-0 text-xs font-semibold text-primary-700 hover:underline">View the request</NuxtLink>
					</header>
					<div class="min-h-0 flex-1 p-4">
						<InquiryThread :key="current.id" :thread-id="current.id" @read="markRead" />
					</div>
				</template>
				<p v-else class="m-auto text-sm text-gray-400">Choose a conversation.</p>
			</div>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { ThreadSummary } from '~/composables/useThreads'

definePageMeta({ layout: 'client', middleware: ['client'] })
useHead({ title: 'Messages | NaSeRy' })

type Thread = ThreadSummary & { event_name?: string | null }
const ALL = 'all'

const route = useRoute()
const api = useThreads()
const threads = ref<Thread[]>([])
const selected = ref<string | null>(null)
const requestFilter = ref<string>(ALL)
const loading = ref(true)
const error = ref('')

const titleOf = (t: Thread) => t.event_name || `${t.event_type} request`
const latest = (list: Thread[]) => list.reduce((m, t) => (t.last?.at && t.last.at > m ? t.last.at : m), '')

// Requests, most recent conversation first, with their unread count.
const requests = computed(() => {
	const byInquiry = new Map<string, Thread[]>()
	for (const t of threads.value) byInquiry.set(t.inquiry_id, [...(byInquiry.get(t.inquiry_id) ?? []), t])
	return [...byInquiry.entries()]
		.map(([id, list]) => ({ id, title: titleOf(list[0]!), unread: list.reduce((n, t) => n + (t.unread || 0), 0), last: latest(list), threads: list }))
		.sort((a, b) => b.last.localeCompare(a.last))
})
const requestOptions = computed(() => [
	{ value: ALL, label: 'All requests' },
	...requests.value.map(r => ({ value: r.id, label: r.unread ? `${r.title} (${r.unread} unread)` : r.title })),
])
const groups = computed(() => requests.value
	.filter(r => requestFilter.value === ALL || r.id === requestFilter.value)
	.map(r => ({ inquiryId: r.id, title: r.title, threads: r.threads })))

const current = computed(() => threads.value.find(t => t.id === selected.value) ?? null)
const isFresh = useFreshKeys(() => threads.value, t => `${t.id}:${t.last?.at ?? ''}`)

// Picking a request opens its first unread (or first) conversation on desktop.
watch(requestFilter, (id) => {
	if (id === ALL || current.value?.inquiry_id === id) return
	const r = requests.value.find(x => x.id === id)
	if (r && wide()) selected.value = (r.threads.find(t => t.unread) ?? r.threads[0])?.id ?? null
	else if (r) selected.value = null
})

function wide() { return import.meta.client && window.matchMedia('(min-width: 768px)').matches }

function markRead() {
	const t = threads.value.find(x => x.id === selected.value)
	if (t) t.unread = 0
}

async function load(quiet = false) {
	if (!quiet) loading.value = true
	error.value = ''
	try {
		threads.value = await api.mine()
		if (!quiet) {
			// From a message alert (?thread=) or a request page (?inquiry=): open that conversation.
			const byThread = typeof route.query.thread === 'string' ? threads.value.find(t => t.id === route.query.thread) : null
			const inquiry = byThread?.inquiry_id ?? (typeof route.query.inquiry === 'string' ? route.query.inquiry : null)
			if (inquiry && requests.value.some(r => r.id === inquiry)) requestFilter.value = inquiry
			const pick = byThread ?? (inquiry ? (threads.value.find(t => t.inquiry_id === inquiry && t.unread) ?? threads.value.find(t => t.inquiry_id === inquiry)) : null)
			// Otherwise (many requests): start on the most recent one, so the list stays short.
			if (!inquiry && requests.value.length > 3) requestFilter.value = requests.value[0]!.id
			if (pick) selected.value = pick.id
			else if (wide()) {
				const first = requests.value.find(r => requestFilter.value === ALL || r.id === requestFilter.value)
				selected.value = (first?.threads.find(t => t.unread) ?? first?.threads[0])?.id ?? null
			}
		}
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

onMounted(() => load())
useLiveRefresh(() => load(true), { interval: 30000 })
</script>
