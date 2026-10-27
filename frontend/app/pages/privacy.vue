<template>
	<article class="legal mx-auto max-w-3xl px-5 py-10 sm:px-8 sm:py-14">
		<p class="text-sm font-bold uppercase tracking-widest text-primary-700">NaSeRy</p>
		<h1 class="mt-2 text-3xl font-extrabold tracking-tight text-gray-950 sm:text-4xl">Privacy Policy</h1>
		<p class="mt-2 text-sm text-gray-500">Last updated {{ UPDATED }}</p>

		<p class="mt-6">
			This explains what personal information NaSeRy collects, why, who sees it, and your rights under the
			Data Privacy Act of 2012 (Republic Act No. 10173). Also see our <NuxtLink to="/terms">Terms of Service</NuxtLink>.
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
	</article>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Privacy Policy | NaSeRy', description: 'What personal information NaSeRy collects, why, and your rights under the Data Privacy Act of 2012.' })

const UPDATED = 'October 27, 2026'
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
