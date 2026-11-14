// Check-in (B11): door scanning, manual check-in, undo, live numbers.

export type ScanResult = 'checked_in' | 'duplicate' | 'invalid' | 'wrong_event' | 'cancelled' | 'unpaid' | 'not_open' | 'event_closed'

export interface CheckInSession {
  id: string
  label: string
  starts_at: string
  ends_at: string
  opens_at: string
  is_open: boolean
  checked_in: number
}

export interface ScanOutcome {
  result: ScanResult
  message: string
  guest: { name: string, tier: string, zone: string | null, seat?: string | null, benefits: string[] } | null
  attendance_id?: string
  session?: Omit<CheckInSession, 'checked_in'>
  checked_in_at?: string
  checked_in_by?: string | null
  duplicate_attempts?: number
}

export interface RecentCheckIn {
  id: string
  name: string
  tier: string
  zone: string | null
  method: 'qr_scan' | 'manual'
  session_id: string | null
  checked_in_at: string
  checked_in_by: string | null
  duplicate_attempts: number
}

export interface CheckInSummary {
  expected: number
  checked_in: number
  walk_ins: number
  /** Tickets sold at the door (already in expected / checked_in). */
  door_sales?: number
  arrived: number
  sessions: CheckInSession[]
  tiers: { name: string, expected: number, checked_in: number }[]
  recent: RecentCheckIn[]
  opens_before_minutes: number
  event_status: string
  can: { scan: boolean, undo: boolean }
}

export interface DoorGuest {
  ticket_id: string
  name: string
  email: string | null
  tier: string
  zone: string | null
  status: 'valid' | 'used' | 'cancelled'
  last_checked_in_at: string | null
}

export interface ClientAttendance {
  event_status: string
  expected: number
  checked_in: number
  walk_ins: number
  arrived: number
  sessions: Pick<CheckInSession, 'id' | 'label' | 'starts_at' | 'ends_at' | 'is_open' | 'checked_in'>[]
  updated_at: string
}

// Walk-ins (B12)
export type CapacityLevel = 'unlimited' | 'ok' | 'warning' | 'full' | 'over'

export interface VenueCapacity {
  limit: number | null
  used: number
  remaining: number | null
  percent: number | null
  level: CapacityLevel
  warning_percent: number
}

export interface WalkInEntry {
  id: string
  category: 'ticket_linked' | 'paid_walk_in' | 'registered_attendee' | 'headcount_only'
  count: number
  name: string | null
  tier: string | null
  amount: string | null
  recorded_by: string | null
  recorded_at: string
}

export interface WalkInSummary {
  capacity: VenueCapacity
  totals: { door_sales: number, names: number, headcount: number, walk_ins: number, door_revenue: string }
  tiers: { id: string, name: string, zone: string | null, price: string, status: string, remaining: number }[]
  sessions: Omit<CheckInSession, 'checked_in'>[]
  recent: WalkInEntry[]
  event_status: string
  can: { record: boolean, override: boolean, undo: boolean }
}

export interface WalkInResult {
  walk_in_id: string
  ticket_id?: string
  amount_due?: string
  over_capacity_by: number
  message: string
}

export interface WalkInPerson { full_name: string, email?: string | null, contact_number?: string | null }
export interface WalkInOptions { session_id?: string | null, override_reason?: string | null }

// Green = let them in; amber = look closer; red = stop.
export const scanTone: Record<ScanResult, 'ok' | 'warn' | 'stop'> = {
  checked_in: 'ok',
  duplicate: 'warn',
  not_open: 'warn',
  invalid: 'stop',
  wrong_event: 'stop',
  cancelled: 'stop',
  unpaid: 'stop',
  event_closed: 'stop',
}

export const scanTitle: Record<ScanResult, string> = {
  checked_in: 'Checked in',
  duplicate: 'Already checked in',
  invalid: 'Invalid QR',
  wrong_event: 'Wrong event',
  cancelled: 'Ticket cancelled',
  unpaid: 'Not paid',
  not_open: 'Check-in not open',
  event_closed: 'Event not open',
}

export const useCheckIn = () => {
  const api = useApi()
  const base = (orgId: string, eventId: string) => `/organizations/${orgId}/events/${eventId}/checkin`

  return {
    summary: async (orgId: string, eventId: string) => (await api.get<CheckInSummary>(base(orgId, eventId))).data,
    scan: async (orgId: string, eventId: string, token: string, sessionId?: string | null) =>
      (await api.post<ScanOutcome>(`${base(orgId, eventId)}/scan`, { token, session_id: sessionId || null })).data,
    manual: async (orgId: string, eventId: string, ticketId: string, proof: { ticket_code?: string, no_ticket_reason?: string }, sessionId?: string | null) =>
      (await api.post<ScanOutcome>(`${base(orgId, eventId)}/manual`, { ticket_id: ticketId, session_id: sessionId || null, ...proof })).data,
    search: async (orgId: string, eventId: string, q: string) =>
      (await api.get<DoorGuest[]>(`${base(orgId, eventId)}/search`, { query: { q } })).data,
    undo: (orgId: string, eventId: string, attendanceId: string, reason: string) =>
      api.post(`${base(orgId, eventId)}/${attendanceId}/undo`, { reason }),
    walkIns: async (orgId: string, eventId: string) =>
      (await api.get<WalkInSummary>(`/organizations/${orgId}/events/${eventId}/walk-ins`)).data,
    sell: async (orgId: string, eventId: string, body: WalkInPerson & WalkInOptions & { ticket_type_id: string, cash_received: boolean }) =>
      (await api.post<WalkInResult>(`/organizations/${orgId}/events/${eventId}/walk-ins/sale`, body, { idempotencyKey: crypto.randomUUID() })).data,
    logName: async (orgId: string, eventId: string, body: WalkInPerson & WalkInOptions) =>
      (await api.post<WalkInResult>(`/organizations/${orgId}/events/${eventId}/walk-ins/name`, body, { idempotencyKey: crypto.randomUUID() })).data,
    headcount: async (orgId: string, eventId: string, body: { count: number } & WalkInOptions) =>
      (await api.post<WalkInResult>(`/organizations/${orgId}/events/${eventId}/walk-ins/headcount`, body, { idempotencyKey: crypto.randomUUID() })).data,
    undoWalkIn: (orgId: string, eventId: string, walkInId: string, reason: string) =>
      api.post(`/organizations/${orgId}/events/${eventId}/walk-ins/${walkInId}/undo`, { reason }),
    clientAttendance: async (eventId: string) => (await api.get<ClientAttendance>(`/events/${eventId}/attendance`)).data,
  }
}
