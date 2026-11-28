<template>
    <div class="relative min-h-screen overflow-hidden bg-[#f4f7f7]">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-[430px] bg-gradient-to-br from-[#173d45] via-[#285F6b] to-[#3f7b87]" />
        <div class="pointer-events-none absolute -right-24 top-20 h-80 w-80 rounded-full border border-white/10" />
        <div class="pointer-events-none absolute -left-32 top-52 h-72 w-72 rounded-full bg-white/5 blur-2xl" />

        <header class="relative z-10 border-b border-white/10 bg-[#173d45]/40 text-white backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                <NuxtLink to="/" class="flex items-center gap-3" aria-label="NaSeRy home">
                    <BrandMark tile class="h-11 w-11 !rounded-2xl text-sm shadow-lg shadow-black/10" />

                    <div>
                        <p class="font-extrabold tracking-tight text-white">
                            NaSeRy
                        </p>

                        <p class="text-xs text-white/60">
                            Secure event registration
                        </p>
                    </div>
                </NuxtLink>

                <span v-if="eventData && isPrivateEvent"
                    class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-2 text-xs font-bold text-white shadow-sm backdrop-blur">
                    <span class="h-2 w-2 rounded-full bg-amber-300" />
                    Private event · by invitation
                </span>
                <span v-else-if="eventData?.public_registration"
                    class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-2 text-xs font-bold text-white shadow-sm backdrop-blur">
                    <span class="h-2 w-2 rounded-full bg-emerald-300 shadow-[0_0_0_4px_rgba(110,231,183,0.15)]" />
                    Registration Open
                </span>
            </div>
        </header>

        <main class="relative z-[1] mx-auto max-w-6xl px-4 pb-14 pt-8 sm:px-6 sm:pb-20 sm:pt-10 lg:px-8">
            <div v-if="loading" class="rounded-2xl border border-gray-200 bg-white p-10 text-center shadow-sm">
                <svg class="mx-auto h-6 w-6 animate-spin text-[#285F6b]" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z" />
                </svg>

                <p class="mt-3 text-sm font-medium text-gray-600">
                    Loading registration form...
                </p>
            </div>

            <div v-else-if="errorMessage && !eventData"
                class="rounded-2xl border border-red-200 bg-white p-8 text-center shadow-sm">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" class="h-5 w-5">
                        <circle cx="12" cy="12" r="10" />
                        <line x1="12" x2="12" y1="8" y2="12" />
                        <line x1="12" x2="12.01" y1="16" y2="16" />
                    </svg>
                </div>

                <h1 class="mt-4 text-xl font-bold text-gray-900">
                    Registration unavailable
                </h1>

                <p class="mt-2 text-sm text-red-600">
                    {{ errorMessage }}
                </p>
            </div>

            <div v-else-if="eventData" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start">
                <section class="space-y-5">
                    <div class="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-[#285F6b] via-[#245661] to-[#173d45] text-white shadow-2xl shadow-[#173d45]/25 ring-1 ring-white/10">
                        <div class="pointer-events-none absolute -right-16 -top-20 -z-10 h-64 w-64 rounded-full border-[36px] border-white/[0.04]" />
                        <div class="pointer-events-none absolute -bottom-24 left-1/3 -z-10 h-48 w-48 rounded-full bg-white/[0.04] blur-2xl" />

                        <div class="p-6 sm:p-8">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[0.16em] text-white/75 ring-1 ring-white/10">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="isPrivateEvent ? 'bg-amber-300' : 'bg-emerald-300'" />
                                    {{ isPrivateEvent ? 'Private event' : (eventData.event_type || 'Event') }}
                                </span>
                                <span class="hidden text-xs font-medium text-white/50 sm:inline">Official NaSeRy event form</span>
                            </div>

                            <h1 class="mt-4 text-2xl font-black tracking-tight text-white sm:mt-5 sm:text-4xl">
                                {{ eventData.name }}
                            </h1>

                            <p v-if="eventData.description" class="mt-2 max-w-2xl text-sm leading-6 text-white/65">
                                {{ eventData.description }}
                            </p>

                            <div class="mt-5 divide-y divide-white/10 overflow-hidden rounded-2xl bg-black/10 ring-1 ring-white/10 sm:mt-7 sm:grid sm:grid-cols-2 sm:divide-x sm:divide-y-0">
                                <div class="flex items-center gap-3 px-3.5 py-3 sm:items-start sm:p-5">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-white/10 sm:h-10 sm:w-10">
                                        <IconBase name="calendar" class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-white/50">Schedule</p>
                                        <p class="mt-1 text-sm font-bold leading-5 text-white">{{ eventSchedule }}</p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 px-3.5 py-3 sm:items-start sm:p-5">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white/10 text-white ring-1 ring-white/10 sm:h-10 sm:w-10">
                                        <IconBase name="map-pin" class="h-5 w-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-white/50">Venue</p>
                                        <p class="mt-1 text-sm font-semibold leading-5 text-white/90">{{ eventData.location || 'Location not specified' }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <RegistrationPayMongoCheckoutCard v-if="pendingPayment" :amount="pendingPayment.amount"
                        :currency="pendingPayment.currency" @checkout="openPayMongoCheckout" />

                    <div v-else-if="registeredTickets.length || pendingApprovalCount"
                        :class="pendingApprovalCount ? 'border-amber-200' : 'border-emerald-200'"
                        class="rounded-3xl border bg-white p-6 shadow-xl shadow-slate-900/5 sm:p-7">
                        <div class="flex items-start gap-3">
                            <div
                                :class="pendingApprovalCount ? 'bg-amber-50 text-amber-600' : 'bg-emerald-50 text-emerald-600'"
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" class="h-5 w-5">
                                    <path v-if="pendingApprovalCount" d="M12 6v6l4 2" />
                                    <circle v-if="pendingApprovalCount" cx="12" cy="12" r="9" />
                                    <path v-else d="M20 6 9 17l-5-5" />
                                </svg>
                            </div>

                            <div>
                                <h2 class="text-xl font-bold text-gray-900">
                                    {{ pendingApprovalCount ? 'Registration sent for approval' : 'Registration successful' }}
                                </h2>

                                <p class="mt-1 text-sm text-gray-500">
                                    {{ successMessage }}
                                </p>
                            </div>
                        </div>

                        <div v-if="pendingApprovalCount" class="mt-6 rounded-2xl border border-amber-100 bg-amber-50 p-5">
                            <p class="font-bold text-amber-900">What happens next</p>
                            <p class="mt-1 text-sm leading-6 text-amber-800">The organizer will review your registration. Once approved, your active QR ticket will be sent to your verified email address.</p>
                            <div class="mt-4 flex items-center gap-2 text-xs font-semibold text-amber-700">
                                <IconBase name="mail" class="h-4 w-4" />
                                You may safely close this page.
                            </div>
                        </div>

                        <div class="mt-6 space-y-4">
                            <div v-for="ticket in registeredTickets" :key="ticket.id"
                                class="rounded-2xl border border-gray-200 bg-gray-50 p-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <p class="text-xs font-medium uppercase tracking-wide text-gray-400">
                                            Attendee
                                        </p>

                                        <p class="mt-1 text-lg font-bold text-gray-900">
                                            {{ ticket.name }}
                                        </p>

                                        <p class="mt-1 text-sm text-gray-500">
                                            {{ ticket.ticket_type?.name || 'General Admission' }}<span v-if="ticket.seat" class="font-semibold text-[#285F6b]"> · {{ ticket.seat }}</span>
                                        </p>

                                        <p class="mt-2 font-mono text-xs font-semibold text-[#285F6b]">
                                            {{ ticket.ticket_id }}
                                        </p>
                                    </div>

                                    <div class="grid grid-cols-1 gap-2 sm:min-w-[210px]">
                                        <button type="button"
                                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50"
                                            @click="downloadTicket(ticket)">
                                            Download PDF Ticket
                                        </button>
                                        <button type="button"
                                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50"
                                            @click="downloadTicket(ticket, 'png')">
                                            Save Ticket Image
                                        </button>

                                        <button type="button"
                                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-[#1f4a54] disabled:cursor-not-allowed disabled:opacity-50"
                                            :disabled="!ticket.email ||
                                                emailSendingTicketId === ticket.id
                                                " @click="sendTicketEmail(ticket)">
                                            {{
                                                emailSendingTicketId === ticket.id
                                                    ? 'Sending Ticket...'
                                                    : 'Send PDF to Email'
                                            }}
                                        </button>
                                    </div>
                                </div>

                                <p v-if="emailSuccessTicketId === ticket.id"
                                    class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">
                                    QR ticket was successfully sent to {{ ticket.email }}.
                                </p>

                                <div v-if="emailErrorTicketId === ticket.id"
                                    class="mt-3 rounded-xl border border-red-200 bg-red-50 px-3.5 py-3 text-xs font-semibold text-red-700">
                                    <p>
                                        {{ emailErrorMessages[ticket.id] || 'Unable to send this ticket.' }}
                                    </p>

                                    <p class="mt-1 font-normal text-red-600">
                                        Recipient: {{ ticket.email || 'No attendee email available' }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <button type="button"
                            class="mt-6 inline-flex h-11 items-center justify-center rounded-xl border border-[#285F6b]/25 bg-white px-5 text-sm font-semibold text-[#285F6b] transition hover:bg-[#285F6b]/5"
                            @click="registerMore">
                            Register another attendee
                        </button>
                    </div>

                    <form v-else class="rounded-3xl border border-gray-200/80 bg-white p-5 shadow-xl shadow-slate-900/5 sm:p-8"
                        @submit.prevent="submitRegistration">
                        <div class="mb-7 flex items-center gap-2 rounded-2xl border border-[#285F6b]/10 bg-[#285F6b]/5 p-2">
                            <div class="flex flex-1 items-center gap-2 rounded-xl bg-white px-3 py-2.5 shadow-sm">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[#285F6b] text-[11px] font-bold text-white">1</span>
                                <span class="text-xs font-bold text-gray-800">Attendee details</span>
                            </div>
                            <div class="hidden flex-1 items-center gap-2 px-3 py-2.5 sm:flex">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white text-[11px] font-bold text-[#285F6b]">2</span>
                                <span class="text-xs font-semibold text-gray-500">{{ signedIn ? 'Tickets' : 'Confirm e-mail once' }}</span>
                            </div>
                            <div class="hidden flex-1 items-center gap-2 px-3 py-2.5 md:flex">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-white text-[11px] font-bold text-[#285F6b]">3</span>
                                <span class="text-xs font-semibold text-gray-500">
                                    {{ hasPaidAttendees ? 'Secure payment' : 'Get QR ticket' }}
                                </span>
                            </div>
                        </div>
                        <!-- Advisor's fixes (Nov 2026): a guest confirms their own e-mail ONCE (every ticket goes to it),
                             then fills in one ticket at a time; seats are shown per attendee. -->
                        <div>
                            <h2 class="text-xl font-bold text-gray-900">{{ signedIn ? 'Your tickets' : 'Your details' }}</h2>
                            <p class="mt-1 text-sm text-gray-500">
                                <template v-if="signedIn">You're signed in as {{ user?.email }}: no e-mail code needed. The tickets also appear under My Tickets.</template>
                                <template v-else>Confirm your e-mail once with a 6-digit code. Every ticket is sent there; for the other attendees we only need their names.</template>
                            </p>
                        </div>

                        <div v-if="errorMessage" class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                            {{ errorMessage }}
                        </div>

                        <!-- Step 1 (guests): the buyer's own e-mail, verified once. -->
                        <div v-if="!signedIn" class="mt-5 rounded-2xl border p-4 sm:p-5" :class="buyerVerified ? 'border-emerald-200 bg-emerald-50/50' : 'border-[#285F6b]/25 bg-white ring-2 ring-[#285F6b]/5'">
                            <div v-if="buyerVerified" class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><IconBase name="check" class="h-4 w-4" /></span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-emerald-800">{{ buyer.name || 'E-mail confirmed' }}</p>
                                        <p class="break-all text-xs text-emerald-700">{{ buyerState.verifiedEmail }} · all tickets are sent here</p>
                                    </div>
                                </div>
                                <button type="button" class="shrink-0 rounded-lg border border-emerald-200 bg-white px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-50" @click="changeVerifiedEmail(0)">Change</button>
                            </div>
                            <template v-else>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label for="buyer-name" class="mb-1.5 block text-sm font-semibold text-gray-700">Your name <span class="text-red-500">*</span></label>
                                        <input id="buyer-name" v-model="buyer.name" type="text" autocomplete="name" placeholder="Your full name" class="reg-field">
                                    </div>
                                    <div>
                                        <label for="buyer-phone" class="mb-1.5 block text-sm font-semibold text-gray-700">Contact number <span class="text-xs font-normal text-gray-400">(optional)</span></label>
                                        <input id="buyer-phone" v-model="buyer.phone" type="tel" autocomplete="tel" placeholder="e.g. 09171234567" class="reg-field">
                                    </div>
                                </div>
                                <label for="buyer-email" class="mb-1.5 mt-3 block text-sm font-semibold text-gray-700">Your e-mail <span class="text-red-500">*</span></label>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <input id="buyer-email" v-model="buyer.email" type="email" autocomplete="email" placeholder="you@example.com" class="reg-field sm:flex-1" @input="handleEmailChanged(0)">
                                    <button type="button" class="inline-flex h-[46px] shrink-0 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-4 text-sm font-bold text-white transition hover:bg-[#1f4a54] disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500"
                                        :disabled="buyerState.sending || !buyer.email.trim() || (buyerState.sent && buyerState.resendIn > 0)" @click="sendVerificationCode(0)">
                                        {{ buyerState.sending ? 'Sending…' : buyerState.sent ? (buyerState.resendIn > 0 ? `Resend in ${buyerState.resendIn}s` : 'Resend code') : 'Send code' }}
                                    </button>
                                </div>
                                <div v-if="buyerState.sent" class="mt-3">
                                    <label for="buyer-code" class="mb-1.5 block text-xs font-bold uppercase tracking-[0.12em] text-gray-500">6-digit code sent to {{ buyer.email }}</label>
                                    <div class="flex flex-col gap-2 sm:flex-row">
                                        <input id="buyer-code" v-model="buyerState.code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000"
                                            class="h-[46px] w-full rounded-xl border border-gray-200 bg-gray-50 px-4 text-center font-mono text-lg font-extrabold tracking-[0.4em] outline-none focus:border-[#285F6b] focus:bg-white focus:ring-2 focus:ring-[#285F6b]/10 sm:max-w-[220px]"
                                            @input="sanitizeVerificationCode(0)" @keyup.enter="verifyEmailCode(0)">
                                        <button type="button" class="inline-flex h-[46px] shrink-0 items-center justify-center rounded-xl bg-[#285F6b] px-5 text-sm font-bold text-white hover:bg-[#1f4a54] disabled:cursor-not-allowed disabled:opacity-50"
                                            :disabled="buyerState.verifying || buyerState.code.length !== 6 || buyerState.expiresIn <= 0" @click="verifyEmailCode(0)">
                                            {{ buyerState.verifying ? 'Confirming…' : 'Confirm e-mail' }}
                                        </button>
                                    </div>
                                    <p class="mt-1.5 text-xs" :class="buyerState.expiresIn > 0 ? 'text-gray-500' : 'font-semibold text-amber-700'">
                                        {{ buyerState.expiresIn > 0 ? `The code expires in ${formatCountdown(buyerState.expiresIn)}. Check Spam if it's not in your inbox.` : 'This code expired. Request a new one.' }}
                                    </p>
                                </div>
                                <p v-if="buyerState.error" class="mt-2 text-xs font-semibold text-red-600" role="alert">{{ buyerState.error }}</p>
                            </template>
                        </div>

                        <!-- Step 2: the tickets, one at a time. Locked (overlay) until a guest's e-mail is confirmed. -->
                        <div class="relative mt-6">
                            <div v-if="!buyerVerified" class="absolute inset-0 z-10 flex items-start justify-center rounded-2xl bg-white/75 pt-10 backdrop-blur-[1px]">
                                <p class="mx-4 flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 shadow-sm">
                                    <IconBase name="check" class="h-4 w-4 text-[#285F6b]" />Confirm your e-mail above to fill in the tickets.
                                </p>
                            </div>
                            <div :inert="!buyerVerified || undefined" :aria-hidden="!buyerVerified || undefined" class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                                <!-- Header: how many tickets, the total, and the one place to add a ticket. -->
                                <div class="flex items-center justify-between gap-3 border-b border-gray-100 bg-gray-50/80 px-4 py-3 sm:px-5">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-gray-900">Tickets</p>
                                        <p class="text-xs text-gray-500">{{ form.attendees.length }} {{ form.attendees.length === 1 ? 'ticket' : 'tickets' }} · {{ formatCurrency(totalAmount) }}<template v-if="form.attendees.length > 1"> · {{ namedCount }} of {{ form.attendees.length }} named</template></p>
                                    </div>
                                    <!-- Many tickets: fill in every name in one list (Advisor's follow-up, Nov 2026). -->
                                    <div v-if="form.attendees.length > 2" class="ml-auto hidden shrink-0 rounded-lg bg-gray-100 p-0.5 text-xs font-semibold sm:flex" role="group" aria-label="View">
                                        <button type="button" class="rounded-md px-2.5 py-1.5" :class="!listView ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" :aria-pressed="!listView" @click="listView = false">One at a time</button>
                                        <button type="button" class="rounded-md px-2.5 py-1.5" :class="listView ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" :aria-pressed="listView" @click="listView = true">All tickets</button>
                                    </div>
                                    <button v-if="availableTicketTypes.length && form.attendees.length < maxTickets" type="button"
                                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border border-[#285F6b]/30 bg-white px-3 text-xs font-bold text-[#285F6b] transition hover:bg-[#285F6b]/5" @click="addAttendee">
                                        <IconBase name="plus" class="h-3.5 w-3.5" />Add ticket
                                    </button>
                                    <span v-else-if="form.attendees.length >= maxTickets" class="shrink-0 text-xs font-semibold text-gray-500">Up to {{ maxTickets }} per buyer</span>
                                </div>

                                <!-- Chips: jump to any ticket. Green check = complete, amber dot = name missing. -->
                                <!-- Phones: the same switch, full width. -->
                                <div v-if="form.attendees.length > 2" class="flex gap-0.5 rounded-lg bg-gray-100 p-0.5 text-xs font-semibold sm:hidden mx-4 mt-3" role="group" aria-label="View">
                                    <button type="button" class="flex-1 rounded-md px-2.5 py-1.5" :class="!listView ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" :aria-pressed="!listView" @click="listView = false">One at a time</button>
                                    <button type="button" class="flex-1 rounded-md px-2.5 py-1.5" :class="listView ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'" :aria-pressed="listView" @click="listView = true">All tickets</button>
                                </div>

                                <ol v-if="listView && form.attendees.length > 2" class="divide-y divide-gray-100 px-4 py-2 sm:px-5" aria-label="All tickets">
                                    <li v-for="(a, i) in form.attendees" :key="i" class="grid grid-cols-[auto_1fr_auto] items-center gap-x-2.5 gap-y-2 py-2.5" :class="availableTicketTypes.length > 1 ? 'sm:grid-cols-[auto_1fr_15rem_auto]' : ''">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold" :class="ticketComplete(a) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">{{ i + 1 }}</span>
                                        <input v-model="a.name" type="text" autocomplete="off" class="reg-field !h-10" :placeholder="`Name on ticket ${i + 1}`" :aria-label="`Name on ticket ${i + 1}`">
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:invisible sm:order-last" :disabled="form.attendees.length === 1" :aria-label="`Remove ticket ${i + 1}`" @click="removeAttendee(i)"><IconBase name="x" class="h-4 w-4" /></button>
                                        <!-- Only one ticket type: no need to choose it on every row. -->
                                        <div v-if="availableTicketTypes.length > 1" class="col-span-3 col-start-2 sm:col-span-1 sm:col-start-auto">
                                            <FormsSelect v-model="a.event_ticket_type_id" :options="ticketTypeOptions" placeholder="Ticket type" :can-clear="false" :aria-label="`Ticket type for ticket ${i + 1}`" />
                                        </div>
                                    </li>
                                </ol>
                                <p v-if="listView && form.attendees.length > 2" class="px-4 pb-4 text-xs text-gray-500 sm:px-5">{{ availableTicketTypes.length > 1 ? '' : `All are ${availableTicketTypes[0]?.name ?? 'the same ticket'}. ` }}E-mails are optional: switch to “One at a time” to add one to a ticket.</p>

                                <div v-if="form.attendees.length > 1 && !(listView && form.attendees.length > 2)" class="flex gap-2 overflow-x-auto px-4 pb-1 pt-3 sm:px-5" role="tablist" aria-label="Tickets">
                                    <button v-for="(a, i) in form.attendees" :key="i" type="button" role="tab" :aria-selected="activeTicket === i"
                                        class="inline-flex max-w-[12rem] shrink-0 items-center gap-2 rounded-full border py-1 pl-1 pr-3 text-xs font-semibold transition"
                                        :class="activeTicket === i ? 'border-[#285F6b] bg-[#285F6b]/10 text-[#285F6b]' : 'border-gray-200 bg-white text-gray-700 hover:border-[#285F6b]/40'"
                                        @click="activeTicket = i">
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold"
                                            :class="ticketComplete(a) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                            <IconBase v-if="ticketComplete(a)" name="check" class="h-3 w-3" /><template v-else>{{ i + 1 }}</template>
                                        </span>
                                        <span class="truncate">{{ a.name.trim() || `Ticket ${i + 1}` }}</span>
                                    </button>
                                </div>

                                <div v-if="currentAttendee && !(listView && form.attendees.length > 2)" class="p-4 sm:p-5">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#285F6b] text-sm font-bold text-white">{{ activeTicket + 1 }}</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-bold text-gray-900" aria-live="polite">Ticket {{ activeTicket + 1 }} of {{ form.attendees.length }}</p>
                                            <p class="truncate text-xs text-gray-500">{{ ticketTypeName(currentAttendee) }} · {{ formatCurrency(ticketPrice(currentAttendee)) }}</p>
                                        </div>
                                        <div v-if="form.attendees.length > 1" class="flex shrink-0 gap-1.5">
                                            <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                                                :disabled="activeTicket === 0" aria-label="Previous ticket" @click="activeTicket--">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true"><path d="m15 18-6-6 6-6" /></svg>
                                            </button>
                                            <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                                                :disabled="activeTicket >= form.attendees.length - 1" aria-label="Next ticket" @click="activeTicket++">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label for="att-name" class="mb-1.5 block text-sm font-semibold text-gray-700">Attendee's full name <span class="text-red-500">*</span></label>
                                            <input id="att-name" v-model="currentAttendee.name" type="text" autocomplete="off" :placeholder="activeTicket === 0 ? 'Your name, or the attendee\'s' : 'Name printed on this ticket'" class="reg-field">
                                        </div>
                                        <div>
                                            <span class="mb-1.5 block text-sm font-semibold text-gray-700">Ticket type <span class="text-red-500">*</span></span>
                                            <FormsSelect v-model="currentAttendee.event_ticket_type_id"
                                                :options="availableTicketTypes.map(ticketType => ({ value: ticketType.id, label: `${ticketType.name} — ${formatCurrency(ticketType.price)}` }))"
                                                placeholder="Select ticket type" :can-clear="false" aria-label="Ticket type" />
                                        </div>
                                        <div :class="signedIn && activeTicket === 0 ? '' : 'sm:col-span-2'">
                                            <label for="att-email" class="mb-1.5 block text-sm font-semibold text-gray-700">Attendee's e-mail <span class="text-xs font-normal text-gray-400">(optional)</span></label>
                                            <input id="att-email" v-model="currentAttendee.email" type="email" autocomplete="off" placeholder="attendee@example.com" class="reg-field">
                                            <p class="mt-1 text-xs text-gray-500">{{ signedIn ? 'We also send this ticket there.' : 'We also send this ticket there. You still get a copy of every ticket.' }}</p>
                                        </div>
                                        <div v-if="signedIn && activeTicket === 0">
                                            <label for="att-phone" class="mb-1.5 block text-sm font-semibold text-gray-700">Your contact number <span class="text-xs font-normal text-gray-400">(optional)</span></label>
                                            <input id="att-phone" v-model="currentAttendee.contact_no" type="tel" autocomplete="tel" placeholder="e.g. 09171234567" class="reg-field">
                                        </div>
                                    </div>

                                    <div v-if="form.attendees.length > 1 || activeTicket < form.attendees.length - 1" class="mt-4 flex items-center justify-between gap-2 border-t border-gray-100 pt-4">
                                        <button v-if="form.attendees.length > 1" type="button" class="inline-flex h-9 items-center gap-1.5 rounded-lg px-2 text-xs font-semibold text-red-600 hover:bg-red-50" @click="removeAttendee(activeTicket)">
                                            <IconBase name="trash" class="h-3.5 w-3.5" />Remove ticket {{ activeTicket + 1 }}
                                        </button>
                                        <span v-else />
                                        <button v-if="activeTicket < form.attendees.length - 1" type="button" class="inline-flex h-9 items-center gap-1 rounded-lg bg-[#285F6b] px-3.5 text-xs font-bold text-white hover:bg-[#1f4a54]" @click="activeTicket++">
                                            Next: ticket {{ activeTicket + 2 }}<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5" aria-hidden="true"><path d="m9 18 6-6-6-6" /></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 0066: ticket types with a layout: choose a table/space or a row (optional; by default we choose, first come, first served).
                             Advisor's follow-up (Nov 2026): one "Seats" step for every ticket type, each part naming who sits there. -->
                        <section v-if="buyerVerified && seatingGroups.length" class="mt-6 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5" aria-label="Seats">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-bold text-gray-900">Seats <span class="font-normal text-gray-400">(optional)</span></p>
                                    <p class="mt-0.5 text-xs leading-5 text-gray-500">{{ seatingGroups.length > 1 ? 'Each ticket type has its own area. ' : '' }}Leave any of it and we choose for you, first come, first served.</p>
                                </div>
                            </div>
                            <div v-for="(group, gi) in seatingGroups" :key="group.id" class="mt-4" :class="gi > 0 ? 'border-t border-gray-100 pt-4' : ''" :aria-label="`Seating for ${group.name}`">
                                <div class="mb-2 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="rounded-full bg-[#285F6b]/10 px-2.5 py-1 text-xs font-bold text-[#285F6b]">{{ group.name }}</span>
                                    <span class="text-xs text-gray-500">for {{ group.who.join(', ') }}</span>
                                </div>
                                <p class="mb-3 text-xs leading-5 text-gray-500">
                                    <template v-if="group.tables.mode === 'rows'">{{ group.need === 1 ? 'Tap a seat in the plan, or pick a row below.' : `Tap a seat for each of the ${group.need} tickets, or pick a row and we seat you side by side when we can.` }}</template>
                                    <template v-else-if="group.need === 1">Tap a {{ group.tables.label }}{{ group.tables.size > 1 ? ` (${group.tables.size} seats each)` : '' }}.</template>
                                    <template v-else>Tap a {{ group.tables.label }} for each ticket{{ group.tables.size > 1 ? ` (${group.tables.size} seats each)` : '' }}: you can split up, or tap one several times to sit together.{{ group.tables.size > 1 && group.need <= group.tables.size ? ` Or choose one ${group.tables.label} for everyone below.` : '' }}</template>
                                </p>
                                <SeatingTablePlan :model-value="tableChoice[group.id] ?? 0" @update:model-value="tableChoice[group.id] = $event" :picks="seatPicks[group.id] ?? []" @update:picks="seatPicks[group.id] = $event" :name="group.name" :mode="group.tables.mode" :label="group.tables.label"
                                    :tables="group.tables.tables" :size="group.tables.size" :need="group.need" :image="gi === 0 ? eventDetail?.floor_plan_url : null" :who="group.who" />
                            </div>
                        </section>

                        <!-- Summary: every ticket on one line (name, type, seat, price). -->
                        <div class="mt-6 rounded-2xl border border-[#285F6b]/10 bg-gradient-to-r from-[#285F6b]/5 to-[#4b8792]/10 p-5">
                            <p class="text-xs font-bold uppercase tracking-[0.12em] text-gray-500">Summary</p>
                            <ul class="mt-2 divide-y divide-[#285F6b]/10 text-sm">
                                <li v-for="(a, i) in form.attendees" :key="i" class="flex items-center justify-between gap-3 py-2">
                                    <button type="button" class="min-w-0 text-left" @click="activeTicket = i">
                                        <span class="block truncate font-semibold" :class="a.name.trim() ? 'text-gray-900' : 'text-amber-700'">{{ i + 1 }}. {{ a.name.trim() || 'Name needed' }}</span>
                                        <span class="block truncate text-xs text-gray-500">{{ ticketTypeName(a) }}<template v-if="seatFor(i)"> · <span class="font-semibold text-[#285F6b]">{{ seatFor(i) }}</span></template></span>
                                    </button>
                                    <span class="shrink-0 font-semibold tabular-nums text-gray-700">{{ formatCurrency(ticketPrice(a)) }}</span>
                                </li>
                            </ul>
                            <div class="mt-2 flex items-center justify-between gap-4 border-t border-[#285F6b]/15 pt-3">
                                <p class="text-sm font-semibold text-gray-900">{{ totalAttendees }} {{ totalAttendees === 1 ? 'ticket' : 'tickets' }}</p>
                                <p class="text-xl font-extrabold text-[#285F6b]">{{ formatCurrency(totalAmount) }}</p>
                            </div>
                        </div>

                        <button type="submit"
                            class="mt-5 inline-flex h-13 w-full items-center justify-center rounded-2xl bg-[#285F6b] px-5 py-3.5 text-sm font-bold text-white shadow-lg shadow-[#285F6b]/20 transition hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-xl disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0"
                            :disabled="submitting || !registrationAvailable || !buyerVerified">
                            {{ submitting ? 'Saving Registration...' : !buyerVerified ? 'Confirm your e-mail to continue' : totalAmount > 0 ? 'Continue to Payment' : 'Submit Registration' }}
                        </button>

                        <p class="mt-3 text-center text-xs leading-5 text-gray-400">
                            Your information will only be used for this event registration.
                        </p>
                    </form>
                </section>

                <aside class="space-y-4 lg:sticky lg:top-6 lg:self-start">
                    <div class="rounded-3xl border border-gray-200/80 bg-white p-5 shadow-xl shadow-slate-900/5">
                        <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-bold text-gray-900">
                            Available Tickets
                        </h3><span class="rounded-full bg-[#285F6b]/10 px-2.5 py-1 text-[11px] font-bold text-[#285F6b]">{{ availableTicketTypes.length }} {{ availableTicketTypes.length === 1 ? 'type' : 'types' }}</span></div>

                        <div v-if="availableTicketTypes.length" class="mt-4 space-y-3">
                            <div v-for="ticketType in availableTicketTypes" :key="ticketType.id"
                                class="group rounded-2xl border border-gray-100 bg-gray-50 p-4 transition hover:border-[#285F6b]/20 hover:bg-[#285F6b]/5">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-semibold text-gray-900">
                                            {{ ticketType.name }}
                                        </p>

                                        <p class="mt-1 text-sm font-bold text-[#285F6b]">
                                            {{ formatCurrency(ticketType.price) }}
                                        </p>
                                    </div>

                                    <span v-if="
                                        ticketType.remaining !== null &&
                                        ticketType.remaining !== undefined
                                    "
                                        class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-600">
                                        {{ ticketType.remaining }} left
                                    </span>
                                </div>
                            </div>
                        </div>

                        <p v-else class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700">
                            No ticket types are currently available.
                            <NuxtLink to="/events" class="ml-1 font-semibold underline">Browse other events</NuxtLink>
                        </p>
                    </div>

                    <div class="rounded-3xl border border-gray-200/80 bg-white p-5 shadow-xl shadow-slate-900/5">
                        <h3 class="text-sm font-bold text-gray-900">
                            What happens next?
                        </h3>

                        <ol class="mt-4 space-y-3 text-sm text-gray-600">
                            <li class="flex gap-3">
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#285F6b]/10 text-xs font-bold text-[#285F6b]">
                                    1
                                </span>
                                {{ signedIn ? 'Check each attendee’s name (yours is filled in).' : 'Confirm your e-mail once with a 6-digit code.' }}
                            </li>

                            <li v-if="!signedIn" class="flex gap-3">
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#285F6b]/10 text-xs font-bold text-[#285F6b]">
                                    2
                                </span>
                                Add a name and ticket type for each ticket.
                            </li>

                            <li class="flex gap-3">
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#285F6b]/10 text-xs font-bold text-[#285F6b]">
                                    3
                                </span>
                                {{ signedIn ? 'Submit (pay if the ticket costs anything): the QR ticket is created.' : 'Submit registration and generate the QR ticket.' }}
                            </li>

                            <li class="flex gap-3">
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[#285F6b]/10 text-xs font-bold text-[#285F6b]">
                                    4
                                </span>
                                {{ signedIn ? 'Find it under My Tickets, or download the PDF.' : 'Every ticket is e-mailed to you; you can also download the PDFs.' }}
                            </li>
                        </ol>
                    </div>
                </aside>
            </div>
        </main>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

import type {
    AttendeeForm,
    PublicEvent,
    PublicRegistrationResponse,
    PublicRegistrationSubmitResponse,
    RegisteredTicket,
} from '~/types/public-registration'

definePageMeta({
    layout: false,
})

type VerificationState = {
    sending: boolean
    verifying: boolean
    sent: boolean
    verified: boolean
    code: string
    error: string
    success: string
    verificationToken: string | null
    verifiedEmail: string
    expiresIn: number
    resendIn: number
}

type VerificationSendResponse = {
    message: string
    data?: {
        expires_in?: number
        resend_in?: number
    }
}

type VerificationVerifyResponse = {
    message: string
    data?: {
        verified?: boolean
        email?: string
        verification_token?: string
    }
}

type AttendeeFormWithContact = AttendeeForm & {
    contact_no?: string
}

const route = useRoute()
const config = useRuntimeConfig()
const api = useApi()
const { user, fetchUser } = useAuth()

/** Signed-in, verified buyers skip the e-mail codes (the API knows who they are). */
const signedIn = computed(() => Boolean(user.value?.email_verified))

const apiBaseURL = String(config.public.apiBase)

/** Set after registering: opens this registration's status and tickets (guests have no session). */
const registrationId = ref<string | null>(null)
const registrationAccess = ref<string | null>(null)

type PublicEventDetail = import('~/composables/useTicketing').PublicEventDetail

/** The public event API, in the shape this page was designed for. */
// Advisor's fix (Nov 2026): overnight / multi-day events show where they end.
const eventSpan = ref<{ starts: string, ends: string, tz: string } | null>(null)
function toPublicEvent(e: PublicEventDetail): PublicEvent {
    eventSpan.value = e.starts_at && e.ends_at ? { starts: e.starts_at, ends: e.ends_at, tz: e.timezone || 'Asia/Manila' } : null
    const day = (iso: string | null) => iso ? new Intl.DateTimeFormat('en-CA', { timeZone: e.timezone }).format(new Date(iso)) : null
    const time = (iso: string | null) => iso ? new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: e.timezone }).format(new Date(iso)) : null
    const tiers = e.tiers.map(t => ({
        id: t.id as unknown as number,
        name: t.name,
        price: t.price,
        capacity: t.remaining,
        issued: 0,
        remaining: t.remaining,
        sold_out: t.availability !== 'available',
    }))

    return {
        id: e.id as unknown as number,
        name: e.name,
        event_type: e.event_type ?? 'Event',
        description: e.description,
        event_date: day(e.starts_at),
        location: [e.venue_name, e.venue_address].filter(Boolean).join(', ') || null,
        expected_guests: null,
        start_time: time(e.starts_at),
        end_time: time(e.ends_at),
        status: e.status,
        public_registration: tiers.some(t => !t.sold_out),
        public_registration_token: null,
        require_approval: false,
        waitlist_enabled: false,
        contact_name: e.organizer.name,
        contact_email: null,
        contact_phone: null,
        ticket_types: tiers,
    } as PublicEvent
}

type GuestRegistration = {
    registration_id: string
    status: string
    tickets: { id: string, status: string, attendee: string, email: string | null, tier: string, seat?: string | null, price: string, qr_token: string | null }[]
}

/** Valid tickets of this registration, in the shape the success panel shows. */
async function loadRegisteredTickets() {
    if (!registrationId.value || !registrationAccess.value) return
    const { data } = await api.get<GuestRegistration>(`/public-registrations/${registrationId.value}`, { query: { access: registrationAccess.value } })
    registeredTickets.value = data.tickets.filter(t => t.status === 'valid').map(t => ({
        id: t.id as unknown as number,
        name: t.attendee,
        email: t.email ?? (data as { buyer?: { email?: string | null } }).buyer?.email ?? null,
        ticket_id: t.id.replace(/-/g, '').slice(-10).toUpperCase(),
        qr_token: t.qr_token ?? '',
        qr_value: t.qr_token ?? '',
        source: 'online',
        payment_status: Number(t.price) > 0 ? 'paid' : 'free',
        status: 'registered',
        checked_in_at: null,
        created_at: new Date().toISOString(),
        ticket_type: { id: 0 as number, name: t.tier, price: t.price, capacity: 0, issued: 0, remaining: null, sold_out: false },
        seat: t.seat ?? null,
    }) as unknown as RegisteredTicket)
}

const token = computed<string>(() => {
    const routeToken = route.params.token

    if (Array.isArray(routeToken)) {
        return routeToken[0] ?? ''
    }

    return typeof routeToken === 'string'
        ? routeToken
        : ''
})

// A private event's link key (?key=…): opens its page and checkout.
const linkKey = computed(() => typeof route.query.key === 'string' && route.query.key ? route.query.key : null)
const withKey = <T extends object>(body: T) => (linkKey.value ? { ...body, key: linkKey.value } : body)

const isPrivateEvent = ref(false)
const loading = ref(true)
const submitting = ref(false)

const errorMessage = ref('')
const successMessage = ref('')

const eventData =
    ref<PublicEvent | null>(null)

const registeredTickets =
    ref<RegisteredTicket[]>([])

const pendingApprovalCount = ref(0)

const pendingPayment = ref<PublicRegistrationSubmitResponse['data']['payment'] | null>(null)

const emailSendingTicketId =
    ref<number | null>(null)

const emailSuccessTicketId =
    ref<number | null>(null)

const emailErrorTicketId =
    ref<number | null>(null)

const emailErrorMessages =
    reactive<Record<number, string>>({})

const attendeeCategoryOptions = [
    { value: 'invited', label: 'Invited — organizer guest' },
    { value: 'free', label: 'Free — no payment required' },
    { value: 'paid', label: 'Paid — PayMongo payment' },
]

const categoryHelp = (category: string) => ({
    invited: 'For a guest attending through an organizer invitation.',
    free: 'A complimentary registration with no payment.',
    paid: 'Continue to secure payment after registration.',
}[category] ?? '')

const createVerificationState =
    (): VerificationState => ({
        sending: false,
        verifying: false,
        sent: false,
        verified: false,
        code: '',
        error: '',
        success: '',
        verificationToken: null,
        verifiedEmail: '',
        expiresIn: 0,
        resendIn: 0,
    })

const form = reactive({
    attendees: [
        {
            name: '',
            email: '',
            contact_no: '',
            attendee_category: 'paid',
            event_ticket_type_id: null,
        },
    ] as AttendeeFormWithContact[],
})

const emailVerificationStates =
    reactive<VerificationState[]>([
        createVerificationState(),
    ])

// Advisor's fix (Nov 2026): a guest buyer confirms their OWN e-mail once (state 0);
// every ticket is sent there, and attendees need only a name.
const buyer = reactive({ name: '', email: '', phone: '' })
const buyerState = computed(() => verificationState(0))
const buyerVerified = computed(() => signedIn.value || Boolean(
    buyerState.value.verified && buyerState.value.verificationToken
    && buyerState.value.verifiedEmail.toLowerCase() === buyer.email.trim().toLowerCase(),
))
// One ticket at a time (◀ Ticket 2 of 4 ▶), with chips to jump between them.
const activeTicket = ref(0)
// 0068: the organizer's tickets-per-buyer cap (10 when not set); the server also counts earlier orders.
const maxTickets = computed(() => eventDetail.value?.max_per_buyer ?? 10)
const listView = ref(false)
const namedCount = computed(() => form.attendees.filter(a => a.name.trim()).length)
const ticketTypeOptions = computed(() => availableTicketTypes.value.map(t => ({ value: t.id, label: `${t.name} — ${formatCurrency(t.price)}` })))
const currentAttendee = computed(() => form.attendees[activeTicket.value] ?? null)
const ticketComplete = (a: AttendeeFormWithContact) => Boolean(a.name.trim() && a.event_ticket_type_id)

let countdownTimer:
    ReturnType<typeof setInterval> |
    null = null

const verificationState = (
    index: number
): VerificationState => {
    if (!emailVerificationStates[index]) {
        emailVerificationStates[index] =
            createVerificationState()
    }

    return emailVerificationStates[index]
}

const ticketTypes = computed(() =>
    eventData.value?.ticket_types ?? []
)

// 0066: tables to choose from, per ticket type picked by at least one attendee.
const eventDetail = ref<PublicEventDetail | null>(null)
const tableChoice = reactive<Record<string, number>>({})
// 0067: exact seats tapped per ticket type ([row, seat]).
const seatPicks = reactive<Record<string, [number, number][]>>({})
const seatingGroups = computed(() => (eventDetail.value?.tiers ?? []).flatMap((t) => {
    const need = form.attendees.filter(a => String(a.event_ticket_type_id) === t.id).length
    // Seats are given in ticket order, so each pick is named after its attendee.
    const who = form.attendees.map((a, i) => ({ a, i })).filter(({ a }) => String(a.event_ticket_type_id) === t.id).map(({ a, i }) => a.name.trim() || `Ticket ${i + 1}`)
    return t.tables && need ? [{ id: t.id, name: t.name, tables: t.tables, need, who }] : []
}))
// A table that no longer seats the group (more attendees, or it filled up): back to "Any table".
watch(seatingGroups, (groups) => {
    for (const g of groups) {
        const chosen = tableChoice[g.id] ?? 0
        if (chosen && (g.tables.tables.find(t => t.number === chosen)?.left ?? 0) < g.need) tableChoice[g.id] = 0
        // Fewer attendees, or a picked seat got taken meanwhile: keep only picks still possible.
        const picks = (seatPicks[g.id] ?? []).filter(([r, s]) => !(g.tables.tables.find(t => t.number === r)?.taken ?? []).includes(s)).slice(-g.need)
        if (picks.length !== (seatPicks[g.id] ?? []).length) seatPicks[g.id] = picks
    }
}, { deep: true })

const availableTicketTypes = computed(() =>
    ticketTypes.value.filter(
        ticketType =>
            !ticketType.sold_out
    )
)

const registrationAvailable = computed(() =>
    Boolean(
        eventData.value?.public_registration &&
        availableTicketTypes.value.length
    )
)

const totalAttendees = computed(
    () => form.attendees.length
)

const verifiedAttendeeCount = computed(
    () =>
        emailVerificationStates.filter(
            state => state.verified
        ).length
)

const allEmailsVerified = computed(
    () =>
        signedIn.value ||
        form.attendees.length > 0 &&
        form.attendees.every(
            (attendee, index) => {
                const state =
                    verificationState(index)

                return (
                    state.verified &&
                    state.verificationToken &&
                    state.verifiedEmail
                        .toLowerCase() ===
                    attendee.email
                        .trim()
                        .toLowerCase()
                )
            }
        )
)

const totalAmount = computed(() =>
    form.attendees.reduce(
        (total, attendee) => {
            const ticketType =
                availableTicketTypes.value.find(
                    item =>
                        item.id ===
                        attendee.event_ticket_type_id
                )

            return (
                total +
                Number(ticketType?.price ?? 0)
            )
        },
        0
    )
)

const hasPaidAttendees = computed(() => totalAmount.value > 0)

const ticketTypeOf = (a: AttendeeFormWithContact) => availableTicketTypes.value.find(t => t.id === a.event_ticket_type_id)
const ticketTypeName = (a: AttendeeFormWithContact) => ticketTypeOf(a)?.name ?? 'Choose a ticket type'
const ticketPrice = (a: AttendeeFormWithContact) => Number(ticketTypeOf(a)?.price ?? 0)
/** The seat tapped for this attendee (rows only): picks follow the order of that ticket type's attendees. */
function seatFor(index: number): string {
    const a = form.attendees[index]
    if (!a) return ''
    const group = seatingGroups.value.find(g => g.id === String(a.event_ticket_type_id))
    if (!group) return ''
    const position = form.attendees.slice(0, index).filter(x => String(x.event_ticket_type_id) === group.id).length
    const pick = (seatPicks[group.id] ?? [])[position]
    if (group.tables.mode !== 'rows') {
        // A table per ticket, or one table chosen for everyone.
        const chosen = pick?.[0] ?? (seatPicks[group.id]?.length ? 0 : tableChoice[group.id])
        return chosen ? (group.tables.tables.find(t => t.number === chosen)?.name ?? '') : ''
    }
    return pick ? `${group.tables.tables.find(t => t.number === pick[0])?.name ?? `Row ${pick[0]}`}, Seat ${pick[1]}` : ''
}

const formatCurrency = (
    amount: string | number
) =>
    new Intl.NumberFormat(
        'en-PH',
        {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }
    ).format(
        Number(amount || 0)
    )

const formatDate = (
    value: string | null
) => {
    if (!value) {
        return 'Date not specified'
    }

    const date =
        new Date(
            value.includes('T')
                ? value
                : `${value}T00:00:00`
        )

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            month: 'long',
            day: 'numeric',
            year: 'numeric',
        }
    ).format(date)
}

const formatTime = (
    value: string | null
) => {
    if (!value) {
        return null
    }

    const parts = value.split(':')

    if (parts.length < 2) {
        return value
    }

    const hours =
        Number(parts[0])

    const minutes =
        Number(parts[1])

    if (
        Number.isNaN(hours) ||
        Number.isNaN(minutes)
    ) {
        return value
    }

    const date =
        new Date()

    date.setHours(
        hours,
        minutes,
        0,
        0
    )

    return new Intl.DateTimeFormat(
        'en-PH',
        {
            hour: 'numeric',
            minute: '2-digit',
        }
    ).format(date)
}

const formatCountdown = (
    seconds: number
): string => {
    const safeSeconds =
        Math.max(0, seconds)

    const minutes =
        Math.floor(
            safeSeconds / 60
        )

    const remainingSeconds =
        safeSeconds % 60

    return `${minutes}:${String(
        remainingSeconds
    ).padStart(2, '0')}`
}

const eventSchedule = computed(() => {
    if (!eventData.value) {
        return ''
    }

    const date =
        formatDate(
            eventData.value.event_date
        )

    const start =
        formatTime(
            eventData.value.start_time
        )

    const end =
        formatTime(
            eventData.value.end_time
        )

    // Several sessions (one per day): "Dec 7 – Dec 9, 2026 • 9:00 AM – 5:00 PM daily" or "Hours vary by day".
    const days = describeSessions(eventDetail.value?.sessions, eventDetail.value?.timezone || 'Asia/Manila')
    if (days) return `${days.dateText} • ${days.same ? days.timeText : days.days.map(d => `${d.label} ${d.hours}`).join(' · ')}`
    const span = eventSpan.value
    if (span && dayGap(span.starts, span.ends, span.tz) > 1) {
        return `${dateSpan(span.starts, span.ends, span.tz)} • ${start} - ${end}`
    }
    if (start && end) {
        return `${date} • ${start} - ${end}${span ? endsSuffix(span.starts, span.ends, span.tz) : ''}`
    }

    return start
        ? `${date} • ${start}`
        : date
})

const getDefaultTicketTypeId =
    (): number | null =>
        availableTicketTypes.value[0]?.id ??
        null

const resetVerificationState = (
    index: number
) => {
    emailVerificationStates[index] =
        createVerificationState()
}

const handleEmailChanged = (
    index: number
) => {
    const state =
        verificationState(index)

    const currentEmail =
        buyer.email.trim().toLowerCase()

    if (
        state.verifiedEmail &&
        currentEmail !==
        state.verifiedEmail
            .trim()
            .toLowerCase()
    ) {
        resetVerificationState(index)
        return
    }

    if (state.sent && !state.verified) {
        state.code = ''
        state.sent = false
        state.success = ''
        state.error = ''
        state.verificationToken = null
        state.expiresIn = 0
        state.resendIn = 0
    }
}

const changeVerifiedEmail = (
    index: number
) => {
    resetVerificationState(index)
}

const sanitizeVerificationCode = (
    index: number
) => {
    const state =
        verificationState(index)

    state.code =
        state.code
            .replace(/\D/g, '')
            .slice(0, 6)
}

const startCountdownTimer = () => {
    if (countdownTimer) {
        return
    }

    countdownTimer =
        setInterval(
            () => {
                emailVerificationStates.forEach(
                    state => {
                        if (state.expiresIn > 0) {
                            state.expiresIn -= 1
                        }

                        if (state.resendIn > 0) {
                            state.resendIn -= 1
                        }
                    }
                )
            },
            1000
        )
}

const addAttendee = () => {
    errorMessage.value = ''

    const defaultTicketTypeId =
        getDefaultTicketTypeId()

    if (!defaultTicketTypeId) {
        errorMessage.value =
            'There are no available ticket types for this event.'

        return
    }

    form.attendees.push({
        name: '',
        email: '',
        contact_no: '',
        attendee_category: 'paid',
        event_ticket_type_id:
            defaultTicketTypeId,
    })
    activeTicket.value = form.attendees.length - 1
}

const removeAttendee = (
    index: number
) => {
    if (
        form.attendees.length === 1
    ) {
        return
    }

    form.attendees.splice(
        index,
        1
    )
    activeTicket.value = Math.min(activeTicket.value, form.attendees.length - 1)
}

const getApiErrorMessage = (
    error: unknown,
    fallback: string
): string => {
    if (error instanceof ApiError) {
        return Object.values(error.fields)[0]?.[0] ?? error.message ?? fallback
    }

    const apiError =
        error as {
            data?: {
                message?: string
                errors?: Record<
                    string,
                    string[]
                >
            }
            message?: string
        }

    const validationMessage =
        Object.values(
            apiError.data?.errors ??
            {}
        )[0]?.[0]

    const message = (
        validationMessage ??
        apiError.data?.message ??
        apiError.message ??
        fallback
    )

    return message.includes('SQLSTATE')
        ? fallback
        : message
}

const sendVerificationCode =
    async (
        index: number
    ) => {
        errorMessage.value = ''

        const email =
            buyer.email.trim()

        const state =
            verificationState(index)

        state.error = ''
        state.success = ''

        if (!email) {
            state.error =
                'Please enter an email address first.'

            return
        }

        const emailPattern =
            /^[^\s@]+@[^\s@]+\.[^\s@]+$/

        if (!emailPattern.test(email)) {
            state.error =
                'Please enter a valid email address.'

            return
        }

        if (state.resendIn > 0) {
            state.error =
                `Please wait ${state.resendIn} seconds before requesting another code.`

            return
        }

        state.sending = true

        try {
            const response =
                await api.post<VerificationSendResponse['data']>(`/public-events/${encodeURIComponent(token.value)}/email-code`, withKey({ email }))

            state.sent = true
            state.verified = false
            state.code = ''
            state.verificationToken = null
            state.verifiedEmail = ''
            state.expiresIn =
                Number(
                    response.data?.expires_in ??
                    600
                )
            state.resendIn =
                Number(
                    response.data?.resend_in ??
                    60
                )
            state.success =
                response.message ||
                'A 6-digit verification code was sent to your email.'
        } catch (error: unknown) {
            state.error =
                getApiErrorMessage(
                    error,
                    'Unable to send the verification code.'
                )
        } finally {
            state.sending = false
        }
    }

const verifyEmailCode =
    async (
        index: number
    ) => {
        errorMessage.value = ''

        const state =
            verificationState(index)

        state.error = ''
        state.success = ''

        const email =
            buyer.email.trim()

        const code =
            state.code.trim()

        if (!email) {
            state.error =
                'Please enter an email address.'

            return
        }

        if (!/^\d{6}$/.test(code)) {
            state.error =
                'Please enter the complete 6-digit verification code.'

            return
        }

        if (state.expiresIn <= 0) {
            state.error =
                'This verification code has expired. Please request a new code.'

            return
        }

        state.verifying = true

        try {
            const response =
                await api.post<VerificationVerifyResponse['data']>(`/public-events/${encodeURIComponent(token.value)}/email-code/verify`, withKey({ email, code }))

            if (
                !response.data?.verified ||
                !response.data
                    ?.verification_token
            ) {
                state.error =
                    'The email could not be verified.'

                return
            }

            state.verified = true
            state.sent = true
            state.verifiedEmail =
                response.data.email ??
                email
            state.verificationToken =
                response.data
                    .verification_token
            state.success =
                response.message ||
                'Email verified successfully.'
            state.code = ''
            // The first ticket is usually the buyer's own.
            if (form.attendees[0] && !form.attendees[0].name.trim() && buyer.name.trim()) form.attendees[0].name = buyer.name.trim()
        } catch (error: unknown) {
            state.error =
                getApiErrorMessage(
                    error,
                    'The verification code is invalid or expired.'
                )
        } finally {
            state.verifying = false
        }
    }

const loadEvent = async () => {
    loading.value = true
    errorMessage.value = ''

    if (!token.value) {
        errorMessage.value =
            'The registration link is invalid or incomplete.'

        loading.value = false

        return
    }

    try {
        await fetchUser()
        const detail = (await api.get<PublicEventDetail>(`/public-events/${encodeURIComponent(token.value)}`, { query: linkKey.value ? { key: linkKey.value } : undefined })).data
        isPrivateEvent.value = !!detail.is_private
        rememberEventKey(token.value, linkKey.value)
        // Signed in: the first attendee is usually the buyer.
        const first = form.attendees[0]
        if (user.value && first && !first.name && !first.email) {
            first.name = [user.value.first_name, user.value.last_name].filter(Boolean).join(' ')
            first.email = user.value.email ?? ''
        }
        eventData.value =
            toPublicEvent(detail)
        eventDetail.value = detail

        const defaultTicketTypeId =
            getDefaultTicketTypeId()

        form.attendees.forEach(
            attendee => {
                const selectedTicketStillAvailable =
                    availableTicketTypes.value.some(
                        ticketType =>
                            ticketType.id ===
                            attendee.event_ticket_type_id
                    )

                if (
                    !selectedTicketStillAvailable
                ) {
                    attendee.event_ticket_type_id =
                        defaultTicketTypeId
                }
            }
        )
    } catch (error: unknown) {
        eventData.value = null

        errorMessage.value = error instanceof ApiError && error.status === 410
            ? error.message
            : error instanceof ApiError && error.status === 404
            ? 'This event isn’t open to the public. If you were invited, open the full link the host sent you.'
            : getApiErrorMessage(
                error,
                'Unable to load this registration page.'
            )
    } finally {
        loading.value = false
    }
}

const submitRegistration =
    async () => {
        errorMessage.value = ''
        successMessage.value = ''

        emailSuccessTicketId.value = null
        emailErrorTicketId.value = null

        if (!token.value) {
            errorMessage.value =
                'The registration link is invalid.'

            return
        }

        if (
            !eventData.value?.public_registration
        ) {
            errorMessage.value =
                'Registration is currently closed for this event.'

            return
        }

        if (
            !availableTicketTypes.value.length
        ) {
            errorMessage.value =
                'There are no available ticket types remaining for this event.'

            return
        }

        const emailPattern =
            /^[^\s@]+@[^\s@]+\.[^\s@]+$/

        if (!signedIn.value && buyer.name.trim().length < 2) {
            errorMessage.value = 'Please enter your name.'

            return
        }

        const invalidAttendeeIndex =
            form.attendees.findIndex(
                attendee =>
                    attendee.name.trim().length < 2 ||
                    (attendee.email.trim() !== '' && !emailPattern.test(
                        attendee.email.trim()
                    )) ||
                    !attendee.event_ticket_type_id
            )

        if (
            invalidAttendeeIndex !== -1
        ) {
            activeTicket.value = invalidAttendeeIndex
            errorMessage.value =
                `Ticket ${invalidAttendeeIndex + 1}: enter the attendee's full name, a valid e-mail (or leave it empty), and choose a ticket type.`

            return
        }

        if (!buyerVerified.value) {
            errorMessage.value = 'Please confirm your e-mail address first.'

            return
        }

        const unavailableTicket =
            form.attendees.find(
                attendee =>
                    !availableTicketTypes.value.some(
                        ticketType =>
                            ticketType.id ===
                            attendee.event_ticket_type_id
                    )
            )

        if (unavailableTicket) {
            errorMessage.value =
                'One of the selected ticket types is no longer available. Please select another ticket type.'

            return
        }

        submitting.value = true

        try {
            const { data, message } =
                await api.post<{
                    registration_id: string
                    access: string
                    status: 'confirmed' | 'awaiting_payment'
                    checkout: { payment_id: string, checkout_url: string, amount: string } | null
                }>(`/public-events/${encodeURIComponent(token.value)}/checkout`, {
                    ...(linkKey.value ? { key: linkKey.value } : {}),
                    attendees: form.attendees.map(attendee => ({
                        full_name: attendee.name.trim(),
                        // Optional: this ticket also goes there (a guest buyer still gets every ticket).
                        email: attendee.email.trim() || null,
                        ticket_type_id: attendee.event_ticket_type_id,
                    })),
                    buyer: signedIn.value
                        ? { phone: form.attendees[0]?.contact_no?.trim() || null }
                        : { name: buyer.name.trim(), email: buyer.email.trim(), phone: buyer.phone.trim() || null, verification_token: buyerState.value.verificationToken },
                    // 0066: the table chosen per ticket type (null: any table).
                    tables: Object.fromEntries(seatingGroups.value.map(g => [g.id, tableChoice[g.id] || null])),
                    // 0067: exact seats tapped (rows), per ticket type.
                    // Tables/spaces: [table] per ticket (Advisor's follow-up, Nov 2026).
                    seats: Object.fromEntries(seatingGroups.value.filter(g => (seatPicks[g.id] ?? []).length)
                        .map(g => [g.id, g.tables.mode === 'rows' ? seatPicks[g.id] : (seatPicks[g.id] ?? []).map(([t]) => [t])])),
                }, { idempotencyKey: crypto.randomUUID() })

            registrationId.value = data.registration_id
            registrationAccess.value = data.access
            pendingApprovalCount.value = 0
            pendingPayment.value = data.checkout
                ? { reference: data.checkout.payment_id, amount: data.checkout.amount, currency: 'PHP', status: 'pending', checkout_url: data.checkout.checkout_url }
                : null

            if (pendingPayment.value) {
                registeredTickets.value = []
                successMessage.value = 'Your places are reserved. Complete payment to receive the QR tickets.'
            } else {
                await loadRegisteredTickets()
                successMessage.value = message ||
                    (registeredTickets.value.length > 1
                        ? 'Registration successful. Your QR tickets are ready.'
                        : 'Registration successful. Your QR ticket is ready.')
            }

            await loadEvent()

            window.scrollTo({
                top: 0,
                behavior: 'smooth',
            })
        } catch (error: unknown) {
            errorMessage.value =
                getApiErrorMessage(
                    error,
                    'Registration failed. Please try again.'
                )
            // Someone may have taken the seats meanwhile: show the tables as they are now.
            if (seatingGroups.value.length) await loadEvent().catch(() => {})
        } finally {
            submitting.value =
                false
        }
    }

const openPayMongoCheckout = () => {
    if (pendingPayment.value?.checkout_url) {
        window.location.assign(pendingPayment.value.checkout_url)
    }
}

const downloadTicket = (
    ticket: RegisteredTicket,
    format: 'pdf' | 'png' = 'pdf'
) => {
    if (!ticket.qr_token) {
        errorMessage.value =
            'This ticket does not have a valid QR token.'

        return
    }

    window.open(
        `${apiBaseURL}/public-registrations/${registrationId.value}/tickets/${ticket.id}/pdf?access=${registrationAccess.value}${format === 'png' ? '&format=png' : ''}`,
        '_blank',
        'noopener,noreferrer'
    )
}

const sendTicketEmail =
    async (
        ticket: RegisteredTicket
    ) => {
        emailSuccessTicketId.value = null
        emailErrorTicketId.value = null
        delete emailErrorMessages[ticket.id]

        if (!ticket.email) {
            emailErrorTicketId.value =
                ticket.id

            const message =
                'This ticket does not have an attendee email address.'

            emailErrorMessages[ticket.id] =
                message

            errorMessage.value =
                message

            return
        }

        if (!ticket.qr_token) {
            emailErrorTicketId.value =
                ticket.id

            const message =
                'This ticket does not have a valid QR token.'

            emailErrorMessages[ticket.id] =
                message

            errorMessage.value =
                message

            return
        }

        emailSendingTicketId.value =
            ticket.id

        try {
            await api.post(`/public-registrations/${registrationId.value}/tickets/${ticket.id}/email`, { access: registrationAccess.value })

            emailSuccessTicketId.value =
                ticket.id

            delete emailErrorMessages[ticket.id]
            errorMessage.value = ''

        } catch (error: unknown) {
            console.error(
                'Unable to send ticket email:',
                error
            )

            emailErrorTicketId.value =
                ticket.id

            const message =
                getApiErrorMessage(
                    error,
                    'Unable to send the QR ticket by email.'
                )

            emailErrorMessages[ticket.id] =
                message

            errorMessage.value =
                message
        } finally {
            emailSendingTicketId.value =
                null
        }
    }

const registerMore = () => {
    registeredTickets.value = []
    pendingApprovalCount.value = 0
    pendingPayment.value = null

    successMessage.value = ''
    errorMessage.value = ''

    emailSendingTicketId.value = null
    emailSuccessTicketId.value = null
    emailErrorTicketId.value = null

    Object.keys(
        emailErrorMessages
    ).forEach(
        key => {
            delete emailErrorMessages[
                Number(key)
            ]
        }
    )

    form.attendees.splice(
        0,
        form.attendees.length,
        {
            name: '',
            email: '',
            contact_no: '',
            attendee_category: 'paid',
            event_ticket_type_id:
                getDefaultTicketTypeId(),
        }
    )

    emailVerificationStates.splice(
        0,
        emailVerificationStates.length,
        createVerificationState()
    )
    activeTicket.value = 0

    window.scrollTo({
        top: 0,
        behavior: 'smooth',
    })
}

onMounted(() => {
    startCountdownTimer()
    loadEvent()
})

onBeforeUnmount(() => {
    if (countdownTimer) {
        clearInterval(
            countdownTimer
        )

        countdownTimer = null
    }
})
</script>

<style scoped>
.reg-field { @apply h-[46px] w-full rounded-xl border border-gray-200 bg-white px-4 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-[#285F6b] focus:ring-2 focus:ring-[#285F6b]/10; }
</style>
