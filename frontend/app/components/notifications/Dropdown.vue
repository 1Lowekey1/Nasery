<template>
	<div ref="root" class="relative">
		<button type="button" aria-label="Open notifications"
			class="relative flex h-10 w-10 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-500 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700"
			@click="togglePanel">
			<IconBase name="bell" class="h-[18px] w-[18px]" />
			<span v-if="unreadCount" class="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-700 px-1 text-[10px] font-bold text-white ring-2 ring-white">
				{{ unreadCount > 9 ? '9+' : unreadCount }}
			</span>
		</button>

		<div v-if="open" class="fixed inset-x-3 top-16 z-[80] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-12 sm:w-96">
			<header class="flex items-center justify-between border-b border-gray-100 px-4 py-3.5">
				<div>
					<p class="text-sm font-bold text-gray-900">Notifications</p>
					<p class="mt-0.5 text-xs text-gray-500">{{ unreadCount ? `${unreadCount} unread update${unreadCount === 1 ? '' : 's'}` : 'You are all caught up' }}</p>
				</div>
				<button v-if="unreadCount" type="button" class="text-xs font-bold text-primary-700 hover:text-primary-900" @click="markAllRead">
					Mark all read
				</button>
			</header>

			<div class="max-h-[min(24rem,calc(100dvh-10rem))] overflow-y-auto overscroll-contain">
				<div v-if="loading && !items.length" class="px-5 py-10 text-center text-sm text-gray-500">Loading updates…</div>
				<div v-else-if="!items.length" class="px-5 py-10 text-center">
					<span class="mx-auto flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-50 text-primary-700"><IconBase name="bell" class="h-5 w-5" /></span>
					<p class="mt-3 text-sm font-bold text-gray-800">No notifications yet</p>
					<p class="mt-1 text-xs text-gray-500">{{ emptyMessage }}</p>
				</div>

				<article v-for="item in items" :key="item.id" class="group flex gap-3 border-b border-gray-100 px-4 py-3.5 last:border-b-0 hover:bg-gray-50" :class="{ 'bg-primary-50/50': !item.read_at }">
					<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.read_at ? 'bg-gray-300' : 'bg-primary-600'" />
					<button type="button" class="min-w-0 flex-1 text-left" @click="openItem(item)">
						<p class="text-sm font-semibold leading-5 text-gray-900">{{ item.data.message }}</p>
						<div class="mt-1 flex items-center gap-2 text-xs text-gray-500">
							<span class="truncate">{{ item.data.event_title }}</span>
							<span>·</span><span class="shrink-0">{{ relativeTime(item.created_at) }}</span>
						</div>
					</button>
					<div class="flex shrink-0 items-start gap-0.5 opacity-70 transition group-hover:opacity-100">
						<button v-if="!item.read_at" type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-white hover:text-primary-700" title="Mark as read" @click="toggleRead(item)">
							<IconBase :name="item.read_at ? 'mail' : 'check'" class="h-4 w-4" />
						</button>
						<button v-if="false" type="button" class="rounded-lg p-1.5 text-gray-400 hover:bg-red-50 hover:text-red-600" title="Delete notification" @click="remove(item.id)">
							<IconBase name="trash" class="h-4 w-4" />
						</button>
					</div>
				</article>
			</div>

			<footer v-if="page < lastPage" class="border-t border-gray-100 bg-gray-50 px-4 py-3 text-center">
				<button type="button" class="text-xs font-bold text-primary-700" :disabled="loading" @click="load(page + 1, true)">{{ loading ? 'Loading…' : 'Load older notifications' }}</button>
			</footer>
			<!-- Every portal reaches the full list and its e-mail settings from the bell. -->
			<nav class="grid grid-cols-2 border-t border-gray-100 text-xs font-bold" aria-label="Notification pages">
				<NuxtLink to="/notifications" class="flex items-center justify-center gap-1.5 px-4 py-3 text-primary-700 hover:bg-gray-50">See all</NuxtLink>
				<NuxtLink :to="{ path: '/notifications', query: { tab: 'settings' } }" class="flex items-center justify-center gap-1.5 border-l border-gray-100 px-4 py-3 text-gray-600 hover:bg-gray-50 hover:text-gray-900">
					<IconBase name="settings" class="h-3.5 w-3.5" />E-mail settings
				</NuxtLink>
			</nav>
		</div>
	</div>
</template>

<script setup lang="ts">
interface OrganizerNotification {
	id: string
	read_at: string | null
	created_at: string
	data: { type: string; message: string; event_title: string; inquiry_id?: number; quotation_id?: number }
}

const props = withDefaults(defineProps<{
	portal?: 'client' | 'organizer' | 'superadmin'
}>(), {
	portal: 'organizer',
})

const { token } = useAuth()
const notifications = useNotifications()
const unreadCount = useUnreadCount()
const open = ref(false)
const loading = ref(false)
const items = ref<(OrganizerNotification & { url: string | null })[]>([])
const page = ref(1)
const lastPage = ref(1)

const emptyMessage = computed(() => {
	if (props.portal === 'client') return 'New quotations and event updates will appear here.'
	if (props.portal === 'superadmin') return 'Platform-wide alerts will appear here.'
	return 'New matching inquiries and accepted offers will appear here.'
})

/** /me/notifications, in the shape this panel was designed for. */
async function load(nextPage = 1, append = false) {
	if (!token.value || loading.value) return
	loading.value = true
	try {
		const { items: rows, meta } = await notifications.list({ page: nextPage })
		const pagination = meta?.pagination as { current_page?: number, last_page?: number } | undefined
		const mapped = rows.map(n => ({
			id: n.id,
			read_at: n.read ? n.created_at : null,
			created_at: n.created_at,
			url: n.url,
			data: { type: n.type, message: n.title, event_title: n.body ?? '' },
		}))
		items.value = append ? [...items.value, ...mapped] : mapped
		page.value = pagination?.current_page ?? nextPage
		lastPage.value = pagination?.last_page ?? 1
		await notifications.refreshCount()
	} catch {
		// The bell stays quiet if notifications can't load.
	} finally { loading.value = false }
}

async function togglePanel() { open.value = !open.value; if (open.value) await load() }
async function toggleRead(item: OrganizerNotification) { await notifications.read(item.id); await load(page.value) }
async function remove(_id: string) {}
async function markAllRead() { await notifications.readAll(); await load() }

async function openItem(item: OrganizerNotification & { url?: string | null }) {
	if (!item.read_at) await notifications.read(item.id)
	open.value = false
	// Each notice links to its own page; fall back to the portal's home.
	const fallback = props.portal === 'client' ? '/client/my-events' : props.portal === 'superadmin' ? '/superadmin/overview' : '/organizer/home'
	await navigateTo(item.url || fallback)
}

function relativeTime(value: string) {
	const seconds = Math.max(1, Math.floor((Date.now() - new Date(value).getTime()) / 1000))
	if (seconds < 60) return 'Just now'
	if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
	if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
	return `${Math.floor(seconds / 86400)}d ago`
}

function closePanel() {
	open.value = false
}

// Close when tapping/clicking outside the panel, pressing Escape, or changing page.
const root = ref<HTMLElement | null>(null)
const route = useRoute()
function onOutside(e: Event) {
	if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false
}
function onKey(e: KeyboardEvent) {
	if (e.key === 'Escape') open.value = false
}
watch(() => route.fullPath, closePanel)

// Close to live (review #12): check the count every 20 seconds while the tab is visible and
// when it comes back; new notices reload the list. Pages using useLiveRefresh watch the
// same count, so they refresh together with the bell.
let poll: ReturnType<typeof setInterval> | undefined
async function checkForNew() {
	if (document.hidden) return
	const before = unreadCount.value
	await notifications.refreshCount().catch(() => {})
	if (unreadCount.value > before) await load()
}
function onVisible() { if (!document.hidden) void checkForNew() }

onMounted(() => {
	window.addEventListener('dashboard:close-notifications', closePanel)
	document.addEventListener('pointerdown', onOutside)
	window.addEventListener('keydown', onKey)
	document.addEventListener('visibilitychange', onVisible)
	load()
	poll = setInterval(checkForNew, 20_000)
})

onBeforeUnmount(() => {
	clearInterval(poll)
	document.removeEventListener('visibilitychange', onVisible)
	window.removeEventListener('dashboard:close-notifications', closePanel)
	document.removeEventListener('pointerdown', onOutside)
	window.removeEventListener('keydown', onKey)
})
</script>
