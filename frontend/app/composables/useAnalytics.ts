// Organizer analytics (B15).

export type AnalyticsRange = '3m' | '6m' | '12m' | 'ytd' | '24m'
export type AnalyticsGroup = 'month' | 'quarter'
export type BidOutcome = 'won' | 'lost' | 'pending' | 'declined' | 'missed' | 'open' | 'withdrawn' | 'cancelled'

export interface BidAnalytics {
  totals: {
    inquiries: number, open: number, bids: number, won: number, lost: number, pending: number, declined: number, missed: number
    win_rate: number | null, response_rate: number | null, median_response_hours: number | null, won_value: string, average_bid: string | null
  }
  trend: { key: string, label: string, inquiries: number, bids: number, won: number, lost: number, won_value?: string }[]
  by_event_type: { event_type: string, inquiries: number, bids: number, won: number, win_rate: number | null }[]
  history: { inquiry_id: string, event_type: string, guest_count: number | null, requested_date: string | null, invited_at: string, first_sent_at: string | null, amount: string | null, outcome: BidOutcome }[]
}

export interface EventAnalytics {
  totals: {
    events: number, completed: number, upcoming: number, attendees: number, average_attendance_rate: number | null, no_show_rate: number | null
    walk_in_share: number | null, average_capacity_fill: number | null, revenue?: string
  }
  trend: { key: string, label: string, events: number, attendees: number, attendance_rate: number | null, no_show_rate: number | null, revenue?: string }[]
  events: { id: string, name: string, status: string, date: string, expected: number, attended: number, no_shows: number | null, attendance_rate: number | null, walk_ins: number, people: number, capacity: number | null, capacity_fill: number | null, revenue?: string }[]
}

export interface AnalyticsOverview {
  period: { from: string, to: string, group: AnalyticsGroup, labels: string[] }
  bids?: BidAnalytics
  events?: EventAnalytics
  can: { bids: boolean, events: boolean, revenue: boolean }
  // B18: what the organization's plan allows (Free: 3 months, monthly only).
  plan?: { ranges: AnalyticsRange[], quarterly: boolean, history_months: number | null }
}

export interface LiveEvent {
  event_id: string
  name: string
  status: string
  session: { label: string, starts_at: string, ends_at: string, opens_at: string, is_open: boolean } | null
  expected: number
  checked_in: number
  walk_ins: number
  arrived: number
  check_in_rate: number | null
  walk_in_ratio: number | null
  capacity: { limit: number | null, used: number, remaining: number | null, percent: number | null, level: string }
  occupancy: number | null
  arrivals_last_15_min: number
  recent: { name: string, tier: string, method: string, checked_in_at: string }[]
  tiers: { name: string, expected: number, checked_in: number }[]
}

export const rangeOptions: { value: AnalyticsRange, label: string }[] = [
  { value: '3m', label: 'Last 3 months' }, { value: '6m', label: 'Last 6 months' }, { value: '12m', label: 'Last 12 months' },
  { value: 'ytd', label: 'This year' }, { value: '24m', label: 'Last 2 years' },
]

/** The range/grouping chosen in the analytics filters (kept in the URL). */
export function useAnalyticsFilters() {
  const route = useRoute()
  // No range in the URL: the server picks the longest the plan allows, up to 12 months (B18).
  const range = computed<AnalyticsRange | undefined>(() => (rangeOptions.some(o => o.value === route.query.range) ? route.query.range as AnalyticsRange : undefined))
  const group = computed<AnalyticsGroup>(() => (route.query.group === 'quarter' ? 'quarter' : 'month'))
  return { range, group }
}

export const useAnalytics = () => {
  const api = useApi()
  // One fetch per range+group, shared by the Overview, Bid and Event tabs.
  const cache = useState<Record<string, AnalyticsOverview>>('analyticsCache', () => ({}))
  const plan = useAnalyticsPlan()

  return {
    overview: async (orgId: string, range: AnalyticsRange | undefined, group: AnalyticsGroup, fresh = false) => {
      const key = `${orgId}:${range ?? 'default'}:${group}`
      if (fresh || !cache.value[key]) {
        cache.value[key] = (await api.get<AnalyticsOverview>(`/organizations/${orgId}/analytics`, { query: range ? { range, group } : { group } })).data
        plan.value = cache.value[key]!.plan ?? null
      }
      return cache.value[key]!
    },
    live: async (orgId: string) => (await api.get<{ live: LiveEvent[], upcoming: (LiveEvent['session'] & { event_id: string, name: string })[] }>(`/organizations/${orgId}/analytics/live`)).data,
  }
}

/** B18: the plan's analytics allowance, from the last overview response. */
export const useAnalyticsPlan = () => useState<AnalyticsOverview['plan'] | null>('analyticsPlan', () => null)
