<template>
	<div class="w-full space-y-8">
		<!-- Messages -->
		<Transition name="rise">
			<div v-if="notice || error || checking" class="flex items-start gap-3 rounded-2xl border px-5 py-4 text-sm font-semibold"
				:class="error ? 'border-red-200 bg-red-50 text-red-700' : checking ? 'border-primary-100 bg-primary-50 text-primary-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'">
				<IconBase :name="error ? 'x-circle' : checking ? 'clock' : 'check-circle'" class="mt-0.5 h-5 w-5 shrink-0" />
				<span class="flex-1">{{ error || (checking ? 'Confirming your payment…' : notice) }}</span>
				<button v-if="!checking" type="button" class="text-xs font-bold opacity-60 hover:opacity-100" @click="notice = ''; error = ''">Dismiss</button>
			</div>
		</Transition>

		<div v-if="!data && !error" class="rounded-3xl border border-gray-200 bg-white p-16 text-center text-sm text-gray-500">
			Loading your plan...
		</div>

		<template v-if="data">
			<!-- Renewal due -->
			<section v-if="data.open_invoice" class="flex flex-wrap items-center justify-between gap-3 rounded-3xl border border-amber-300 bg-amber-50 p-6">
				<div>
					<p class="font-bold text-amber-900">Renewal due: {{ peso(data.open_invoice.amount) }} for {{ data.open_invoice.plan }}</p>
					<p class="text-sm text-amber-800">Pay by {{ shortDate(data.open_invoice.due_by) }} to keep your plan. After that you move to Free; nothing you made is removed.</p>
				</div>
				<button v-if="data.can.manage" type="button" :disabled="busy" class="rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50"
					@click="choose(data.open_invoice.plan_slug, data.open_invoice.cycle)">Pay renewal</button>
			</section>

			<!-- Switch waiting for the period end -->
			<section v-if="data.scheduled_change" class="flex flex-wrap items-center justify-between gap-3 rounded-3xl border border-primary-100 bg-primary-50 p-6">
				<p class="text-sm text-primary-900">You're switching to <b>{{ data.scheduled_change.plan }} ({{ data.scheduled_change.cycle }})</b> on {{ shortDate(data.scheduled_change.starts_at) }}. You'll pay {{ peso(data.scheduled_change.price) }} then; until then you keep {{ data.plan.name }}.</p>
				<button v-if="data.can.manage" type="button" :disabled="busy" class="rounded-xl border border-primary-200 bg-white px-4 py-2 text-sm font-bold text-primary-800" @click="resume">Keep {{ data.plan.name }} instead</button>
			</section>

			<!-- Hero -->
			<section class="hero fade-up relative overflow-hidden rounded-[2rem] px-6 py-8 text-white shadow-xl shadow-primary-900/10 sm:px-10 sm:py-10" style="--d: 0ms">
				<span class="orb orb-a" />
				<span class="orb orb-b" />
				<span class="grid-sheen" />

				<div class="relative grid gap-8 lg:grid-cols-5 lg:items-center">
					<div class="lg:col-span-2">
						<div class="inline-flex items-center gap-2 rounded-full bg-white/12 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-white/85 ring-1 ring-white/20 backdrop-blur">
							<IconBase name="sparkles" class="h-3.5 w-3.5" />
							Plan &amp; Billing
						</div>
						<p class="mt-6 text-sm font-semibold text-white/65">Your current plan</p>
						<div class="mt-1 flex flex-wrap items-end gap-3">
							<h1 class="text-4xl font-extrabold tracking-tight sm:text-6xl">{{ data.plan.name }}</h1>
							<span v-if="data.subscription" class="mb-2 rounded-full bg-white/15 px-3 py-1 text-xs font-bold capitalize ring-1 ring-white/25">{{ data.subscription.cycle }}</span>
						</div>
						<template v-if="data.subscription">
							<p class="mt-4 text-sm leading-6 text-white/80">
								<template v-if="data.subscription.status === 'grace_period'">Renewal overdue.</template>
								<template v-else-if="data.scheduled_change">Switches on <span class="font-bold text-white">{{ shortDate(data.subscription.period_end) }}</span>.</template>
								<template v-else-if="data.subscription.cancel_at_period_end">Ends <span class="font-bold text-white">{{ shortDate(data.subscription.period_end) }}</span>, then Free.</template>
								<template v-else>Renews <span class="font-bold text-white">{{ shortDate(data.subscription.period_end) }}</span></template>
								<span class="text-white/60"> · {{ daysLeft }} day{{ daysLeft === 1 ? '' : 's' }} left</span>
							</p>
							<div class="mt-3 h-2 w-full max-w-sm overflow-hidden rounded-full bg-white/15">
								<div class="bar-fill h-full rounded-full bg-gradient-to-r from-amber-300 to-white" :style="{ width: periodProgress + '%' }" />
							</div>
							<div v-if="data.can.manage && data.subscription.status === 'active'" class="mt-4">
								<button v-if="!data.subscription.cancel_at_period_end" type="button" :disabled="busy" class="text-xs font-bold text-white/70 underline hover:text-white" @click="cancel">Cancel plan</button>
								<button v-else-if="!data.scheduled_change" type="button" :disabled="busy" class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-primary-800" @click="resume">Keep my plan (resume)</button>
							</div>
						</template>
						<template v-else>
							<p class="mt-4 max-w-md text-sm leading-6 text-white/80">Free forever. Upgrade when you need more events, a bigger team or CSV exports. Everything you create stays yours, whatever the plan.</p>
							<button type="button" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-bold text-primary-800 shadow-lg shadow-black/10 transition hover:-translate-y-0.5 hover:shadow-xl" @click="scrollToPlans">
								See plans <IconBase name="arrow-right" class="h-4 w-4" />
							</button>
						</template>
						<p v-if="!data.can.manage" class="mt-3 text-xs text-white/60">Only an owner can change the plan.</p>
					</div>

					<!-- Usage rings -->
					<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-3">
						<div v-for="(item, index) in usageItems" :key="item.key"
							class="fade-up rounded-2xl bg-white/10 p-5 ring-1 ring-white/20 backdrop-blur-md transition hover:bg-white/15"
							:style="{ '--d': 120 + index * 90 + 'ms' }">
							<div class="flex items-center gap-4 sm:flex-col sm:items-start">
								<div class="relative h-[72px] w-[72px] shrink-0">
									<svg viewBox="0 0 64 64" class="h-full w-full -rotate-90">
										<circle cx="32" cy="32" r="26" fill="none" stroke="rgba(255,255,255,.18)" stroke-width="6" />
										<circle cx="32" cy="32" r="26" fill="none" :stroke="item.color" stroke-width="6" stroke-linecap="round"
											:stroke-dasharray="CIRCUMFERENCE" :stroke-dashoffset="ringsReady ? item.offset : CIRCUMFERENCE" class="ring-stroke" />
									</svg>
									<span class="absolute inset-0 flex items-center justify-center text-base font-extrabold">{{ item.unlimited ? '∞' : item.used }}</span>
								</div>
								<div>
									<p class="text-sm font-bold">{{ item.label }}</p>
									<p class="mt-0.5 text-xs text-white/65">{{ item.unlimited ? `${item.used} used · unlimited` : `${item.used} of ${item.limit} used` }}</p>
									<p v-if="item.full" class="mt-1.5 inline-block rounded-full bg-red-400/25 px-2 py-0.5 text-[11px] font-bold text-red-100">Limit reached</p>
									<p v-else-if="item.warn" class="mt-1.5 inline-block rounded-full bg-amber-300/25 px-2 py-0.5 text-[11px] font-bold text-amber-100">Almost full</p>
								</div>
							</div>
						</div>
					</div>
				</div>
			</section>

			<!-- Confirm a plan choice with the exact numbers -->
			<section v-if="pendingQuote" ref="quotePanel" class="rounded-3xl border-2 border-primary-300 bg-white p-6" aria-live="polite">
				<p class="text-lg font-extrabold text-gray-950">{{ pendingQuote.plan }} ({{ pendingQuote.cycle }})</p>
				<p class="mt-1 text-sm text-gray-700">{{ pendingQuote.message }}</p>
				<dl v-if="pendingQuote.kind === 'upgrade'" class="mt-3 grid max-w-sm grid-cols-2 gap-1 text-sm">
					<dt class="text-gray-500">Price</dt><dd class="text-right">{{ peso(pendingQuote.price) }}</dd>
					<dt class="text-gray-500">Credit for unused days</dt><dd class="text-right text-green-700">−{{ peso(pendingQuote.credit) }}</dd>
					<dt class="font-bold text-gray-900">Pay now</dt><dd class="text-right font-bold">{{ peso(pendingQuote.due_now) }}</dd>
				</dl>
				<div class="mt-4 flex gap-2">
					<button type="button" :disabled="busy" class="rounded-xl bg-primary-700 px-5 py-2.5 text-sm font-bold text-white disabled:opacity-50" @click="confirmChoice">
						{{ pendingQuote.kind === 'scheduled' ? 'Schedule the switch' : `Continue to pay ${peso(pendingQuote.due_now)}` }}
					</button>
					<button type="button" class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-semibold" @click="pendingQuote = null">Cancel</button>
				</div>
			</section>

			<!-- Plans -->
			<section ref="plansRef" class="scroll-mt-6">
				<div class="fade-up flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between" style="--d: 80ms">
					<div>
						<h2 class="text-2xl font-extrabold tracking-tight text-gray-950">Choose your plan</h2>
						<p class="mt-1 text-sm text-gray-500">Upgrade any time: unused days of your current plan are credited toward the new one.</p>
					</div>
					<div class="relative inline-grid grid-cols-2 rounded-full bg-gray-100 p-1 text-sm font-bold">
						<span class="toggle-pill absolute inset-y-1 left-1 w-[calc(50%-4px)] rounded-full bg-primary-700 shadow-md" :class="cycle === 'yearly' ? 'translate-x-full' : ''" />
						<button type="button" class="relative z-10 rounded-full px-5 py-2 transition-colors" :class="cycle === 'monthly' ? 'text-white' : 'text-gray-600'" @click="cycle = 'monthly'">Monthly</button>
						<button type="button" class="relative z-10 flex items-center justify-center gap-2 rounded-full px-5 py-2 transition-colors" :class="cycle === 'yearly' ? 'text-white' : 'text-gray-600'" @click="cycle = 'yearly'">
							Yearly
							<span v-if="yearlySavings" class="rounded-full px-2 py-0.5 text-[11px]" :class="cycle === 'yearly' ? 'bg-amber-300 text-amber-950' : 'bg-amber-100 text-amber-800'">Save {{ yearlySavings }}%</span>
						</button>
					</div>
				</div>

				<div class="mt-6 grid grid-cols-1 gap-6 md:grid-cols-3">
					<article v-for="(p, index) in data.plans" :key="p.id"
						class="plan-card fade-up relative flex flex-col rounded-3xl p-7"
						:class="p.slug === featuredSlug ? 'featured' : 'border border-gray-200 bg-white shadow-sm'"
						:style="{ '--d': 160 + index * 100 + 'ms' }">
						<span v-if="p.slug === featuredSlug" class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-gradient-to-r from-amber-400 to-amber-300 px-4 py-1 text-[11px] font-extrabold uppercase tracking-[0.14em] text-amber-950 shadow-lg shadow-amber-500/25">Most popular</span>
						<div class="flex items-center justify-between">
							<h3 class="text-lg font-extrabold text-gray-950">{{ p.name }}</h3>
							<span v-if="p.slug === data.plan.slug" class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2.5 py-1 text-[11px] font-bold text-primary-700"><span class="h-1.5 w-1.5 rounded-full bg-primary-700" />Current</span>
						</div>
						<div class="mt-6 flex items-end gap-1.5">
							<span class="text-4xl font-extrabold tracking-tight text-gray-950 sm:text-5xl">{{ p.is_free ? '₱0' : peso(cycle === 'yearly' ? p.price_yearly : p.price_monthly) }}</span>
							<span class="mb-1.5 text-sm font-semibold text-gray-400">/ {{ cycle === 'yearly' ? 'year' : 'month' }}</span>
						</div>
						<p class="mt-1 h-5 text-xs font-semibold text-gray-400">
							<template v-if="p.is_free">Free forever</template>
							<template v-else-if="cycle === 'yearly'">That's {{ peso(Number(p.price_yearly) / 12) }} a month</template>
							<template v-else>Billed monthly</template>
						</p>
						<ul class="mt-6 flex-1 space-y-3 text-sm">
							<li v-for="(label, key) in limitLabels" :key="key" class="flex items-start gap-2.5 text-gray-700">
								<svg viewBox="0 0 20 20" class="mt-0.5 h-[18px] w-[18px] shrink-0 text-primary-700" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
								<span><span class="font-bold text-gray-950">{{ p.limits?.[key] === null || p.limits?.[key] === undefined ? 'Unlimited' : p.limits[key] }}</span> {{ label }}</span>
							</li>
							<li class="flex items-start gap-2.5" :class="p.features?.advanced_reports ? 'text-gray-700' : 'text-gray-400'">
								<svg v-if="p.features?.advanced_reports" viewBox="0 0 20 20" class="mt-0.5 h-[18px] w-[18px] shrink-0 text-primary-700" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
								<span v-else class="mt-0.5 flex h-[18px] w-[18px] shrink-0 items-center justify-center text-gray-300">—</span>
								<span>Quarterly analytics &amp; CSV exports</span>
							</li>
						</ul>
						<div class="mt-8">
							<div v-if="p.slug === data.plan.slug && (p.is_free || data.subscription?.cycle === cycle)" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary-50 px-5 py-3 text-sm font-bold text-primary-700">
								<IconBase name="check-circle" class="h-4 w-4" /> Your plan
							</div>
							<div v-else-if="p.is_free" class="rounded-xl bg-gray-50 px-4 py-3 text-center text-xs font-medium text-gray-500">Cancel your paid plan to return to Free.</div>
							<button v-else-if="data.can.manage" type="button"
								class="cta group flex w-full items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold transition duration-200 disabled:opacity-50"
								:class="p.slug === featuredSlug ? 'bg-gradient-to-r from-primary-700 to-[#3b8693] text-white shadow-lg shadow-primary-700/25 hover:-translate-y-0.5 hover:shadow-xl hover:shadow-primary-700/30' : 'border-2 border-primary-700 bg-white text-primary-700 hover:bg-primary-700 hover:text-white'"
								:disabled="busy" @click="choose(p.slug, cycle)">
								{{ data.scheduled_change?.plan_slug === p.slug && data.scheduled_change.cycle === cycle ? 'Scheduled' : p.slug === data.plan.slug ? `Switch to ${cycle}` : `Choose ${p.name}` }}
								<IconBase name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" />
							</button>
						</div>
					</article>
				</div>

				<p class="mt-4 flex items-start gap-2 text-xs text-gray-400">
					<IconBase name="shield-check" class="mt-px h-4 w-4 shrink-0" />
					<span><template v-if="data.payment_mode === 'fake'">Demo mode: payments use NaSeRy's test checkout, no real money moves. </template><template v-else-if="data.payment_mode === 'test'">Test mode: payments go through PayMongo's test checkout, no real money moves. </template>Upgrades start right away with the unused days of your current plan credited; downgrades start when your current period ends.</span>
				</p>
			</section>

			<!-- Invoices -->
			<section class="fade-up rounded-3xl border border-gray-200 bg-white p-6 shadow-sm sm:p-8" style="--d: 200ms">
				<h2 class="text-xl font-extrabold tracking-tight text-gray-950">Invoices</h2>
				<p class="mt-1 text-sm text-gray-500">Every plan payment, newest first</p>
				<p v-if="!data.invoices.length" class="mt-5 rounded-xl border border-dashed border-gray-300 py-10 text-center text-sm text-gray-400">No invoices yet.</p>
				<div v-else class="mt-5 overflow-x-auto">
					<table class="w-full text-left text-sm">
						<thead>
							<tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
								<th class="pb-3 pr-4">Plan</th><th class="pb-3 pr-4">Period</th><th class="pb-3 pr-4 text-right">Amount</th><th class="pb-3 pl-4">Status</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="i in data.invoices" :key="i.id" class="transition hover:bg-gray-50/70">
								<td class="py-4 pr-4 font-bold text-gray-900">{{ i.plan }} <span class="font-medium capitalize text-gray-400">{{ i.cycle }}</span></td>
								<td class="py-4 pr-4 text-gray-600">{{ shortDate(i.period_start) }} – {{ shortDate(i.period_end) }}</td>
								<td class="py-4 pr-4 text-right">
									<div class="font-bold text-gray-900">{{ peso(i.amount) }}</div>
									<div v-if="i.credit" class="text-xs text-gray-400">{{ peso(i.list_price!) }} − {{ peso(i.credit) }} credit</div>
								</td>
								<td class="py-4 pl-4">
									<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold" :class="i.status === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">
										<span class="h-1.5 w-1.5 rounded-full bg-current" />{{ i.status === 'paid' ? 'Paid' : 'Due' }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { BillingOverview, PlanQuote } from '~/composables/useBilling'
import { limitLabels } from '~/composables/useBilling'

definePageMeta({ layout: 'dashboard' })

const route = useRoute()
const { organization, fetchOrganization } = useOrganization()
const billing = useBilling()
const payments = usePayments()
const data = ref<BillingOverview | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const checking = ref(false)
const cycle = ref<'monthly' | 'yearly'>('monthly')
const pendingQuote = ref<PlanQuote | null>(null)
const quotePanel = ref<HTMLElement | null>(null)

async function orgId() {
	return (organization.value ?? await fetchOrganization())!.id
}

async function load() {
	try {
		data.value = await billing.overview(await orgId())
		if (data.value.subscription) cycle.value = data.value.subscription.cycle
	} catch (e) {
		error.value = authMessage(e)
	}
}

async function run(fn: () => Promise<BillingOverview>, message: string) {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		data.value = await fn()
		notice.value = message
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

/** First show what the choice costs (credit, or a switch at period end), then act. */
async function choose(plan: string, c: 'monthly' | 'yearly') {
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		pendingQuote.value = await billing.quote(await orgId(), plan, c)
		// Renewals are already explained by the banner: go straight to payment.
		if (pendingQuote.value.kind === 'renewal') await confirmChoice()
		// The plan cards sit below the fold: bring the quote into view.
		else nextTick(() => scrollToElement(quotePanel.value, { block: 'center' }))
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

async function confirmChoice() {
	const q = pendingQuote.value
	if (!q) return
	busy.value = true
	error.value = ''
	try {
		const result = await billing.checkout(await orgId(), q.plan_slug, q.cycle)
		if (result.checkout_url) {
			window.location.href = result.checkout_url
			return
		}
		pendingQuote.value = null
		notice.value = result.scheduled ? q.message : 'Your new plan is active.'
		await load()
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

const cancel = async () => {
	if (await confirmDialog('Cancel your plan? You keep it until the end of this period, then move to Free. Nothing you made is removed.', { confirmLabel: 'Cancel plan', cancelLabel: 'Keep my plan' })) {
		await run(async () => billing.cancel(await orgId()), 'Your plan ends at the close of this period.')
	}
}
const resume = async () => run(async () => billing.resume(await orgId()), data.value?.scheduled_change ? `You're staying on ${data.value.plan.name}.` : 'Your plan will renew as usual.')

/** Back from the checkout page: tell the owner what happened. */
async function afterCheckout(paymentId: string) {
	if (route.query.cancelled) {
		notice.value = 'Checkout cancelled. Your plan is unchanged.'
		return
	}
	if (route.query.failed) {
		error.value = 'The payment was declined. Your plan is unchanged; you can try again.'
		return
	}
	checking.value = true
	try {
		for (let i = 0; i < 5; i++) {
			const state = await payments.status(paymentId)
			if (state.status === 'paid') {
				notice.value = 'Payment received. Your new plan is active.'
				return
			}
			await new Promise(r => setTimeout(r, 1500))
		}
		notice.value = 'Your payment is still being confirmed. This page updates when it arrives.'
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		checking.value = false
	}
}

onMounted(async () => {
	if (typeof route.query.payment === 'string') await afterCheckout(route.query.payment)
	await load()
	if (route.query.payment) navigateTo({ query: {} }, { replace: true })
})

// ---------- Redesign helpers ----------
const CIRCUMFERENCE = 2 * Math.PI * 26
const ringsReady = ref(false)
watch(data, (value) => { if (value) setTimeout(() => { ringsReady.value = true }, 150) })
const plansRef = ref<HTMLElement | null>(null)
function scrollToPlans() { scrollToElement(plansRef.value) }
// The most capable paid plan gets the "Most popular" ribbon.
const featuredSlug = computed(() => [...(data.value?.plans ?? [])].filter(p => !p.is_free).sort((a, b) => Number(b.price_monthly) - Number(a.price_monthly))[0]?.slug ?? null)
const yearlySavings = computed(() => {
	const p = data.value?.plans.find(x => !x.is_free && Number(x.price_monthly) > 0)
	return p ? Math.max(0, Math.round((1 - Number(p.price_yearly) / (Number(p.price_monthly) * 12)) * 100)) : 0
})
const daysLeft = computed(() => {
	const end = data.value?.subscription?.period_end
	return end ? Math.max(0, Math.ceil((new Date(end).getTime() - Date.now()) / 86400000)) : 0
})
const periodProgress = computed(() => {
	const s = data.value?.subscription
	if (!s) return 0
	const start = new Date(s.period_start).getTime()
	const total = new Date(s.period_end).getTime() - start
	return total > 0 ? Math.min(100, Math.max(0, ((Date.now() - start) / total) * 100)) : 0
})
// Counted limits as rings (history-type limits have no "used" number).
const usageItems = computed(() => (data.value?.usage ?? []).filter(u => u.used !== null).slice(0, 4).map((u) => {
	const used = u.used ?? 0
	const unlimited = u.limit === null
	const ratio = unlimited ? (used ? 0.12 : 0) : Math.min(1, used / Math.max(1, u.limit!))
	const full = !unlimited && used >= u.limit!
	const warn = !unlimited && !full && ratio >= 0.8
	return {
		key: u.key, label: u.label.charAt(0).toUpperCase() + u.label.slice(1), used, limit: u.limit, unlimited, full, warn,
		color: full ? '#fca5a5' : warn ? '#fcd34d' : '#ffffff',
		offset: CIRCUMFERENCE * (1 - ratio),
	}
}))
</script>

<style scoped>
.hero {
	background: linear-gradient(135deg, #1b434b 0%, #285f6b 55%, #3b8693 100%);
}

.orb {
	position: absolute;
	border-radius: 9999px;
	filter: blur(60px);
	pointer-events: none;
}

.orb-a {
	top: -120px;
	right: 8%;
	height: 340px;
	width: 340px;
	background: rgba(251, 191, 36, 0.28);
	animation: drift 14s ease-in-out infinite;
}

.orb-b {
	bottom: -140px;
	left: -60px;
	height: 300px;
	width: 300px;
	background: rgba(125, 211, 225, 0.3);
	animation: drift 18s ease-in-out infinite reverse;
}

.grid-sheen {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.14) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
	-webkit-mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
}

.ring-stroke {
	transition: stroke-dashoffset 1.2s cubic-bezier(0.22, 1, 0.36, 1) 0.25s;
}

.bar-fill {
	animation: grow 1.1s cubic-bezier(0.22, 1, 0.36, 1) both;
	transform-origin: left;
}

.toggle-pill {
	transition: transform 0.3s cubic-bezier(0.34, 1.3, 0.64, 1);
}

.plan-card {
	transition: transform 0.25s ease, box-shadow 0.25s ease;
}

.plan-card:hover {
	transform: translateY(-6px);
	box-shadow: 0 24px 48px -20px rgba(40, 95, 107, 0.35);
}

.plan-card.featured {
	background:
		linear-gradient(#fff, #fff) padding-box,
		linear-gradient(135deg, #285f6b, #7dd3e1 45%, #fbbf24 75%, #285f6b) border-box;
	background-size: 100% 100%, 250% 250%;
	border: 2px solid transparent;
	box-shadow: 0 20px 44px -22px rgba(40, 95, 107, 0.45);
	animation: sheen 7s linear infinite;
}

.fade-up {
	animation: fade-up 0.6s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.dialog {
	animation: pop 0.28s cubic-bezier(0.34, 1.4, 0.64, 1) both;
}

.rise-enter-active, .rise-leave-active, .fade-enter-active, .fade-leave-active {
	transition: opacity 0.25s ease, transform 0.25s ease;
}

.rise-enter-from, .rise-leave-to {
	opacity: 0;
	transform: translateY(-8px);
}

.fade-enter-from, .fade-leave-to {
	opacity: 0;
}

@keyframes fade-up {
	from { opacity: 0; transform: translateY(18px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes drift {
	0%, 100% { transform: translate(0, 0) scale(1); }
	50% { transform: translate(-24px, 18px) scale(1.08); }
}

@keyframes grow {
	from { transform: scaleX(0); }
	to { transform: scaleX(1); }
}

@keyframes sheen {
	0% { background-position: 0 0, 0% 50%; }
	100% { background-position: 0 0, 250% 50%; }
}

@keyframes pop {
	from { opacity: 0; transform: translateY(12px) scale(0.96); }
	to { opacity: 1; transform: translateY(0) scale(1); }
}

@media (prefers-reduced-motion: reduce) {
	.orb, .plan-card.featured, .fade-up, .bar-fill, .dialog { animation: none; }
	.ring-stroke, .toggle-pill, .plan-card { transition: none; }
}
</style>
