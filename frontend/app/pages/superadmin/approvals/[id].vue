<template>
	<div>
		<NuxtLink to="/superadmin/approvals" class="mb-4 inline-block text-sm font-semibold text-primary-600">&larr; All organizers</NuxtLink>

		<Alert v-if="error" type="danger" :text="error" />
		<p v-if="notice" class="mb-4 rounded-xl border border-green-100 bg-green-50 px-3 py-2 text-sm text-green-800">{{ notice }}</p>

		<div v-if="!app && !error" class="py-12 text-center text-gray-400">Loading…</div>

		<template v-if="app">
			<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
				<div>
					<h1 class="text-2xl font-extrabold text-gray-900">{{ app.display_name }}</h1>
					<p class="mt-1 text-gray-500">{{ app.legal_name }} · nasery/{{ app.slug }}</p>
				</div>
				<span class="inline-flex rounded-full px-3 py-1 text-sm font-semibold" :class="approvalBadge[app.approval_status]">
					{{ approvalLabels[app.approval_status] }}
				</span>
			</div>

			<dl v-if="app.totals" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
				<div v-for="t in totalTiles" :key="t.label" class="rounded-2xl border border-gray-200 bg-white p-4">
					<dt class="text-xs text-gray-500">{{ t.label }}</dt>
					<dd class="mt-1 text-xl font-extrabold text-gray-900">{{ t.value }}</dd>
				</div>
			</dl>

			<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
				<div class="space-y-6 lg:col-span-2">
					<section class="rounded-2xl border border-gray-200 bg-white p-6">
						<h2 class="mb-4 text-base font-bold text-gray-900">Business profile</h2>
						<dl v-if="app.profile" class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
							<div><dt class="text-gray-400">Brand name</dt><dd class="font-medium text-gray-900">{{ app.profile.business_name }}</dd></div>
							<div><dt class="text-gray-400">Location</dt><dd class="font-medium text-gray-900">{{ app.profile.location_text || '—' }}</dd></div>
							<div><dt class="text-gray-400">Contact email</dt><dd class="font-medium text-gray-900">{{ app.profile.contact_email || '—' }}</dd></div>
							<div><dt class="text-gray-400">Contact mobile</dt><dd class="font-medium text-gray-900">{{ app.profile.contact_phone || '—' }}</dd></div>
							<div class="sm:col-span-2"><dt class="text-gray-400">About</dt><dd class="whitespace-pre-line text-gray-800">{{ app.profile.description || '—' }}</dd></div>
						</dl>
					</section>

					<section class="rounded-2xl border border-gray-200 bg-white p-6">
						<h2 class="mb-4 text-base font-bold text-gray-900">Services offered</h2>
						<p v-if="!app.services.length" class="text-sm text-gray-400">No services added yet.</p>
						<ul v-else class="divide-y divide-gray-100 text-sm">
							<li v-for="s in app.services" :key="s.id" class="flex items-center justify-between py-2.5">
								<span class="font-medium text-gray-900">{{ s.name }} <span v-if="!s.is_active" class="text-xs text-gray-400">(inactive)</span></span>
								<span class="text-gray-500">{{ s.base_price ? `₱${Number(s.base_price).toLocaleString('en-PH')}` : '—' }}</span>
							</li>
						</ul>
					</section>

					<section class="rounded-2xl border border-gray-200 bg-white p-6">
						<h2 class="mb-4 text-base font-bold text-gray-900">History</h2>
						<ol class="space-y-3 text-sm">
							<li v-for="(h, i) in app.history" :key="i" class="flex gap-3">
								<span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="h.kind === 'decision' ? 'bg-primary-600' : 'bg-gray-300'" />
								<div>
									<p class="font-medium text-gray-900">
										{{ h.kind === 'submission' ? 'Submitted for review' : approvalLabels[h.status] }}
										<span class="font-normal text-gray-400">· {{ formatDate(h.decided_at || h.submitted_at) }}</span>
									</p>
									<p v-if="h.decision_reason" class="text-gray-600">{{ h.decision_reason }}</p>
								</div>
							</li>
						</ol>
					</section>

					<AdminWorkspacePanel :organization-id="app.id" :organization-name="app.display_name" :access="app.access" @changed="reload" />
				</div>

				<div class="space-y-6">
					<section class="rounded-2xl border border-gray-200 bg-white p-6">
						<h2 class="mb-4 text-base font-bold text-gray-900">Owner</h2>
						<div v-if="app.owner" class="space-y-1 text-sm">
							<p class="font-semibold text-gray-900">{{ app.owner.full_name }}</p>
							<p class="text-gray-600">{{ app.owner.email }}
								<span v-if="app.owner.email_verified" class="text-xs text-green-600">(verified)</span></p>
							<p class="text-gray-600">{{ app.owner.phone || 'No mobile on file' }}</p>
						</div>
						<!-- A bare mailto: does nothing without a default mail app, so offer the usual ways. -->
						<template v-if="app.owner">
							<button type="button" :aria-expanded="emailOpen"
								class="mt-4 flex w-full items-center justify-center gap-1.5 rounded-lg border border-gray-200 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
								@click="emailOpen = !emailOpen">
								Email owner for documents <span aria-hidden="true" class="text-xs text-gray-400">{{ emailOpen ? '▲' : '▼' }}</span>
							</button>
							<div v-if="emailOpen" class="mt-2 grid grid-cols-2 gap-2">
								<a v-for="opt in emailLinks" :key="opt.label" :href="opt.href" :target="opt.external ? '_blank' : undefined" rel="noopener"
									class="rounded-lg bg-gray-50 px-3 py-2 text-center text-xs font-semibold text-gray-700 hover:bg-gray-100">{{ opt.label }}</a>
								<button type="button" class="col-span-2 rounded-lg bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100" @click="copyEmail">
									{{ copied ? 'Address copied ✓' : 'Copy address' }}
								</button>
							</div>
						</template>
					</section>

					<section v-if="app.allowed_decisions.length" class="rounded-2xl border border-gray-200 bg-white p-6">
						<h2 class="mb-4 text-base font-bold text-gray-900">Decision</h2>
						<div class="space-y-2">
							<button v-for="d in app.allowed_decisions" :key="d" type="button" :disabled="busy"
								class="w-full rounded-lg px-3 py-2.5 text-sm font-semibold disabled:opacity-60"
								:class="decisionStyle[d]" @click="startDecision(d)">
								{{ decisionLabel[d] }}
							</button>
						</div>
						<div v-if="pending" class="mt-4">
							<FormsLabel :text="needsReason(pending) ? 'Reason (sent to the organizer)' : 'Note (optional)'" :required="needsReason(pending)" />
							<textarea v-model="reason" rows="3" maxlength="1000"
								class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none" />
							<div class="mt-2 flex gap-2">
								<button type="button" :disabled="busy || (needsReason(pending) && !reason.trim())"
									class="flex-1 rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
									@click="confirmDecision">
									{{ busy ? 'Saving…' : `Confirm: ${decisionLabel[pending]}` }}
								</button>
								<button type="button" class="rounded-lg border border-gray-200 px-3 py-2 text-sm" @click="pending = null">Cancel</button>
							</div>
						</div>
					</section>
				</div>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import type { ApprovalStatus } from '~/composables/useOrganization'
import type { ApplicationDetail } from '~/composables/useOrganizerApplications'

definePageMeta({ layout: 'superadmin' })

const route = useRoute()
const { show, decide } = useOrganizerApplications()

const app = ref<ApplicationDetail | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const pending = ref<ApprovalStatus | null>(null)
const reason = ref('')

// Email the owner: Gmail / Outlook on the web, the computer's mail app, or copy the address.
const emailOpen = ref(false)
const copied = ref(false)
const emailLinks = computed(() => {
	const owner = app.value?.owner
	if (!owner) return []
	const to = encodeURIComponent(owner.email)
	const subject = encodeURIComponent(`NaSeRy organizer application: ${app.value?.display_name}`)
	const body = encodeURIComponent(`Hi ${owner.full_name},\n\nThank you for your NaSeRy organizer application (${app.value?.display_name}). To finish reviewing it, please send us:\n\n- \n\nThank you,\nNaSeRy`)
	return [
		{ label: 'Gmail', href: `https://mail.google.com/mail/?view=cm&fs=1&to=${to}&su=${subject}&body=${body}`, external: true },
		{ label: 'Outlook', href: `https://outlook.live.com/mail/0/deeplink/compose?to=${to}&subject=${subject}&body=${body}`, external: true },
		{ label: 'Mail app', href: `mailto:${owner.email}?subject=${subject}&body=${body}`, external: false },
	]
})
async function copyEmail() {
	const email = app.value?.owner?.email
	if (!email) return
	try {
		await navigator.clipboard.writeText(email)
	} catch {
		window.prompt('Copy the owner’s email address:', email)
	}
	copied.value = true
	setTimeout(() => { copied.value = false }, 2000)
}

const decisionLabel: Record<ApprovalStatus, string> = {
	pending: 'Pending',
	under_review: 'Mark under review',
	approved: 'Approve',
	rejected: 'Request changes (reject)',
	suspended: 'Suspend',
}
const decisionStyle: Record<ApprovalStatus, string> = {
	pending: '',
	under_review: 'border border-sky-200 bg-sky-50 text-sky-800 hover:bg-sky-100',
	approved: 'bg-green-600 text-white hover:bg-green-700',
	rejected: 'border border-rose-200 bg-rose-50 text-rose-800 hover:bg-rose-100',
	suspended: 'border border-gray-300 bg-gray-50 text-gray-800 hover:bg-gray-100',
}
const needsReason = (s: ApprovalStatus) => s === 'rejected' || s === 'suspended'

function startDecision(status: ApprovalStatus) {
	pending.value = status
	reason.value = ''
}

async function confirmDecision() {
	if (!pending.value || !app.value) return
	busy.value = true
	error.value = ''
	notice.value = ''
	try {
		app.value = { ...app.value, ...await decide(app.value.id, pending.value, reason.value.trim()) }
		notice.value = `Saved: ${approvalLabels[app.value.approval_status]}.`
		pending.value = null
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		busy.value = false
	}
}

const totalTiles = computed(() => {
	const t = app.value!.totals!
	return [
		{ label: 'Events', value: t.events },
		{ label: 'Completed', value: t.completed_events },
		{ label: 'Tickets sold', value: t.tickets_sold },
		{ label: 'Ticket sales', value: peso(t.sales) },
		{ label: 'Rating', value: t.rating ? `★ ${t.rating.toFixed(1)} (${t.reviews})` : '—' },
		{ label: 'Team', value: t.members },
	]
})

async function reload() {
	app.value = await show(String(route.params.id))
}

function formatDate(value: string | null) {
	return value ? new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }) : ''
}

onMounted(async () => {
	try {
		app.value = await show(String(route.params.id))
	} catch (e) {
		error.value = authMessage(e)
	}
})
</script>
