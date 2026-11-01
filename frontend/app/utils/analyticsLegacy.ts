// Frontend merge: the redesigned analytics tabs each expected their own
// endpoint. They're all served by the one analytics API (/organizations/{org}/
// analytics, plan-aware), turned into each tab's shape here.

import type { AnalyticsOverview } from '~/composables/useAnalytics'

async function overviewFor(): Promise<AnalyticsOverview | null> {
  const org = await useOrganization().fetchOrganization()
  if (!org) return null
  const { range, group } = useAnalyticsFilters()
  return useAnalytics().overview(org.id, range.value, group.value)
}

const pct = (n: number | null | undefined) => (n === null || n === undefined ? null : Math.round(n * 10) / 10)

/** The period the numbers cover, as the server chose it (the range filter, or the plan's default). */
function periodOf(o: AnalyticsOverview | null) {
  const fmt = (iso: string) => new Date(iso).toLocaleDateString('en-PH', { month: 'short', year: 'numeric', timeZone: 'Asia/Manila' })
  return {
    text: o?.period ? `${fmt(o.period.from)} – ${fmt(o.period.to)}` : 'this period',
    by: o?.period.group === 'quarter' ? 'Quarterly' : 'Monthly',
  }
}

/** Overview tab: headline numbers, how inquiries were handled, latest bids. */
export async function legacyAnalyticsOverview() {
  const o = await overviewFor()
  const bids = o?.bids?.totals
  const statusOf: Record<string, 'won' | 'lost' | 'submitted' | 'withdrawn' | 'new'> = {
    won: 'won', lost: 'lost', pending: 'submitted', withdrawn: 'withdrawn', cancelled: 'withdrawn', open: 'new', declined: 'lost', missed: 'lost',
  }
  return {
    stats: {
      total_bids: bids?.bids ?? 0,
      win_rate: pct(bids?.win_rate),
      avg_attendance_rate: pct(o?.events?.totals.average_attendance_rate),
      active_inquiries: bids?.open ?? 0,
    },
    // Money the API already works out (the redesign had dropped it): won bids at their final
    // agreed price, and ticket revenue for members who may see finances.
    money: {
      won_value: bids ? Number(bids.won_value) : null,
      won_count: bids?.won ?? 0,
      revenue: o?.can.revenue && o.events?.totals.revenue !== undefined ? Number(o.events.totals.revenue) : null,
    },
    distribution: bids
      ? [
          { label: 'Received', value: bids.open },
          { label: 'Bid Submitted', value: bids.bids },
          { label: 'Ignored', value: bids.declined + bids.missed },
        ].filter(r => r.value > 0)
      : [],
    recent_activity: [...(o?.bids?.history ?? [])]
      .sort((a, b) => (b.first_sent_at ?? b.invited_at).localeCompare(a.first_sent_at ?? a.invited_at))
      .slice(0, 6)
      .map(h => ({
        title: `${h.event_type}${h.guest_count ? ` · ${h.guest_count} guests` : ''}${h.amount ? ` · ₱${Number(h.amount).toLocaleString('en-PH')}` : ''}`,
        time: h.first_sent_at ?? h.invited_at,
        status: statusOf[h.outcome] ?? 'submitted',
      })),
    plan: o?.plan ?? null,
  }
}

/** Bid Performance tab: won vs lost per period. */
export async function legacyBidPerformance() {
  const o = await overviewFor()
  const t = o?.bids?.totals
  return {
    monthly: (o?.bids?.trend ?? []).map(r => ({ label: r.label, won: r.won, lost: r.lost, won_value: Number(r.won_value ?? 0) })),
    total_bids: t?.bids ?? 0,
    bids_won: t?.won ?? 0,
    bids_lost: t?.lost ?? 0,
    won_value: t ? Number(t.won_value) : 0,
    period: periodOf(o),
  }
}

/** Event Performance tab: attendance and no-shows per period. */
export async function legacyEventPerformance() {
  const o = await overviewFor()
  const t = o?.events?.totals
  return {
    monthly: (o?.events?.trend ?? []).map(r => ({ label: r.label, attendance_rate: pct(r.attendance_rate) ?? 0, no_show_rate: pct(r.no_show_rate) ?? 0 })),
    avg_capacity_fill: pct(t?.average_capacity_fill),
    avg_no_show_rate: pct(t?.no_show_rate),
    total_events: t?.events ?? 0,
    period: periodOf(o),
  }
}
