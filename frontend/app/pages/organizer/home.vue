<template>
	<div>
		<div class="mb-6 rounded-2xl bg-gradient-to-r from-gray-100 to-gray-50 p-8">
			<h1 class="text-3xl font-extrabold text-gray-900">{{ greeting }}, {{ user?.first_name }}</h1>
			<p class="mt-1.5 text-gray-500">
				<template v-if="organization">Here's what's happening at {{ organization.display_name }} today.</template>
				<template v-else>Loading your workspace…</template>
			</p>
		</div>

		<!-- Numbers: each from the API; a tile the member's role can't see is left out. -->
		<div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
			<component :is="t.to ? NuxtLinkComponent : 'div'" v-for="t in tiles" :key="t.label" :to="t.to"
				class="rounded-2xl border border-gray-200 bg-white p-5" :class="t.to ? 'hover:border-primary-300' : ''">
				<div class="mb-3 flex items-center justify-between">
					<span class="text-sm text-gray-500">{{ t.label }}</span>
					<IconBase :name="t.icon" class="h-[18px] w-[18px] text-gray-400" />
				</div>
				<div class="text-3xl font-extrabold text-gray-900">{{ t.value }}</div>
				<div class="mt-2 text-sm text-gray-500">{{ t.sub }}</div>
			</component>
			<div v-if="loading && !tiles.length" class="col-span-full py-6 text-center text-sm text-gray-400">Loading your numbers…</div>
		</div>

		<div class="mb-6 grid grid-cols-1 gap-5 lg:grid-cols-5">
			<section class="rounded-2xl border border-gray-200 bg-white p-6 lg:col-span-3">
				<div class="mb-5 flex items-start justify-between">
					<div>
						<h2 class="text-base font-bold text-gray-900">Recent activity</h2>
						<p class="text-sm text-gray-500">Your latest notifications</p>
					</div>
					<NuxtLink to="/notifications" class="text-sm font-semibold text-primary-700">See all</NuxtLink>
				</div>
				<p v-if="!activity.length" class="rounded-xl bg-gray-50 p-6 text-center text-sm text-gray-500">
					{{ loading ? 'Loading…' : 'Nothing yet. New inquiries, bookings and team updates will show up here.' }}
				</p>
				<ul class="space-y-3">
					<li v-for="n in activity" :key="n.id">
						<component :is="n.url ? NuxtLinkComponent : 'div'" :to="n.url ?? undefined" class="flex items-start gap-3 rounded-xl border border-gray-100 p-3" :class="n.url ? 'hover:bg-gray-50' : ''">
							<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="n.read ? 'bg-gray-200' : 'bg-primary-600'" aria-hidden="true" />
							<span class="min-w-0">
								<span class="block text-sm font-semibold text-gray-900">{{ n.title }}</span>
								<span v-if="n.body" class="block truncate text-xs text-gray-500">{{ n.body }}</span>
								<span class="block text-xs text-gray-400">{{ ago(n.created_at) }}</span>
							</span>
						</component>
					</li>
				</ul>
			</section>

			<section class="rounded-2xl border border-gray-200 bg-white p-6 lg:col-span-2">
				<div class="mb-5 flex items-start justify-between">
					<div>
						<h2 class="text-base font-bold text-gray-900">Upcoming events</h2>
						<p class="text-sm text-gray-500">Soonest first, with preparation readiness</p>
					</div>
					<NuxtLink to="/organizer/eventmanagement/all-events" class="text-sm font-semibold text-primary-700">All events</NuxtLink>
				</div>
				<p v-if="!upcoming.length" class="rounded-xl bg-gray-50 p-6 text-center text-sm text-gray-500">
					{{ loading ? 'Loading…' : 'No upcoming events. Create one, or win an inquiry to set one up.' }}
				</p>
				<ul class="space-y-4">
					<li v-for="e in upcoming" :key="e.id">
						<NuxtLink :to="`/organizer/eventmanagement/event-details?event=${e.id}`" class="block rounded-xl p-2 hover:bg-gray-50">
							<div class="flex items-baseline justify-between gap-2">
								<span class="truncate text-sm font-semibold text-gray-900">{{ e.name }}</span>
								<span class="shrink-0 text-xs text-gray-500">{{ e.when }}</span>
							</div>
							<div class="mt-2 flex items-center gap-2">
								<div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="e.readiness ?? 0" aria-valuemin="0" aria-valuemax="100" :aria-label="`${e.name} readiness`">
									<div class="h-full rounded-full bg-primary-600" :style="{ width: `${e.readiness ?? 0}%` }" />
								</div>
								<span class="w-10 text-right text-xs text-gray-500">{{ e.readiness === null ? '—' : `${e.readiness}%` }}</span>
							</div>
						</NuxtLink>
					</li>
				</ul>
			</section>
		</div>

		<section class="rounded-2xl border border-gray-200 bg-white p-6">
			<h2 class="mb-4 text-base font-bold text-gray-900">Quick actions</h2>
			<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
				<NuxtLink v-for="a in quickActions" :key="a.label" :to="a.to"
					class="rounded-xl border border-gray-200 p-5 text-left hover:border-primary-300 hover:bg-primary-50/40">
					<IconBase :name="a.icon" class="mb-3 h-5 w-5 text-gray-700" />
					<div class="text-sm font-semibold text-gray-900">{{ a.label }}</div>
					<div class="text-xs text-gray-400">{{ a.sub }}</div>
				</NuxtLink>
			</div>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { AppNotification } from '~/composables/useNotifications'

definePageMeta({ layout: 'dashboard' })

const NuxtLinkComponent = resolveComponent('NuxtLink')
const { user } = useAuth()
const { organization, fetchOrganization } = useOrganization()
const events = useOrganizerEvents()
const inquiries = useOrganizerInquiries()
const analytics = useAnalytics()
const billing = useBilling()
const notifications = useNotifications()

type Tile = { label: string, value: string, sub: string, icon: string, to?: string }
const tiles = ref<Tile[]>([])
const activity = ref<AppNotification[]>([])
const upcoming = ref<{ id: string, name: string, when: string, readiness: number | null }[]>([])
const loading = ref(true)

const greeting = computed(() => {
	const h = new Date().getHours()
	return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
})

function ago(at: string) {
	const mins = Math.round((Date.now() - new Date(at).getTime()) / 60000)
	if (mins < 1) return 'just now'
	if (mins < 60) return `${mins} min ago`
	if (mins < 1440) return `${Math.round(mins / 60)} h ago`
	return new Date(at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

/** Runs one data source; a 403 (role can't see it) or any error just leaves it out. */
async function quietly<T>(fn: () => Promise<T>): Promise<T | null> {
	try {
		return await fn()
	} catch {
		return null
	}
}

onMounted(async () => {
	const org = organization.value ?? await fetchOrganization()
	if (!org) {
		loading.value = false
		return
	}

	const [active, waiting, overview, plan, recent] = await Promise.all([
		quietly(() => events.list(org.id, ['draft', 'published', 'ongoing'])),
		quietly(() => inquiries.list(org.id, ['pending', 'viewed'])),
		quietly(() => analytics.overview(org.id, undefined, 'month')),
		quietly(() => billing.overview(org.id)),
		quietly(() => notifications.list()),
	])

	const t: Tile[] = []
	if (active) {
		const limit = plan?.usage.find(u => u.key === 'active_event_limit')?.limit
		t.push({ label: 'Active events', value: String(active.pagination.total), icon: 'calendar', to: '/organizer/eventmanagement/all-events',
			sub: limit ? `of ${limit} on your ${plan!.plan.name} plan` : 'Draft, published and happening now' })
	}
	if (waiting) {
		t.push({ label: 'Inquiries to answer', value: String(waiting.pagination.total), icon: 'bell', to: '/organizer/inquiries/match-inquiries',
			sub: waiting.pagination.total ? 'Waiting for your quotation or reply' : 'You are all caught up' })
	}
	const bids = overview?.bids?.totals
	if (bids) {
		t.push({ label: 'Win rate', value: bids.win_rate === null ? '—' : `${Math.round(bids.win_rate * 10) / 10}%`, icon: 'trending-up', to: '/organizer/analyticsdashboard/bid-performance',
			sub: `${bids.won} won of ${bids.won + bids.lost} decided bids` })
	}
	if (plan) {
		t.push({ label: 'Plan', value: plan.plan.name, icon: 'credit-card', to: '/organizer/billing',
			sub: plan.subscription ? `Renews ${new Date(plan.subscription.period_end).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })}` : 'Upgrade anytime for more room' })
	}
	tiles.value = t

	activity.value = (recent?.items ?? []).slice(0, 5)

	const now = Date.now()
	upcoming.value = (active?.items ?? [])
		.map((e) => {
			const next = e.sessions.filter(s => new Date(s.ends_at).getTime() > now).sort((a, b) => a.starts_at.localeCompare(b.starts_at))[0]
			return { e, next }
		})
		.filter(x => x.next)
		.sort((a, b) => a.next!.starts_at.localeCompare(b.next!.starts_at))
		.slice(0, 4)
		.map(({ e, next }) => ({
			id: e.id,
			name: e.name,
			when: new Date(next!.starts_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', timeZone: e.timezone }),
			readiness: e.readiness_percent,
		}))
	loading.value = false
})

const quickActions = [
	{ label: 'Matching inquiries', sub: 'Answer clients', icon: 'bell', to: '/organizer/inquiries/match-inquiries' },
	{ label: 'Events', sub: 'Create or manage', icon: 'calendar', to: '/organizer/eventmanagement/all-events' },
	{ label: 'Check-in', sub: 'QR scanning', icon: 'qr-code', to: '/organizer/ticketattendance/qr-checkin' },
	{ label: 'Analytics', sub: 'Performance', icon: 'chart-bar', to: '/organizer/analyticsdashboard/overview' },
]
</script>
