// Post-event attendance report (B14).

export interface AttendanceReport {
  event: { id: string, name: string, organizer: string, venue: string | null, status: string, visibility: string, timezone: string, sessions: { id: string, label: string, when: string }[] }
  final: boolean
  generated_at: string
  totals: {
    expected: number, attended: number, no_shows: number, attendance_rate: number | null, walk_ins: number, door_sales: number, people: number,
    capacity: number | null, capacity_used: number | null, cancelled_tickets: number, manual_check_ins: number, repeat_scans: number
  }
  tiers: { name: string, zone: string | null, expected: number, attended: number, no_shows: number, rate: number }[]
  sources: { source: string, label: string, expected: number, attended: number }[]
  sessions: { label: string, checked_in: number, walk_ins: number }[]
  timeline: { bucket_minutes: number, buckets: { label: string, starts_at: string, check_ins: number, walk_ins: number }[], peak: { label: string, check_ins: number, walk_ins: number } | null }
  revenue?: { online_gross: string, platform_fee: string, refunds: string, online_net: string, door_cash: string, total: string }
  attendees?: { name: string, email: string | null, tier: string, zone: string | null, source: string, status: 'attended' | 'no_show' | 'cancelled', first_check_in: string | null, sessions_attended: number, method: string | null }[]
  walk_in_names?: { name: string, at: string }[]
}

export interface ReportShare { version: number, note: string | null, includes_revenue: boolean, shared_by?: string, shared_at: string }

export interface OrganizerReport extends AttendanceReport {
  shares: ReportShare[]
  can: { revenue: boolean, share: boolean, has_client: boolean }
}

export interface SharedReport {
  shared: { version: number, note: string | null, shared_at: string, report: AttendanceReport } | null
  versions: ReportShare[]
}

export const attendeeStatusLabels = { attended: 'Attended', no_show: 'No-show', cancelled: 'Cancelled' } as const

export function saveBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  a.click()
  URL.revokeObjectURL(url)
}

export const useReports = () => {
  const api = useApi()
  const base = (orgId: string, eventId: string) => `/organizations/${orgId}/events/${eventId}/report`

  return {
    report: async (orgId: string, eventId: string) => (await api.get<OrganizerReport>(base(orgId, eventId))).data,
    pdf: (orgId: string, eventId: string) => api.blob(`${base(orgId, eventId)}/pdf`),
    csv: (orgId: string, eventId: string) => api.blob(`${base(orgId, eventId)}/csv`),
    share: async (orgId: string, eventId: string, body: { note: string | null, include_revenue: boolean }) =>
      (await api.post<ReportShare>(`${base(orgId, eventId)}/share`, body, { idempotencyKey: crypto.randomUUID() })).data,
    clientReport: async (eventId: string, version?: number) =>
      (await api.get<SharedReport>(`/events/${eventId}/report`, { query: { version } })).data,
    clientPdf: (eventId: string, version?: number) => api.blob(`/events/${eventId}/report/pdf`, { query: { version } }),
  }
}
