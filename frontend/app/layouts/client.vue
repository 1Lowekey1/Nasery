<template>
	<div class="flex h-screen overflow-hidden bg-gray-50">
		<!-- <div class="fixed inset-x-0 top-0 z-50 h-1 bg-gray-900" /> -->

		<aside class="shrink-0 overflow-hidden border-r border-gray-200 bg-white transition-all duration-200"
			:class="asideClass">
			<div class="flex h-full w-64 flex-col overflow-y-auto">
				<div class="flex items-center gap-2.5 px-5 py-5">
					<div
						class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-700 text-sm font-bold text-white">
						N
					</div>
					<div>
						<div class="text-base font-bold leading-tight text-gray-900">
							NaSeRy
						</div>
						<div class="text-[11px] text-gray-400">
							Event Management System
						</div>
					</div>
				</div>

				<div class="px-5 pb-3">
					<span
						class="inline-flex items-center gap-1.5 rounded-md bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700">
						<span class="h-1.5 w-1.5 rounded-full bg-primary-600" />
						Client Portal
					</span>
				</div>

				<nav class="flex-1 space-y-1 px-3">
					<NuxtLink v-for="item in navItems" :key="item.label" :to="item.to"
						class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-semibold"
						:class="activeItem.label === item.label ? 'bg-primary-700 text-white' : 'text-gray-600 hover:bg-gray-100'">
						<IconBase :name="item.icon" class="h-[18px] w-[18px] shrink-0" />
						{{ item.label }}
					</NuxtLink>
				</nav>

				<div class="border-t border-gray-100 px-4 py-4">
					<button type="button"
						class="flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-gray-700"
						@click="handleLogout">
						<IconBase name="log-out" class="h-4 w-4" />

						Sign out
					</button>
				</div>
			</div>
		</aside>
		<div v-if="isMobile && sidebarOpen" class="fixed inset-0 z-[85] bg-gray-900/40" aria-hidden="true" @click="closeSidebar" />

		<div class="flex min-w-0 flex-1 flex-col">
			<header class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 bg-white px-4 py-3 sm:px-6 sm:py-3.5">
				<div class="flex items-center gap-4">
					<button type="button" class="-ml-2 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700" :aria-label="sidebarOpen ? 'Close menu' : 'Open menu'" :aria-expanded="sidebarOpen" @click="sidebarOpen = !sidebarOpen">
						<IconBase :name="sidebarOpen ? 'x' : 'menu'" class="h-5 w-5" />
					</button>
					<div>
						<div class="text-xs text-gray-400">Client Portal</div>
						<div class="text-sm font-bold text-gray-900">{{ headerTitle }}</div>
					</div>
				</div>
				<div class="flex items-center gap-2">
					<NotificationsDropdown portal="client" />
					<button type="button" class="flex max-w-52 items-center gap-2 rounded-xl border border-gray-200 bg-white p-1.5 pr-3 text-left transition hover:border-primary-200 hover:bg-primary-50" @click="openProfilePanel">
						<span class="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-primary-700 text-xs font-bold text-white">
							<img v-if="user?.avatar_url" :src="user.avatar_url" alt="" class="h-full w-full object-cover" /><span v-else>{{ sidebarInitials }}</span>
						</span>
						<span class="hidden min-w-0 sm:block"><span class="block truncate text-xs font-bold text-gray-900">{{ sidebarName }}</span><span class="block text-[10px] text-gray-400">Edit profile</span></span>
					</button>
				</div>
			</header>

			<main class="flex-1 overflow-y-auto p-4 sm:p-6">
				<slot />
			</main>
		</div>

		<Transition name="fade">
			<div v-if="showProfilePanel" class="fixed inset-0 z-[90] bg-gray-900/40" @click="closeProfilePanel" />
		</Transition>

		<Transition name="slide">
			<div v-if="showProfilePanel"
				class="fixed inset-y-0 right-0 z-[100] flex w-full max-w-2xl flex-col overflow-hidden bg-white shadow-2xl"
				role="dialog" aria-modal="true" aria-labelledby="client-profile-title">
				<div class="relative shrink-0 pb-11">
					<div class="h-28 bg-gradient-to-br from-primary-700 to-primary-900" />
					<div aria-hidden="true" class="absolute right-20 top-5 h-20 w-20 rounded-full border border-white/10" />
					<button type="button" aria-label="Close profile" class="absolute right-5 top-5 flex h-9 w-9 items-center justify-center rounded-full bg-gray-900/20 text-white backdrop-blur-sm transition hover:bg-gray-900/35"
						@click="closeProfilePanel">
						<IconBase name="x" class="h-5 w-5" />
					</button>
					<div class="absolute -bottom-1 left-6 flex h-20 w-20 items-center justify-center overflow-hidden rounded-2xl border-4 border-white bg-primary-50 text-xl font-extrabold text-primary-700 shadow-md">
						<img v-if="user?.avatar_url" :src="user.avatar_url" alt="" class="h-full w-full object-cover" />
						<span v-else>{{ panelInitials }}</span>
					</div>
				</div>

				<div class="shrink-0 px-6 pb-5">
					<p class="mb-1 text-[10px] font-extrabold uppercase tracking-[.18em] text-primary-700">Client profile</p>
					<h2 id="client-profile-title" class="truncate text-xl font-extrabold text-gray-900">{{ draftName || 'Your profile' }}</h2>
					<p class="mt-0.5 text-sm text-gray-500">Your name, contact number and photo on NaSeRy.</p>
				</div>

				<div class="flex shrink-0 scroll-px-6 scroll-smooth gap-2 overflow-x-auto overscroll-x-contain border-y border-gray-100 bg-gray-50/80 px-6 py-3">
					<button v-for="tab in profileTabs" :key="tab.label"
						type="button" class="flex shrink-0 items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-bold transition"
						:class="activeProfileTab === tab.label ? 'border-primary-200 bg-white text-primary-700 shadow-sm' : 'border-transparent text-gray-400 hover:bg-white hover:text-gray-600'"
						@click="activeProfileTab = tab.label">
						<IconBase :name="tab.icon" class="h-4 w-4" />
						{{ tab.label }}
					</button>
				</div>

				<div class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden bg-white px-6 py-6">
					<div v-if="activeProfileTab === 'Identity'">
						<div class="mb-5 flex items-center gap-3">
							<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
								<IconBase name="user" class="h-4 w-4" />
							</div>
							<div>
								<div class="text-sm font-bold text-gray-900">Identity</div>
								<div class="text-xs text-gray-500">The name organizers see on your requests</div>
							</div>
						</div>

						<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
							<div>
								<FormsLabel text="First name" required />
								<FormsTextField v-model="draft.first_name" autocomplete="given-name" />
								<p v-if="fields.first_name" class="mt-1 text-xs text-rose-600">{{ fields.first_name }}</p>
							</div>
							<div>
								<FormsLabel text="Last name" required />
								<FormsTextField v-model="draft.last_name" autocomplete="family-name" />
								<p v-if="fields.last_name" class="mt-1 text-xs text-rose-600">{{ fields.last_name }}</p>
							</div>
						</div>
						<div class="mt-4">
							<FormsLabel text="Middle name (optional)" />
							<FormsTextField v-model="draft.middle_name" autocomplete="additional-name" />
						</div>
					</div>

					<div v-else-if="activeProfileTab === 'Contact'">
						<div class="mb-5 flex items-center gap-3">
							<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
								<IconBase name="phone" class="h-4 w-4" />
							</div>
							<div>
								<div class="text-sm font-bold text-gray-900">Contact</div>
								<div class="text-xs text-gray-500">How organizers can reach you</div>
							</div>
						</div>

						<FormsLabel text="Email address" />
						<div class="rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600">{{ user?.email }}</div>
						<p class="mt-1.5 text-xs text-gray-400">This is your sign-in e-mail, so it can't be changed here.</p>

						<div class="mt-4">
							<FormsLabel text="Mobile number" />
							<FormsTextField v-model="draft.phone" type="tel" autocomplete="tel" placeholder="0917 123 4567" />
							<p v-if="fields.phone" class="mt-1 text-xs text-rose-600">{{ fields.phone }}</p>
							<p v-else class="mt-1.5 text-xs text-gray-400">A Philippine mobile number. Leave it empty to remove it.</p>
						</div>
					</div>

					<div v-else-if="activeProfileTab === 'Appearance'">
						<div class="mb-5 flex items-center gap-3">
							<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-100 text-gray-500">
								<IconBase name="camera" class="h-4 w-4" />
							</div>
							<div>
								<div class="text-sm font-bold text-gray-900">Appearance</div>
								<div class="text-xs text-gray-500">Your profile photo</div>
							</div>
						</div>
						<MediaBatchScope :batch="mediaBatch"><MediaAvatarUploader /></MediaBatchScope>
					</div>

					<p v-if="saveError" class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ saveError }}</p>
				</div>

				<div class="flex shrink-0 gap-3 border-t border-gray-200 bg-white px-6 py-4 shadow-[0_-8px_24px_rgba(15,23,42,0.04)]">
					<FormsButton variant="secondary" class="flex-1" @click="closeProfilePanel">
						Cancel
					</FormsButton>
					<FormsButton class="flex-1" :disabled="saving" @click="saveProfile">
						{{ saving ? 'Saving...' : 'Save Profile' }}
					</FormsButton>
				</div>
			</div>
		</Transition>
	</div>
</template>

<script setup lang="ts">
const route = useRoute()

const {
	user,
	firstName,
	initials,
	logout,
	updateProfile,
} = useAuth()

const { sidebarOpen, isMobile, asideClass, closeSidebar } = useResponsiveSidebar()

const navItems = [
	{
		label: 'My Events',
		to: '/client/my-events',
		icon: 'home',
	},
	{
		label: 'Post Event Inquiry',
		to: '/client/post-event',
		icon: 'send',
	},
	{
		label: 'Preparation Tracking',
		to: '/client/preparation-tracking',
		icon: 'clipboard-list',
	},
	{
		label: 'Activity Timeline',
		to: '/client/activity-timeline',
		icon: 'activity',
	},
	{
		label: 'My Tickets',
		to: '/my-tickets',
		icon: 'ticket',
	},
	{
		label: 'Event Marketplace',
		to: '/marketplace',
		icon: 'calendar',
	},
	{
		label: 'Browse Organizers',
		to: '/organizers',
		icon: 'users',
	},
]

const activeItem = computed(() => {
	return (
		navItems.find(
			(item) => item.to === route.path
		) ?? navItems[0]
	)
})

// Pages outside the menu still get their own title in the header.
const pageTitles: Record<string, string> = { '/notifications': 'Notifications', '/my-tickets': 'My Tickets' }
const headerTitle = computed(() => pageTitles[route.path] ?? activeItem.value?.label ?? '')

/**
 * Sidebar name.
 *
 * Example:
 * firstname = "Nina"
 *
 * Sidebar:
 * Nina
 */
const sidebarName = computed(() => {
	return (
		firstName.value ||
		'User'
	)
})

const sidebarInitials = computed(() => {
	return (
		initials.value ||
		'U'
	)
})

// The client profile is their account: name, mobile number and photo
// (PATCH auth/me + the photo upload). E-mail is the sign-in and stays read-only.
const profileTabs = [
	{ label: 'Identity', icon: 'user' },
	{ label: 'Contact', icon: 'phone' },
	{ label: 'Appearance', icon: 'camera' },
]

const draft = reactive({ first_name: '', middle_name: '', last_name: '', phone: '' })
const draftName = computed(() => [draft.first_name, draft.middle_name, draft.last_name].map(v => v.trim()).filter(Boolean).join(' '))

const showProfilePanel = ref(false)
const activeProfileTab = ref('Identity')
const saving = ref(false)
const saveError = ref('')
const fields = ref<Record<string, string>>({})

function syncDraft() {
	Object.assign(draft, {
		first_name: user.value?.first_name ?? '',
		middle_name: user.value?.middle_name ?? '',
		last_name: user.value?.last_name ?? '',
		phone: user.value?.phone ?? '',
	})
}

function initialsOf(name: string): string {
	return (name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(word => word[0]).join('').toUpperCase()
}

/** Profile-panel initials (follow the name being edited). */
const panelInitials = computed(() => initialsOf(draftName.value) || initials.value || 'U')

function openProfilePanel() {
	window.dispatchEvent(new CustomEvent('dashboard:close-notifications'))
	syncDraft()
	saveError.value = ''
	fields.value = {}
	activeProfileTab.value = 'Identity'
	showProfilePanel.value = true
}

// Pictures picked in the panel are uploaded by Save Profile.
const mediaBatch = createMediaBatch()

function closeProfilePanel() {
	showProfilePanel.value = false
	saveError.value = ''
	fields.value = {}
	mediaBatch.clear()
}

async function saveProfile() {
	saveError.value = ''
	fields.value = {}
	if (!draft.first_name.trim() || !draft.last_name.trim()) {
		fields.value = {
			...(draft.first_name.trim() ? {} : { first_name: 'Enter your first name.' }),
			...(draft.last_name.trim() ? {} : { last_name: 'Enter your last name.' }),
		}
		activeProfileTab.value = 'Identity'
		return
	}

	saving.value = true
	try {
		const phone = draft.phone.trim()
		await updateProfile({
			first_name: draft.first_name.trim(),
			middle_name: draft.middle_name.trim() || null,
			last_name: draft.last_name.trim(),
			// Only when edited, so an older saved number never blocks a name change.
			...(phone !== (user.value?.phone ?? '') ? { phone: phone || null } : {}),
		})
	} catch (e: unknown) {
		fields.value = fieldErrors(e)
		saveError.value = Object.keys(fields.value).length ? 'Please check the highlighted fields.' : authMessage(e)
		activeProfileTab.value = fields.value.phone ? 'Contact' : 'Identity'
		saving.value = false
		return
	}

	saveError.value = await mediaBatch.flush()
	saving.value = false
	if (saveError.value) {
		activeProfileTab.value = 'Appearance'
		return
	}
	closeProfilePanel()
}

async function handleLogout() {
	// Finish signing out first, or the login page sends a still-signed-in user back home.
	await logout()

	await navigateTo('/login')
}
</script>
