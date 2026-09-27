<template>
	<div>
		<div class="mb-6">
			<h1 class="text-2xl font-extrabold text-gray-900">Platform Overview</h1>
			<p class="mt-1 text-gray-500">A bird's-eye view of every organizer, event, and client on NaSeRy.</p>
		</div>

		<Alert v-if="error" type="danger" :text="error" />
		<div v-if="!data && !error" class="py-16 text-center text-sm text-gray-400">Loading…</div>

		<template v-if="data">
			<div class="mb-6 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3">
				<div v-for="tile in tiles" :key="tile.label" class="rounded-2xl border border-gray-200 bg-white p-5">
					<div class="flex items-start justify-between">
						<span class="text-sm text-gray-500">{{ tile.label }}</span>
						<div class="flex h-8 w-8 items-center justify-center rounded-lg" :class="tile.tone">
							<IconBase :name="tile.icon" class="h-4 w-4" />
						</div>
					</div>
					<div class="mt-2 text-3xl font-extrabold text-gray-900">{{ tile.value }}</div>
					<div class="mt-2 text-sm text-gray-500">{{ tile.sub }}</div>
				</div>
			</div>

			<div v-if="attention.length" class="mb-6 rounded-2xl border border-amber-200 bg-amber-50/60 p-5">
				<h2 class="text-base font-bold text-gray-900">Needs your attention</h2>
				<div class="mt-3 flex flex-wrap gap-3">
					<NuxtLink v-for="a in attention" :key="a.label" :to="a.to"
						class="flex items-center gap-2 rounded-xl border border-amber-100 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 hover:border-amber-300">
						<span class="flex h-6 min-w-6 items-center justify-center rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">{{ a.count }}</span>
						{{ a.label }}
					</NuxtLink>
				</div>
			</div>

			<div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
				<div class="rounded-2xl border border-gray-200 bg-white p-6">
					<h2 class="text-base font-bold text-gray-900">Top Organizers</h2>
					<p class="mt-1 text-sm text-gray-500">Ranked by ticket sales on NaSeRy</p>
					<p v-if="!data.top_organizers.length" class="mt-5 text-sm text-gray-400">No approved organizers yet.</p>
					<div class="mt-5 divide-y divide-gray-100">
						<NuxtLink v-for="org in data.top_organizers" :key="org.id" :to="`/admin/organizers/${org.id}`" class="flex items-center justify-between py-3 hover:bg-gray-50">
							<div class="flex items-center gap-3">
								<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-700 text-xs font-bold text-white">{{ initialsOf(org.name) }}</div>
								<div>
									<div class="text-sm font-semibold text-gray-900">{{ org.name }}</div>
									<div class="text-xs text-gray-500">{{ org.events }} event{{ org.events === 1 ? '' : 's' }} · {{ org.tickets_sold }} tickets<template v-if="org.rating"> · ★ {{ org.rating.toFixed(1) }}</template></div>
								</div>
							</div>
							<span class="text-sm font-bold text-gray-900">{{ peso(org.sales) }}</span>
						</NuxtLink>
					</div>
				</div>

				<div class="rounded-2xl border border-gray-200 bg-white p-6">
					<div class="flex items-start justify-between">
						<div>
							<h2 class="text-base font-bold text-gray-900">Recent Platform Activity</h2>
							<p class="mt-1 text-sm text-gray-500">Latest actions across NaSeRy</p>
						</div>
						<NuxtLink to="/admin/activity-log" class="text-sm font-semibold text-primary-700">View all</NuxtLink>
					</div>
					<p v-if="!data.recent_activity.length" class="mt-5 text-sm text-gray-400">Nothing yet.</p>
					<div class="mt-5 space-y-4">
						<div v-for="(item, i) in data.recent_activity" :key="i" class="flex items-start gap-3">
							<div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="item.actor_type === 'admin' ? 'bg-gray-900 text-white' : 'bg-primary-50 text-primary-700'">
								<IconBase :name="item.actor_type === 'admin' ? 'settings' : 'activity'" class="h-4 w-4" />
							</div>
							<div class="min-w-0">
								<div class="text-sm text-gray-900"><span class="font-semibold">{{ item.actor }}</span> · {{ actionLabel(item.action) }}<template v-if="item.organization"> · {{ item.organization }}</template></div>
								<div class="text-xs text-gray-400">{{ dateTime(item.at) }}</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { AdminOverview } from '~/composables/useAdmin'

definePageMeta({ layout: 'admin' })

const admin = useAdmin()
const data = ref<AdminOverview | null>(null)
const error = ref('')

const tiles = computed(() => {
	const t = data.value!.totals
	const organizers = t.organizers.approved + t.organizers.suspended
	return [
		{ label: 'Organizers', value: organizers, sub: `${t.organizers.approved} active · ${t.organizers.pending + t.organizers.under_review} applying`, icon: 'briefcase', tone: 'bg-primary-50 text-primary-700' },
		{ label: 'Events Managed', value: t.events.total, sub: `${t.events.published + t.events.ongoing} upcoming or live · ${t.events.completed} completed`, icon: 'calendar', tone: 'bg-primary-50 text-primary-700' },
		{ label: 'Platform Fee Earned', value: peso(t.platform_fee), sub: `from ${peso(t.sales)} in ticket sales`, icon: 'cash', tone: 'bg-green-50 text-green-600' },
		{ label: 'Avg. Organizer Rating', value: t.rating.average?.toFixed(1) ?? '—', sub: `${t.rating.count} published review${t.rating.count === 1 ? '' : 's'}`, icon: 'star', tone: 'bg-amber-50 text-amber-600' },
		{ label: 'Clients', value: t.users.client, sub: `${t.users.business} organizer accounts · ${t.users.suspended} suspended`, icon: 'users', tone: 'bg-gray-100 text-gray-600' },
		{ label: 'Tickets Issued', value: t.tickets_sold, sub: `paid, free and invited · ${t.checked_in} checked in`, icon: 'qr-code', tone: 'bg-gray-100 text-gray-600' },
	]
})

const attention = computed(() => {
	const a = data.value!.attention
	return [
		{ label: 'Organizer applications', count: a.applications, to: '/admin/organizers?status=pending' },
		{ label: 'Reported reviews', count: a.reports, to: '/admin/moderation' },
		{ label: 'Refund fee claims', count: a.fee_claims, to: '/admin/moderation?tab=claims' },
		{ label: 'Support invites', count: a.support_invites, to: '/admin/access' },
	].filter(x => x.count > 0)
})

function initialsOf(name: string) {
	return name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

onMounted(async () => {
	try {
		data.value = await admin.overview()
	} catch (e) {
		error.value = authMessage(e)
	}
})
</script>
