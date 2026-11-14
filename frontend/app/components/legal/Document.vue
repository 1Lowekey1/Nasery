<template>
	<!-- The Terms / Privacy text, shared by the /terms and /privacy pages and the sign-up popup. -->
	<div class="legal">
		<p class="text-sm text-gray-500">Last updated {{ UPDATED }}</p>

		<template v-if="doc === 'terms'">
			<p class="mt-6">
				NaSeRy connects people planning events with event organizers, and helps organizers run those events: quotations,
				tickets, guest lists and check-in. By creating an account or using NaSeRy you agree to these terms.
				Please also read our <a href="/privacy" @click.prevent="emit('open', 'privacy')">Privacy Policy</a>.
			</p>

			<h2>Your account</h2>
			<ul>
				<li>Give your real name and an e-mail address and mobile number you can access. We send sign-in and verification codes there.</li>
				<li>Keep your password to yourself. You are responsible for what happens under your account; tell us right away if you think someone else is using it.</li>
				<li>One person, one account per e-mail address. Organizer team members are invited by their organizer and use the account the invitation creates.</li>
			</ul>

			<h2>Clients</h2>
			<ul>
				<li>Event inquiries you post should describe a real event. Organizers who match it may send you quotations.</li>
				<li>Price list estimates are only estimates. The price that counts is the one in the quotation you accept.</li>
				<li>Accepting a quotation is an agreement between you and that organizer. NaSeRy is not a party to it and does not hold or handle payments between you.</li>
			</ul>

			<h2>Organizers</h2>
			<ul>
				<li>Your business profile, permits and documents must be genuine and yours. NaSeRy reviews them before your profile is public and may ask for more.</li>
				<li>You deliver the services you quote. Quotations, prices and event details you publish must be accurate.</li>
				<li>Attendee and guest information you see through NaSeRy is only for running that event. Don't use it for anything else.</li>
				<li>Paid plans (NaSeRy Access) renew as described on the Plan &amp; Billing page. Ticket payments go through our payment provider, not through NaSeRy's own accounts.</li>
			</ul>

			<h2>Tickets and check-in</h2>
			<ul>
				<li>A ticket's QR code admits one person once. Don't share or copy it; the first scan counts.</li>
				<li>Refunds and changes are up to the event's organizer.</li>
			</ul>

			<h2>Things you may not do</h2>
			<ul>
				<li>Post anything false, offensive, or that you don't have the right to share, including photos.</li>
				<li>Try to get into accounts or data that aren't yours, overload the service, or get around its limits.</li>
				<li>Use NaSeRy to send spam or to collect other people's information.</li>
			</ul>
			<p>We may remove content, or suspend or close accounts, that break these terms.</p>

			<h2>Reviews and your content</h2>
			<p>
				You keep ownership of what you upload. You let NaSeRy show it on the service for the purpose you uploaded it for,
				for example your business photos on your public profile. Reviews must be about a real event you were part of.
			</p>

			<h2>The service itself</h2>
			<p>
				NaSeRy is a student capstone project and is provided as it is. We work to keep it running and your data safe,
				but we can't promise it will always be available or error-free, and we are not liable for losses from an event,
				a quotation or a dealing between clients and organizers.
			</p>

			<h2>Changes</h2>
			<p>We may update these terms. If a change matters, we'll tell you in NaSeRy or by e-mail before it applies.</p>

			<h2>Contact</h2>
			<p>Questions? E-mail <a :href="`mailto:${CONTACT}`">{{ CONTACT }}</a>.</p>
		</template>

		<template v-else>
			<p class="mt-6">
				This explains what personal information NaSeRy collects, why, who sees it, and your rights under the
				Data Privacy Act of 2012 (Republic Act No. 10173). Also see our <a href="/terms" @click.prevent="emit('open', 'terms')">Terms of Service</a>.
			</p>

			<h2>What we collect</h2>
			<ul>
				<li><strong>Your account:</strong> name, e-mail address, mobile number, profile photo, and your password (stored only as a one-way hash, never readable).</li>
				<li><strong>Organizers:</strong> business details, service area, price list, photos, and the permits and documents you submit for review.</li>
				<li><strong>Events and inquiries:</strong> event type, date, guest count, budget, venue or location, and the messages and quotations exchanged.</li>
				<li><strong>Tickets and guests:</strong> attendee names and e-mail addresses, ticket status and check-in times.</li>
				<li><strong>Payments:</strong> amounts, status and reference numbers. Card and e-wallet details are entered on the payment provider's page; NaSeRy never sees or stores them.</li>
				<li><strong>Security:</strong> sign-in times, device and browser, and IP address, kept to protect your account and spot misuse.</li>
			</ul>

			<h2>Why we use it</h2>
			<ul>
				<li>To run the service: match inquiries with organizers, send quotations, issue tickets and check guests in.</li>
				<li>To review organizers before their profile goes public.</li>
				<li>To e-mail you codes, receipts, tickets and notifications. You choose which notifications also come by e-mail under Notifications → E-mail settings; codes, tickets and important changes such as refunds and cancellations are always sent.</li>
				<li>To keep accounts safe and keep a record of important changes (an activity log).</li>
			</ul>
			<p>We don't sell your information, and we don't use it for advertising.</p>

			<h2>Who can see it</h2>
			<ul>
				<li><strong>Organizers</strong> see the inquiries that match their services, and the details of clients and attendees of their own events.</li>
				<li><strong>Clients</strong> see organizers' public profiles and the quotations sent to them.</li>
				<li><strong>The public</strong> sees only public profiles, public events and published reviews. Private events are visible only through their invitation link.</li>
				<li><strong>NaSeRy administrators</strong> see what they need to review organizers and handle reports.</li>
				<li><strong>Business documents</strong> an organizer uploads are private: only that organization's owner and NaSeRy administrators can open them, and they're used only to confirm the business.</li>
				<li><strong>Service providers</strong> that run parts of NaSeRy for us: our e-mail sender, our payment provider and our map and address search. They get only what that job needs.</li>
			</ul>

			<h2>How long we keep it</h2>
			<p>
				For as long as your account is in use, and afterwards only as long as records of events, payments and the activity
				log are needed. If you leave an organizer's team, your account is deactivated rather than erased so the event history
				stays correct; you can reuse your e-mail address later.
			</p>

			<h2>How we protect it</h2>
			<p>
				Connections are encrypted (HTTPS), passwords are hashed, each organization's data is separated at the database level,
				and verifying your e-mail or resetting your password needs a code sent to that e-mail.
			</p>

			<h2>Your rights</h2>
			<p>
				You can ask to see the personal information we have about you, correct it, object to how it is used, or have it
				deleted where the law allows. Most of it you can view and edit yourself in your profile.
				For anything else, e-mail <a :href="`mailto:${CONTACT}`">{{ CONTACT }}</a>. You may also complain to the
				National Privacy Commission.
			</p>

			<h2>Changes</h2>
			<p>If we change this policy in a way that matters, we'll tell you in NaSeRy or by e-mail first.</p>
		</template>
	</div>
</template>

<script setup lang="ts">
defineProps<{ doc: 'terms' | 'privacy' }>()
/** The other document's link was clicked: the page navigates, the popup switches tabs. */
const emit = defineEmits<{ open: ['terms' | 'privacy'] }>()

const UPDATED = 'November 7, 2026'
const CONTACT = 'naserynotifications@gmail.com'
</script>

<style scoped>
.legal { color: rgb(55 65 81); font-size: 0.95rem; line-height: 1.7; }
.legal h2 { margin-top: 2rem; font-size: 1.15rem; font-weight: 800; color: rgb(3 7 18); }
.legal p { margin-top: 0.75rem; }
.legal ul { margin-top: 0.75rem; list-style: disc; padding-left: 1.25rem; }
.legal li + li { margin-top: 0.4rem; }
.legal a { font-weight: 600; color: #285F6b; text-decoration: underline; text-underline-offset: 2px; }
</style>
