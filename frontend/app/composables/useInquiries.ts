// Inquiries (B4): client side and organizer side.

export type InquiryStatus = 'draft' | 'submitted' | 'receiving_bids' | 'accepted' | 'converted_to_event' | 'cancelled'
export type InvitationStatus = 'pending' | 'viewed' | 'declined' | 'quoted' | 'withdrawn' | 'accepted' | 'rejected'

export interface InquiryServiceInput {
  service_id?: string | null
  custom_service_name?: string | null
  notes?: string | null
}

export interface InquiryPayload {
  event_type_id?: string | null
  requested_date?: string | null
  guest_count?: number | null
  budget_min?: number | null
  budget_max?: number | null
  location_text?: string | null
  latitude?: number | null
  longitude?: number | null
  additional_requirements?: string | null
  services?: InquiryServiceInput[]
}

export interface ClientInquiry {
  id: string
  status: InquiryStatus
  via: 'direct' | 'open_posting' | null
  /** Direct requests: the organizer(s) it went to (dashboard shows "Direct to …"). */
  direct_to?: string[] | null
  /** The event name the client gave on Post an Event, when there is one. */
  event_name?: string | null
  event_type_id: string | null
  event_type: string | null
  requested_date: string | null
  guest_count: number | null
  budget_min: string | null
  budget_max: string | null
  location_text: string | null
  invited_count: number | null
  viewed_count: number | null
  declined_count: number | null
  created_at: string
  latitude?: number | null
  longitude?: number | null
  match_radius_km?: number | null
  additional_requirements?: string | null
  /** 0062: mood board / colour palette pictures. */
  mood_board?: { id: string, url: string | null, caption: string | null, added_by?: string | null, by_organization?: string | null }[]
  /** 0062: false once the request is cancelled or its event is over. */
  mood_board_open?: boolean
  /** 0051: when the client last changed the request after sending it, and how many changes remain. */
  revised_at?: string | null
  revisions_left?: number
  /** Set once the accepted quotation became an event. */
  event_id?: string | null
  services?: { id: string, service_id: string | null, custom_service_name: string | null, notes: string | null }[]
  invitations?: { organization_id: string, display_name: string, slug: string | null, status: InvitationStatus, decline_reason: string | null, invited_at: string, responded_at: string | null }[]
}

export interface OrganizerInquiry {
  inquiry_id: string
  invitation_status: InvitationStatus
  decline_reason: string | null
  invited_at: string
  responded_at: string | null
  inquiry_status: InquiryStatus
  is_open: boolean
  via: 'direct' | 'open_posting'
  client: string
  /** The client's own name for the event ("Marlo & Rena Wedding"), when they gave one. */
  event_name?: string | null
  event_type: string | null
  requested_date: string | null
  guest_count: number | null
  budget_min: string | null
  budget_max: string | null
  location_text: string | null
  services: { name: string | null, notes: string | null }[]
  additional_requirements: string | null
  /** 0062: the client's mood board / colour palette pictures. */
  mood_board?: { id: string, url: string | null, caption: string | null, added_by?: string | null, by_organization?: string | null }[]
  /** 0062: whether NaSeRy adds VAT to this organizer's offer (some event types can be VAT-free). */
  vat_applies?: boolean
  /** Field test (Nov 2026): this organizer was booked and may add to the mood board. */
  mood_board_editable?: boolean
  /** This organization's latest quotation for the inquiry (B5). */
  quotation: { id: string, status: 'draft' | 'submitted' | 'accepted' | 'rejected' | 'withdrawn' | 'cancelled', total_amount: string, before_changes?: boolean } | null
  /** 0051: when the client last changed the request after sending it. */
  revised_at?: string | null
}

export const inquiryStatusLabels: Record<InquiryStatus, string> = {
  draft: 'Draft',
  submitted: 'Sent to organizers',
  receiving_bids: 'Receiving quotations',
  accepted: 'Quotation accepted',
  converted_to_event: 'Event created',
  cancelled: 'Cancelled',
}

export const invitationLabels: Record<InvitationStatus, string> = {
  pending: 'Not opened yet',
  viewed: 'Viewed',
  declined: 'Declined',
  quoted: 'Quoted',
  withdrawn: 'Quote withdrawn',
  accepted: 'Accepted',
  rejected: 'Not selected',
}

export function budgetLabel(min: string | number | null, max: string | number | null): string {
  if (min && max) return `${formatPeso(min)} – ${formatPeso(max)}`
  if (min) return `from ${formatPeso(min)}`
  if (max) return `up to ${formatPeso(max)}`
  return 'Budget not set'
}

export const useInquiries = () => {
  const api = useApi()

  return {
    list: async (page = 1) => {
      const r = await api.get<ClientInquiry[]>('/inquiries', { query: { page } })
      return { items: r.data, pagination: r.meta?.pagination as { current_page: number, last_page: number, total: number } }
    },
    show: async (id: string) => (await api.get<ClientInquiry>(`/inquiries/${id}`)).data,
    /** 0062: add a mood board picture / remove one. Both return the updated request. */
    addMoodImage: async (id: string, file: File, caption?: string | null) => {
      const data = new FormData()
      data.append('image', file)
      if (caption) data.append('caption', caption)
      return (await api.post<ClientInquiry>(`/inquiries/${id}/mood-board`, data)).data
    },
    removeMoodImage: async (id: string, imageId: string) => (await api.delete<ClientInquiry>(`/inquiries/${id}/mood-board/${imageId}`)).data,
    create: async (payload: InquiryPayload) => (await api.post<ClientInquiry>('/inquiries', payload)).data,
    update: async (id: string, payload: InquiryPayload) => (await api.patch<ClientInquiry>(`/inquiries/${id}`, payload)).data,
    remove: (id: string) => api.delete(`/inquiries/${id}`),
    matches: async (id: string, radius: number) => {
      const r = await api.get<{ organization_id: string, slug: string, display_name: string, distance_km: number }[]>(`/inquiries/${id}/matches`, { query: { match_radius_km: radius } })
      return r.data
    },
    submit: async (id: string, payload: { mode: 'direct', organization_ids: string[] } | { mode: 'open', match_radius_km: number }) =>
      (await api.post<ClientInquiry>(`/inquiries/${id}/submit`, payload, { idempotencyKey: crypto.randomUUID() })).data,
    invite: async (id: string, organizationIds: string[]) =>
      (await api.post<ClientInquiry>(`/inquiries/${id}/invite`, { organization_ids: organizationIds }, { idempotencyKey: crypto.randomUUID() })).data,
    cancel: async (id: string, reason?: string) => (await api.post<ClientInquiry>(`/inquiries/${id}/cancel`, { reason: reason || null })).data,
  }
}

export const useOrganizerInquiries = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}/inquiries`

  return {
    list: async (orgId: string, status: InvitationStatus[] = [], page = 1) => {
      const r = await api.get<OrganizerInquiry[]>(base(orgId), { query: { 'status[]': status.length ? status : undefined, page } })
      return { items: r.data, pagination: r.meta?.pagination as { current_page: number, last_page: number, total: number } }
    },
    show: async (orgId: string, inquiryId: string) => (await api.get<OrganizerInquiry>(`${base(orgId)}/${inquiryId}`)).data,
    /** Field test (Nov 2026): the booked organizer adds to the mood board / removes its own pictures. */
    addMoodImage: async (orgId: string, inquiryId: string, file: File) => {
      const data = new FormData()
      data.append('image', file)
      return (await api.post<OrganizerInquiry>(`${base(orgId)}/${inquiryId}/mood-board`, data)).data
    },
    removeMoodImage: async (orgId: string, inquiryId: string, imageId: string) => (await api.delete<OrganizerInquiry>(`${base(orgId)}/${inquiryId}/mood-board/${imageId}`)).data,
    decline: async (orgId: string, inquiryId: string, reason: string) =>
      (await api.post<OrganizerInquiry>(`${base(orgId)}/${inquiryId}/decline`, { reason })).data,
  }
}
