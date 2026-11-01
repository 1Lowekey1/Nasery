<template>
	<div class="space-y-6">
		<!-- Hero -->
		<header class="hm-hero hm-rise relative overflow-hidden rounded-[2rem] text-white">
			<span class="hm-orb hm-orb-a" />
			<span class="hm-orb hm-orb-b" />
			<span class="hm-dots" />

			<div class="relative flex flex-col gap-4 px-5 pb-5 pt-5 sm:gap-6 sm:px-9 sm:pb-6 sm:pt-8 lg:flex-row lg:items-center lg:justify-between">
				<div class="max-w-2xl">
					<div class="hidden items-center gap-2 rounded-full bg-white/12 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-white/85 ring-1 ring-white/20 backdrop-blur sm:inline-flex">
						<span class="h-1.5 w-1.5 rounded-full bg-white/80"></span>
						Organizer workspace
					</div>
					<h1 class="text-2xl font-extrabold tracking-tight sm:mt-4 sm:text-4xl">
						{{ greeting }}, {{ organizerFirstName }}
					</h1>
					<p class="mt-1 max-w-xl text-sm leading-6 text-white/70 sm:mt-2 sm:text-base">
						Here's what's happening with your events today.
					</p>
				</div>

				<button type="button"
					class="group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-primary-800 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg active:translate-y-0 active:scale-[0.98] sm:w-auto"
					@click="navigateTo('/organizer/inquiries/match-inquiries')">
					<IconBase name="bell" class="h-4 w-4" />
					Review new inquiries
					<IconBase name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" />
				</button>
			</div>

			<!-- How it works: wider screens only -->
			<div class="relative hidden gap-3 px-9 pb-7 sm:grid sm:grid-cols-3">
				<div v-for="(step, index) in workflowSteps" :key="step.title"
					class="hm-step hm-rise flex items-center gap-3.5 rounded-2xl bg-white/10 p-4 ring-1 ring-white/20 backdrop-blur-sm"
					:style="{ '--d': 120 + index * 80 + 'ms' }">
					<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-sm font-extrabold text-primary-700">
						{{ index + 1 }}
					</span>
					<div class="min-w-0">
						<p class="text-sm font-bold">{{ step.title }}</p>
						<p class="mt-0.5 text-xs text-white/70">{{ step.description }}</p>
					</div>
				</div>
			</div>
		</header>

		<div v-if="isLoading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
			Loading your dashboard...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else>
			<!-- Stats -->
			<div class="grid grid-cols-2 gap-2.5 sm:gap-4 lg:grid-cols-4">
				<div v-for="(stat, index) in stats" :key="stat.label"
					class="hm-card hm-rise group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-3.5 sm:p-5"
					:style="{ '--d': 80 + index * 60 + 'ms' }">
					<span class="hm-line absolute inset-x-0 top-0 h-[2px] bg-primary-700" />

					<div class="flex items-start justify-between gap-3">
						<div class="min-w-0">
							<p class="text-[11px] font-bold uppercase tracking-[0.08em] text-gray-400 sm:text-xs sm:tracking-[0.1em]">
								{{ stat.label }}
							</p>
							<p class="mt-1 text-xl font-extrabold tracking-tight text-gray-950 sm:mt-2 sm:text-4xl">
								{{ stat.value }}
							</p>
						</div>
						<div class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 transition duration-200 group-hover:scale-105 sm:flex">
							<IconBase :name="stat.icon" class="h-5 w-5" />
						</div>
					</div>

					<div class="mt-1.5 flex items-center gap-1.5 text-[11px] font-semibold sm:mt-3 sm:text-xs"
						:class="stat.hasTrendIcon ? 'text-primary-700' : 'text-gray-400'">
						<IconBase v-if="stat.hasTrendIcon" name="trending-up" class="h-3.5 w-3.5" />
						<span>{{ stat.trend }}</span>
					</div>
				</div>
			</div>

			<div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
				<!-- Recent activity -->
				<section class="hm-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-7 lg:col-span-3" style="--d: 200ms">
					<div class="mb-5 flex items-start justify-between">
						<div>
							<h2 class="text-lg font-extrabold tracking-tight text-gray-950">
								Recent Activity
							</h2>

							<p class="mt-0.5 text-sm text-gray-500">
								Your latest updates and notifications
							</p>
						</div>

						<span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gray-50 text-gray-400">
							<IconBase name="bell" class="h-4 w-4" />
						</span>
					</div>

					<div v-if="recentActivity.length" class="-mx-2 space-y-0.5 sm:mx-0 sm:space-y-2">
						<button v-for="(item, index) in recentActivity" :key="item.title" type="button"
							class="hm-row group flex w-full items-center justify-between gap-3 rounded-2xl border border-transparent px-2 py-2 text-left sm:gap-4 sm:px-3 sm:py-3 transition duration-200 hover:border-gray-200 hover:bg-gray-50"
							:style="{ '--d': 260 + index * 50 + 'ms' }" @click="navigateTo(item.route)">
							<div class="flex min-w-0 items-center gap-3.5">
								<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-700 sm:h-10 sm:w-10">
									<IconBase :name="activityIcon(item.title)" class="h-[18px] w-[18px]" />
								</span>

								<div class="min-w-0">
									<div class="line-clamp-2 break-words text-sm font-bold text-gray-900">
										{{ item.title }}
									</div>

									<div class="mt-0.5 text-xs text-gray-400">
										{{ formatRelativeTime(item.time) }}
									</div>
								</div>
							</div>

							<span class="flex shrink-0 items-center gap-1 text-sm font-bold text-primary-700">
								<span class="hidden sm:inline">{{ item.action }}</span>

								<IconBase name="arrow-right" class="h-4 w-4 transition duration-200 group-hover:translate-x-1" />
							</span>
						</button>
					</div>
					<p v-else class="rounded-2xl border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400">No recent activity yet.</p>
				</section>

				<!-- Upcoming events -->
				<section class="hm-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-7 lg:col-span-2" style="--d: 280ms">
					<div class="mb-5">
						<h2 class="text-lg font-extrabold tracking-tight text-gray-950">
							Upcoming Events
						</h2>

						<p class="mt-0.5 text-sm text-gray-500">
							Your scheduled events and preparation status
						</p>
					</div>

					<div v-if="upcomingEvents.length" class="space-y-3">
						<div v-for="event in upcomingEvents" :key="event.name"
							class="rounded-2xl border border-gray-200 p-4 transition duration-200 hover:border-primary-200">
							<div class="flex items-start justify-between gap-3">
								<div class="min-w-0">
									<div class="truncate text-sm font-bold text-gray-900">
										{{ event.name }}
									</div>

									<div class="mt-1 flex items-center gap-1.5 text-xs text-gray-400">
										<IconBase name="calendar" class="h-3.5 w-3.5" />
										{{ event.date }}
										&bull;
										{{ event.guests }} guests
									</div>
								</div>

								<span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold" :class="event.progress >= 80
									? 'bg-primary-700 text-white'
									: 'bg-primary-50 text-primary-700'
									">
									{{ event.progress }}%
								</span>
							</div>

							<div class="mt-4 flex items-center justify-between text-[11px] font-semibold text-gray-400">
								<span>Preparation progress</span>
							</div>

							<div class="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-gray-100">
								<div class="hm-bar h-full rounded-full bg-primary-700" :style="{
									width: event.progress + '%',
								}" />
							</div>
						</div>
					</div>

					<div v-else class="rounded-2xl border border-dashed border-gray-300 px-5 py-12 text-center">
						<span class="hm-float mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50 text-primary-700">
							<IconBase name="calendar" class="h-7 w-7" />
						</span>
						<p class="mt-4 text-sm font-bold text-gray-900">No upcoming events yet</p>
						<p class="mx-auto mt-1 max-w-xs text-xs leading-5 text-gray-500">
							Events you plan with a confirmed date will show up here with their preparation progress.
						</p>
						<button type="button" class="mt-5 inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-xs font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700"
							@click="navigateTo('/organizer/eventmanagement/all-events')">
							Open event management
							<IconBase name="arrow-right" class="h-3.5 w-3.5" />
						</button>
					</div>
				</section>
			</div>

			<!-- What's in demand across NaSeRy (counts only, no other organizer's details) -->
			<AnalyticsEventTrendsCard class="hm-rise" style="--d: 300ms" subtitle="What clients are asking for across NaSeRy, so you can plan your services and price list." />
		</template>

		<!-- Quick actions -->
		<section class="hm-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-7" style="--d: 320ms">
			<h2 class="text-lg font-extrabold tracking-tight text-gray-950">
				Quick Actions
			</h2>

			<p class="mb-5 mt-0.5 text-sm text-gray-500">
				Common tasks to help you get started
			</p>

			<div class="stat-grid grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
				<button v-for="action in quickActions" :key="action.label" type="button"
					class="hm-action group relative rounded-2xl border border-gray-200 p-5 text-left"
					@click="navigateTo(action.route)">
					<span class="mb-4 flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 transition duration-200 group-hover:bg-primary-700 group-hover:text-white">
						<IconBase :name="action.icon" class="h-5 w-5" />
					</span>

					<div class="text-sm font-extrabold text-gray-950">
						{{ action.label }}
					</div>

					<div class="mt-0.5 text-xs text-gray-400">
						{{ action.sub }}
					</div>

					<IconBase name="arrow-right" class="absolute right-5 top-5 h-4 w-4 -translate-x-1 text-primary-700 opacity-0 transition duration-200 group-hover:translate-x-0 group-hover:opacity-100" />
				</button>
			</div>
		</section>

		<!-- Platform status -->
		<div class="hm-rise flex items-start gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-4" style="--d: 380ms">
			<span class="relative mt-1.5 flex h-2.5 w-2.5 shrink-0">
				<span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-60" />
				<span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500" />
			</span>

			<p class="text-sm text-gray-600">
				<span class="font-bold text-gray-900">All systems operational.</span>
				You're receiving real-time notifications for matching inquiries based on your service
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

function activityIcon(title: string): string {
	return /^congratulations/i.test(title) ? 'award' : 'file-text'
}

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
async function loadDashboard(quiet = false) {
	if (!quiet) isLoading.value = true
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
// Close to live (review #12): other people's changes appear without a reload.
useLiveRefresh(() => loadDashboard(true), { interval: 60000 })
</script>

<style scoped>
.hm-hero {
	background: #285f6b;
}

.hm-orb {
	position: absolute;
	border-radius: 9999px;
	filter: blur(60px);
	pointer-events: none;
}

.hm-orb-a {
	top: -120px;
	right: 6%;
	height: 320px;
	width: 320px;
	background: rgba(255, 255, 255, 0.1);
	animation: hm-drift 16s ease-in-out infinite;
}

.hm-orb-b {
	bottom: -140px;
	left: -50px;
	height: 280px;
	width: 280px;
	background: rgba(255, 255, 255, 0.06);
	animation: hm-drift 20s ease-in-out infinite reverse;
}

.hm-dots {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
	-webkit-mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
}

.hm-rise {
	animation: hm-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.hm-row {
	animation: hm-rise 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.hm-step {
	transition: background-color 0.25s ease, transform 0.25s ease;
}

.hm-step:hover {
	background-color: rgba(255, 255, 255, 0.16);
	transform: translateY(-2px);
}

.hm-card {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.hm-card:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.hm-line {
	transform: scaleX(0);
	transform-origin: left;
	transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.hm-card:hover .hm-line {
	transform: scaleX(1);
}

.hm-action {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.hm-action:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.3);
	box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.hm-bar {
	animation: hm-grow 1s cubic-bezier(0.22, 1, 0.36, 1) both;
	transform-origin: left;
}

.hm-float {
	animation: hm-float 3.2s ease-in-out infinite;
}

@keyframes hm-rise {
	from { opacity: 0; transform: translateY(14px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes hm-drift {
	0%, 100% { transform: translate(0, 0) scale(1); }
	50% { transform: translate(-22px, 16px) scale(1.08); }
}

@keyframes hm-grow {
	from { transform: scaleX(0); }
	to { transform: scaleX(1); }
}

@keyframes hm-float {
	0%, 100% { transform: translateY(0); }
	50% { transform: translateY(-4px); }
}

@media (prefers-reduced-motion: reduce) {
	.hm-orb, .hm-rise, .hm-row, .hm-bar, .hm-float { animation: none; }
	.hm-card, .hm-line, .hm-action, .hm-step { transition: none; }
}
</style>
