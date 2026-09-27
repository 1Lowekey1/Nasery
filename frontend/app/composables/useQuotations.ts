// Quotations (B5) and the organizer price list behind the quotation calculator.

export type QuotationStatus = 'draft' | 'submitted' | 'accepted' | 'rejected' | 'withdrawn' | 'cancelled'
export type PricingUnit = 'flat' | 'per_guest'

export interface QuotationLine {
  id?: string
  organization_service_id?: string | null
  description: string
  quantity: string | number
  unit_price: string | number
  subtotal?: string
}

export interface QuotationContent {
  package_name: string | null
  proposal: string | null
  terms: string | null
  items: QuotationLine[]
}

export interface Quotation extends QuotationContent {
  id: string
  inquiry_id: string
  organization_id: string
  status: QuotationStatus
  total_amount: string
  currency: string
  submitted_at: string | null
  decided_at: string | null
  updated_at: string | null
}

/** Organizer view: with the inquiry and the client (contact only once accepted). */
export interface OrganizerQuotation extends Quotation {
  /** The event created from this (won) quotation, B6. */
  event_id: string | null
  inquiry: { id: string, status: string, event_type_id: string | null, event_type: string | null, requested_date: string | null, guest_count: number | null, budget_min: string | null, budget_max: string | null, location_text: string | null } | null
  client: { name: string, email: string | null, phone: string | null } | null
}

/** Client view: one current offer per organizer. */
export interface ClientQuotation extends Quotation {
  earlier_versions: number
  organizer: { id: string, display_name: string, slug: string | null, contact_email: string | null, contact_phone: string | null }
}

export interface PriceOption {
  id: string
  organization_service_id: string | null
  name: string
  description: string | null
  pricing_unit: PricingUnit
  price: string
  min_guests: number | null
  is_active?: boolean
  sort_order?: number
}

export const quotationStatusLabels: Record<QuotationStatus, string> = {
  draft: 'Draft',
  submitted: 'Sent',
  accepted: 'Won',
  rejected: 'Not selected',
  withdrawn: 'Withdrawn',
  cancelled: 'Closed',
}

export const quotationStatusClasses: Record<QuotationStatus, string> = {
  draft: 'bg-gray-100 text-gray-600',
  submitted: 'bg-primary-50 text-primary-700',
  accepted: 'bg-green-50 text-green-700',
  rejected: 'bg-rose-50 text-rose-700',
  withdrawn: 'bg-amber-50 text-amber-700',
  cancelled: 'bg-gray-100 text-gray-500',
}

/** Line subtotal in the same cents arithmetic as the server (half-up). */
export function lineSubtotal(quantity: string | number, unitPrice: string | number): number {
  const q = Math.round(Number(quantity || 0) * 100)
  const p = Math.round(Number(unitPrice || 0) * 100)
  return Math.floor((q * p + 50) / 100) / 100
}

export function linesTotal(items: QuotationLine[]): number {
  return Math.round(items.reduce((sum, i) => sum + lineSubtotal(i.quantity, i.unit_price), 0) * 100) / 100
}

/** A calculator / price-list option as a quotation line for a guest count. */
export function optionAsLine(option: PriceOption, guests: number | null): QuotationLine {
  const perGuest = option.pricing_unit === 'per_guest'
  return {
    organization_service_id: option.organization_service_id,
    description: perGuest ? `${option.name} (per guest)` : option.name,
    quantity: perGuest ? Math.max(guests ?? 1, option.min_guests ?? 1) : 1,
    unit_price: option.price,
  }
}

type Page<T> = { items: T[], pagination: { current_page: number, last_page: number, total: number } }

export const useOrganizerQuotations = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}/quotations`
  const key = () => ({ idempotencyKey: crypto.randomUUID() })

  return {
    list: async (orgId: string, status: QuotationStatus[] = [], page = 1): Promise<Page<OrganizerQuotation>> => {
      const r = await api.get<OrganizerQuotation[]>(base(orgId), { query: { 'status[]': status.length ? status : undefined, page } })
      return { items: r.data, pagination: r.meta?.pagination as Page<OrganizerQuotation>['pagination'] }
    },
    show: async (orgId: string, id: string) => (await api.get<OrganizerQuotation>(`${base(orgId)}/${id}`)).data,
    create: async (orgId: string, inquiryId: string, content: QuotationContent, submit: boolean) =>
      (await api.post<OrganizerQuotation>(`/organizations/${orgId}/inquiries/${inquiryId}/quotations`, { ...content, submit }, key())).data,
    update: async (orgId: string, id: string, content: QuotationContent) => (await api.patch<OrganizerQuotation>(`${base(orgId)}/${id}`, content)).data,
    submit: async (orgId: string, id: string) => (await api.post<OrganizerQuotation>(`${base(orgId)}/${id}/submit`, {}, key())).data,
    revise: async (orgId: string, id: string, content: QuotationContent) => (await api.post<OrganizerQuotation>(`${base(orgId)}/${id}/revise`, content, key())).data,
    withdraw: async (orgId: string, id: string, reason: string) => (await api.post<OrganizerQuotation>(`${base(orgId)}/${id}/withdraw`, { reason })).data,
    remove: (orgId: string, id: string) => api.delete(`${base(orgId)}/${id}`),
  }
}

export const useClientQuotations = () => {
  const api = useApi()

  return {
    forInquiry: async (inquiryId: string) => (await api.get<ClientQuotation[]>(`/inquiries/${inquiryId}/quotations`)).data,
    accept: (id: string) => api.post(`/quotations/${id}/accept`, {}, { idempotencyKey: crypto.randomUUID() }),
    decline: (id: string, reason: string) => api.post(`/quotations/${id}/decline`, { reason }),
  }
}

export const usePriceOptions = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}/price-options`

  return {
    list: async (orgId: string) => (await api.get<PriceOption[]>(base(orgId))).data,
    create: async (orgId: string, payload: Partial<PriceOption>) => (await api.post<PriceOption>(base(orgId), payload)).data,
    update: async (orgId: string, id: string, payload: Partial<PriceOption>) => (await api.patch<PriceOption>(`${base(orgId)}/${id}`, payload)).data,
    remove: (orgId: string, id: string) => api.delete(`${base(orgId)}/${id}`),
  }
}
