<template>
	<section v-if="state && (open || state.can_request)" class="rounded-2xl border bg-white p-5" :class="open?.status === 'pending' ? 'border-amber-200' : 'border-gray-200'">
		<Alert v-if="error" type="danger" :text="error" class="mb-3" />

		<!-- Waiting for an answer -->
		<template v-if="open?.status === 'pending'">
			<p class="text-sm font-bold text-gray-950">{{ open.can_respond ? `${otherName} asked to cancel this booking` : 'You asked to cancel this booking' }}</p>
			<p class="mt-1 text-sm text-gray-600">Reason: {{ open.reason }}</p>
			<p class="mt-1 text-xs text-amber-800">
				{{ open.can_respond ? 'If you don’t answer by' : `If ${otherName} doesn’t answer by` }} {{ formatDateTime(open.respond_by) }}, the booking will be cancelled.
			</p>

			<template v-if="open.can_respond">
				<label :for="`cancel-note-${inquiryId}`" class="mt-3 block text-sm font-semibold text-gray-700">Your note <span class="font-normal text-gray-400">(optional)</span></label>
				<textarea :id="`cancel-note-${inquiryId}`" v-model="note" rows="2" maxlength="500"
					class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none" />
				<div class="mt-3 flex flex-wrap gap-2">
					<button type="button" :disabled="busy" class="rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-primary-900 disabled:opacity-60" @click="answer('decline')">Keep the booking</button>
					<button type="button" :disabled="busy" class="rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-60" @click="answer('agree')">Agree to cancel</button>
				</div>
			</template>
			<button v-else-if="open.can_withdraw" type="button" :disabled="busy" class="mt-3 rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60" @click="withdraw">Withdraw request</button>
		</template>

		<!-- Finished -->
		<template v-else>
			<template v-if="open">
				<p class="text-sm font-bold text-gray-950">{{ outcome }}</p>
				<p class="mt-1 text-sm text-gray-600">Reason given: {{ open.reason }}</p>
				<p v-if="open.response_note" class="mt-1 text-sm text-gray-600">Note: {{ open.response_note }}</p>
			</template>

			<template v-if="state.can_request">
				<button v-if="!asking" type="button" class="text-sm font-semibold text-rose-700 hover:underline" :class="open ? 'mt-3' : ''" @click="asking = true">Need to cancel this booking?</button>
				<form v-else class="mt-1" @submit.prevent="ask">
					<label :for="`cancel-reason-${inquiryId}`" class="block text-sm font-bold text-gray-950">Why do you need to cancel?</label>
					<p class="mt-0.5 text-xs text-gray-500">{{ otherName }} can agree or keep the booking. If they don’t answer within {{ state.grace_hours }} hours, it will be cancelled.</p>
					<textarea :id="`cancel-reason-${inquiryId}`" v-model="reason" rows="3" maxlength="500"
						class="mt-2 w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none" />
					<div class="mt-3 flex flex-wrap gap-2">
						<button type="submit" :disabled="busy || reason.trim().length < 3" class="rounded-xl bg-rose-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-800 disabled:opacity-50">Send request</button>
						<button type="button" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-700" @click="asking = false">Never mind</button>
					</div>
				</form>
			</template>
		</template>
	</section>
</template>

<script setup lang="ts">
import type { BookingCancellationState } from '~/composables/useBookingCancellation'

// Backing out after an offer is accepted: ask with a reason, or answer the other side.
const props = defineProps<{ inquiryId: string, organizationId?: string | null, otherName: string }>()
const emit = defineEmits<{ changed: [] }>()

const api = useBookingCancellation(props.inquiryId, props.organizationId)
const state = ref<BookingCancellationState | null>(null)
const open = computed(() => state.value?.cancellation ?? null)
const asking = ref(false)
const reason = ref('')
const note = ref('')
const busy = ref(false)
const error = ref('')

const outcome = computed(() => ({
	agreed: 'This booking was cancelled by agreement.',
	auto_agreed: 'This booking was cancelled because the time to answer passed.',
	declined: 'The request to cancel was declined. The booking stands.',
	withdrawn: 'The request to cancel was withdrawn. The booking stands.',
	pending: '',
}[open.value?.status ?? 'pending']))

const formatDateTime = (iso: string) => new Date(iso).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })

async function run(action: () => Promise<BookingCancellationState>) {
	busy.value = true
	error.value = ''
	try {
		state.value = await action()
		emit('changed')
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

const ask = async () => {
	await run(() => api.request(reason.value.trim()))
	if (!error.value) { asking.value = false; reason.value = '' }
}
async function answer(decision: 'agree' | 'decline') {
	if (decision === 'agree' && !(await confirmDialog('Cancel this booking? This can’t be undone, and any tickets for the event stop working.', { title: 'Agree to cancel?', confirmLabel: 'Yes, cancel the booking', cancelLabel: 'Go back' }))) return
	await run(() => api.respond(decision, note.value.trim() || null))
}
const withdraw = () => run(() => api.withdraw())

async function load() {
	try {
		state.value = await api.state()
	} catch {
		state.value = null // No access (a team member) or not a booking: show nothing.
	}
}

onMounted(load)
useLiveRefresh(load, { paused: () => busy.value || asking.value })
</script>
