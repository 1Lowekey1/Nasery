<template>
	<NuxtLayout :name="layout">
		<div class="mx-auto max-w-3xl" :class="layout === 'public' ? 'px-5 pt-8 pb-16 sm:px-8' : ''">
			<h1 class="text-2xl font-extrabold text-gray-900">Notifications</h1>
			<p class="mt-1 text-gray-500">Updates about your events, tickets and team. You always see them here; choose which also come by e-mail.</p>

			<div class="mt-5 mb-5 inline-flex gap-1 rounded-xl bg-gray-100 p-1" role="tablist">
				<button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key"
					class="rounded-lg px-4 py-2 text-sm font-semibold" :class="tab === t.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'"
					@click="setTab(t.key)">{{ t.label }}<span v-if="t.key === 'unread' && unread" class="ml-1.5 rounded-full bg-rose-600 px-1.5 text-xs text-white">{{ unread }}</span></button>
			</div>

			<Alert v-if="error" type="danger" :text="error" />
			<Alert v-if="notice" type="success" :text="notice" />

			<!-- List -->
			<section v-if="tab !== 'settings'" class="rounded-2xl border border-gray-200 bg-white">
				<div class="flex items-center justify-between border-b border-gray-100 px-5 py-3">
					<p class="text-sm text-gray-500">{{ tab === 'unread' ? 'Unread' : 'All notifications' }}</p>
					<button v-if="unread" type="button" class="text-sm font-semibold text-primary-700" @click="readAll">Mark all read</button>
				</div>
				<p v-if="loading && !items.length" class="px-5 py-10 text-center text-sm text-gray-400">Loading…</p>
				<p v-else-if="!items.length" class="px-5 py-10 text-center text-sm text-gray-400">{{ tab === 'unread' ? "You're all caught up." : 'No notifications yet.' }}</p>
				<ul v-else class="divide-y divide-gray-100">
					<li v-for="n in items" :key="n.id" class="flex gap-3 px-5 py-4">
						<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-gray-200' : n.critical ? 'bg-rose-600' : 'bg-primary-600'" aria-hidden="true" />
						<div class="min-w-0 flex-1">
							<p class="text-sm" :class="n.read ? 'text-gray-700' : 'font-semibold text-gray-900'">{{ n.title }}</p>
							<p v-if="n.body" class="mt-0.5 text-sm text-gray-500">{{ n.body }}</p>
							<p class="mt-1 text-xs text-gray-400">{{ timeAgo(n.created_at) }}<span v-if="n.critical"> · important</span></p>
						</div>
						<div class="flex shrink-0 flex-col items-end gap-1">
							<button v-if="n.url" type="button" class="text-sm font-semibold text-primary-700" @click="openItem(n)">Open</button>
							<button v-if="!n.read" type="button" class="text-xs text-gray-500 hover:text-gray-800" @click="markRead(n)">Mark read</button>
						</div>
					</li>
				</ul>
				<div v-if="lastPage > 1" class="flex justify-between border-t border-gray-100 px-5 py-3 text-sm">
					<button type="button" :disabled="page <= 1" class="font-semibold text-primary-700 disabled:text-gray-300" @click="load(page - 1)">Newer</button>
					<span class="text-gray-400">Page {{ page }} of {{ lastPage }}</span>
					<button type="button" :disabled="page >= lastPage" class="font-semibold text-primary-700 disabled:text-gray-300" @click="load(page + 1)">Older</button>
				</div>
			</section>

			<!-- E-mail settings -->
			<section v-else class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">E-mail me when…</h2>
				<p class="mt-1 text-sm text-gray-500">Important ones (refunds, cancellations, a full venue) always come by e-mail. Ticket e-mails with your QR codes are always sent.</p>
				<div v-for="group in groupedPrefs" :key="group.key" class="mt-5">
					<h3 class="text-xs font-bold uppercase tracking-wide text-gray-400">{{ group.label }}</h3>
					<ul class="mt-2 divide-y divide-gray-100">
						<li v-for="p in group.items" :key="p.type" class="flex items-center justify-between gap-4 py-3">
							<label :for="`pref-${p.type}`" class="text-sm text-gray-800">{{ p.label }}<span v-if="p.critical" class="ml-2 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">always</span></label>
							<input :id="`pref-${p.type}`" v-model="p.email" type="checkbox" :disabled="p.critical || saving" class="h-5 w-5 accent-[#285F6b]">
						</li>
					</ul>
				</div>
				<button type="button" :disabled="saving" class="mt-5 rounded-xl bg-primary-700 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50" @click="save">
					{{ saving ? 'Saving…' : 'Save settings' }}
				</button>
			</section>
		</div>
	</NuxtLayout>
</template>

<script setup lang="ts">
import { timeAgo, type AppNotification, type NotificationPreference } from '~/composables/useNotifications'

definePageMeta({ layout: false })
useSeoMeta({ title: 'Notifications | NaSeRy', robots: 'noindex' })

const route = useRoute()
const router = useRouter()
const { user } = useAuth()
const api = useNotifications()
const unread = useUnreadCount()

type PortalLayout = 'dashboard' | 'client' | 'superadmin' | 'public'
const layout = computed<PortalLayout>(() => ({ business: 'dashboard', client: 'client', admin: 'superadmin' } as Record<string, PortalLayout>)[user.value?.account_type ?? ''] ?? 'public')
type Tab = 'all' | 'unread' | 'settings'
const tabs: { key: Tab, label: string }[] = [{ key: 'all', label: 'All' }, { key: 'unread', label: 'Unread' }, { key: 'settings', label: 'E-mail settings' }]
const tab = ref<Tab>((['all', 'unread', 'settings'] as Tab[]).includes(route.query.tab as Tab) ? route.query.tab as Tab : 'all')
const items = ref<AppNotification[]>([])
const prefs = ref<NotificationPreference[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const notice = ref('')

const groupLabels: Record<NotificationPreference['group'], string> = { organizer: 'Your business', client: 'Your events', tickets: 'Tickets', account: 'Your account', admin: 'NaSeRy admin' }
const groupedPrefs = computed(() => (['admin', 'organizer', 'client', 'tickets', 'account'] as const)
	.map(key => ({ key, label: groupLabels[key], items: prefs.value.filter(p => p.group === key) }))
	.filter(g => g.items.length))

async function load(p = 1) {
	loading.value = true
	error.value = ''
	try {
		const r = await api.list({ unread: tab.value === 'unread', page: p })
		items.value = r.items
		page.value = r.meta?.pagination?.current_page ?? p
		lastPage.value = r.meta?.pagination?.last_page ?? 1
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		loading.value = false
	}
}

async function loadPrefs() {
	try {
		prefs.value = await api.preferences()
	} catch (e) {
		error.value = authMessage(e)
	}
}

function setTab(t: Tab) {
	tab.value = t
	notice.value = ''
	router.replace({ query: t === 'all' ? {} : { tab: t } })
	if (t === 'settings') loadPrefs()
	else load(1)
}

async function markRead(n: AppNotification) {
	await api.read(n.id)
	n.read = true
	if (tab.value === 'unread') items.value = items.value.filter(i => i.id !== n.id)
}

async function openItem(n: AppNotification) {
	if (!n.read) await markRead(n)
	if (n.url) await navigateTo(n.url)
}

async function readAll() {
	await api.readAll()
	items.value = tab.value === 'unread' ? [] : items.value.map(n => ({ ...n, read: true }))
}

async function save() {
	saving.value = true
	notice.value = ''
	try {
		prefs.value = await api.savePreferences(prefs.value.filter(p => !p.critical).map(p => ({ type: p.type, email: p.email })))
		notice.value = 'Settings saved.'
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		saving.value = false
	}
}

onMounted(() => (tab.value === 'settings' ? loadPrefs() : load()))
</script>
