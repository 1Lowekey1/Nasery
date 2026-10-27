<template>
	<article class="legal mx-auto max-w-3xl px-5 py-10 sm:px-8 sm:py-14">
		<p class="text-sm font-bold uppercase tracking-widest text-primary-700">NaSeRy</p>
		<h1 class="mt-2 text-3xl font-extrabold tracking-tight text-gray-950 sm:text-4xl">Terms of Service</h1>
		<p class="mt-2 text-sm text-gray-500">Last updated {{ UPDATED }}</p>

		<p class="mt-6">
			NaSeRy connects people planning events with event organizers, and helps organizers run those events: quotations,
			tickets, guest lists and check-in. By creating an account or using NaSeRy you agree to these terms.
			Please also read our <NuxtLink to="/privacy">Privacy Policy</NuxtLink>.
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
	</article>
</template>

<script setup lang="ts">
definePageMeta({ layout: 'public' })
useSeoMeta({ title: 'Terms of Service | NaSeRy', description: 'The rules for using NaSeRy as a client or an event organizer.' })

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
