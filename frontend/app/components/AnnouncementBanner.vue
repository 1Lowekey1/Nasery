<template>
	<div v-if="shown.length" class="space-y-2" :class="$attrs.class">
		<div v-for="a in shown" :key="a.id" role="status" class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
			<IconBase name="bell" class="mt-0.5 h-4 w-4 shrink-0" />
			<div class="min-w-0 flex-1">
				<p class="font-semibold">{{ a.title }}</p>
				<p class="whitespace-pre-line">{{ a.body }}</p>
			</div>
			<button type="button" class="tap-target shrink-0 text-sky-700 hover:text-sky-900" :aria-label="`Dismiss: ${a.title}`" @click="dismiss(a.id)">
				<IconBase name="x" class="h-4 w-4" />
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { Announcement } from '~/composables/useAdmin'

// B17: NaSeRy-wide notices for the viewer's audience. Dismissals are kept per
// browser (a convenience only; the server decides what is live).
defineOptions({ inheritAttrs: false })

const KEY = 'nasery.dismissed_announcements'
const api = useApi()
const { user } = useAuth()
const items = ref<Announcement[]>([])
const dismissed = ref<string[]>([])

const shown = computed(() => items.value.filter(a => !dismissed.value.includes(a.id)))

function dismiss(id: string) {
	dismissed.value = [...dismissed.value, id]
	try {
		localStorage.setItem(KEY, JSON.stringify(dismissed.value.slice(-50)))
	} catch {}
}

async function load() {
	try {
		items.value = (await api.get<Announcement[]>('/announcements')).data
	} catch {
		items.value = []
	}
}

onMounted(() => {
	try {
		dismissed.value = JSON.parse(localStorage.getItem(KEY) ?? '[]')
	} catch {}
	load()
})
// Audience depends on who is signed in.
watch(() => user.value?.id, load)
</script>
