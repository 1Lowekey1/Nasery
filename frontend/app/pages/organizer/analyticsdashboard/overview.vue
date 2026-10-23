<template>
	<div>
		<div v-if="isLoading" class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
			<div v-for="n in 4" :key="n" class="h-36 animate-pulse rounded-2xl bg-white ring-1 ring-gray-200" />
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else>
			<!-- Headline numbers -->
			<div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
				<div v-for="(stat, i) in stats" :key="stat.label" class="an-tile group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-5 transition hover:border-primary-200 hover:shadow-[0_12px_28px_-18px_rgba(16,63,77,0.35)]" :style="{ '--i': i }">
					<div class="flex items-start justify-between gap-3">
						<span class="text-sm font-medium text-gray-500">{{ stat.label }}</span>
						<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700 transition group-hover:bg-primary-700 group-hover:text-white">
							<IconBase :name="stat.icon" class="h-[18px] w-[18px]" />
						</span>
					</div>
					<p class="mt-2 text-3xl font-extrabold tabular-nums tracking-tight text-gray-950">{{ stat.display }}</p>
					<div v-if="stat.percent !== null" class="mt-3 h-1.5 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="stat.percent" aria-valuemin="0" aria-valuemax="100" :aria-label="stat.label">
						<div class="h-full rounded-full bg-primary-700 transition-[width] duration-1000 ease-out" :style="{ width: (mounted ? stat.percent : 0) + '%' }" />
					</div>
					<div class="mt-2.5 flex items-center justify-between gap-2 text-xs">
						<span class="text-gray-500">{{ stat.note }}</span>
						<NuxtLink v-if="stat.link" :to="stat.link.to" class="inline-flex shrink-0 items-center gap-1 font-semibold text-primary-700 hover:underline">{{ stat.link.label }} <IconBase name="arrow-right" class="h-3 w-3" /></NuxtLink>
					</div>
				</div>
			</div>

			<div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
				<!-- How you respond -->
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<div class="flex flex-wrap items-start justify-between gap-3">
						<div>
							<h2 class="text-base font-bold text-gray-950">How you respond to inquiries</h2>
							<p class="mt-0.5 text-sm text-gray-500">Matching inquiries and what happened to them</p>
						</div>
						<div class="text-right">
							<p class="text-2xl font-extrabold tabular-nums text-gray-950">{{ distributionTotal }}</p>
							<p class="text-xs text-gray-500">matching {{ distributionTotal === 1 ? 'inquiry' : 'inquiries' }}</p>
						</div>
					</div>

					<template v-if="distributionTotal > 0">
						<!-- One 100% bar: segments with 2px gaps, hover to focus -->
						<div class="mt-6 flex h-4 w-full gap-[2px] overflow-hidden rounded-full" role="img" :aria-label="distributionSummary">
							<div v-for="row in distributionRows" :key="row.label" class="h-full transition-[flex-grow,opacity] duration-700 ease-out first:rounded-l-full last:rounded-r-full" :style="{ flexGrow: mounted ? row.value : 0, flexBasis: 0, backgroundColor: row.color, opacity: focused && focused !== row.label ? 0.25 : 1 }" :title="`${row.label}: ${row.value} (${row.percent}%)`" @mouseenter="focused = row.label" @mouseleave="focused = ''" />
						</div>

						<ul class="mt-5 divide-y divide-gray-100">
							<li v-for="row in distributionRows" :key="row.label" class="flex cursor-default items-center gap-3 rounded-lg px-2 py-3 transition-colors" :class="focused === row.label ? 'bg-gray-50' : ''" @mouseenter="focused = row.label" @mouseleave="focused = ''">
								<span class="h-3 w-3 shrink-0 rounded-[4px]" :style="{ backgroundColor: row.color }" aria-hidden="true" />
								<div class="min-w-0 flex-1">
									<p class="text-sm font-semibold text-gray-900">{{ row.label }}</p>
									<p class="truncate text-xs text-gray-500">{{ row.description }}</p>
								</div>
								<p class="w-10 text-right text-sm font-bold tabular-nums text-gray-950">{{ row.value }}</p>
								<p class="w-12 text-right text-xs font-medium tabular-nums text-gray-500">{{ row.percent }}%</p>
							</li>
						</ul>
					</template>
					<div v-else class="mt-6 rounded-xl border border-dashed border-gray-200 px-6 py-12 text-center">
						<p class="text-sm font-semibold text-gray-800">No matching inquiries yet</p>
						<p class="mt-1 text-sm text-gray-500">Add event types and a map pin in Business Profile so clients can find you.</p>
					</div>
				</section>

				<!-- Recent activity -->
				<section class="rounded-2xl border border-gray-200 bg-white p-6">
					<div class="flex items-start justify-between gap-3">
						<div>
							<h2 class="text-base font-bold text-gray-950">Recent activity</h2>
							<p class="mt-0.5 text-sm text-gray-500">Latest bid and inquiry updates</p>
						</div>
						<NuxtLink to="/organizer/inquiries/offers" class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-primary-700 hover:underline">View all <IconBase name="arrow-right" class="h-3 w-3" /></NuxtLink>
					</div>

					<ol v-if="recentActivity.length" class="mt-5">
						<li v-for="(item, i) in recentActivity" :key="`${item.title}-${item.time}`" class="an-row relative flex items-center gap-3 pb-5 last:pb-0" :style="{ '--i': i }">
							<span v-if="i < recentActivity.length - 1" class="absolute left-[17px] top-9 h-[calc(100%-2.25rem)] w-px bg-gray-100" aria-hidden="true" />
							<span class="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-50 text-gray-500 ring-1 ring-gray-200">
								<IconBase :name="statusOf(item.status).icon" class="h-4 w-4" />
							</span>
							<div class="min-w-0 flex-1">
								<p class="truncate text-sm font-semibold text-gray-900">{{ item.title }}</p>
								<p class="text-xs text-gray-400">{{ formatRelativeTime(item.time) }}</p>
							</div>
							<span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700">
								<span class="h-1.5 w-1.5 rounded-full" :class="statusOf(item.status).dot" aria-hidden="true" />{{ statusOf(item.status).label }}
							</span>
						</li>
					</ol>
					<div v-else class="mt-6 rounded-xl border border-dashed border-gray-200 px-6 py-12 text-center">
						<p class="text-sm font-semibold text-gray-800">Nothing yet</p>
						<p class="mt-1 text-sm text-gray-500">Your bids and new inquiries will show up here.</p>
					</div>
				</section>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'dashboard' })

const { token } = useAuth('organizer')
const config = useRuntimeConfig()

interface AnalyticsStats {
	total_bids: number
	win_rate: number | null
	avg_attendance_rate: number | null
	active_inquiries: number
}
interface DistributionRow { label: string, value: number }
interface AnalyticsActivity { title: string, time: string, status: 'won' | 'lost' | 'submitted' | 'withdrawn' | 'new' }
interface AnalyticsOverviewResponse {
	data: { stats: AnalyticsStats, distribution: DistributionRow[], recent_activity: AnalyticsActivity[] }
}

const isLoading = ref(false)
const errorMessage = ref('')
const analyticsStats = ref<AnalyticsStats | null>(null)
const distribution = ref<DistributionRow[]>([])
const recentActivity = ref<AnalyticsActivity[]>([])
const mounted = ref(false)
const focused = ref('')

// Fixed colours per category (brand teal, amber, neutral grey). Every slice is also labelled in the rows below.
const categoryMeta: Record<string, { color: string, description: string, order: number }> = {
	'Bid Submitted': { color: '#285F6b', description: 'You sent a quotation', order: 0 },
	Received: { color: '#c97f1f', description: 'Waiting for your reply', order: 1 },
	Ignored: { color: '#7d8796', description: 'Passed or let expire', order: 2 },
}

// Status keeps a small coloured dot plus a text label; icons stay neutral.
const statusMeta: Record<AnalyticsActivity['status'], { label: string, dot: string, icon: string }> = {
	won: { label: 'Won', dot: 'bg-green-600', icon: 'check-circle' },
	lost: { label: 'Lost', dot: 'bg-red-500', icon: 'x-circle' },
	submitted: { label: 'Bid sent', dot: 'bg-amber-500', icon: 'send' },
	withdrawn: { label: 'Withdrawn', dot: 'bg-gray-400', icon: 'x-circle' },
	new: { label: 'New', dot: 'bg-primary-600', icon: 'calendar' },
}
function statusOf(status: AnalyticsActivity['status']) { return statusMeta[status] ?? statusMeta.submitted }

// ---------- Headline numbers (count up once) ----------
const shown = reactive({ bids: 0, win: 0, attendance: 0, active: 0 })
function countUp(key: keyof typeof shown, to: number) {
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { shown[key] = to; return }
	const start = performance.now()
	const step = (now: number) => {
		const t = Math.min(1, (now - start) / 800)
		shown[key] = Math.round(to * (1 - Math.pow(1 - t, 3)) * 10) / 10
		if (t < 1) requestAnimationFrame(step)
	}
	requestAnimationFrame(step)
}

const stats = computed(() => {
	const d = analyticsStats.value
	const hasWin = d?.win_rate !== null && d?.win_rate !== undefined
	const hasAttendance = d?.avg_attendance_rate !== null && d?.avg_attendance_rate !== undefined
	return [
		{ label: 'Bids submitted', icon: 'file-text', display: String(Math.round(shown.bids)), percent: null, note: d?.total_bids ? 'All-time quotations sent' : 'No bids yet', link: null },
		{ label: 'Win rate', icon: 'trending-up', display: hasWin ? `${trim(shown.win)}%` : '—', percent: hasWin ? Number(d!.win_rate) : null, note: hasWin ? 'Accepted out of decided bids' : 'No decisions yet', link: null },
		{ label: 'Attendance rate', icon: 'users', display: hasAttendance ? `${trim(shown.attendance)}%` : '—', percent: hasAttendance ? Number(d!.avg_attendance_rate) : null, note: hasAttendance ? 'Checked in out of tickets issued' : 'No ticketed events yet', link: null },
		{ label: 'Active inquiries', icon: 'clock', display: String(Math.round(shown.active)), percent: null, note: d?.active_inquiries ? 'Waiting for your reply' : 'You’re all caught up', link: d?.active_inquiries ? { to: '/organizer/inquiries/match-inquiries', label: 'Respond' } : null },
	]
})
function trim(value: number) { return Number.isInteger(value) ? String(value) : value.toFixed(1) }

// ---------- Response breakdown ----------
const distributionTotal = computed(() => distribution.value.reduce((sum, row) => sum + row.value, 0))
const distributionRows = computed(() => distribution.value
	.map(row => ({
		...row,
		color: categoryMeta[row.label]?.color ?? '#7d8796',
		description: categoryMeta[row.label]?.description ?? '',
		order: categoryMeta[row.label]?.order ?? 9,
		percent: distributionTotal.value ? Math.round((row.value / distributionTotal.value) * 100) : 0,
	}))
	.sort((a, b) => a.order - b.order))
const distributionSummary = computed(() => distributionRows.value.map(r => `${r.label} ${r.value} (${r.percent}%)`).join(', '))

function formatRelativeTime(dateString: string) {
	const minutes = Math.floor((Date.now() - new Date(dateString).getTime()) / 60000)
	if (minutes < 1) return 'Just now'
	if (minutes < 60) return `${minutes}m ago`
	const hours = Math.floor(minutes / 60)
	if (hours < 24) return `${hours}h ago`
	const days = Math.floor(hours / 24)
	return days < 30 ? `${days}d ago` : new Date(dateString).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

async function loadAnalytics() {

	isLoading.value = true
	errorMessage.value = ''
	try {
		const response = { data: await legacyAnalyticsOverview() }
		analyticsStats.value = response.data.stats
		distribution.value = response.data.distribution
		recentActivity.value = response.data.recent_activity
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Your analytics couldn’t be loaded. Refresh the page to try again.'
	} finally {
		isLoading.value = false
	}
	await nextTick()
	const d = analyticsStats.value
	countUp('bids', d?.total_bids ?? 0)
	countUp('win', Number(d?.win_rate ?? 0))
	countUp('attendance', Number(d?.avg_attendance_rate ?? 0))
	countUp('active', d?.active_inquiries ?? 0)
	requestAnimationFrame(() => { mounted.value = true })
}

onMounted(loadAnalytics)
</script>

<style scoped>
.an-tile { animation: an-rise 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 60ms); }
.an-row { animation: an-rise 0.45s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(200ms + var(--i, 0) * 50ms); }
@keyframes an-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) {
	.an-tile, .an-row { animation: none; }
}
</style>
