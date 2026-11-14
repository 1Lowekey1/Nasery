<template>
	<div class="flex h-dvh overflow-clip bg-gray-50">
		<!-- <div class="fixed inset-x-0 top-0 z-50 h-1 bg-gray-900" /> -->

		<aside class="shrink-0 overflow-hidden border-r border-gray-200 bg-white transition-all duration-200"
			:class="asideClass">
			<div class="flex h-full w-64 flex-col overflow-y-auto">
				<div class="flex items-center gap-2.5 px-5 py-5">
					<BrandMark class="h-9 w-9 text-sm" />
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
						:class="activeItem?.label === item.label ? 'bg-primary-700 text-white' : 'text-gray-600 hover:bg-gray-100'">
						<IconBase :name="item.icon" class="h-[18px] w-[18px] shrink-0" />
						{{ item.label }}
					</NuxtLink>
				</nav>

				<div class="border-t border-gray-100 px-4 py-4">
					<button type="button"
						class="-mx-2 flex min-h-10 items-center gap-2 rounded-lg px-2 text-sm font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700"
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
						<span class="hidden min-w-0 sm:block"><span class="block truncate text-xs font-bold text-gray-900">{{ sidebarName }}</span><span class="block text-[11px] text-gray-400">Edit profile</span></span>
					</button>
				</div>
			</header>

			<main class="flex-1 overflow-y-auto p-4 sm:p-6">
				<AnnouncementBanner class="mb-4" />
				<slot />
			</main>
		</div>

		<Transition name="fade">
			<div v-if="showProfilePanel" class="fixed inset-0 z-[90] bg-gray-900/40 backdrop-blur-[2px]" @click="closeProfilePanel" />
		</Transition>

		<Transition name="slide">
			<div v-if="showProfilePanel"
				class="fixed inset-y-0 right-0 z-[100] flex w-full max-w-3xl flex-col overflow-hidden bg-white shadow-2xl"
				role="dialog" aria-modal="true" aria-labelledby="client-profile-title">
				<!-- Header -->
				<div class="relative shrink-0">
					<div class="relative h-32 overflow-hidden bg-primary-700">
						<span class="cp-dots" />
						<span class="pointer-events-none absolute -right-12 -top-16 h-52 w-52 rounded-full bg-white/10 blur-2xl" />
					</div>

					<button type="button" aria-label="Close profile"
						class="absolute right-5 top-5 flex h-9 w-9 items-center justify-center rounded-full bg-black/20 text-white backdrop-blur-sm transition hover:bg-black/35"
						@click="closeProfilePanel">
						<IconBase name="x" class="h-5 w-5" />
					</button>

					<div class="flex items-end gap-5 px-6 md:px-8">
						<div class="-mt-12 flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-3xl bg-primary-50 text-2xl font-extrabold text-primary-700 shadow-lg ring-4 ring-white">
							<img v-if="user?.avatar_url" :src="user.avatar_url" alt="" class="h-full w-full object-cover" />
							<span v-else>{{ panelInitials }}</span>
						</div>

						<div class="min-w-0 flex-1 pb-1 pt-4">
							<p class="text-[11px] font-extrabold uppercase tracking-[0.18em] text-primary-700">Client profile</p>
							<h2 id="client-profile-title" class="mt-0.5 truncate text-2xl font-extrabold tracking-tight text-gray-950">{{ draftName || 'Your profile' }}</h2>
							<p class="mt-0.5 truncate text-sm text-gray-500">{{ user?.email }}</p>
						</div>
					</div>
				</div>

				<!-- Mobile section chips -->
				<div class="mt-5 flex shrink-0 gap-2 overflow-x-auto border-b border-gray-100 px-6 pb-3 md:hidden">
					<button v-for="tab in profileTabs" :key="tab.label" type="button"
						class="flex min-h-9 shrink-0 items-center gap-1.5 rounded-full px-3.5 py-2 text-xs font-bold transition"
						:class="activeProfileTab === tab.label ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-600'"
						@click="activeProfileTab = tab.label">
						<IconBase :name="tab.icon" class="h-3.5 w-3.5" />
						{{ tab.label }}
					</button>
				</div>

				<div class="flex min-h-0 flex-1 border-t border-gray-100 md:mt-6">
					<!-- Section rail -->
					<nav class="hidden w-64 shrink-0 space-y-1 overflow-y-auto border-r border-gray-100 bg-gray-50/60 p-4 md:block" aria-label="Profile sections">
						<button v-for="tab in profileTabs" :key="tab.label" type="button"
							class="group relative flex w-full items-center gap-3 rounded-2xl px-3 py-3 text-left transition duration-200"
							:class="activeProfileTab === tab.label ? 'bg-white shadow-sm ring-1 ring-gray-200' : 'hover:bg-white/70'"
							@click="activeProfileTab = tab.label">
							<span v-if="activeProfileTab === tab.label" class="absolute inset-y-3 left-0 w-[3px] rounded-r-full bg-primary-700" />
							<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl transition"
								:class="activeProfileTab === tab.label ? 'bg-primary-700 text-white' : 'bg-white text-gray-400 ring-1 ring-gray-200 group-hover:text-primary-700'">
								<IconBase :name="tab.icon" class="h-4 w-4" />
							</span>
							<span class="min-w-0">
								<span class="block truncate text-sm font-bold" :class="activeProfileTab === tab.label ? 'text-gray-950' : 'text-gray-600'">{{ tab.label }}</span>
								<span class="block truncate text-[11px] text-gray-400">{{ tab.hint }}</span>
							</span>
						</button>
					</nav>

					<!-- Section content -->
					<div class="min-w-0 flex-1 overflow-y-auto overflow-x-hidden px-6 py-8 md:px-9">
						<div :key="activeProfileTab" class="cp-fade mx-auto max-w-xl">
							<div class="mb-8 flex items-center gap-4">
								<span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
									<IconBase :name="activeTabMeta.icon" class="h-5 w-5" />
								</span>
								<div>
									<h3 class="text-lg font-extrabold tracking-tight text-gray-950">{{ activeTabMeta.label }}</h3>
									<p class="mt-0.5 text-sm text-gray-500">{{ activeTabMeta.description }}</p>
								</div>
							</div>

							<!-- Identity -->
							<div v-if="activeProfileTab === 'Identity'" class="space-y-6">
								<div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
									<div class="space-y-2">
										<FormsLabel text="First name" required />
										<FormsTextField v-model="draft.first_name" size="lg" autocomplete="given-name" />
										<p v-if="fields.first_name" class="text-xs text-rose-600">{{ fields.first_name }}</p>
									</div>
									<div class="space-y-2">
										<FormsLabel text="Last name" required />
										<FormsTextField v-model="draft.last_name" size="lg" autocomplete="family-name" />
										<p v-if="fields.last_name" class="text-xs text-rose-600">{{ fields.last_name }}</p>
									</div>
								</div>
								<div class="space-y-2">
									<FormsLabel text="Middle name (optional)" />
									<FormsTextField v-model="draft.middle_name" size="lg" autocomplete="additional-name" />
								</div>
							</div>

							<!-- Contact -->
							<div v-else-if="activeProfileTab === 'Contact'" class="space-y-6">
								<div class="space-y-2">
									<FormsLabel text="Email address" />
									<div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">{{ user?.email }}</div>
									<p class="text-xs text-gray-400">This is your sign-in e-mail, so it can't be changed here.</p>
								</div>
								<div class="space-y-2">
									<FormsLabel text="Mobile number" />
									<FormsTextField v-model="draft.phone" type="tel" size="lg" autocomplete="tel" placeholder="0917 123 4567" />
									<p v-if="fields.phone" class="text-xs text-rose-600">{{ fields.phone }}</p>
									<p v-else class="text-xs text-gray-400">A Philippine mobile number. Leave it empty to remove it.</p>
								</div>
							</div>

							<!-- Appearance -->
							<div v-else-if="activeProfileTab === 'Appearance'">
								<MediaBatchScope :batch="mediaBatch"><MediaAvatarUploader /></MediaBatchScope>
							</div>

							<p v-if="saveError" class="mt-6 rounded-xl bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ saveError }}</p>
						</div>
					</div>
				</div>

				<!-- Footer -->
				<div class="flex shrink-0 items-center justify-between gap-4 border-t border-gray-200 bg-white px-6 py-4 md:px-9">
					<p class="hidden text-xs text-gray-400 sm:block">Review your changes, then save.</p>
					<div class="flex w-full gap-3 sm:w-auto">
						<button type="button" class="flex-1 rounded-xl border border-gray-200 bg-white px-6 py-3 text-sm font-bold text-gray-700 transition hover:bg-gray-50 active:scale-[0.98] sm:flex-none"
							@click="closeProfilePanel">
							Cancel
						</button>
						<button type="button" class="flex-1 rounded-xl bg-primary-700 px-7 py-3 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-primary-900 hover:shadow-md active:translate-y-0 active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60 sm:flex-none"
							:disabled="saving" @click="saveProfile">
							{{ saving ? 'Saving…' : 'Save Profile' }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</div>
</template>

<script setup lang="ts">
useLockedFrame()
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
		label: 'Messages',
		to: '/client/messages',
		icon: 'message',
	},
	{
		label: 'Event Journey',
		to: '/client/preparation-tracking',
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
// The browser tab shows the page name too; a page's own title wins.
useSeoMeta({ title: () => headerTitle.value ? `${headerTitle.value} | NaSeRy` : 'NaSeRy' })

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
	{ label: 'Identity', icon: 'user', hint: 'Your name', description: 'The name organizers see on your requests.' },
	{ label: 'Contact', icon: 'phone', hint: 'How to reach you', description: 'How organizers can reach you.' },
	{ label: 'Appearance', icon: 'camera', hint: 'Profile photo', description: 'The photo shown with your name.' },
]

const draft = reactive({ first_name: '', middle_name: '', last_name: '', phone: '' })
const draftName = computed(() => [draft.first_name, draft.middle_name, draft.last_name].map(v => v.trim()).filter(Boolean).join(' '))

const showProfilePanel = ref(false)
const activeProfileTab = ref('Identity')
const activeTabMeta = computed(() => profileTabs.find(tab => tab.label === activeProfileTab.value) ?? profileTabs[0]!)
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

<style scoped>
.cp-dots {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 20%, #000 95%);
	-webkit-mask-image: linear-gradient(115deg, transparent 20%, #000 95%);
}

.cp-fade {
	animation: cp-fade 0.35s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes cp-fade {
	from { opacity: 0; transform: translateY(8px); }
	to { opacity: 1; transform: translateY(0); }
}

@media (prefers-reduced-motion: reduce) {
	.cp-fade { animation: none; }
}
</style>
