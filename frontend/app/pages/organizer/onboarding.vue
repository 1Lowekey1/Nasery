<template>
	<div class="mx-auto max-w-2xl">
		<!-- A former team member (removed or left): say so first, before "set up your organization". -->
		<section v-if="formerTeam && !invitations.length" class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-6" role="status">
			<h1 class="text-xl font-extrabold text-gray-900">You're no longer on {{ formerTeam.organization }}'s team</h1>
			<p class="mt-2 text-sm leading-relaxed text-gray-700">
				{{ formerTeam.how === 'left' ? 'You left the team' : `${formerTeam.organization} removed you from their team` }}<template v-if="formerTeam.ended_at"> on {{ new Date(formerTeam.ended_at).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) }}</template>,
				so you can't open their events anymore. Your account stays yours.
			</p>
			<ul class="mt-4 space-y-2 text-sm text-gray-700">
				<li><span class="font-semibold">Joining another team?</span> Ask that organizer to invite <span class="font-semibold">{{ user?.email }}</span>. The invitation will appear here.</li>
				<li><span class="font-semibold">Starting your own business?</span>
					<button type="button" class="font-semibold text-primary-700 underline" @click="showSetup = true">Set up your organization</button>.</li>
				<li><span class="font-semibold">Want your account closed?</span> Contact NaSeRy support and we will close it for you.</li>
			</ul>
		</section>

		<template v-if="!formerTeam || invitations.length || showSetup">
		<h1 class="text-2xl font-extrabold text-gray-900">Set up your organization</h1>
		<p class="mt-1 mb-6 text-gray-500">
			Tell clients who you are. NaSeRy reviews every organizer before they can bid, run events and sell tickets.
		</p>
		</template>

		<Alert v-if="error" type="danger" :text="error" />

		<section v-if="invitations.length" class="mb-6 rounded-2xl border border-primary-200 bg-primary-50 p-6">
			<h2 class="text-base font-bold text-gray-900">You've been invited to a team</h2>
			<p class="mt-1 text-sm text-gray-600">Joining a team means you work for that organizer; you won't set up your own organization.</p>
			<ul class="mt-4 space-y-3">
				<li v-for="inv in invitations" :key="inv.id" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4 text-sm">
					<div>
						<p class="font-bold text-gray-900">{{ inv.organization }}</p>
						<p class="text-gray-500">As {{ inv.role }}<template v-if="inv.invited_by"> · invited by {{ inv.invited_by }}</template></p>
					</div>
					<div class="flex gap-2">
						<button type="button" :disabled="responding" class="rounded-lg bg-primary-700 px-4 py-2 font-semibold text-white disabled:opacity-50" @click="respond(inv.id, true)">Join team</button>
						<button type="button" :disabled="responding" class="rounded-lg border border-gray-200 bg-white px-4 py-2 font-semibold text-gray-700" @click="respond(inv.id, false)">Decline</button>
					</div>
				</li>
			</ul>
			<p class="mt-4 text-xs text-gray-500">Or set up your own organization below.</p>
		</section>
		<p v-else-if="!formerTeam" class="mb-6 text-sm text-gray-500">Joining an organizer's team instead? Ask them to invite {{ user?.email }}; the invitation will appear here.</p>

		<form v-if="!formerTeam || invitations.length || showSetup" novalidate class="space-y-6" @submit.prevent="handleSubmit">
			<FormsDraftNotice :draft="draft" />
			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-4 text-base font-bold text-gray-900">Business</h2>
				<div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
					<div>
						<FormsLabel text="Display Name" required />
						<FormsTextField v-model="form.display_name" placeholder="Creative Kings Events" required />
						<p v-if="fields.display_name" class="mt-1.5 text-xs text-red-600">{{ fields.display_name }}</p>
					</div>
					<div>
						<FormsLabel text="Registered Business Name" required />
						<FormsTextField v-model="form.legal_name" placeholder="Creative Kings Events Management Inc." required />
						<p v-if="fields.legal_name" class="mt-1.5 text-xs text-red-600">{{ fields.legal_name }}</p>
					</div>
				</div>
				<div class="mt-4">
					<FormsLabel text="Brand / Trade Name" required />
					<FormsTextField v-model="form.profile.business_name" placeholder="Creative Kings Event Management" required />
					<p v-if="fields['profile.business_name']" class="mt-1.5 text-xs text-red-600">{{ fields['profile.business_name'] }}</p>
				</div>
				<div class="mt-4">
					<FormsLabel text="About Your Services" />
					<textarea v-model="form.profile.description" rows="4" maxlength="2000"
						placeholder="Weddings, debuts and corporate events across Davao…"
						class="w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-2.5 text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15" />
				</div>
			</section>

			<section class="rounded-2xl border border-gray-200 bg-white p-6">
				<h2 class="mb-4 text-base font-bold text-gray-900">Location &amp; Contact</h2>
				<div>
					<FormsLabel text="Business Address" required />
					<FormsTextField v-model="form.profile.location_text" placeholder="Obrero, Davao City" required />
					<p v-if="fields['profile.location_text']" class="mt-1.5 text-xs text-red-600">{{ fields['profile.location_text'] }}</p>
				</div>
				<div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
					<div>
						<FormsLabel text="Contact Email" required />
						<FormsTextField v-model="form.profile.contact_email" type="email" placeholder="hello@yourbusiness.com" required />
						<p v-if="fields['profile.contact_email']" class="mt-1.5 text-xs text-red-600">{{ fields['profile.contact_email'] }}</p>
					</div>
					<div>
						<FormsLabel text="Contact Mobile" />
						<FormsTextField v-model="form.profile.contact_phone" type="tel" placeholder="0917 123 4567" />
						<p v-if="fields['profile.contact_phone']" class="mt-1.5 text-xs text-red-600">{{ fields['profile.contact_phone'] }}</p>
					</div>
				</div>
				<p class="mt-4 text-xs text-gray-500">
					After you submit, our team may email your account address to request your business permit and other documents.
				</p>
			</section>

			<button type="submit" :disabled="submitting"
				class="w-full rounded-xl bg-primary-700 py-4 text-[15px] font-bold text-white hover:bg-primary-900 disabled:cursor-wait disabled:opacity-70">
				{{ submitting ? 'Submitting…' : 'Submit for Review' }}
			</button>
		</form>
	</div>
</template>

<script setup lang="ts">
import type { OnboardingPayload } from '~/composables/useOrganization'
import type { FormerTeam, MyInvitation } from '~/composables/useTeam'

definePageMeta({ layout: 'dashboard' })

const { user } = useAuth()
const { create, fetchOrganization } = useOrganization()
const teamApi = useTeam()
const invitations = ref<MyInvitation[]>([])
const formerTeam = ref<FormerTeam | null>(null)
const showSetup = ref(false)
const responding = ref(false)

async function respond(id: string, accept: boolean) {
	error.value = ''
	responding.value = true
	try {
		if (accept) {
			await teamApi.accept(id)
			await fetchOrganization(true)
			await navigateTo('/team/dashboard')
		} else {
			await teamApi.decline(id)
			invitations.value = invitations.value.filter(i => i.id !== id)
		}
	} catch (e) {
		error.value = authMessage(e)
	} finally {
		responding.value = false
	}
}

onMounted(async () => {
	try {
		;[invitations.value, formerTeam.value] = await Promise.all([teamApi.myInvitations(), teamApi.formerTeam().catch(() => null)])
	} catch {
		// Invitations are optional here; onboarding still works without them.
	}
})

const form = reactive<OnboardingPayload>({
	legal_name: '',
	display_name: '',
	profile: {
		business_name: '',
		description: '',
		location_text: '',
		contact_email: user.value?.email ?? '',
		contact_phone: '',
	},
})
const draft = useDraft(() => 'organizer:onboarding', form)
const submitting = ref(false)
const error = ref('')
const fields = ref<Record<string, string>>({})

watch(() => form.display_name, (value, old) => {
	if (!form.profile.business_name || form.profile.business_name === old) form.profile.business_name = value
})

async function handleSubmit() {
	error.value = ''
	fields.value = {}
	submitting.value = true
	try {
		await create({
			...form,
			profile: { ...form.profile, contact_phone: form.profile.contact_phone || null, description: form.profile.description || null },
		})
		draft.clear()
		await navigateTo('/organizer/home')
	} catch (e) {
		fields.value = fieldErrors(e)
		error.value = Object.keys(fields.value).length ? 'Please check the highlighted fields.' : authMessage(e)
	} finally {
		submitting.value = false
	}
}
</script>
