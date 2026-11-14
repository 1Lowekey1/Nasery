<template>
	<div>
		<h1 class="text-2xl font-extrabold text-gray-900">Settings</h1>
		<p class="mt-1 text-gray-500">Platform fee, limits, plans and announcements. Every change is logged. Services are on Service Tags.</p>

		<div class="mt-5 flex gap-1 border-b border-gray-200" role="tablist">
			<button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" class="-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold"
				:class="tab === t.key ? 'border-primary-700 text-primary-700' : 'border-transparent text-gray-500 hover:text-gray-800'" @click="setTab(t.key)">{{ t.label }}</button>
		</div>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />

		<!-- Fee & limits -->
		<template v-if="tab === 'platform' && overview">
			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Platform fee</h2>
				<p class="mt-1 text-sm text-gray-500">Taken out of each ticket sale (buyers never pay extra). A change applies to payments from now on; earlier payments keep the fee they were charged.</p>
				<p class="mt-3 text-sm">Now: <b>{{ feeText(overview.fee.current) }}</b> <span class="text-gray-400">since {{ dateTime(overview.fee.current?.from ?? null) }}</span></p>

				<form class="mt-4 grid gap-3 rounded-xl bg-gray-50 p-4 sm:grid-cols-2" @submit.prevent="saveFee">
					<label class="flex items-center gap-2 text-sm font-semibold text-gray-700 sm:col-span-2"><input v-model="fee.enabled" type="checkbox"> Charge a platform fee</label>
					<template v-if="fee.enabled">
						<label class="text-xs font-semibold text-gray-600">Type
							<FormsSelect v-model="fee.model" class="mt-1" :can-clear="false" :options="[{ value: 'percentage', label: 'Percentage' }, { value: 'fixed', label: 'Fixed per payment' }, { value: 'hybrid', label: 'Percentage + fixed' }]" />
						</label>
						<div class="flex gap-3">
							<label v-if="fee.model !== 'fixed'" class="flex-1 text-xs font-semibold text-gray-600">Percent (%)
								<input v-model.number="fee.percent" type="number" min="0" max="20" step="0.1" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
							</label>
							<label v-if="fee.model !== 'percentage'" class="flex-1 text-xs font-semibold text-gray-600">Fixed (₱)
								<input v-model.number="fee.fixed" type="number" min="0" max="1000" step="0.5" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
							</label>
						</div>
					</template>
					<label class="text-xs font-semibold text-gray-600 sm:col-span-2">Reason for the change (logged)
						<input v-model="fee.reason" maxlength="1000" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
					</label>
					<div class="sm:col-span-2"><button type="submit" :disabled="busy || fee.reason.trim().length < 5" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Start new fee now</button></div>
				</form>

				<details class="mt-4 text-sm">
					<summary class="cursor-pointer font-semibold text-gray-700">History ({{ overview.fee.history.length }})</summary>
					<ul class="mt-2 divide-y divide-gray-100">
						<li v-for="f in overview.fee.history" :key="f.id" class="flex flex-wrap justify-between gap-2 py-2">
							<span>{{ feeText(f) }} <span class="text-gray-400">· {{ f.by }}</span></span>
							<span class="text-gray-500">{{ shortDate(f.from) }} → {{ f.to ? shortDate(f.to) : 'now' }}</span>
						</li>
					</ul>
				</details>
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Limits</h2>
				<form class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3" @submit.prevent="saveSettings">
					<label v-for="s in overview.settings" :key="s.key" class="text-sm">
						<span class="font-semibold text-gray-800">{{ s.label }}</span>
						<span class="block text-xs text-gray-500">{{ s.help }} ({{ s.min }}–{{ s.max }} {{ s.unit }})</span>
						<span class="mt-1 flex items-center gap-2">
							<input v-model.number="values[s.key]" type="number" :min="s.min" :max="s.max" class="w-28 rounded-xl border border-gray-200 px-3 py-2 text-sm">
							<span class="text-gray-500">{{ s.unit }}</span>
						</span>
					</label>
					<div class="sm:col-span-2"><button type="submit" :disabled="busy" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save limits</button></div>
				</form>
			</section>
		</template>

		<!-- Plans (B18) -->
		<template v-if="tab === 'plans' && billingData">
			<dl class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
				<div class="rounded-2xl border border-gray-200 bg-white p-4"><dt class="text-xs text-gray-500">Monthly recurring</dt><dd class="text-xl font-extrabold">{{ peso(billingData.mrr) }}</dd></div>
				<div class="rounded-2xl border border-gray-200 bg-white p-4"><dt class="text-xs text-gray-500">Paid this month</dt><dd class="text-xl font-extrabold">{{ peso(billingData.paid_this_month) }}</dd></div>
				<div class="rounded-2xl border border-gray-200 bg-white p-4"><dt class="text-xs text-gray-500">On Free</dt><dd class="text-xl font-extrabold">{{ billingData.free_organizers }}</dd></div>
				<div class="rounded-2xl border border-gray-200 bg-white p-4"><dt class="text-xs text-gray-500">Renewal overdue · cancelling</dt><dd class="text-xl font-extrabold">{{ billingData.in_grace }} · {{ billingData.cancelling }}</dd></div>
			</dl>

			<section v-for="p in billingData.plans" :key="p.id" class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<div class="flex flex-wrap items-baseline justify-between gap-2">
					<h2 class="text-base font-bold text-gray-900">{{ p.name }} <span class="text-sm font-normal text-gray-500">· {{ p.is_free ? 'default for everyone' : subscriberText(p.subscribers ?? 0) }}</span></h2>
					<label v-if="!p.is_free" class="flex items-center gap-2 text-sm"><input v-model="planForms[p.id]!.is_active" type="checkbox"> Available to new subscribers</label>
				</div>
				<form class="mt-4 grid gap-3 sm:grid-cols-3" @submit.prevent="savePlan(p.id)">
					<template v-if="!p.is_free">
						<label class="text-xs font-semibold text-gray-600">Monthly (₱)<input v-model.number="planForms[p.id]!.price_monthly" type="number" min="0" step="1" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm"></label>
						<label class="text-xs font-semibold text-gray-600">Yearly (₱)<input v-model.number="planForms[p.id]!.price_yearly" type="number" min="0" step="1" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm"></label>
						<span class="hidden sm:block" />
					</template>
					<label v-for="(label, key) in limitLabels" :key="key" class="text-xs font-semibold text-gray-600">{{ label.charAt(0).toUpperCase() + label.slice(1) }}
						<input v-model="planForms[p.id]!.limits[key]" type="number" min="0" placeholder="Unlimited" class="mt-1 block w-full rounded-xl border border-gray-200 px-3 py-2 text-sm">
					</label>
					<label class="flex items-center gap-2 text-sm sm:col-span-3"><input v-model="planForms[p.id]!.features.advanced_reports" type="checkbox"> Quarterly analytics &amp; report CSV exports</label>
					<div class="sm:col-span-3"><button type="submit" :disabled="busy" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save {{ p.name }}</button>
						<span class="ml-2 text-xs text-gray-500">Leave a limit empty for unlimited. Current subscribers keep their price until renewal.</span></div>
				</form>
			</section>

			<section class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Recent plan invoices</h2>
				<p v-if="!billingData.recent.length" class="mt-3 text-sm text-gray-400">None yet.</p>
				<ul class="mt-3 divide-y divide-gray-100 text-sm">
					<li v-for="i in billingData.recent" :key="i.id" class="flex flex-wrap justify-between gap-2 py-2">
						<span><b>{{ i.organization }}</b> · {{ i.plan }} {{ i.cycle }}</span>
						<span>{{ peso(i.amount) }} <span class="text-xs" :class="i.status === 'paid' ? 'text-green-700' : 'text-amber-700'">{{ i.status === 'paid' ? 'paid' : 'due' }}</span> <span class="text-xs text-gray-400">{{ shortDate(i.issued_at) }}</span></span>
					</li>
				</ul>
			</section>
		</template>

		<!-- Announcements -->
		<section v-if="tab === 'announcements'" class="mt-5 rounded-2xl border border-gray-200 bg-white p-6">
			<h2 class="text-base font-bold text-gray-900">Announcements</h2>
			<p class="mt-1 text-sm text-gray-500">A banner at the top of NaSeRy for the chosen audience, between the dates you set.</p>
			<form class="mt-4 grid gap-3 rounded-xl bg-gray-50 p-4 sm:grid-cols-2" @submit.prevent="saveAnnouncement">
				<label class="text-xs font-semibold text-gray-600 sm:col-span-2">Title<input v-model="ann.title" maxlength="120" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm"></label>
				<label class="text-xs font-semibold text-gray-600 sm:col-span-2">Message<textarea v-model="ann.body" rows="2" maxlength="1000" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm" /></label>
				<label class="text-xs font-semibold text-gray-600">Who sees it
					<FormsSelect v-model="ann.audience" class="mt-1" :can-clear="false" :options="[{ value: 'all', label: 'Everyone (including visitors)' }, { value: 'organizers', label: 'Organizers' }, { value: 'clients', label: 'Clients' }]" />
				</label>
				<div class="flex gap-3">
					<label class="flex-1 text-xs font-semibold text-gray-600">Starts<input v-model="ann.starts_at" type="datetime-local" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-2 py-2 text-sm"></label>
					<label class="flex-1 text-xs font-semibold text-gray-600">Ends<input v-model="ann.ends_at" type="datetime-local" class="mt-1 block w-full rounded-xl border border-gray-200 bg-white px-2 py-2 text-sm"></label>
				</div>
				<div class="flex gap-2 sm:col-span-2">
					<button type="submit" :disabled="busy || ann.title.trim().length < 3 || ann.body.trim().length < 3" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ ann.id ? 'Save' : 'Post' }}</button>
					<button v-if="ann.id" type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm" @click="resetAnnouncement">Cancel</button>
				</div>
			</form>
			<p v-if="!announcements.length" class="mt-4 text-sm text-gray-400">No announcements.</p>
			<ul class="mt-4 space-y-3">
				<li v-for="a in announcements" :key="a.id" class="rounded-xl border border-gray-100 p-4 text-sm">
					<div class="flex flex-wrap items-start justify-between gap-2">
						<p class="font-semibold text-gray-900">{{ a.title }}</p>
						<span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="stateBadge[a.state]">{{ a.state }}</span>
					</div>
					<p class="mt-1 text-gray-700">{{ a.body }}</p>
					<p class="mt-1 text-xs text-gray-500">{{ audienceLabel[a.audience] }} · {{ dateTime(a.starts_at) }} → {{ a.ends_at ? dateTime(a.ends_at) : 'until removed' }}</p>
					<div class="mt-2 flex gap-3 text-xs font-semibold">
						<button type="button" class="text-primary-700" @click="editAnnouncement(a)">Edit</button>
						<button type="button" class="text-rose-700" @click="removeAnnouncement(a.id)">Remove</button>
					</div>
				</li>
			</ul>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { Announcement, FeeState, FeeVersion, PlatformSetting } from '~/composables/useAdmin'
import type { AdminBilling } from '~/composables/useBilling'
import { limitLabels } from '~/composables/useBilling'

definePageMeta({ layout: 'superadmin' })

type Tab = 'platform' | 'plans' | 'announcements'
const route = useRoute()
const admin = useAdmin()
const tabs: { key: Tab, label: string }[] = [{ key: 'platform', label: 'Fee & limits' }, { key: 'plans', label: 'Plans' }, { key: 'announcements', label: 'Announcements' }]
const tab = ref<Tab>((['platform', 'plans', 'announcements'] as Tab[]).includes(route.query.tab as Tab) ? route.query.tab as Tab : 'platform')
const error = ref('')
const notice = ref('')
const busy = ref(false)

const overview = ref<{ settings: PlatformSetting[], fee: FeeState } | null>(null)
const values = reactive<Record<string, number>>({})
const fee = reactive({ enabled: true, model: 'percentage' as FeeVersion['model'], percent: 1 as number | null, fixed: null as number | null, reason: '' })
const announcements = ref<Announcement[]>([])
const ann = reactive({ id: '', title: '', body: '', audience: 'all', starts_at: '', ends_at: '' })

const audienceLabel = { all: 'Everyone', organizers: 'Organizers', clients: 'Clients' }
const stateBadge = { live: 'bg-green-50 text-green-700', scheduled: 'bg-sky-50 text-sky-700', ended: 'bg-gray-100 text-gray-600' }

function feeText(f: FeeVersion | null) {
	if (!f || !f.enabled) return 'No fee'
	return [f.percent !== null && f.model !== 'fixed' ? `${f.percent}%` : null, f.fixed !== null && f.model !== 'percentage' ? peso(f.fixed) : null].filter(Boolean).join(' + ')
}

// datetime-local (Philippine wall time in the admin's browser) ⇄ ISO
const toLocal = (iso: string | null) => iso ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16) : ''
const toIso = (local: string) => local ? new Date(local).toISOString() : null

async function run(fn: () => Promise<unknown>, message: string) {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		await fn()
		notice.value = message
		return true
	} catch (e) {
		error.value = Object.values(fieldErrors(e))[0] ?? authMessage(e)
		return false
	} finally {
		busy.value = false
	}
}

async function loadPlatform() {
	overview.value = await admin.settings()
	overview.value.settings.forEach((s) => { values[s.key] = s.value })
	const c = overview.value.fee.current
	Object.assign(fee, { enabled: c?.enabled ?? false, model: c?.model ?? 'percentage', percent: c?.percent ?? 1, fixed: c?.fixed ?? null, reason: '' })
}

const saveSettings = () => run(async () => { await admin.updateSettings({ ...values }); await loadPlatform() }, 'Limits saved.')
const saveFee = () => run(async () => {
	await admin.changeFee({ enabled: fee.enabled, model: fee.model, percent: fee.percent, fixed: fee.fixed, reason: fee.reason.trim() })
	await loadPlatform()
}, 'New fee saved. It applies to payments from now on.')

const billingApi = useBilling()
const billingData = ref<AdminBilling | null>(null)
type PlanForm = { price_monthly: number, price_yearly: number, is_active: boolean, limits: Record<string, number | string | null>, features: Record<string, boolean> }
const planForms = reactive<Record<string, PlanForm>>({})
const subscriberText = (n: number) => n === 1 ? '1 subscriber' : n + ' subscribers'

async function loadPlans() {
	billingData.value = await billingApi.adminOverview()
	for (const p of billingData.value.plans) {
		planForms[p.id] = {
			price_monthly: p.price_monthly, price_yearly: p.price_yearly, is_active: p.is_active,
			limits: Object.fromEntries(Object.keys(limitLabels).map(k => [k, p.limits?.[k] ?? null])),
			features: { advanced_reports: !!p.features?.advanced_reports },
		}
	}
}
const savePlan = async (id: string) => {
	const f = planForms[id]!
	const plan = billingData.value!.plans.find(p => p.id === id)!
	const limits = Object.fromEntries(Object.entries(f.limits).map(([k, v]) => [k, v === '' || v === null ? null : Number(v)]))
	const body = plan.is_free ? { limits, features: f.features } : { price_monthly: f.price_monthly, price_yearly: f.price_yearly, is_active: f.is_active, limits, features: f.features }
	if (await run(() => billingApi.updatePlan(id, body), plan.name + ' saved.')) await loadPlans()
}

async function loadAnnouncements() { announcements.value = await admin.announcements() }
function resetAnnouncement() { Object.assign(ann, { id: '', title: '', body: '', audience: 'all', starts_at: '', ends_at: '' }) }
function editAnnouncement(a: Announcement) {
	Object.assign(ann, { id: a.id, title: a.title, body: a.body, audience: a.audience, starts_at: toLocal(a.starts_at), ends_at: toLocal(a.ends_at) })
}
const saveAnnouncement = async () => {
	const body = { title: ann.title.trim(), body: ann.body.trim(), audience: ann.audience, starts_at: toIso(ann.starts_at), ends_at: toIso(ann.ends_at) }
	if (await run(() => admin.saveAnnouncement(body, ann.id || undefined), ann.id ? 'Announcement updated.' : 'Announcement posted.')) {
		resetAnnouncement()
		await loadAnnouncements()
	}
}
const removeAnnouncement = async (id: string) => {
	if ((await confirmDialog('Remove this announcement?', { confirmLabel: 'Remove' })) && await run(() => admin.deleteAnnouncement(id), 'Announcement removed.')) await loadAnnouncements()
}

async function load() {
	error.value = ''
	try {
		await ({ platform: loadPlatform, plans: loadPlans, announcements: loadAnnouncements })[tab.value]()
	} catch (e) {
		error.value = authMessage(e)
	}
}

function setTab(t: Tab) {
	tab.value = t
	notice.value = ''
	navigateTo({ query: { tab: t } }, { replace: true })
	load()
}

onMounted(load)
</script>
