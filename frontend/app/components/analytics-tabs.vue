<template>
	<div>
		<h1 class="text-xl font-extrabold text-gray-900 sm:text-2xl">Organizer Analytics Dashboard</h1>
		<p class="mt-1 text-sm text-gray-500 sm:text-base">Track your performance, bids, and event metrics</p>

		<!-- Phones: four equal tabs with short names in one row -->
		<div class="mb-5 mt-4 grid grid-cols-4 gap-1 rounded-xl bg-gray-100 p-1 sm:mb-6 sm:mt-6 sm:inline-flex sm:max-w-full sm:flex-wrap">
			<NuxtLink v-for="tab in tabs" :key="tab.label" :to="tab.to" :aria-label="tab.label"
				class="flex min-h-10 items-center justify-center rounded-lg px-1 py-2 text-[13px] font-semibold transition-colors duration-200 sm:px-4 sm:text-sm"
				:class="route.path === tab.to ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
				<span class="sm:hidden">{{ tab.short }}</span><span class="hidden sm:inline">{{ tab.label }}</span>
			</NuxtLink>
		</div>
	</div>
</template>

<script setup lang="ts">
const route = useRoute()

const tabs = [
	{ label: 'Overview', short: 'Overview', to: '/organizer/analyticsdashboard/overview' },
	{ label: 'Bid Performance', short: 'Bids', to: '/organizer/analyticsdashboard/bid-performance' },
	{ label: 'Event Performance', short: 'Events', to: '/organizer/analyticsdashboard/event-performance' },
	{ label: 'Live Events', short: 'Live', to: '/organizer/analyticsdashboard/live-events' }
]
</script>