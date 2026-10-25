// Super Admin portal data, served by the NaSeRy admin API (/api/v1/admin/*)
// and mapped onto the shapes the redesigned pages use.
//
// Privacy (B17): organizer totals are open; an organizer's events, clients
// and reviews show only while NaSeRy has an access grant the organizer
// approved (`access` on the organizer detail says whether it does). Client
// accounts show counts only: what a client asked for or booked stays private.
// The `token` arguments are unused (the session cookie authenticates) and kept
// so the pages' calls stay unchanged.

import type { AdminEvent, AdminUser, OrganizerTotals, PlatformTotals, PlatformTrends, Workspace } from '~/composables/useAdmin'
import { actionLabel } from '~/composables/useAdmin'
import type { ApplicationDetail, ApplicationSummary } from '~/composables/useOrganizerApplications'

export interface AdminOrganizerSummary {
    id: number
    name: string
    email: string | null
    phone: string | null
    location: string | null
    joined: string
    events_count: number
    revenue: number
    rating: number
    reviews_count: number
    /** Approval status (pending, under_review, approved, rejected, suspended). */
    status?: string
    slug?: string
    /** `revenue` above is gross ticket sales; this is where that money went. */
    refunded?: number
    platform_fee?: number
    organizer_net?: number
    plan_payments?: number
}
export interface AdminOrganizerEvent {
    id: number
    name: string
    client: string
    event_date: string | null
    location: string | null
    status: string
    tickets_sold: number
    capacity: number
    revenue: number
}
export interface AdminOrganizerReview {
    id: number
    client: string
    rating: number
    review: string | null
    created_at: string
}
export interface AdminOrganizerQuotation {
    id: number
    client: string
    event: string
    amount: number
    status: string
    submitted_at: string
}
export interface AdminOrganizerDetail extends AdminOrganizerSummary {
    bio: string | null
    website: string | null
    bids_count: number
    won_bids_count: number
    win_rate: number
    clients: string[]
    events: AdminOrganizerEvent[]
    quotations: AdminOrganizerQuotation[]
    reviews: AdminOrganizerReview[]
    /** NaSeRy access: the lists above are filled only while a grant is active. */
    access?: ApplicationDetail['access'] & { granted: boolean }
    completed_events?: number
    tickets_sold?: number
    members?: number
}
export interface AdminClientSummary {
    id: number
    name: string
    email: string | null
    phone: string | null
    joined: string
    inquiries_count: number
    events_count: number
    /** As an attendee: paid ticket purchases, net of refunds. */
    total_spend: number
    tickets_count?: number
    status?: string
}
export interface AdminClientInquiry {
    id: number
    event_title: string | null
    event_type: string
    event_date: string | null
    status: string
    budget_range: string | null
    quotations_count: number
    created_at: string
}
export interface AdminClientEvent {
    id: number
    name: string
    organizer: string
    event_date: string | null
    location: string | null
    status: string
    amount: number
}
export interface AdminClientReview {
    id: number
    organizer: string
    rating: number
    review: string | null
    created_at: string
}
export interface AdminClientDetail extends AdminClientSummary {
    address: string | null
    inquiries: AdminClientInquiry[]
    events: AdminClientEvent[]
    reviews: AdminClientReview[]
    tickets_count?: number
    reviews_count?: number
    verified?: boolean
}
export interface AdminPlatformEvent {
    id: number
    name: string
    event_type: string | null
    organizer: string
    organizer_id: number
    client: string
    client_id: number
    event_date: string | null
    location: string | null
    status: string
    expected_guests: number
    tickets_sold: number
    capacity: number
    revenue: number
}
export interface AdminActivityItem {
    type: string
    icon: string
    label: string
    at: string
}
export interface AdminPendingQuotation {
    id: number
    organizer: string
    event: string
    amount: number
    submitted_at: string
}
export interface AdminOverview {
    organizer_count: number
    client_count: number
    event_count: number
    active_event_count: number
    new_organizers_this_month: number
    new_clients_this_month: number
    new_events_this_month: number
    total_revenue: number
    avg_organizer_rating: number
    bids_this_month: number
    pending_quotations_count: number
    pending_quotations: AdminPendingQuotation[]
    top_organizers: Array<{ id: number, name: string, events_count: number, revenue: number }>
    recent_activity: Array<{ type: string, label: string, at: string }>
    /** NaSeRy's own income: platform fees (after fees returned on refunds) + plan subscriptions. */
    nasery_fees?: number
    nasery_plans?: number
    /** Ticket money processed for organizers (not NaSeRy's). */
    ticket_sales?: number
    /** Items waiting for an admin (applications, reports, fee claims, support invites). */
    attention?: { applications: number, reports: number, fee_claims: number, support_invites: number }
}
export interface AdminAnalytics {
    revenue_by_month: Array<{ label: string, value: number }>
    signups_by_month: Array<{ label: string, organizers: number, clients: number }>
    events_by_status: Array<{ status: string, total: number }>
    quotations_by_status: Array<{ status: string, total: number }>
}
export interface AdminEventTag {
    id: number
    name: string
    description?: string | null
    events?: number
    inquiries?: number
    /** Organizers who list this type on their profile. */
    organizers?: number
}

type Totals = PlatformTotals & {
    quotations_pending?: number
    quotations?: Record<string, number>
    this_month?: { clients: number, organizers: number, events: number, quotations: number }
}

const asId = (id: string) => id as unknown as number
const monthLabel = (ym: string) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('en-PH', { month: 'short', year: '2-digit' })

/** Every page of a paginated admin list (the redesigned pages filter in the browser). */
async function allPages<T>(url: string, query: Record<string, unknown>, max = 10): Promise<T[]> {
    const api = useApi()
    const items: T[] = []
    for (let page = 1, last = 1; page <= last && page <= max; page++) {
        const r = await api.get<T[]>(url, { query: { ...query, page } })
        items.push(...r.data)
        last = (r.meta?.pagination as { last_page?: number } | undefined)?.last_page ?? 1
    }
    return items
}

function organizerSummary(a: ApplicationSummary): AdminOrganizerSummary {
    const t = a.totals as OrganizerTotals | null | undefined
    return {
        id: asId(a.id),
        name: a.display_name,
        email: a.owner_email ?? null,
        phone: null,
        location: a.location_text,
        joined: a.submitted_at,
        events_count: t?.events ?? 0,
        revenue: t?.sales ?? 0,
        refunded: t?.refunded ?? 0,
        platform_fee: t?.platform_fee ?? 0,
        organizer_net: t?.organizer_net ?? 0,
        plan_payments: t?.plan_payments ?? 0,
        rating: t?.rating ?? 0,
        reviews_count: t?.reviews ?? 0,
        status: a.approval_status,
        slug: a.slug,
    }
}

function clientSummary(u: AdminUser & { inquiries?: number, bookings?: number, tickets?: number, spend?: number }): AdminClientSummary {
    return {
        id: asId(u.id),
        name: u.name,
        email: u.email,
        phone: u.phone,
        joined: u.joined,
        inquiries_count: u.inquiries ?? 0,
        events_count: u.bookings ?? 0,
        total_spend: u.spend ?? 0,
        tickets_count: u.tickets ?? 0,
        status: u.status,
    }
}

export const superAdminService = {
    async getOverview(_token?: string | null): Promise<{ data: AdminOverview }> {
        const d = (await useApi().get<{
            totals: Totals
            attention: AdminOverview['attention']
            top_organizers: OrganizerTotals[]
            recent_activity: { action: string, actor: string, organization: string | null, at: string }[]
        }>('/admin/overview')).data
        const t = d.totals
        return {
            data: {
                organizer_count: t.organizers.approved,
                client_count: t.users.client,
                event_count: t.events.total,
                active_event_count: t.events.published + t.events.ongoing,
                new_organizers_this_month: t.this_month?.organizers ?? 0,
                new_clients_this_month: t.this_month?.clients ?? 0,
                new_events_this_month: t.this_month?.events ?? 0,
                total_revenue: t.platform_fee + t.subscriptions,
                nasery_fees: t.platform_fee,
                nasery_plans: t.subscriptions,
                ticket_sales: t.sales,
                avg_organizer_rating: t.rating.average ?? 0,
                bids_this_month: t.this_month?.quotations ?? 0,
                pending_quotations_count: t.quotations_pending ?? 0,
                // Amounts of open quotations stay between the organizer and the client.
                pending_quotations: [],
                top_organizers: d.top_organizers.map(o => ({ id: asId(o.id), name: o.name, events_count: o.events, revenue: o.sales })),
                recent_activity: d.recent_activity.map(a => ({
                    type: a.action.split('.')[0] ?? 'activity',
                    label: `${a.actor}: ${actionLabel(a.action)}${a.organization ? ` (${a.organization})` : ''}`,
                    at: a.at,
                })),
                attention: d.attention,
            },
        }
    },

    async getOrganizers(_token?: string | null, search = ''): Promise<{ data: AdminOrganizerSummary[] }> {
        const rows = await allPages<ApplicationSummary>('/admin/organizer-applications', { search: search || undefined })
        return { data: rows.map(organizerSummary) }
    },

    async getOrganizer(_token: string | null | undefined, id: number | string): Promise<{ data: AdminOrganizerDetail }> {
        const api = useApi()
        const a = (await api.get<ApplicationDetail>(`/admin/organizer-applications/${id}`)).data
        const granted = Boolean(a.access?.grant)
        let workspace: Workspace | null = null
        if (granted) {
            try {
                workspace = (await api.get<Workspace>(`/admin/organizations/${id}/workspace`)).data
            } catch {
                workspace = null
            }
        }
        const t = a.totals
        return {
            data: {
                ...organizerSummary(a),
                phone: a.profile?.contact_phone ?? a.owner?.phone ?? null,
                email: a.owner?.email ?? a.profile?.contact_email ?? null,
                bio: a.profile?.description ?? null,
                website: null,
                bids_count: 0,
                won_bids_count: 0,
                win_rate: 0,
                clients: workspace?.clients.map(c => c.name) ?? [],
                events: workspace?.events.map(e => ({
                    id: asId(e.id),
                    name: e.name,
                    client: e.client ?? (e.visibility === 'public' ? 'Public event' : '—'),
                    event_date: e.starts_at,
                    location: e.venue,
                    status: e.status,
                    tickets_sold: e.tickets_sold,
                    capacity: e.capacity ?? 0,
                    revenue: e.sales,
                })) ?? [],
                quotations: [],
                reviews: workspace?.reviews.map(r => ({ id: asId(r.id), client: r.reviewer, rating: r.rating, review: r.text, created_at: r.at })) ?? [],
                access: { ...(a.access ?? { grant: null, pending_request_id: null, default_hours: 24 }), granted },
                completed_events: t?.completed_events ?? 0,
                tickets_sold: t?.tickets_sold ?? 0,
                members: t?.members ?? 0,
            },
        }
    },

    async getClients(_token?: string | null, search = ''): Promise<{ data: AdminClientSummary[] }> {
        const rows = await allPages<AdminUser>('/admin/users', { type: 'client', search: search || undefined })
        return { data: rows.map(clientSummary) }
    },

    async getClient(_token: string | null | undefined, id: number | string): Promise<{ data: AdminClientDetail }> {
        const u = (await useApi().get<AdminUser & { inquiries: number, bookings: number, tickets: number, spend: number, reviews: number }>(`/admin/users/${id}`)).data
        return {
            data: {
                ...clientSummary(u),
                address: null,
                inquiries: [],
                events: [],
                reviews: [],
                tickets_count: u.tickets,
                reviews_count: u.reviews,
                verified: u.verified,
            },
        }
    },

    async getEvents(_token?: string | null, search = '', status = ''): Promise<{ data: AdminPlatformEvent[] }> {
        const { items } = (await useApi().get<{ items: AdminEvent[] }>('/admin/events', { query: { ...(search ? { search } : {}), ...(status && status !== 'all' ? { status } : {}) } })).data
        return {
            data: items.map(e => ({
                id: asId(e.id),
                name: e.name,
                event_type: e.event_type ?? null,
                organizer: e.organizer.name,
                organizer_id: asId(e.organizer.id),
                client: e.visibility === 'public' ? 'Public' : 'Private client',
                client_id: 0,
                event_date: e.starts_at,
                location: e.venue,
                status: e.status,
                expected_guests: e.capacity ?? 0,
                tickets_sold: e.tickets_sold,
                capacity: e.capacity ?? 0,
                revenue: e.ticket_sales ?? 0,
            })),
        }
    },

    async getActivity(_token?: string | null): Promise<{ data: AdminActivityItem[] }> {
        const rows = await allPages<{ action: string, actor: string, organization?: { name: string } | string | null, entity: string, at: string }>('/admin/activity', {}, 2)
        return {
            data: rows.map(r => ({
                type: r.action.split('.')[0] ?? 'activity',
                icon: r.action.startsWith('payment') ? 'credit-card' : r.action.startsWith('event') ? 'calendar' : r.action.startsWith('review') ? 'star' : 'clipboard-list',
                label: `${r.actor}: ${actionLabel(r.action)}${r.organization ? ` (${typeof r.organization === 'string' ? r.organization : r.organization.name})` : ''}`,
                at: r.at,
            })),
        }
    },

    async getAnalytics(_token?: string | null): Promise<{ data: AdminAnalytics }> {
        const d = (await useApi().get<{ totals: Totals, trends: PlatformTrends }>('/admin/analytics', { query: { months: 12 } })).data
        const m = d.trends.months
        return {
            data: {
                // NaSeRy's revenue: its platform fees + plan subscriptions (ticket money is the organizers').
                revenue_by_month: m.map((ym, i) => ({ label: monthLabel(ym), value: (d.trends.platform_fee[i] ?? 0) + (d.trends.subscriptions[i] ?? 0) })),
                signups_by_month: m.map((ym, i) => ({ label: monthLabel(ym), organizers: d.trends.new_organizers[i] ?? 0, clients: d.trends.new_clients[i] ?? 0 })),
                events_by_status: (['draft', 'published', 'ongoing', 'completed', 'cancelled'] as const).map(status => ({ status, total: d.totals.events[status] })),
                quotations_by_status: Object.entries(d.totals.quotations ?? {}).map(([status, total]) => ({ status, total })),
            },
        }
    },

    async getEventTags(_token?: string | null): Promise<{ data: AdminEventTag[] }> {
        return { data: (await useApi().get<AdminEventTag[]>('/admin/event-types')).data }
    },

    async createEventTag(_token: string | null | undefined, name: string): Promise<{ message: string, data: AdminEventTag }> {
        const r = await useApi().post<AdminEventTag>('/admin/event-types', { name })
        return { message: r.message ?? 'Event type added.', data: r.data }
    },

    async updateEventTag(_token: string | null | undefined, id: number | string, name: string): Promise<{ message: string, data: AdminEventTag }> {
        const r = await useApi().patch<AdminEventTag>(`/admin/event-types/${id}`, { name })
        return { message: r.message ?? 'Event type updated.', data: r.data }
    },

    async deleteEventTag(_token: string | null | undefined, id: number | string): Promise<{ message: string }> {
        const r = await useApi().delete(`/admin/event-types/${id}`)
        return { message: r.message ?? 'Event type removed.' }
    },
}
