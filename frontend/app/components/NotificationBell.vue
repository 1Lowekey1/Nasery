<template>
	<div ref="root" class="relative">
		<button type="button" class="relative flex h-10 w-10 items-center justify-center rounded-xl text-gray-500 hover:bg-gray-100 hover:text-gray-800"
			:aria-label="unread ? `Notifications, ${unread} unread` : 'Notifications'" :aria-expanded="open" @click="toggle">
			<IconBase name="bell" class="h-5 w-5" />
			<span v-if="unread" class="absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[10px] font-bold text-white">{{ unread > 99 ? '99+' : unread }}</span>
		</button>

		<div v-if="open" class="absolute right-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl">
			<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
				<p class="text-sm font-bold text-gray-900">Notifications</p>
				<button v-if="unread" type="button" class="text-xs font-semibold text-primary-700" @click="readAll">Mark all read</button>
			</div>
			<div v-if="loading && !items.length" class="px-4 py-8 text-center text-sm text-gray-400">Loading…</div>
			<p v-else-if="!items.length" class="px-4 py-8 text-center text-sm text-gray-400">You're all caught up.</p>
			<ul v-else class="max-h-96 divide-y divide-gray-100 overflow-y-auto">
				<li v-for="n in items" :key="n.id">
					<button type="button" class="flex w-full gap-3 px-4 py-3 text-left hover:bg-gray-50" @click="openItem(n)">
						<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-transparent' : n.critical ? 'bg-rose-600' : 'bg-primary-600'" aria-hidden="true" />
						<span class="min-w-0">
							<span class="block text-sm" :class="n.read ? 'text-gray-600' : 'font-semibold text-gray-900'">{{ n.title }}</span>
							<span v-if="n.body" class="mt-0.5 line-clamp-2 block text-xs text-gray-500">{{ n.body }}</span>
							<span class="mt-1 block text-[11px] text-gray-400">{{ timeAgo(n.created_at) }}</span>
						</span>
					</button>
				</li>
			</ul>
			<NuxtLink to="/notifications" class="block border-t border-gray-100 px-4 py-3 text-center text-sm font-semibold text-primary-700 hover:bg-gray-50" @click="open = false">
				See all and e-mail settings
			</NuxtLink>
		</div>
	</div>
</template>

<script setup lang="ts">
import { timeAgo, type AppNotification } from '~/composables/useNotifications'

// Header bell: unread count (checked every minute while the tab is
// visible) and the latest notifications.
const api = useNotifications()
const unread = useUnreadCount()
const open = ref(false)
const loading = ref(false)
const items = ref<AppNotification[]>([])
const root = ref<HTMLElement | null>(null)

async function toggle() {
	open.value = !open.value
	if (!open.value) return
	loading.value = true
	try {
		items.value = (await api.list()).items.slice(0, 10)
	} catch {
		// The bell stays usable; the page shows errors.
	} finally {
		loading.value = false
	}
}

async function openItem(n: AppNotification) {
	open.value = false
	if (!n.read) {
		n.read = true
		api.read(n.id).catch(() => {})
	}
	if (n.url) await navigateTo(n.url)
}

async function readAll() {
	await api.readAll()
	items.value.forEach(n => (n.read = true))
}

function onDocClick(e: MouseEvent) {
	if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false
}
function onKey(e: KeyboardEvent) {
	if (e.key === 'Escape') open.value = false
}

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
	api.refreshCount().catch(() => {})
	timer = setInterval(() => {
		if (!document.hidden) api.refreshCount().catch(() => {})
	// Every 20 s: pages with live refresh also reload as soon as the count goes up.
	document.addEventListener('click', onDocClick)
	document.addEventListener('keydown', onKey)
})
onBeforeUnmount(() => {
	clearInterval(timer)
	document.removeEventListener('click', onDocClick)
	document.removeEventListener('keydown', onKey)
})
</script>
