// frontend/app/composables/useOrganization.ts
//
// The signed-in organizer's workspace (B2): onboarding, profile, offered
// services and approval status. V1: one owned organization per account.

export type ApprovalStatus = 'pending' | 'under_review' | 'approved' | 'rejected' | 'suspended'

export interface OrganizationProfile {
  business_name: string
  description: string | null
  location_text: string | null
  latitude: number | null
  longitude: number | null
  contact_email: string | null
  contact_phone: string | null
  cover_image_url: string | null
  /** Uploaded pictures (the cover falls back to cover_image_url). */
  logo_url?: string | null
  cover_url?: string | null
  cover_uploaded?: boolean
  is_published: boolean
  /** 0046: details from the business profile panel. */
  years_experience?: string | null
  service_radius_km?: number | null
  event_tags?: string[]
  specialties?: string[]
  website?: string | null
  facebook?: string | null
  instagram?: string | null
  banner_color?: string | null
}

export interface Organization {
  id: string
  legal_name: string
  display_name: string
  slug: string
  approval_status: ApprovalStatus
  is_suspended: boolean
  suspended_reason?: string | null
  is_operational: boolean
  my_membership: {
    member_id: string
    is_owner: boolean
    is_primary_owner: boolean
    organization_role_id: string | null
    /** B8: role name, effective organization-wide permissions, event scope. */
    role: string | null
    permissions: string[]
    sees_all_events: boolean
    /** Permissions held only on events they're assigned to (e.g. checkin.perform). */
    event_permissions?: string[]
  } | null
  profile?: OrganizationProfile
  created_at: string
}

export interface ApprovalHistoryEntry {
  status: ApprovalStatus
  decision_reason: string | null
  kind: 'submission' | 'decision' | 'note'
  submitted_at: string | null
  decided_at: string | null
}

export interface ApprovalState {
  approval_status: ApprovalStatus
  is_suspended: boolean
  is_operational: boolean
  can_resubmit: boolean
  history: ApprovalHistoryEntry[]
}

export interface OfferedService {
  id: string
  service_id: string | null
  name: string
  custom_name: string | null
  description: string | null
  base_price: string | null
  is_active: boolean
}

export interface OnboardingPayload {
  legal_name: string
  display_name: string
  profile: Partial<OrganizationProfile> & { business_name: string, location_text: string, contact_email: string }
}

export const approvalLabels: Record<ApprovalStatus, string> = {
  pending: 'Pending review',
  under_review: 'Under review',
  approved: 'Approved',
  rejected: 'Needs changes',
  suspended: 'Suspended',
}

export const useOrganization = () => {
  const api = useApi()
  const organization = useState<Organization | null>('org.current', () => null)
  const loaded = useState<boolean>('org.loaded', () => false)

  const fetchOrganization = async (force = false): Promise<Organization | null> => {
    if (loaded.value && !force) return organization.value
    const { data } = await api.get<Organization[]>('/organizations/mine')
    organization.value = data.find(o => o.my_membership?.is_owner) ?? data[0] ?? null
    loaded.value = true
    return organization.value
  }

  const reset = () => {
    organization.value = null
    loaded.value = false
  }

  const create = async (payload: OnboardingPayload) => {
    organization.value = (await api.post<Organization>('/organizations', payload)).data
    loaded.value = true
    return organization.value
  }

  const update = async (payload: Partial<Pick<Organization, 'legal_name' | 'display_name' | 'slug'>>) => {
    organization.value = (await api.patch<Organization>(`/organizations/${organization.value!.id}`, payload)).data
    return organization.value
  }

  const updateProfile = async (payload: Partial<OrganizationProfile>) => {
    const { data } = await api.patch<OrganizationProfile>(`/organizations/${organization.value!.id}/profile`, payload)
    if (organization.value) organization.value = { ...organization.value, profile: data }
    return data
  }

  const approval = async () => (await api.get<ApprovalState>(`/organizations/${organization.value!.id}/approval`)).data

  const resubmit = async () => {
    organization.value = (await api.post<Organization>(`/organizations/${organization.value!.id}/approval/resubmit`)).data
    return organization.value
  }

  const services = async () => (await api.get<OfferedService[]>(`/organizations/${organization.value!.id}/services`)).data
  const addService = async (payload: Partial<OfferedService>) =>
    (await api.post<OfferedService>(`/organizations/${organization.value!.id}/services`, payload)).data
  const updateService = async (id: string, payload: Partial<OfferedService>) =>
    (await api.patch<OfferedService>(`/organizations/${organization.value!.id}/services/${id}`, payload)).data
  const removeService = (id: string) => api.delete(`/organizations/${organization.value!.id}/services/${id}`)

  return {
    organization: readonly(organization),
    loaded: readonly(loaded),
    fetchOrganization,
    reset,
    create,
    update,
    updateProfile,
    approval,
    resubmit,
    services,
    addService,
    updateService,
    removeService,
  }
}
