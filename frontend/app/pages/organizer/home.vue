<template>
	<div>
		<header class="mb-6 overflow-hidden rounded-3xl border border-primary-100 bg-white shadow-sm">
			<div class="flex flex-col gap-6 px-5 py-6 sm:px-7 lg:flex-row lg:items-center lg:justify-between">
				<div class="max-w-2xl">
					<div
						class="mb-3 inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-primary-700">
						<span class="h-1.5 w-1.5 rounded-full bg-primary-600"></span>
						Organizer workspace
					</div>
					<h1 class="text-2xl font-extrabold tracking-tight text-gray-950 sm:text-3xl">
						{{ greeting }}, {{ organizerFirstName }}
					</h1>
					<p class="mt-2 max-w-xl text-sm leading-6 text-gray-500 sm:text-base">
						Here's what's happening with your events today.
					</p>
				</div>

				<button type="button"
					class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary-700 px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-primary-800 focus:outline-none focus:ring-4 focus:ring-primary-100 sm:w-auto"
					@click="navigateTo('/organizer/inquiries/match-inquiries')">
					<IconBase name="bell" class="h-4 w-4" />
					Review new inquiries
				</button>
			</div>

			<div class="grid border-t border-gray-100 bg-gray-50/70 sm:grid-cols-3">
				<div v-for="(step, index) in workflowSteps" :key="step.title"
					class="flex items-center gap-3 border-b border-gray-100 px-5 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0 sm:px-7">
					<span
						class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white text-xs font-extrabold text-primary-700 shadow-sm ring-1 ring-gray-200">
						{{ index + 1 }}
					</span>
					<div>
						<p class="text-sm font-bold text-gray-900">{{ step.title }}</p>
						<p class="mt-0.5 text-xs text-gray-500">{{ step.description }}</p>
					</div>
				</div>
			</div>
		</header>

		<div v-if="isLoading"
			class="mb-7 rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
			Loading your dashboard...
		</div>

		<div v-else-if="errorMessage" class="mb-7 rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else>
			<div class="mb-7 grid grid-cols-2 gap-3 lg:grid-cols-4">
				<div v-for="stat in stats" :key="stat.label"
					class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md sm:p-5">
					<div class="flex items-center justify-between gap-3">
						<div>
							<p class="text-xs font-semibold text-gray-500 sm:text-sm">
								{{ stat.label }}
							</p>
							<p class="mt-2 text-2xl font-extrabold tracking-tight text-gray-950 sm:text-3xl">
								{{ stat.value }}
							</p>
						</div>
						<div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
							:class="stat.iconBg">
							<IconBase :name="stat.icon" class="h-5 w-5" :class="stat.iconColor" />
						</div>
					</div>

					<div class="mt-3 flex items-center gap-1 text-xs font-semibold"
						:class="stat.hasTrendIcon ? 'text-green-600' : 'text-gray-400'">
						<IconBase v-if="stat.hasTrendIcon" name="trending-up" class="h-3.5 w-3.5" />
						<span>{{ stat.trend }}</span>
					</div>
				</div>
			</div>

			<div class="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-5">
				<div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm lg:col-span-3">
					<div class="mb-5 flex items-start justify-between">
						<div>
							<h2 class="text-base font-bold text-gray-900">
								Recent Activity
							</h2>

							<p class="text-sm text-gray-500">
								Your latest updates and notifications
							</p>
						</div>

						<IconBase name="bell" class="h-5 w-5 text-gray-400" />
					</div>

					<div v-if="recentActivity.length" class="space-y-3">
						<div v-for="item in recentActivity" :key="item.title"
							class="flex items-center justify-between gap-4 rounded-xl border border-gray-200 p-4 transition hover:border-primary-200 hover:bg-primary-50/30">
							<div class="min-w-0">
								<div class="truncate text-sm font-semibold text-gray-900">
									{{ item.title }}
								</div>

								<div class="mt-1 text-xs text-gray-400">
									{{ formatRelativeTime(item.time) }}
								</div>
							</div>

							<button type="button"
								class="flex shrink-0 items-center gap-1 text-sm font-bold text-primary-700 hover:text-primary-900"
								@click="navigateTo(item.route)">
								{{ item.action }}

								<IconBase name="arrow-right" class="h-4 w-4" />
							</button>
						</div>
					</div>
					<p v-else class="py-6 text-center text-sm text-gray-400">No recent activity yet.</p>
				</div>

				<div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm lg:col-span-2">
					<div class="mb-5">
						<h2 class="text-base font-bold text-gray-900">
							Upcoming Events
						</h2>

						<p class="text-sm text-gray-500">
							Your scheduled events and preparation status
						</p>
					</div>

					<div v-if="upcomingEvents.length" class="space-y-5">
						<div v-for="event in upcomingEvents" :key="event.name">
							<div class="mb-1.5 flex items-start justify-between gap-3">
								<div class="min-w-0 truncate text-sm font-semibold text-gray-900">
									{{ event.name }}
								</div>

								<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :class="event.progress >= 80
									? 'bg-primary-700 text-white'
									: 'bg-gray-100 text-gray-700'
									">
									{{ event.progress }}%
								</span>
							</div>

							<div class="mb-2 text-xs text-gray-400">
								{{ event.date }}
								&bull;
								{{ event.guests }} guests
							</div>

							<div class="mb-1 flex items-center justify-between text-xs text-gray-400">
								<span>
									Preparation Progress
								</span>

								<span>
									{{ event.progress }}%
								</span>
							</div>

							<div class="h-2 w-full overflow-hidden rounded-full bg-gray-100">
								<div class="h-full rounded-full bg-primary-700" :style="{
									width: event.progress + '%',
								}" />
							</div>
						</div>
					</div>
					<p v-else class="py-6 text-center text-sm text-gray-400">No upcoming events yet.</p>
				</div>
			</div>
		</template>

		<div class="mb-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
			<h2 class="text-base font-bold text-gray-900">
				Quick Actions
			</h2>

			<p class="mb-5 text-sm text-gray-500">
				Common tasks to help you get started
			</p>

			<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
				<button v-for="action in quickActions" :key="action.label" type="button"
					class="group rounded-xl border border-gray-200 p-5 text-left transition hover:-translate-y-0.5 hover:border-primary-300 hover:bg-primary-50/40 hover:shadow-sm"
					@click="navigateTo(action.route)">
					<span
						class="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-700 transition group-hover:bg-primary-100 group-hover:text-primary-700">
						<IconBase :name="action.icon" class="h-5 w-5" />
					</span>

					<div class="text-sm font-semibold text-gray-900">
						{{ action.label }}
					</div>

					<div class="text-xs text-gray-400">
						{{ action.sub }}
					</div>
				</button>
			</div>
		</div>

		<div class="rounded-2xl border border-primary-100 bg-primary-50/40 p-6 shadow-sm">
			<div class="mb-2 flex items-center gap-2">
				<IconBase name="check-circle" class="h-5 w-5 text-primary-700" />

				<h2 class="text-base font-bold text-gray-900">
					Platform Status
				</h2>
			</div>

			<p class="text-sm text-gray-600">
				All systems operational. You're receiving real-time
				notifications for matching inquiries based on your service
				tags and location preferences.
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { pesoExact } from '~/composables/useEarnings'
definePageMeta({
	layout: 'dashboard',
})

const { firstName, user, isAuthenticated, token } = useAuth('organizer')
const config = useRuntimeConfig()

const organizerFirstName = computed(() => firstName.value || 'Organizer')

const greeting = computed(() => {
	const hour = new Date().getHours()
	if (hour < 12) return 'Good morning'
	if (hour < 18) return 'Good afternoon'
	return 'Good evening'
})

const workflowSteps = [
	{ title: 'Get matched', description: 'Qualified inquiries reach your inbox' },
	{ title: 'Send a quotation', description: 'Pitch your package and pricing' },
	{ title: 'Deliver the event', description: 'Plan, check in, and follow up' },
]

interface DashboardStats {
	active_events: number
	active_events_new_this_month: number
	pending_offers: number
	pending_offers_new_this_week: number
	win_rate: number
	accepted_quotations: number
	rejected_quotations: number
	total_revenue_this_quarter: number
	total_revenue_all_time: number
	matching_inquiries: number
}

interface DashboardActivity {
	title: string
	time: string
	action: string
	route: string
}

interface DashboardUpcomingEvent {
	name: string
	date: string
	guests: number
	progress: number
}

interface DashboardSummaryResponse {
	data: {
		stats: DashboardStats
		recent_activity: DashboardActivity[]
		upcoming_events: DashboardUpcomingEvent[]
	}
}

const isLoading = ref(false)
const errorMessage = ref('')
const dashboardStats = ref<DashboardStats | null>(null)
const recentActivity = ref<DashboardActivity[]>([])
const upcomingEvents = ref<DashboardUpcomingEvent[]>([])

const stats = computed(() => {
	const data = dashboardStats.value

	return [
		{
			label: 'Active Events',
			value: data ? String(data.active_events) : '0',
			icon: 'calendar',
			iconBg: 'bg-gray-100',
			iconColor: 'text-gray-500',
			trend: data?.active_events_new_this_month
				? `+${data.active_events_new_this_month} created this month`
				: 'No new events this month',
			hasTrendIcon: Boolean(data?.active_events_new_this_month),
		},
		{
			label: 'Pending Offers',
			value: data ? String(data.pending_offers) : '0',
			icon: 'clock',
			iconBg: 'bg-amber-50',
			iconColor: 'text-amber-600',
			trend: data?.pending_offers_new_this_week
				? `${data.pending_offers_new_this_week} new this week`
				: 'No new offers this week',
			hasTrendIcon: false,
		},
		{
			label: 'Win Rate',
			value: data ? `${data.win_rate}%` : '0%',
			icon: 'trending-up',
			iconBg: 'bg-blue-50',
			iconColor: 'text-blue-600',
			trend: data ? `${data.accepted_quotations} won · ${data.rejected_quotations} lost` : 'No decisions yet',
			hasTrendIcon: Boolean(data?.accepted_quotations),
		},
		{
			label: 'Your Ticket Share',
			value: pesoExact(data?.total_revenue_all_time ?? 0),
			icon: 'cash',
			iconBg: 'bg-green-50',
			iconColor: 'text-green-600',
			// After refunds to buyers and NaSeRy's fee; NaSeRy pays it out (see Earnings).
			trend: `Still to be paid: ${pesoExact(data?.total_revenue_this_quarter ?? 0)}`,
			hasTrendIcon: false,
			route: '/organizer/earnings',
		},
	]
})

const quickActions = computed(() => [
	{
		label: 'View Inquiries',
		sub: dashboardStats.value?.matching_inquiries
			? `${dashboardStats.value.matching_inquiries} new match${dashboardStats.value.matching_inquiries === 1 ? '' : 'es'}`
			: 'No new matches',
		icon: 'bell',
		route: '/organizer/inquiries/match-inquiries',
	},
	{
		label: 'Manage Events',
		sub: 'Track your events',
		icon: 'calendar',
		route: '/organizer/eventmanagement/all-events',
	},
	{
		label: 'Check Attendance',
		sub: 'QR scanning',
		icon: 'qr-code',
		route: '/organizer/ticketattendance/qr-checkin',
	},
	{
		label: 'View Analytics',
		sub: 'Performance',
		icon: 'chart-bar',
		route: '/organizer/analyticsdashboard/overview',
	},
])

function formatCompactCurrency(amount: number) {
	if (amount >= 1000000) return `₱${(amount / 1000000).toFixed(1)}M`
	if (amount >= 1000) return `₱${(amount / 1000).toFixed(0)}K`
	return `₱${amount}`
}

function formatRelativeTime(dateString: string) {
	const diffMs = Date.now() - new Date(dateString).getTime()
	const minutes = Math.floor(diffMs / 60000)
	if (minutes < 1) return 'Just now'
	if (minutes < 60) return `${minutes}m ago`
	const hours = Math.floor(minutes / 60)
	if (hours < 24) return `${hours}h ago`
	const days = Math.floor(hours / 24)
	return `${days}d ago`
}

/** One data source; a 403 (the role can't see it) or any error just leaves it out. */
async function quietly<T>(fn: () => Promise<T>): Promise<T | null> {
	try {
		return await fn()
	} catch {
		return null
	}
}

const { fetchOrganization } = useOrganization()
const eventsApi = useOrganizerEvents()
const quotationsApi = useOrganizerQuotations()
const inquiriesApi = useOrganizerInquiries()
const analyticsApi = useAnalytics()
const earningsApi = useEarnings()
const notificationsApi = useNotifications()

/** The dashboard, built from the NaSeRy API (events, quotations, inquiries, bids, earnings, notifications). */
async function loadDashboard() {
	isLoading.value = true
	errorMessage.value = ''

	try {
		const org = await fetchOrganization()
		if (!org) return

		const [active, pending, matching, overview, money, notices] = await Promise.all([
			quietly(() => eventsApi.list(org.id, ['draft', 'published', 'ongoing'])),
			quietly(() => quotationsApi.list(org.id, ['submitted'])),
			quietly(() => inquiriesApi.list(org.id, ['pending', 'viewed'])),
			quietly(() => analyticsApi.overview(org.id, undefined, 'month')),
			quietly(() => earningsApi.summary(org.id)),
			quietly(() => notificationsApi.list()),
		])

		const monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1).getTime()
		const weekAgo = Date.now() - 7 * 86400000
		const bids = overview?.bids?.totals

		dashboardStats.value = {
			active_events: active?.pagination.total ?? 0,
			active_events_new_this_month: (active?.items ?? []).filter(e => new Date(e.created_at).getTime() >= monthStart).length,
			pending_offers: pending?.pagination.total ?? 0,
			pending_offers_new_this_week: (pending?.items ?? []).filter(q => q.submitted_at && new Date(q.submitted_at).getTime() >= weekAgo).length,
			win_rate: bids?.win_rate === null || bids?.win_rate === undefined ? 0 : Math.round(bids.win_rate),
			accepted_quotations: bids?.won ?? 0,
			rejected_quotations: bids?.lost ?? 0,
			// The money tile: the organizer's share and what NaSeRy still has to pay out.
			total_revenue_all_time: money?.share ?? 0,
			total_revenue_this_quarter: Math.max(0, money?.owed ?? 0),
			matching_inquiries: matching?.pagination.total ?? 0,
		}

		recentActivity.value = (notices?.items ?? []).slice(0, 5).map(n => ({
			title: n.title,
			time: n.created_at,
			action: 'View',
			route: n.url || '/notifications',
		}))

		const now = Date.now()
		upcomingEvents.value = (active?.items ?? [])
			.map(e => ({ e, next: [...e.sessions].filter(x => new Date(x.ends_at).getTime() > now).sort((a, b) => a.starts_at.localeCompare(b.starts_at))[0] }))
			.filter(x => x.next)
			.sort((a, b) => a.next!.starts_at.localeCompare(b.next!.starts_at))
			.slice(0, 4)
			.map(({ e, next }) => ({
				name: e.name,
				date: new Date(next!.starts_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', timeZone: e.timezone }),
				guests: e.overall_capacity ?? 0,
				progress: Math.round(e.readiness_percent ?? 0),
			}))
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load your dashboard.'
	} finally {
		isLoading.value = false
	}
}

onMounted(loadDashboard)
</script>
