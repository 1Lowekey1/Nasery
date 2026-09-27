<template>
	<div v-if="data && (live || data.arrived > 0)" class="mt-4 rounded-xl border p-4" :class="live ? 'border-green-200 bg-green-50/60' : 'border-gray-200 bg-gray-50'">
		<div class="flex items-baseline justify-between gap-3">
			<p class="text-sm font-bold text-gray-900">
				<span v-if="live" class="mr-1.5 inline-block h-2 w-2 animate-pulse rounded-full bg-green-600 align-middle" aria-hidden="true" />
				{{ live ? 'Live attendance' : 'Attendance' }}
			</p>
			<span v-if="live" class="text-xs text-gray-500">updated {{ new Date(data.updated_at).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' }) }}</span>
		</div>
		<p class="mt-2 text-2xl font-extrabold text-gray-900">{{ data.checked_in }}<span class="text-base font-semibold text-gray-400"> of {{ data.expected }} guests arrived</span></p>
		<p v-if="data.walk_ins" class="text-sm text-gray-600">+ {{ data.walk_ins }} walk-in{{ data.walk_ins === 1 ? '' : 's' }} · {{ data.arrived }} people in total</p>
		<div class="mt-2 h-2 overflow-hidden rounded-full bg-white">
			<div class="h-full rounded-full bg-green-600 transition-all" :style="{ width: (data.expected ? Math.round(data.checked_in / data.expected * 100) : 0) + '%' }" />
		</div>
		<ul v-if="data.sessions.length > 1" class="mt-2 space-y-0.5 text-xs text-gray-600">
			<li v-for="s in data.sessions" :key="s.id">{{ s.label }}: {{ s.checked_in }} checked in<span v-if="s.is_open" class="font-semibold text-green-700"> · open now</span></li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import type { ClientAttendance } from '~/composables/useCheckIn'

// Paper: client-facing live attendance view. Numbers only; refreshes
// every 10 s while check-in is open, otherwise shows the final count.
const props = defineProps<{ eventId: string }>()
const { clientAttendance } = useCheckIn()
const data = ref<ClientAttendance | null>(null)
const live = computed(() => !!data.value && (data.value.event_status === 'ongoing' || data.value.sessions.some(s => s.is_open)))

let timer: ReturnType<typeof setInterval> | undefined
async function load() {
	try {
		data.value = await clientAttendance(props.eventId)
	} catch {
		// Optional panel: stay hidden if it can't load.
	}
}

onMounted(async () => {
	await load()
	timer = setInterval(() => {
		if (live.value && !document.hidden) load()
	}, 10000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
