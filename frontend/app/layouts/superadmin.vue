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
						class="inline-flex items-center gap-1.5 rounded-md bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-600">
						<span class="h-1.5 w-1.5 rounded-full bg-red-500" />
						Super Admin
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
					<NuxtLink to="/marketplace" class="mb-3 flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-gray-700">
						<IconBase name="grid" class="h-4 w-4" />
						View public site
					</NuxtLink>
					<button type="button"
						class="flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-gray-700"
						@click="signOut">
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
						<div class="text-xs text-gray-400">Super Admin Portal</div>
						<div class="text-sm font-bold text-gray-900">{{ headerTitle }}</div>
					</div>
				</div>
				<div class="flex items-center gap-3">
					<NotificationsDropdown portal="superadmin" />

					<div ref="profileMenuRef" class="relative">
						<button type="button"
							class="flex items-center gap-2.5 rounded-xl border border-gray-200 bg-white py-1.5 pl-1.5 pr-3 transition hover:border-primary-200 hover:bg-primary-50"
							@click="toggleProfileMenu">
							<SuperadminAvatar :name="fullName || 'Super Admin'" :src="user?.avatar_url" size="sm" />
							<span class="text-sm font-semibold text-gray-700">Profile</span>
							<IconBase name="chevron-down" class="h-3.5 w-3.5 text-gray-400" />
						</button>

						<div v-if="profileMenuOpen"
							class="absolute right-0 top-12 z-[80] w-80 max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl">
							<div class="border-b border-gray-100 px-4 py-3.5">
								<p class="truncate text-sm font-bold text-gray-900">{{ fullName || 'NaSeRy HQ' }}</p>
								<p class="text-xs text-gray-400">Super Admin</p>
							</div>
							<div class="border-b border-gray-100 px-4 py-4">
								<MediaAvatarUploader />
							</div>
							<button type="button"
								class="flex w-full items-center gap-2.5 px-4 py-3 text-sm font-medium text-gray-600 hover:bg-gray-50"
								@click="signOut">
								<IconBase name="log-out" class="h-4 w-4" />
								Sign out
							</button>
						</div>
					</div>
				</div>
			</header>

			<main class="flex-1 overflow-y-auto p-4 sm:p-6">
				<slot />
			</main>
		</div>
	</div>
</template>

<script setup lang="ts">
const route = useRoute()

const { sidebarOpen, isMobile, asideClass, closeSidebar } = useResponsiveSidebar()

const navItems = [
	{ label: 'Overview', to: '/superadmin/overview', icon: 'home', matches: ['/superadmin/overview'] },
	{ label: 'Organizers', to: '/superadmin/organizers', icon: 'briefcase', matches: ['/superadmin/organizers'] },
	{ label: 'Clients', to: '/superadmin/clients', icon: 'users', matches: ['/superadmin/clients'] },
	{ label: 'Events', to: '/superadmin/events', icon: 'calendar', matches: ['/superadmin/events'] },
	{ label: 'Analytics', to: '/superadmin/analytics', icon: 'chart-bar', matches: ['/superadmin/analytics'] },
	{ label: 'Event Tags', to: '/superadmin/event-tags', icon: 'tag', matches: ['/superadmin/event-tags'] },
	{ label: 'Approvals', to: '/superadmin/approvals', icon: 'check-circle', matches: ['/superadmin/approvals'] },
	{ label: 'Moderation', to: '/superadmin/moderation', icon: 'star', matches: ['/superadmin/moderation'] },
	{ label: 'NaSeRy Access', to: '/superadmin/access', icon: 'shield-check', matches: ['/superadmin/access'] },
	{ label: 'Payouts', to: '/superadmin/payouts', icon: 'peso', matches: ['/superadmin/payouts'] },
	{ label: 'Activity Log', to: '/superadmin/activity-log', icon: 'clipboard-list', matches: ['/superadmin/activity-log'] },
	{ label: 'Settings', to: '/superadmin/settings', icon: 'settings', matches: ['/superadmin/settings'] },
	{ label: 'Admin Team', to: '/superadmin/team', icon: 'user-plus', matches: ['/superadmin/team'] },
]

const activeItem = computed(
	() => navItems.find((item) => item.matches.some((path) => route.path.startsWith(path))) ?? navItems[0]
)

// Pages outside the menu still get their own title in the header.
const pageTitles: Record<string, string> = { '/notifications': 'Notifications', '/my-tickets': 'My Tickets' }
const headerTitle = computed(() => pageTitles[route.path] ?? activeItem.value?.label ?? '')

const { fullName, logout, user } = useAuth('superadmin')

const profileMenuOpen = ref(false)
const profileMenuRef = ref<HTMLElement | null>(null)

function toggleProfileMenu() {
	profileMenuOpen.value = !profileMenuOpen.value
	if (profileMenuOpen.value) {
		window.dispatchEvent(new CustomEvent('dashboard:close-notifications'))
	}
}

function closeProfileMenuOnOutsideClick(event: MouseEvent) {
	// Dialogs opened from the menu (photo crop, confirmations) sit outside it.
	const inDialog = (event.target as Element | null)?.closest?.('[aria-modal="true"], .fixed.inset-0')
	if (profileMenuOpen.value && !inDialog && !profileMenuRef.value?.contains(event.target as Node)) {
		profileMenuOpen.value = false
	}
}

onMounted(() => {
	document.addEventListener('click', closeProfileMenuOnOutsideClick)
})

onBeforeUnmount(() => {
	document.removeEventListener('click', closeProfileMenuOnOutsideClick)
})

async function signOut() {
	await logout()
	await navigateTo('/superadmin/login')
}
</script>
