<template>
	<div class="flex h-full min-h-0 flex-col">
		<div v-if="!thread && !error" class="py-8 text-center text-sm text-gray-400">Loading messages…</div>
		<Alert v-if="error" type="danger" :text="error" />

		<template v-if="thread">
			<p class="text-xs text-gray-500">
				Private between {{ thread.client }} and {{ thread.organization.name }}.
				<template v-if="thread.side === 'organizer'">The client's contact details are shared once they accept your quotation.</template>
			</p>

			<ol ref="list" class="mt-3 min-h-40 flex-1 space-y-3 overflow-y-auto rounded-xl bg-gray-50 p-3" aria-label="Messages" aria-live="polite">
				<li v-if="!thread.messages.length" class="py-6 text-center text-sm text-gray-400">
					No messages yet. {{ thread.side === 'client' ? 'Ask about availability, packages or anything else.' : 'Ask the client about details you need for your quotation.' }}
				</li>
				<li v-for="m in thread.messages" :key="m.id" class="flex" :class="m.mine ? 'justify-end' : 'justify-start'">
					<div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm" :class="m.mine ? 'bg-primary-700 text-white' : 'border border-gray-200 bg-white text-gray-800'">
						<p v-if="!m.mine" class="text-xs font-semibold text-gray-500">{{ m.author }}</p>
						<p class="whitespace-pre-line break-words">{{ m.body }}</p>
						<p class="mt-0.5 text-right text-[11px]" :class="m.mine ? 'text-primary-100' : 'text-gray-400'">{{ time(m.at) }}</p>
					</div>
				</li>
			</ol>

			<form v-if="thread.can_send" class="mt-3 flex items-end gap-2" @submit.prevent="send">
				<label :for="`msg-${threadId}`" class="sr-only">Message</label>
				<textarea :id="`msg-${threadId}`" v-model="draft" rows="2" maxlength="2000" placeholder="Write a message… (Enter to send, Shift+Enter for a new line)"
					class="min-w-0 flex-1 resize-none rounded-xl border border-gray-200 px-3 py-2 text-sm" @keydown.enter.exact.prevent="send" />
				<button type="submit" :disabled="sending || !draft.trim()" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Send</button>
			</form>
			<p v-else class="mt-3 rounded-xl bg-gray-100 px-3 py-2 text-xs text-gray-600">This conversation is closed: the inquiry is no longer open with {{ thread.side === 'client' ? 'this organizer' : 'you' }}. You can still read it.</p>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ThreadDetail } from '~/composables/useThreads'

const props = defineProps<{ threadId: string }>()
const emit = defineEmits<{ read: [threadId: string] }>()

const api = useThreads()
const thread = ref<ThreadDetail | null>(null)
const error = ref('')
const draft = ref('')
// A half-written message survives leaving the page (per thread); sending empties it.
useDraft(() => `message:${props.threadId}`, reactive({ text: draft }))
const sending = ref(false)
const list = ref<HTMLElement | null>(null)
let timer: ReturnType<typeof setInterval> | undefined

const time = (at: string) => new Date(at).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })

async function scrollDown() {
	await nextTick()
	list.value?.scrollTo({ top: list.value.scrollHeight })
}

async function load(quiet = false) {
	try {
		const before = thread.value?.messages.length ?? 0
		thread.value = await api.show(props.threadId)
		error.value = ''
		emit('read', props.threadId)
		if (!quiet || thread.value.messages.length !== before) await scrollDown()
	} catch (e) {
		if (!quiet) error.value = authMessage(e)
	}
}

async function send() {
	const body = draft.value.trim()
	if (!body || sending.value) return
	sending.value = true
	error.value = ''
	try {
		const m = await api.send(props.threadId, body)
		thread.value?.messages.push({ ...m, mine: true })
		draft.value = ''
		await scrollDown()
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
	} finally {
		sending.value = false
	}
}

// New replies show up every few seconds while the thread is on screen.
function start() {
	clearInterval(timer)
	timer = setInterval(() => { if (document.visibilityState === 'visible') load(true) }, 6000)
}

watch(() => props.threadId, () => {
	thread.value = null
	load()
	start()
})
onMounted(() => {
	load()
	start()
})
onBeforeUnmount(() => clearInterval(timer))
</script>
