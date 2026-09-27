// Admin review of organizer applications (B2).
import type { ApprovalHistoryEntry, ApprovalStatus, OfferedService, OrganizationProfile } from '~/composables/useOrganization'
import type { OrganizerTotals } from '~/composables/useAdmin'

export interface ApplicationSummary {
  id: string
  display_name: string
  legal_name: string
  slug: string
  approval_status: ApprovalStatus
  is_suspended: boolean
  business_name: string | null
  location_text: string | null
  submitted_at: string
  // B17: list rows carry the owner's e-mail and platform totals.
  owner_email?: string | null
  totals?: OrganizerTotals | null
}

export interface ApplicationDetail extends ApplicationSummary {
  suspended_reason: string | null
  allowed_decisions: ApprovalStatus[]
  owner: { id: string, full_name: string, email: string, email_verified: boolean, phone: string | null, account_status: string } | null
  profile: OrganizationProfile | null
  services: OfferedService[]
  history: ApprovalHistoryEntry[]
  totals?: OrganizerTotals | null
  access?: { grant: { id: string, expires_at: string } | null, pending_request_id: string | null, default_hours: number }
}

export const useOrganizerApplications = () => {
  const api = useApi()

  const list = async (params: { status?: ApprovalStatus | '', search?: string, page?: number } = {}) => {
    const query = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v !== undefined))
    const response = await api.get<ApplicationSummary[]>('/admin/organizer-applications', { query })
    return {
      items: response.data,
      pagination: response.meta?.pagination as { current_page: number, last_page: number, total: number },
      counts: response.meta?.counts as Record<ApprovalStatus, number>,
    }
  }

  const show = async (id: string) => (await api.get<ApplicationDetail>(`/admin/organizer-applications/${id}`)).data

  const decide = async (id: string, status: ApprovalStatus, reason?: string) =>
    (await api.post<ApplicationDetail>(`/admin/organizer-applications/${id}/decision`, { status, reason: reason || null }, {
      idempotencyKey: crypto.randomUUID(),
    })).data

  return { list, show, decide }
}

export const approvalBadge: Record<ApprovalStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  under_review: 'bg-sky-50 text-sky-700',
  approved: 'bg-green-50 text-green-700',
  rejected: 'bg-rose-50 text-rose-700',
  suspended: 'bg-gray-100 text-gray-700',
}
