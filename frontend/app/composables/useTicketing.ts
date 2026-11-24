// Ticketing (B9): organizer tiers + guest list, public events, my tickets.

export type TierStatus = 'draft' | 'on_sale' | 'paused' | 'closed'

export interface TicketTier {
  id: string
  name: string
  description: string | null
  zone_name: string | null
  benefits: string[]
  price: string
  currency: string
  capacity: number
  status: TierStatus
  sort_order: number
  sales_start_at: string | null
  sales_end_at: string | null
  sales_start_local: string | null
  sales_end_local: string | null
  sold: number
  checked_in: number
  held: number
  remaining: number
}

export interface TierInput {
  name: string
  description?: string | null
  zone_name?: string | null
  benefits?: string[]
  price: number | string
  capacity: number | string
  sales_start_at?: string | null
  sales_end_at?: string | null
  status?: TierStatus
  sort_order?: number
}

export interface TicketSummary { total: number, valid: number, used: number, cancelled: number }

export interface GuestRow {
  id: string
  name: string
  email: string | null
  contact_number: string | null
  tier: string
  zone: string | null
  seat: string | null
  status: 'valid' | 'used' | 'cancelled' | 'invalidated'
  admission_type: 'paid' | 'free' | 'invited' | 'complimentary'
  source: 'online' | 'organizer' | 'staff' | 'walk_in'
  issued_at: string | null
  cancellation_reason: string | null
  // B13: the last ticket e-mail (sent / queued / failed / suppressed + why).
  email_delivery: { status: 'queued' | 'sent' | 'failed' | 'suppressed', recipient: string | null, at: string, error: string | null } | null
}

export interface PublicEventCard {
  id: string
  name: string
  /** Uploaded event cover, when there is one. */
  cover_url?: string | null
  event_type: string | null
  status: string
  organizer: { name: string, slug: string }
  venue_name: string | null
  venue_address: string | null
  timezone: string
  starts_at: string | null
  ends_at: string | null
  from_price?: string | null
  tiers?: number
}

export interface PublicTier {
  id: string
  name: string
  description: string | null
  zone_name: string | null
  benefits: string[]
  price: string
  currency: string
  remaining: number
  sales_start_at: string | null
  sales_end_at: string | null
  availability: 'available' | 'sold_out' | 'not_yet' | 'ended'
}

// The card has a tier count; the detail page has the tiers themselves.
export interface PublicEventDetail extends Omit<PublicEventCard, 'tiers'> {
  is_private?: boolean
  description: string | null
  latitude: number | null
  longitude: number | null
  sessions: { name: string | null, starts_at: string, ends_at: string }[]
  tiers: PublicTier[]
}

export interface MyTicket {
  id: string
  status: 'valid' | 'used' | 'cancelled' | 'invalidated'
  attendee: string
  tier: string
  zone: string | null
  seat: string | null
  benefits: string[]
  event_id: string
  event: string
  event_status: string
  organizer: string
  venue: string | null
  starts_at: string | null
  qr_token: string | null
  cancellable: boolean
  /** 0061: a paid purchase can be refunded on request; the organizer decides. */
  refund?: { paid: boolean, can_request: boolean, request_status: 'pending' | 'refunded' | 'declined' | 'withdrawn' | null, response_note: string | null,
    /** Money already returned to the buyer (any refund, asked for or not). */
    paid_amount?: string | null, refunded_amount?: string | null, refunded_at?: string | null }
}

export function priceLabel(price: string | number | null | undefined): string {
  return Number(price ?? 0) === 0 ? 'Free' : formatPeso(price ?? 0)
}

type Page<T> = { items: T[], pagination: { current_page: number, last_page: number, total: number }, meta?: Record<string, unknown> }

export const useOrganizerTicketing = () => {
  const api = useApi()
  const base = (orgId: string, eventId: string) => `/organizations/${orgId}/events/${eventId}`
  const key = () => ({ idempotencyKey: crypto.randomUUID() })

  return {
    tiers: async (orgId: string, eventId: string) =>
      (await api.get<{ tiers: TicketTier[], summary: TicketSummary, overall_capacity: number | null, can_manage: boolean, can_manage_guests: boolean }>(`${base(orgId, eventId)}/ticket-types`)).data,
    removeTier: async (orgId: string, eventId: string, id: string) => (await api.delete<TicketTier[]>(`${base(orgId, eventId)}/ticket-types/${id}`)).data,

    guests: async (orgId: string, eventId: string, filters: { search?: string, status?: string, ticket_type_id?: string, page?: number }): Promise<Page<GuestRow>> => {
      const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined))
      const r = await api.get<GuestRow[]>(`${base(orgId, eventId)}/guests`, { query })
      return { items: r.data, pagination: r.meta?.pagination as Page<GuestRow>['pagination'], meta: r.meta as Record<string, unknown> }
    },
    addGuest: async (orgId: string, eventId: string, body: { full_name: string, email: string | null, contact_number: string | null, ticket_type_id: string, admission_type: 'invited' | 'complimentary', send_email: boolean, seat?: string | null }) =>
      (await api.post<{ ticket_id: string, qr_token: string }>(`${base(orgId, eventId)}/guests`, body, key())).data,
    importGuests: async (orgId: string, eventId: string, file: File, ticketTypeId: string, admission: 'invited' | 'complimentary', sendEmail = true) => {
      const form = new FormData()
      form.append('file', file)
      form.append('ticket_type_id', ticketTypeId)
      form.append('admission_type', admission)
      form.append('send_email', sendEmail ? '1' : '0')
      return (await api.post<{ imported: number }>(`${base(orgId, eventId)}/guests/import`, form, key())).data
    },
    exportCsv: (orgId: string, eventId: string) => api.blob(`${base(orgId, eventId)}/guests/export`),
    qr: async (orgId: string, eventId: string, ticketId: string) =>
      (await api.get<{ ticket_id: string, status: string, qr_token: string | null }>(`${base(orgId, eventId)}/tickets/${ticketId}/qr`)).data,
    reissueQr: async (orgId: string, eventId: string, ticketId: string) =>
      (await api.post<{ ticket_id: string, qr_token: string }>(`${base(orgId, eventId)}/tickets/${ticketId}/reissue-qr`)).data,
    setSeat: (orgId: string, eventId: string, ticketId: string, seat: string | null) =>
      api.patch(`${base(orgId, eventId)}/tickets/${ticketId}/seat`, { seat }),
    cancelTicket: (orgId: string, eventId: string, ticketId: string, reason: string) =>
      api.post(`${base(orgId, eventId)}/tickets/${ticketId}/cancel`, { reason }),
    ticketPdf: (orgId: string, eventId: string, ticketId: string) => api.blob(`${base(orgId, eventId)}/tickets/${ticketId}/pdf`),
    emailTicket: (orgId: string, eventId: string, ticketId: string) =>
      api.post<{ email_delivery: GuestRow['email_delivery'] }>(`${base(orgId, eventId)}/tickets/${ticketId}/email`),
  }
}

export const usePublicEvents = () => {
  const api = useApi()

  return {
    list: async (filters: { search?: string, page?: number }): Promise<Page<PublicEventCard>> => {
      const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined))
      const r = await api.get<PublicEventCard[]>('/public-events', { query })
      return { items: r.data, pagination: r.meta?.pagination as Page<PublicEventCard>['pagination'] }
    },
    // key: a private event's link key (opens its page and checkout; public events need none).
    show: async (id: string, key?: string | null) => (await api.get<PublicEventDetail>(`/public-events/${id}`, { query: key ? { key } : undefined })).data,
    register: async (id: string, ticketTypeId: string, attendees: { full_name: string, email?: string | null }[], key?: string | null) =>
      (await api.post<{ registration_id: string, status: 'confirmed' | 'awaiting_payment', hold_expires_at: string | null, checkout?: CheckoutStart }>(
        `/public-events/${id}/register`, { ticket_type_id: ticketTypeId, attendees, ...(key ? { key } : {}) }, { idempotencyKey: crypto.randomUUID() })),
    myTickets: async () => (await api.get<MyTicket[]>('/me/tickets')).data,
    cancelMine: (ticketId: string) => api.post(`/me/tickets/${ticketId}/cancel`),
    requestRefund: (ticketId: string, reason: string) => api.post(`/me/tickets/${ticketId}/refund-request`, { reason }),
    myTicketPdf: (ticketId: string) => api.blob(`/me/tickets/${ticketId}/pdf`),
    /** The ticket as an image: QR with the event, type, name and seat (field test, Nov 2026). */
    myTicketImage: (ticketId: string) => api.blob(`/me/tickets/${ticketId}/pdf?format=png`),
  }
}

// ---- Payments (B10) ----------------------------------------------------

export interface CheckoutStart { payment_id: string, checkout_url: string, amount: string, mode: 'fake' | 'test' | 'live' }

export interface PendingReservation {
  registration_id: string
  event_id: string
  event: string
  tier: string
  quantity: number
  amount: string
  hold_expires_at: string
}

export interface PaymentState {
  payment_id: string
  // B18: plan payments share the demo checkout; they have no registration.
  kind?: 'tickets' | 'subscription'
  status: 'pending' | 'processing' | 'paid' | 'failed' | 'cancelled'
  amount: string
  registration_status?: 'pending' | 'confirmed' | 'cancelled'
  event_id?: string
  hold_expires_at?: string | null
  failure_reason: string | null
  mode: 'fake' | 'test' | 'live'
}

export type RefundCategory = 'force_majeure' | 'payment_error' | 'organizer_fault' | 'buyer_request'

// Policy: NaSeRy's platform fee is returned only for a valid reason, never for the organizer's negligence.
export const refundCategories: { value: RefundCategory, label: string, hint: string, returnsFee: boolean }[] = [
  { value: 'force_majeure', label: 'Force majeure', hint: 'Typhoon, calamity or government order cancelled or moved the event', returnsFee: true },
  { value: 'payment_error', label: 'Payment error', hint: 'Duplicate charge or a technical payment problem', returnsFee: true },
  { value: 'organizer_fault', label: 'Organizer fault', hint: 'Cancelled or changed by the organizer, overbooking, or another mistake on our side', returnsFee: false },
  { value: 'buyer_request', label: 'Buyer request', hint: 'The guest can no longer attend (goodwill refund)', returnsFee: false },
]

export interface EventPayment {
  id: string
  status: 'paid' | 'pending' | 'failed'
  amount: string
  fee: string | null
  refunded: string
  fee_returned: string
  /** When the last refund on this payment went out. */
  refunded_at?: string | null
  net: string | null
  buyer: string | null
  email: string | null
  tickets: number
  paid_at: string | null
  created_at: string
  failure_reason: string | null
  /** 0061: the buyer's open refund request on this payment. */
  refund_request: { id: string, reason: string, created_at: string } | null
}

export const usePayments = () => {
  const api = useApi()

  return {
    pending: async () => {
      const r = await api.get<PendingReservation[]>('/me/registrations/pending')
      return { items: r.data, mode: (r.meta?.mode ?? 'fake') as CheckoutStart['mode'] }
    },
    checkout: async (registrationId: string) =>
      (await api.post<CheckoutStart>(`/me/registrations/${registrationId}/checkout`, {}, { idempotencyKey: crypto.randomUUID() })).data,
    cancelReservation: (registrationId: string) => api.post(`/me/registrations/${registrationId}/cancel`),
    status: async (paymentId: string) => (await api.get<PaymentState>(`/me/payments/${paymentId}`)).data,
    /** Demo gateway button: the signed URL comes from the checkout link. */
    demoComplete: (actionUrl: string, intent: string, outcome: 'paid' | 'failed') => api.post(actionUrl, { outcome, intent }),
    eventPayments: async (orgId: string, eventId: string) =>
      (await api.get<{ payments: EventPayment[], totals: { gross: string, fees: string, fee_returned: string, refunds: string, net: string } }>(`/organizations/${orgId}/events/${eventId}/payments`)).data,
    refund: (orgId: string, eventId: string, paymentId: string, body: { amount: number, category: RefundCategory, reason: string, cancel_tickets: boolean }) =>
      api.post(`/organizations/${orgId}/events/${eventId}/payments/${paymentId}/refund`, body, { idempotencyKey: crypto.randomUUID() }),
    declineRefundRequest: (orgId: string, eventId: string, requestId: string, note: string | null) =>
      api.post(`/organizations/${orgId}/events/${eventId}/refund-requests/${requestId}/decline`, { note }),
  }
}
