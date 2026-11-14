// Where an organizer's ticket money goes, and NaSeRy's payouts of their share (0043).

export interface Payout {
  id: string
  amount: number
  method: 'bank_transfer' | 'gcash' | 'maya' | 'other'
  method_label: string
  reference: string
  note: string | null
  paid_at: string
  voided: boolean
  void_reason: string | null
}

export interface MoneySplit {
  /** Ticket sales paid online (the buyers' money). */
  gross: number
  /** Already sent back to buyers through PayMongo. */
  refunded: number
  /** What NaSeRy keeps (after fees returned on valid refunds). */
  platform_fee: number
  /** The organizer's share: gross - refunded - platform_fee. */
  share: number
}

export interface EarningsSummary extends MoneySplit {
  paid_out: number
  /** Still to be paid by NaSeRy (negative: taken off the next payout). */
  owed: number
  payouts: Payout[]
  plan: { details: boolean }
}

export interface EarningsDetails {
  events: (MoneySplit & { id: string, name: string, payments: number })[]
  months: (MoneySplit & { month: string })[]
}

/** A refund returned to a buyer (admin Payouts page). */
export interface RecentRefund {
  id: string
  amount: number
  reason: string
  buyer: string | null
  event: string
  organizer: string
  refunded_at: string
}

export interface PayoutBalance extends Omit<EarningsSummary, 'payouts' | 'plan'> {
  organization: { id: string, name: string }
  last_payout_at: string | null
}

export const useEarnings = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}/earnings`

  return {
    summary: async (orgId: string) => (await api.get<EarningsSummary>(base(orgId))).data,
    details: async (orgId: string, months = 6) => (await api.get<EarningsDetails>(`${base(orgId)}/details`, { query: { months } })).data,
    exportCsv: (orgId: string) => api.blob(`${base(orgId)}/export`),

    // Admins
    balances: async () => (await api.get<{ organizers: PayoutBalance[], methods: Record<string, string>, refunds?: RecentRefund[] }>('/admin/payouts')).data,
    payouts: async (orgId: string) => (await api.get<Payout[]>(`/admin/organizations/${orgId}/payouts`)).data,
    record: async (orgId: string, body: { amount: number, method: string, reference: string, note?: string | null, paid_at?: string | null }) =>
      (await api.post<Payout>(`/admin/organizations/${orgId}/payouts`, body, { idempotencyKey: crypto.randomUUID() })).data,
    voidPayout: async (id: string, reason: string) => (await api.post<Payout>(`/admin/payouts/${id}/void`, { reason })).data,
  }
}

export const pesoExact = (n: number) => `${n < 0 ? '−' : ''}₱${Math.abs(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
