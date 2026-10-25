<template>
    <main class="relative min-h-screen overflow-hidden bg-slate-50 px-4 py-12 sm:px-6">
        <div class="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-primary-100/50 blur-3xl" />

        <div class="relative mx-auto max-w-2xl">
            <NuxtLink to="/" class="mb-8 flex w-fit items-center gap-3" aria-label="NaSeRy home">
                <BrandMark class="h-11 w-11" />
                <div><p class="font-bold text-slate-900">NaSeRy</p><p class="text-xs text-slate-500">Secure event payment</p></div>
            </NuxtLink>

            <section class="overflow-hidden rounded-[28px] border border-slate-200/70 bg-white shadow-2xl shadow-slate-900/10">
                <div class="h-1.5 bg-[#285F6b]" />

                <div class="p-6 sm:p-9">
                    <div v-if="loading" class="py-10 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-50">
                            <IconBase name="refresh-cw" class="h-5 w-5 animate-spin text-primary-700" />
                        </div>
                        <p class="mt-4 font-bold text-slate-900">Confirming your payment…</p>
                        <p class="mt-1 text-sm text-slate-500">Please keep this page open.</p>
                    </div>
                    <div v-else-if="errorMessage" class="rounded-2xl border border-red-100 bg-red-50 p-5 text-red-700">
                        <p class="font-bold">We could not confirm the payment</p>
                        <p class="mt-1 text-sm">{{ errorMessage }}</p>
                        <button class="mt-4 inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-bold shadow-sm ring-1 ring-red-200 transition hover:bg-red-50/50" @click="loadPayment">
                            <IconBase name="refresh-cw" class="h-4 w-4" />
                            Try again
                        </button>
                    </div>
                    <template v-else>
                        <div class="relative mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 ring-8 ring-emerald-50/60">
                            <IconBase name="check-circle" class="h-8 w-8 text-emerald-600" />
                        </div>
                        <h1 class="mt-5 text-center text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">Payment confirmed</h1>
                        <p class="mx-auto mt-2 max-w-sm text-center text-sm leading-6 text-slate-500">Your registration is complete. We've e-mailed your {{ tickets.length > 1 ? 'tickets' : 'ticket' }} to <span class="font-semibold text-slate-700">{{ sentTo }}</span> (check Spam if it isn't there in a few minutes). You can also download or print {{ tickets.length > 1 ? 'them' : 'it' }} here.</p>

                        <div class="mt-8 space-y-3">
                            <div v-for="ticket in tickets" :key="ticket.id"
                                class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-5 transition hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-md sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex items-center gap-3.5">
                                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50">
                                        <IconBase name="ticket" class="h-5 w-5 text-primary-700" />
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-slate-900">{{ ticket.name }}</p>
                                        <p class="mt-0.5 text-sm text-slate-500">{{ ticket.ticket_type?.name }} · <span class="font-mono">{{ ticket.ticket_id }}</span></p>
                                    </div>
                                </div>
                                <button
                                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-bold text-white shadow-sm shadow-primary-900/20 transition hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md"
                                    @click="openDeliveryModal(ticket)">
                                    <IconBase name="ticket" class="h-4 w-4" />
                                    Get My Ticket
                                </button>
                            </div>
                        </div>

                        <div v-if="!tickets.length" class="mt-8 flex items-start gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-5">
                            <IconBase name="clock" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
                            <p class="text-sm leading-6 text-amber-800">Payment is complete. This event requires organizer approval, so your ticket will be emailed after approval.</p>
                        </div>

                        <div v-if="registrationToken" class="mt-8 border-t border-dashed border-slate-200 pt-6 text-center">
                            <NuxtLink :to="registrationPath(registrationToken)"
                                class="inline-flex items-center gap-1.5 rounded-full border border-primary-100 bg-primary-50/60 px-4 py-2 text-sm font-bold text-primary-700 transition hover:bg-primary-100">
                                <IconBase name="arrow-left" class="h-3.5 w-3.5" />
                                Return to Event Registration
                            </NuxtLink>
                        </div>
                    </template>
                </div>
            </section>

            <!-- Way out once the purchase is done (this page has no site menu). -->
            <nav v-if="!loading" class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row" aria-label="After your purchase">
                <NuxtLink to="/"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-[#1f4a54] sm:w-auto">
                    <IconBase name="home" class="h-4 w-4" />
                    Back to home
                </NuxtLink>
                <NuxtLink v-if="isLoggedIn" to="/my-tickets"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:border-primary-200 hover:text-primary-700 sm:w-auto">
                    <IconBase name="ticket" class="h-4 w-4" />
                    My Tickets
                </NuxtLink>
                <NuxtLink to="/events"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 transition hover:border-primary-200 hover:text-primary-700 sm:w-auto">
                    Browse more events
                </NuxtLink>
            </nav>
        </div>

        <RegistrationTicketDeliveryModal :open="activeTicket !== null" :ticket-name="activeTicket?.name ?? ''"
            :ticket-label="activeTicket ? `${activeTicket.ticket_type?.name ?? ''} · ${activeTicket.ticket_id}` : ''"
            :emailing="activeTicket !== null && emailingId === activeTicket.id"
            :email-sent="activeTicket !== null && emailedIds.has(activeTicket.id)" :email-error="emailError"
            :already-emailed-to="activeTicket?.email || sentTo"
            @close="closeDeliveryModal" @download="downloadTicket(activeTicket!)" @email="emailTicket(activeTicket!)"
            @print="printTicket(activeTicket!)" />
    </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { RegisteredTicket } from '~/types/public-registration'

definePageMeta({ layout: false })
const route = useRoute()
const config = useRuntimeConfig()
const api = useApi()
const { isLoggedIn } = useAuth()
const apiBaseURL = String(config.public.apiBase)
const loading = ref(true)
const errorMessage = ref('')
const tickets = ref<RegisteredTicket[]>([])
const registrationToken = ref('')
const emailingId = ref<number | null>(null)
const emailedIds = ref<Set<number>>(new Set())
const emailError = ref('')
const activeTicket = ref<RegisteredTicket | null>(null)

// PayMongo sends the buyer back with the registration id, its access key and the payment id.
const query = (key: string) => (typeof route.query[key] === 'string' ? route.query[key] as string : '')
const registrationId = query('registration')
const access = query('access')

type GuestRegistration = {
    status: string
    event: { id: string, name: string }
    payment: { id: string, status: string, failure_reason: string | null } | null
    tickets: { id: string, status: string, attendee: string, email: string | null, tier: string, price: string, qr_token: string | null }[]
}

const openDeliveryModal = (ticket: RegisteredTicket) => {
    emailError.value = ''
    activeTicket.value = ticket
}
const closeDeliveryModal = () => { activeTicket.value = null }
// Tickets are e-mailed automatically once the payment is confirmed (to the attendee, else the buyer).
const sentTo = computed(() => tickets.value.find(t => t.email)?.email || 'your e-mail')

const loadPayment = async () => {
    loading.value = true
    errorMessage.value = ''
    if (!registrationId || !access) { errorMessage.value = 'The payment link is incomplete.'; loading.value = false; return }
    try {
        let data: GuestRegistration | null = null
        // The confirmation can take a few seconds to arrive from PayMongo.
        for (let attempt = 0; attempt < 5; attempt++) {
            data = (await api.get<GuestRegistration>(`/public-registrations/${registrationId}`, { query: { access, payment: query('payment') || undefined } })).data
            if (query('cancelled') || data.status !== 'pending' || data.payment?.status !== 'pending') break
            await new Promise(r => setTimeout(r, 2000))
        }
        if (query('cancelled') || data?.payment?.status === 'cancelled' || data?.payment?.status === 'failed') {
            await navigateTo({ path: '/event-registration/payment-cancelled', query: { event: data?.event.id } }, { replace: true })
            return
        }
        if (data?.status !== 'confirmed') throw new Error('Payment is still processing. Please refresh this page in a moment.')
        registrationToken.value = data.event.id
        tickets.value = data.tickets.filter(t => t.status === 'valid').map(t => ({
            id: t.id as unknown as number,
            name: t.attendee,
            email: t.email,
            ticket_id: t.id.replace(/-/g, '').slice(-10).toUpperCase(),
            qr_token: t.qr_token ?? '',
            qr_value: t.qr_token ?? '',
            ticket_type: { id: 0, name: t.tier, price: t.price, capacity: 0, issued: 0, remaining: null, sold_out: false },
        }) as unknown as RegisteredTicket)
    } catch (error: unknown) {
        errorMessage.value = getApiErrorMessage(error, 'Unable to verify this payment.')
    } finally { loading.value = false }
}

const emailTicket = async (ticket: RegisteredTicket) => {
    emailError.value = ''
    emailingId.value = ticket.id
    try {
        await api.post(`/public-registrations/${registrationId}/tickets/${ticket.id}/email`, { access })
        emailedIds.value.add(ticket.id)
    } catch (error: unknown) {
        emailError.value = getApiErrorMessage(error, 'Unable to email this ticket. Please try again.')
    } finally { emailingId.value = null }
}

function getApiErrorMessage(error: unknown, fallback: string): string {
    if (error instanceof ApiError) return Object.values(error.fields)[0]?.[0] ?? error.message ?? fallback
    if (error instanceof Error) return error.message
    return fallback
}

const pdfUrl = (ticket: RegisteredTicket) => `${apiBaseURL}/public-registrations/${registrationId}/tickets/${ticket.id}/pdf?access=${access}`
const downloadTicket = (ticket: RegisteredTicket) => window.location.assign(pdfUrl(ticket))
const printTicket = (ticket: RegisteredTicket) => {
    const popup = window.open(pdfUrl(ticket), '_blank')
    popup?.focus()
}

onMounted(loadPayment)
</script>
