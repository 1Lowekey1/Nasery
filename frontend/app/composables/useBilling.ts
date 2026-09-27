// B18 plans and billing for an organization. Members read; owners pay,
// cancel and resume. Payment goes through the same (demo) checkout as tickets.

export interface PlanInfo {
  id: string
  name: string
  slug: string
  price_monthly: number
  price_yearly: number
  is_free: boolean
  is_active: boolean
  limits?: Record<string, number | null>
  features?: Record<string, boolean>
  subscribers?: number
}

export interface Invoice {
  id: string
  plan: string
  cycle: 'monthly' | 'yearly'
  amount: number
  status: 'issued' | 'paid' | 'void' | 'draft'
  period_start: string
  period_end: string
  issued_at: string | null
  // Upgrades: list price minus the credited unused days.
  list_price?: number
  credit?: number
}

export interface PlanQuote {
  kind: 'new' | 'upgrade' | 'scheduled' | 'renewal'
  plan: string
  plan_slug: string
  cycle: 'monthly' | 'yearly'
  price: number
  credit: number
  due_now: number
  starts_at: string
  message: string
}

export interface BillingOverview {
  plan: PlanInfo
  subscription: {
    id: string
    status: 'active' | 'payment_failed' | 'grace_period'
    cycle: 'monthly' | 'yearly'
    period_start: string
    period_end: string
    cancel_at_period_end: boolean
    grace_until: string | null
  } | null
  usage: { key: string, label: string, hint: string, limit: number | null, used: number | null }[]
  features: { key: string, label: string, included: boolean }[]
  plans: PlanInfo[]
  open_invoice: (Invoice & { plan_slug: string, due_by: string }) | null
  payment_mode: 'fake' | 'test' | 'live'
  scheduled_change: { plan: string, plan_slug: string, cycle: 'monthly' | 'yearly', starts_at: string, price: number } | null
  invoices: Invoice[]
  can: { manage: boolean }
  grace_days: number
}

export interface AdminBilling {
  plans: PlanInfo[]
  free_organizers: number
  mrr: number
  in_grace: number
  cancelling: number
  paid_this_month: number
  recent: (Invoice & { organization: string })[]
}

/** Labels for plan limits on comparison cards (null = unlimited). */
export const limitLabels: Record<string, string> = {
  active_event_limit: 'active events',
  total_event_limit: 'events in total',
  staff_limit: 'team members',
  custom_event_type_limit: 'custom event types',
  price_option_limit: 'price list options',
  analytics_history_months: 'months of analytics',
}

export const useBilling = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}/billing`

  return {
    overview: async (orgId: string) => (await api.get<BillingOverview>(base(orgId))).data,
    quote: async (orgId: string, plan: string, cycle: 'monthly' | 'yearly') => (await api.get<PlanQuote>(`${base(orgId)}/quote`, { query: { plan, cycle } })).data,
    checkout: async (orgId: string, plan: string, cycle: 'monthly' | 'yearly') =>
      (await api.post<{ scheduled: boolean, quote: PlanQuote, payment_id?: string | null, checkout_url?: string | null, amount?: string }>(`${base(orgId)}/checkout`, { plan, cycle }, { idempotencyKey: crypto.randomUUID() })).data,
    cancel: async (orgId: string) => (await api.post<BillingOverview>(`${base(orgId)}/cancel`)).data,
    resume: async (orgId: string) => (await api.post<BillingOverview>(`${base(orgId)}/resume`)).data,
    // Admin
    adminOverview: async () => (await api.get<AdminBilling>('/admin/billing')).data,
    updatePlan: async (planId: string, body: Partial<Pick<PlanInfo, 'price_monthly' | 'price_yearly' | 'is_active'>> & { limits?: Record<string, number | null>, features?: Record<string, boolean> }) =>
      (await api.patch<PlanInfo>(`/admin/plans/${planId}`, body)).data,
  }
}
