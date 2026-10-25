// B17 platform administration. Totals are open to admins; an organizer's
// events, clients, team and payments need that organizer's consent
// (access requests / support invites).

export interface OrganizerTotals {
  id: string
  name: string
  events: number
  completed_events: number
  tickets_sold: number
  /** Gross ticket sales (the buyers' money) and where it went. */
  sales: number
  refunded?: number
  platform_fee?: number
  organizer_net?: number
  /** What the organizer paid NaSeRy for its plan. */
  plan_payments?: number
  rating: number | null
  reviews: number
  members: number
}

export interface PlatformTotals {
  users: { client: number, business: number, admin: number, suspended: number }
  organizers: Record<'pending' | 'under_review' | 'approved' | 'rejected' | 'suspended', number>
  events: Record<'draft' | 'published' | 'ongoing' | 'completed' | 'cancelled' | 'total', number>
  tickets_sold: number
  checked_in: number
  sales: number
  refunds: number
  platform_fee: number
  subscriptions: number
  rating: { average: number | null, count: number }
}

export interface AdminOverview {
  totals: PlatformTotals
  attention: { applications: number, reports: number, fee_claims: number, support_invites: number }
  top_organizers: OrganizerTotals[]
  recent_activity: { action: string, actor: string, actor_type: string | null, organization: string | null, at: string }[]
}

export interface PlatformTrends {
  months: string[]
  new_clients: number[]
  new_organizers: number[]
  events_created: number[]
  tickets_sold: number[]
  sales: number[]
  platform_fee: number[]
  subscriptions: number[]
  inquiries: number[]
  quotations_accepted: number[]
}

export type AccessStatus = 'pending' | 'approved' | 'declined' | 'cancelled' | 'expired'

export interface AccessRequest {
  id: string
  organization: { id: string, name: string }
  initiated_by: 'admin' | 'organizer'
  status: AccessStatus
  reason: string
  duration_hours: number
  requested_by: string
  admin: string | null
  decided_by: string | null
  decided_at: string | null
  decision_note: string | null
  expires_at: string
  grant: { id: string, expires_at: string, active: boolean } | null
  created_at: string
}

export interface AccessGrant {
  id: string
  organization: { id: string, name: string }
  admin: string
  granted_by: string | null
  reason: string
  starts_at: string
  expires_at: string
}

export interface Workspace {
  grant: { id: string, expires_at: string, reason: string }
  events: { id: string, name: string, status: string, visibility: string, client: string | null, venue: string | null, starts_at: string | null, tickets_sold: number, capacity: number | null, sales: number }[]
  clients: { name: string, bookings: number, last_booked: string | null }[]
  team: { name: string, email: string, role: string, status: string, joined: string }[]
  payments: { paid_count: number, sales: number, failed_count: number, fee: number, refunded: number,
    recent_refunds: { amount: number, fee_returned: number | null, reason: string, event: string, at: string }[] }
  reviews: { id: string, rating: number, text: string | null, status: string, event: string, reviewer: string, at: string }[]
}

export interface ModeratedReview {
  id: string
  status: 'published' | 'disputed' | 'hidden'
  rating: number
  written_review: string | null
  reviewer: string
  event_name: string
  organizer: { id: string, name: string, slug: string }
  reply: string | null
  created_at: string
  reports: { reason: string, status: string, by: string, by_role: string, at: string }[]
  history: { status: string, action: string | null, note: string | null, by: string | null, at: string }[]
}

export interface FeeClaim {
  id: string
  category: string
  reason: string
  amount: number
  payment_amount: number
  fee_returned: number | null
  refunded_at: string
  recorded_by: string
  event: { id: string, name: string, status: string }
  organizer: { id: string, name: string }
  review: { outcome: 'accepted' | 'flagged', note: string | null, by: string, at: string } | null
}

export interface PlatformSetting { key: string, value: number, label: string, help: string, min: number, max: number, unit: string }
export interface FeeVersion { id: string, name: string, enabled: boolean, model: 'percentage' | 'fixed' | 'hybrid', percent: number | null, fixed: number | null, from: string, to: string | null, by: string }
export interface FeeState { current: FeeVersion | null, history: FeeVersion[] }

export interface AdminUser {
  id: string
  name: string
  email: string
  phone: string | null
  type: 'client' | 'business' | 'admin'
  status: 'active' | 'suspended' | 'deactivated'
  verified: boolean
  organizations: string | null
  joined: string
}

export interface AdminMember { id: string, name: string, email: string, status: 'active' | 'suspended' | 'deactivated', invited: boolean, joined: string }

export interface CatalogService { id: string, name: string, category: string | null, archived: boolean, uses: number }

export interface Announcement {
  id: string
  title: string
  body: string
  audience: 'all' | 'organizers' | 'clients'
  starts_at: string
  ends_at: string | null
  state: 'scheduled' | 'live' | 'ended'
}

export interface ActivityEntry {
  id: string
  action: string
  entity: string
  actor: string
  actor_type: string
  organization: { id: string, name: string } | null
  reason: string | null
  changes: { old: Record<string, unknown> | null, new: Record<string, unknown> | null } | null
  details_hidden: boolean
  at: string
}

export interface AdminEvent {
  id: string
  name: string
  status: string
  visibility: 'public' | 'private'
  event_type: string | null
  organizer: { id: string, name: string }
  venue: string | null
  starts_at: string | null
  ends_at: string | null
  tickets_sold: number
  ticket_sales?: number
  capacity: number | null
  via_grant: boolean
}

type Page = { current_page: number, last_page: number, total: number }
const clean = (q: Record<string, unknown>) => Object.fromEntries(Object.entries(q).filter(([, v]) => v !== '' && v !== undefined && v !== null))

export const useAdmin = () => {
  const api = useApi()

  return {
    overview: async () => (await api.get<AdminOverview>('/admin/overview')).data,
    analytics: async (months: number) => (await api.get<{ totals: PlatformTotals, trends: PlatformTrends }>('/admin/analytics', { query: { months } })).data,
    events: async (q: { status?: string, search?: string }) => (await api.get<{ counts: PlatformTotals['events'], items: AdminEvent[] }>('/admin/events', { query: clean(q) })).data,

    access: async () => (await api.get<{ invites: AccessRequest[], requests: AccessRequest[], grants: AccessGrant[], default_hours: number }>('/admin/access')).data,
    requestAccess: async (orgId: string, reason: string, hours?: number) => (await api.post<AccessRequest>(`/admin/organizations/${orgId}/access-requests`, { reason, hours })).data,
    cancelRequest: (id: string) => api.delete(`/admin/access-requests/${id}`),
    acceptInvite: async (id: string) => (await api.post<AccessRequest>(`/admin/access-requests/${id}/accept`)).data,
    endGrant: (id: string) => api.delete(`/admin/access-grants/${id}`),
    workspace: async (orgId: string) => (await api.get<Workspace>(`/admin/organizations/${orgId}/workspace`)).data,

    reportedReviews: async (queue: string) => (await api.get<{ counts: { open: number, in_review: number, hidden: number }, items: ModeratedReview[] }>('/admin/moderation/reviews', { query: { queue } })).data,
    startReview: (id: string, note?: string) => api.post(`/admin/moderation/reviews/${id}/start`, { note: note || null }),
    decideReview: (id: string, decision: 'keep' | 'hide', reason: string) => api.post(`/admin/moderation/reviews/${id}/decision`, { decision, reason }),
    restoreReview: (id: string, reason: string) => api.post(`/admin/moderation/reviews/${id}/restore`, { reason }),
    feeClaims: async (status: string) => (await api.get<{ counts: { pending: number, flagged: number }, items: FeeClaim[] }>('/admin/moderation/fee-claims', { query: { status } })).data,
    reviewFeeClaim: async (id: string, outcome: 'accepted' | 'flagged', note?: string) => (await api.post<FeeClaim>(`/admin/moderation/fee-claims/${id}`, { outcome, note: note || null })).data,

    settings: async () => (await api.get<{ settings: PlatformSetting[], fee: FeeState }>('/admin/settings')).data,
    updateSettings: async (values: Record<string, number>) => (await api.patch<{ settings: PlatformSetting[], fee: FeeState }>('/admin/settings', values)).data,
    changeFee: async (body: { enabled: boolean, model?: string, percent?: number | null, fixed?: number | null, reason: string }) => (await api.post<FeeState>('/admin/settings/fee', body)).data,

    users: async (q: { search?: string, type?: string, status?: string, page?: number }) => {
      const r = await api.get<AdminUser[]>('/admin/users', { query: clean(q) })
      return { items: r.data, pagination: r.meta?.pagination as Page }
    },
    setUserStatus: (id: string, status: 'active' | 'suspended', reason: string) => api.post(`/admin/users/${id}/status`, { status, reason }),

    services: async () => (await api.get<CatalogService[]>('/admin/services')).data,
    saveService: (body: { name: string, category?: string | null }, id?: string) => id ? api.patch(`/admin/services/${id}`, body) : api.post('/admin/services', body),
    archiveService: (id: string, archive: boolean) => archive ? api.delete(`/admin/services/${id}`) : api.post(`/admin/services/${id}/restore`),

    announcements: async () => (await api.get<Announcement[]>('/admin/announcements')).data,
    saveAnnouncement: (body: { title: string, body: string, audience: string, starts_at?: string | null, ends_at?: string | null }, id?: string) =>
      id ? api.patch(`/admin/announcements/${id}`, body) : api.post('/admin/announcements', body),
    deleteAnnouncement: (id: string) => api.delete(`/admin/announcements/${id}`),

    admins: async () => (await api.get<AdminMember[]>('/admin/admins')).data,
    inviteAdmin: (body: { first_name: string, last_name: string, email: string }) => api.post('/admin/admins', body),
    resendAdminInvite: (id: string) => api.post(`/admin/admins/${id}/resend`),
    setAdminStatus: (id: string, status: 'active' | 'suspended', reason: string) => api.post(`/admin/admins/${id}/status`, { status, reason }),

    activity: async (q: { actor?: string, organization_id?: string, search?: string, from?: string, to?: string, page?: number }) => {
      const r = await api.get<ActivityEntry[]>('/admin/activity', { query: clean(q) })
      return { items: r.data, pagination: r.meta?.pagination as Page }
    },
  }
}

/** The organization's side: who asked for access, who has it, support invites. */
export const useNaseryAccess = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}/nasery-access`

  return {
    load: async (orgId: string) => (await api.get<{ pending: AccessRequest[], grants: AccessGrant[], history: AccessRequest[], default_hours: number, can: { manage: boolean } }>(base(orgId))).data,
    decide: (orgId: string, id: string, approve: boolean, note?: string) => api.post(`${base(orgId)}/requests/${id}/decision`, { approve, note: note || null }),
    invite: (orgId: string, reason: string, hours?: number) => api.post(`${base(orgId)}/invite`, { reason, hours }),
    cancelInvite: (orgId: string, id: string) => api.delete(`${base(orgId)}/invite/${id}`),
    endGrant: (orgId: string, id: string) => api.delete(`${base(orgId)}/grants/${id}`),
  }
}

export const peso = (n: number) => `₱${n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
export const shortDate = (at: string | null) => at ? new Date(at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : '—'
export const dateTime = (at: string | null) => at ? new Date(at).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—'

/** Human wording for audit actions (falls back to the raw key). */
export const actionLabel = (action: string) => ({
  'organizer_approval_status_change': 'Changed an organizer\'s approval',
  'organization.created': 'Applied as an organizer',
  'organization.approval_resubmitted': 'Resubmitted an application',
  'admin_access.requested': 'Requested workspace access',
  'admin_access.approved': 'Approved NaSeRy access',
  'admin_access.declined': 'Refused NaSeRy access',
  'admin_access.invited': 'Invited NaSeRy support',
  'admin_access.invite_accepted': 'Accepted a support invite',
  'admin_access.revoked': 'Ended NaSeRy access',
  'admin_access.viewed': 'Viewed a workspace (granted)',
  'review.reported': 'Reported a review',
  'review.moderation_started': 'Took a review report',
  'review.kept': 'Kept a reported review',
  'review.hidden': 'Hid a review',
  'review.restored': 'Restored a review',
  'refund.claim_accepted': 'Accepted a refund fee claim',
  'refund.claim_flagged': 'Flagged a refund fee claim',
  'payment.refunded': 'Recorded a refund',
  'platform.fee_changed': 'Changed the platform fee',
  'platform.settings_updated': 'Changed platform settings',
  'user.registered': 'Signed up',
  'user.suspended': 'Suspended an account',
  'user.reactivated': 'Reactivated an account',
  'announcement.saved': 'Posted an announcement',
  'quotation_accept': 'Accepted a quotation',
} as Record<string, string>)[action] ?? action.replace(/[._]/g, ' ')
