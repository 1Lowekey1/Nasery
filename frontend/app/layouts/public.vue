<script setup lang="ts">
const { isLoggedIn, homePath } = useAuth()
</script>

<template>
	<div class="public-site min-h-screen bg-[#f8f9f7] text-gray-900">
		<a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>
		<header class="border-b border-gray-200 bg-white">
			<nav aria-label="Main navigation" class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-5 sm:px-8">
				<NuxtLink to="/" aria-label="NaSeRy home" class="flex items-center gap-2.5">
					<span class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-700 text-xl font-bold text-white">N</span>
					<span class="text-xl font-extrabold tracking-tight">NaSeRy<span class="text-primary-600">.</span></span>
				</NuxtLink>
				<div class="flex flex-wrap items-center gap-4 text-sm font-semibold sm:gap-7">
					<NuxtLink to="/events" class="text-primary-700 hover:underline">Events</NuxtLink>
					<NuxtLink to="/organizers" class="text-primary-700 hover:underline">Browse organizers</NuxtLink>
					<NuxtLink v-if="isLoggedIn" to="/my-tickets" class="text-primary-700 hover:underline">My tickets</NuxtLink>
					<NotificationBell v-if="isLoggedIn" />
					<NuxtLink v-if="isLoggedIn" :to="homePath()" class="rounded-lg border border-gray-200 px-4 py-2.5 hover:bg-gray-50">My dashboard</NuxtLink>
					<template v-else>
						<NuxtLink to="/login" class="hover:text-primary-700">Sign in</NuxtLink>
						<NuxtLink to="/register?as=client" class="rounded-lg bg-primary-700 px-4 py-2.5 text-white hover:bg-primary-900">Plan an event</NuxtLink>
					</template>
				</div>
			</nav>
		</header>
		<main id="main-content">
			<AnnouncementBanner class="mx-auto max-w-6xl px-5 pt-4 sm:px-8" />
			<slot />
		</main>
		<footer class="mt-16 border-t border-gray-200">
			<div class="mx-auto flex max-w-7xl flex-col justify-between gap-3 px-5 py-8 text-sm text-gray-500 sm:flex-row sm:px-8">
				<span class="font-bold text-primary-800">NaSeRy · Bring your event to life.</span>
				<span>Explore freely. Sign in when you're ready to plan.</span>
			</div>
		</footer>
	</div>
</template>

<style scoped>
.public-site :deep(a:focus-visible), .public-site :deep(button:focus-visible),
.public-site :deep(input:focus-visible), .public-site :deep(select:focus-visible) {
	outline: 3px solid #417f88;
	outline-offset: 4px;
}
</style>
