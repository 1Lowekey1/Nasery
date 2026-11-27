// Frontend merge (2026-09-29): the redesigned pages were written for an
// older API (numeric ids, `event_date` + `start_time`, `quotation_status`...).
// These helpers turn NaSeRy API objects into those shapes so the pages keep
// their design and logic. Ids stay UUID strings (typed as the pages expect).

import type { NaseryEvent, OrganizerEvent as ApiOrganizerEvent } from '~/composables/useEvents'
import type { OrganizerQuotation as ApiOrganizerQuotation, QuotationStatus } from '~/composables/useQuotations'

/** A route/query id as the UUID string it is (the old pages used Number()). */
export function routeId(value: unknown): string | null {
  const v = Array.isArray(value) ? value[0] : value
  return typeof v === 'string' && v.trim() !== '' ? v : null
}

const localDate = (iso: string | null, timeZone: string) =>
  iso ? new Intl.DateTimeFormat('en-CA', { timeZone }).format(new Date(iso)) : null
const localTime = (iso: string | null, timeZone: string) =>
  iso ? new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone }).format(new Date(iso)) : null

export interface LegacyEvent {
  id: string
  inquiry_id: string | null
  quotation_id: string | null
  name: string
  event_type: string
  description: string | null
  event_date: string | null
  /** Last day of the event (differs from event_date for overnight / multi-day events). */
  end_date: string | null
  location: string | null
  expected_guests: number | null
  start_time: string | null
  end_time: string | null
  status: string
  visibility: 'public' | 'private'
  readiness_percent: number | null
  timezone: string
  venue_name: string | null
  venue_address: string | null
  contact_name: string | null
  contact_email: string | null
  contact_phone: string | null
  starts_at: string | null
  ends_at: string | null
  client_name: string | null
  event_type_id: string | null
  first_session_id: string | null
  public_registration: boolean
  /** Uploaded cover picture (MediaController). */
  cover_url: string | null
  ticket_types?: { id: string, name: string, price: string | number, capacity: number }[]
}

export function toLegacyEvent(e: NaseryEvent | ApiOrganizerEvent, quotationId: string | null = null): LegacyEvent {
  const accepted = 'accepted_quotation_id' in e ? e.accepted_quotation_id : null
  const tz = e.timezone || 'Asia/Manila'
  const first = [...(e.sessions ?? [])].sort((a, b) => a.starts_at.localeCompare(b.starts_at))[0]
  const last = [...(e.sessions ?? [])].sort((a, b) => b.ends_at.localeCompare(a.ends_at))[0]
  const starts = first?.starts_at ?? e.starts_at
  const ends = last?.ends_at ?? e.ends_at

  return {
    id: e.id,
    inquiry_id: e.inquiry_id,
    quotation_id: quotationId ?? accepted,
    name: e.name,
    event_type: e.event_type ?? 'Event',
    description: e.description,
    event_date: localDate(starts, tz),
    end_date: localDate(ends, tz),
    location: [e.venue_name, e.venue_address].filter(Boolean).join(', ') || null,
    expected_guests: e.overall_capacity,
    start_time: localTime(starts, tz),
    end_time: localTime(ends, tz),
    status: e.status,
    visibility: e.visibility,
    readiness_percent: e.readiness_percent,
    timezone: tz,
    venue_name: e.venue_name,
    venue_address: e.venue_address,
    contact_name: e.contact_name,
    contact_email: e.contact_email,
    contact_phone: e.contact_phone,
    starts_at: starts,
    ends_at: ends,
    client_name: 'client' in e && e.client ? e.client.name : null,
    event_type_id: e.event_type_id,
    first_session_id: first?.id ?? null,
    public_registration: e.visibility === 'public',
    cover_url: e.cover_url ?? null,
  }
}

const quotationStatus: Record<QuotationStatus, 'pending' | 'accepted' | 'rejected' | 'withdrawn'> = {
  draft: 'pending',
  submitted: 'pending',
  accepted: 'accepted',
  rejected: 'rejected',
  withdrawn: 'withdrawn',
  cancelled: 'withdrawn',
}

export interface LegacyQuotation {
  id: string
  inquiry_id: string
  organizer_id: string
  quotation_amount: string
  package_name: string | null
  timeline: string | null
  quotation_details: string | null
  quotation_status: 'pending' | 'accepted' | 'rejected' | 'withdrawn'
  status: QuotationStatus
  submitted_at: string | null
  event_id: string | null
  client: ApiOrganizerQuotation['client']
  inquiry?: {
    id: string
    event_title: string | null
    event_type: string
    event_date: string | null
    start_time: string | null
    end_time: string | null
    location: string
    expected_guests?: number
    budget_min: string | null
    budget_max: string | null
  }
  event: LegacyEvent | null
}

export function toLegacyQuotation(q: ApiOrganizerQuotation, events: LegacyEvent[] = []): LegacyQuotation {
  return {
    id: q.id,
    inquiry_id: q.inquiry_id,
    organizer_id: q.organization_id,
    quotation_amount: q.total_amount,
    package_name: q.package_name,
    timeline: q.terms,
    quotation_details: q.proposal,
    quotation_status: quotationStatus[q.status],
    status: q.status,
    submitted_at: q.submitted_at,
    event_id: q.event_id,
    client: q.client,
    inquiry: q.inquiry
      ? {
          id: q.inquiry.id,
          // The client's own event name, else "Wedding for Nicole D." (the event's own name wins once it exists).
          event_title: q.inquiry.event_name || `${q.inquiry.event_type ?? 'Event'}${q.client?.name ? ` for ${q.client.name}` : ''}`,
          event_type: q.inquiry.event_type ?? 'Event',
          event_date: q.inquiry.requested_date,
          start_time: null,
          end_time: null,
          location: q.inquiry.location_text ?? '',
          expected_guests: q.inquiry.guest_count ?? undefined,
          budget_min: q.inquiry.budget_min,
          budget_max: q.inquiry.budget_max,
        }
      : undefined,
    event: q.event_id ? events.find(e => e.id === q.event_id) ?? null : null,
  }
}

/** Every page of an organizer list (the redesigned pages filter in the browser). */
export async function allItems<T>(fetchPage: (page: number) => Promise<{ items: T[], pagination: { last_page: number } }>, max = 10): Promise<T[]> {
  const items: T[] = []
  for (let page = 1, last = 1; page <= last && page <= max; page++) {
    const r = await fetchPage(page)
    items.push(...r.items)
    last = r.pagination?.last_page ?? 1
  }
  return items
}

/** The organizer's events and quotations in the redesigned pages' shapes. */
export async function loadLegacyOrganizerData(orgId: string): Promise<{ events: LegacyEvent[], quotations: LegacyQuotation[] }> {
  const eventsApi = useOrganizerEvents()
  const quotationsApi = useOrganizerQuotations()
  const [apiEvents, apiQuotations] = await Promise.all([
    allItems(page => eventsApi.list(orgId, [], page)),
    allItems(page => quotationsApi.list(orgId, [], page)).catch(() => [] as ApiOrganizerQuotation[]),
  ])
  const byEvent = new Map(apiQuotations.filter(q => q.event_id).map(q => [q.event_id!, q.id]))
  const events = apiEvents.map(e => toLegacyEvent(e, byEvent.get(e.id) ?? null))
  return { events, quotations: apiQuotations.map(q => toLegacyQuotation(q, events)) }
}
