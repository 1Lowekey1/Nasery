// Reviews (B16): client reviews of completed events, organizer replies, reports.

export interface Review {
  id: string
  event_id: string
  event_name?: string | null
  event_type?: string | null
  rating: number
  written_review: string | null
  reviewer?: string | null
  created_at: string
  edited: boolean
  editable_until: string
  reply: { text: string, at: string } | null
  status?: 'published' | 'hidden' | 'disputed'
  open_reports?: number
}

export interface MyReviewState {
  can_review: boolean
  reason: string | null
  review: Review | null
}

export interface OrganizerReviews {
  summary: { average: number | null, count: number, distribution: Record<string, number>, awaiting_reply: number }
  reviews: Review[]
  can: { reply: boolean }
}

export const useReviews = () => {
  const api = useApi()

  return {
    mine: async (eventId: string) => (await api.get<MyReviewState>(`/events/${eventId}/review`)).data,
    create: async (eventId: string, body: { rating: number, written_review: string | null }) =>
      (await api.post<Review>(`/events/${eventId}/review`, body, { idempotencyKey: crypto.randomUUID() })).data,
    update: async (eventId: string, body: { rating: number, written_review: string | null }) =>
      (await api.patch<Review>(`/events/${eventId}/review`, body)).data,
    remove: (eventId: string) => api.delete(`/events/${eventId}/review`),
    forOrganization: async (orgId: string) => (await api.get<OrganizerReviews>(`/organizations/${orgId}/reviews`)).data,
    reply: async (orgId: string, reviewId: string, text: string) => (await api.put<Review>(`/organizations/${orgId}/reviews/${reviewId}/reply`, { text })).data,
    removeReply: (orgId: string, reviewId: string) => api.delete(`/organizations/${orgId}/reviews/${reviewId}/reply`),
    report: (reviewId: string, reason: string) => api.post(`/reviews/${reviewId}/report`, { reason }),
  }
}
