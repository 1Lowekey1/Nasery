<template>
	<div class="mx-auto max-w-5xl">
		<h1 class="text-2xl font-extrabold text-gray-900">Plan &amp; Billing</h1>
		<p class="mt-1 text-gray-500">Your NaSeRy plan, what it includes, and what you've used. Everything you create stays yours, whatever the plan.</p>

		<Alert v-if="error" type="danger" :text="error" class="mt-4" />
		<Alert v-if="notice" type="success" :text="notice" class="mt-4" />
		<div v-if="checking" class="mt-4 rounded-xl bg-sky-50 p-3 text-sm text-sky-900">Confirming your payment…</div>
		<div v-if="!data && !error" class="py-12 text-center text-sm text-gray-400">Loading…</div>

		<template v-if="data">
			<!-- Renewal due -->
			<section v-if="data.open_invoice" class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-5">
				<div>
					<p class="font-bold text-amber-900">Renewal due: {{ peso(data.open_invoice.amount) }} for {{ data.open_invoice.plan }}</p>
					<p class="text-sm text-amber-800">Pay by {{ shortDate(data.open_invoice.due_by) }} to keep your plan. After that you move to Free; nothing you made is removed.</p>
				</div>
				<button v-if="data.can.manage" type="button" :disabled="busy" class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
					@click="choose(data.open_invoice.plan_slug, data.open_invoice.cycle)">Pay renewal</button>
			</section>

			<!-- Switch waiting for the period end -->
			<section v-if="data.scheduled_change" class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-5">
				<p class="text-sm text-sky-900">You're switching to <b>{{ data.scheduled_change.plan }} ({{ data.scheduled_change.cycle }})</b> on {{ shortDate(data.scheduled_change.starts_at) }}. You'll pay {{ peso(data.scheduled_change.price) }} then; until then you keep {{ data.plan.name }}.</p>
				<button v-if="data.can.manage" type="button" :disabled="busy" class="rounded-xl border border-sky-300 bg-white px-4 py-2 text-sm font-semibold text-sky-900" @click="resume">Keep {{ data.plan.name }} instead</button>
			</section>

			<!-- Confirm a plan choice with the exact numbers -->
			<section v-if="pendingQuote" ref="quotePanel" class="mt-5 rounded-2xl border-2 border-primary-300 bg-white p-5" aria-live="polite">
				<p class="font-bold text-gray-900">{{ pendingQuote.plan }} ({{ pendingQuote.cycle }})</p>
				<p class="mt-1 text-sm text-gray-700">{{ pendingQuote.message }}</p>
				<dl v-if="pendingQuote.kind === 'upgrade'" class="mt-3 grid max-w-sm grid-cols-2 gap-1 text-sm">
					<dt class="text-gray-500">Price</dt><dd class="text-right">{{ peso(pendingQuote.price) }}</dd>
					<dt class="text-gray-500">Credit for unused days</dt><dd class="text-right text-green-700">−{{ peso(pendingQuote.credit) }}</dd>
					<dt class="font-bold text-gray-900">Pay now</dt><dd class="text-right font-bold">{{ peso(pendingQuote.due_now) }}</dd>
				</dl>
				<div class="mt-4 flex gap-2">
					<button type="button" :disabled="busy" class="rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="confirmChoice">
						{{ pendingQuote.kind === 'scheduled' ? 'Schedule the switch' : `Continue to pay ${peso(pendingQuote.due_now)}` }}
					</button>
					<button type="button" class="rounded-xl border border-gray-200 px-4 py-2 text-sm" @click="pendingQuote = null">Cancel</button>
				</div>
			</section>

			<!-- Current plan -->
			<section class="mt-5 grid gap-5 rounded-2xl border border-gray-200 bg-white p-6 md:grid-cols-[1fr_1.4fr]">
				<div>
					<p class="text-xs font-bold uppercase tracking-wide text-gray-400">Current plan</p>
					<p class="mt-1 text-3xl font-extrabold text-gray-900">{{ data.plan.name }}</p>
					<p v-if="data.subscription" class="mt-1 text-sm text-gray-600">
						{{ data.subscription.cycle === 'yearly' ? 'Yearly' : 'Monthly' }} ·
						<template v-if="data.subscription.status === 'grace_period'">renewal overdue</template>
						<template v-else-if="data.scheduled_change">switches {{ shortDate(data.subscription.period_end) }}</template>
						<template v-else-if="data.subscription.cancel_at_period_end">ends {{ shortDate(data.subscription.period_end) }}, then Free</template>
						<template v-else>renews {{ shortDate(data.subscription.period_end) }}</template>
					</p>
					<p v-else class="mt-1 text-sm text-gray-600">Free forever. Upgrade when you need more room.</p>
					<div v-if="data.can.manage && data.subscription?.status === 'active'" class="mt-3">
						<button v-if="!data.subscription.cancel_at_period_end" type="button" :disabled="busy" class="text-sm font-semibold text-rose-700" @click="cancel">Cancel plan</button>
						<button v-else-if="!data.scheduled_change" type="button" :disabled="busy" class="text-sm font-semibold text-primary-700" @click="resume">Keep my plan (resume)</button>
					</div>
					<p v-if="!data.can.manage" class="mt-3 text-xs text-gray-500">Only an owner can change the plan.</p>
					<ul class="mt-4 space-y-1 text-sm">
						<li v-for="f in data.features" :key="f.key" :class="f.included ? 'text-gray-800' : 'text-gray-400'">{{ f.included ? '✓' : '—' }} {{ f.label }}</li>
					</ul>
				</div>
				<ul class="space-y-3" aria-label="Usage">
					<li v-for="u in data.usage" :key="u.key">
						<div class="flex items-baseline justify-between gap-2 text-sm">
							<span class="font-semibold text-gray-800">{{ u.label.charAt(0).toUpperCase() + u.label.slice(1) }}</span>
							<span class="text-gray-600">
								<template v-if="u.used === null">{{ u.limit === null ? 'Full history' : `Last ${u.limit} months` }}</template>
								<template v-else>{{ u.used }} of {{ u.limit === null ? 'unlimited' : u.limit }}</template>
							</span>
						</div>
						<div v-if="u.used !== null && u.limit !== null" class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100">
							<div class="h-full rounded-full" :class="u.used >= u.limit ? 'bg-rose-500' : u.used / u.limit >= 0.8 ? 'bg-amber-500' : 'bg-primary-600'"
								:style="{ width: `${Math.min(100, (u.used / u.limit) * 100)}%` }" />
						</div>
						<p class="text-xs text-gray-400">{{ u.hint }}</p>
					</li>
				</ul>
			</section>

			<!-- Plans -->
			<div class="mt-6 flex flex-wrap items-center justify-between gap-3">
				<h2 class="text-lg font-bold text-gray-900">Plans</h2>
				<div class="inline-flex rounded-xl border border-gray-200 bg-white p-1" role="group" aria-label="Billing cycle">
					<button v-for="c in (['monthly', 'yearly'] as const)" :key="c" type="button" :aria-pressed="cycle === c"
						class="rounded-lg px-3 py-1.5 text-sm font-semibold" :class="cycle === c ? 'bg-gray-900 text-white' : 'text-gray-600'" @click="cycle = c">
						{{ c === 'monthly' ? 'Monthly' : 'Yearly (save ~17%)' }}
					</button>
				</div>
			</div>
			<div class="mt-3 grid gap-4 md:grid-cols-3">
				<article v-for="p in data.plans" :key="p.id" class="flex flex-col rounded-2xl border bg-white p-5"
					:class="p.slug === data.plan.slug ? 'border-primary-500 ring-2 ring-primary-500/15' : 'border-gray-200'">
					<p class="text-lg font-bold text-gray-900">{{ p.name }}</p>
					<p class="mt-1 text-2xl font-extrabold text-gray-900">
						{{ p.is_free ? '₱0' : peso(cycle === 'yearly' ? p.price_yearly : p.price_monthly) }}
						<span class="text-sm font-normal text-gray-500">/{{ cycle === 'yearly' ? 'year' : 'month' }}</span>
					</p>
					<ul class="mt-3 flex-1 space-y-1 text-sm text-gray-700">
						<li v-for="(label, key) in limitLabels" :key="key">
							<b>{{ p.limits?.[key] === null || p.limits?.[key] === undefined ? 'Unlimited' : p.limits[key] }}</b> {{ label }}
						</li>
						<li :class="p.features?.advanced_reports ? '' : 'text-gray-400'">{{ p.features?.advanced_reports ? '✓' : '—' }} Quarterly analytics &amp; CSV exports</li>
					</ul>
					<p v-if="p.slug === data.plan.slug && (p.is_free || data.subscription?.cycle === cycle)" class="mt-4 rounded-xl bg-gray-100 py-2 text-center text-sm font-semibold text-gray-600">Your plan</p>
					<p v-else-if="p.is_free" class="mt-4 py-2 text-center text-xs text-gray-500">Cancel your paid plan to return to Free.</p>
					<button v-else-if="data.can.manage" type="button" :disabled="busy" class="mt-4 rounded-xl bg-primary-700 py-2 text-sm font-semibold text-white disabled:opacity-50" @click="choose(p.slug, cycle)">
						{{ data.scheduled_change?.plan_slug === p.slug && data.scheduled_change.cycle === cycle ? 'Scheduled' : p.slug === data.plan.slug ? `Switch to ${cycle}` : `Choose ${p.name}` }}
					</button>
				</article>
			</div>
			<p class="mt-2 text-xs text-gray-500"><template v-if="data.payment_mode === 'fake'">Demo mode: payments use NaSeRy's test checkout, no real money moves. </template><template v-else-if="data.payment_mode === 'test'">Test mode: payments go through PayMongo's test checkout, no real money moves. </template>Upgrades start right away with the unused days of your current plan credited; downgrades start when your current period ends.</p>

			<!-- Invoices -->
			<section class="mt-6 rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="text-base font-bold text-gray-900">Invoices</h2>
				<p v-if="!data.invoices.length" class="mt-3 text-sm text-gray-400">No invoices yet.</p>
				<div v-else class="mt-3 overflow-x-auto">
					<table class="w-full text-left text-sm">
						<thead><tr class="border-b border-gray-100 text-xs font-semibold uppercase tracking-wide text-gray-400">
							<th class="pb-2 pr-4">Plan</th><th class="pb-2 pr-4">Period</th><th class="pb-2 pr-4 text-right">Amount</th><th class="pb-2">Status</th>
						</tr></thead>
						<tbody class="divide-y divide-gray-100">
							<tr v-for="i in data.invoices" :key="i.id">
								<td class="py-2.5 pr-4 font-semibold text-gray-900">{{ i.plan }} <span class="font-normal text-gray-400">{{ i.cycle }}</span></td>
								<td class="py-2.5 pr-4 text-gray-600">{{ shortDate(i.period_start) }} – {{ shortDate(i.period_end) }}</td>
								<td class="py-2.5 pr-4 text-right text-gray-900">{{ peso(i.amount) }}<span v-if="i.credit" class="block text-xs text-green-700">{{ peso(i.list_price!) }} − {{ peso(i.credit) }} credit</span></td>
								<td class="py-2.5"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="i.status === 'paid' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-800'">{{ i.status === 'paid' ? 'Paid' : 'Due' }}</span></td>
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
		else nextTick(() => quotePanel.value?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
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
	if (confirm('Cancel your plan? You keep it until the end of this period, then move to Free. Nothing you made is removed.')) {
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
</script>
