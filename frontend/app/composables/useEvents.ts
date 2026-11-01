// Events (B6): organizer management and the client's read-only view.

export type EventStatus = 'draft' | 'published' | 'ongoing' | 'completed' | 'cancelled'
export type EventAction = 'publish' | 'unpublish' | 'start' | 'stop' | 'complete' | 'cancel'

export interface EventSession {
  id: string
  name: string | null
  status: 'upcoming' | 'ongoing' | 'completed' | 'cancelled'
  starts_at: string
  ends_at: string
  /** For <input type="datetime-local">, in the event timezone. */
  starts_local: string
  ends_local: string
}

export interface EventFields {
  name: string
  event_type_id: string | null
  description: string | null
  timezone?: string
  overall_capacity: number | null
  venue_name: string | null
  venue_address: string | null
  latitude: number | null
  longitude: number | null
  visibility: 'private' | 'public'
  contact_name: string | null
  contact_email: string | null
  contact_phone: string | null
}

export interface SessionInput {
  name: string | null
  starts_at: string
  ends_at: string
}

export interface NaseryEvent extends EventFields {
  id: string
  organization_id: string
  status: EventStatus
  event_type: string | null
  timezone: string
  inquiry_id: string | null
  cancelled_reason: string | null
  cancelled_at: string | null
  starts_at: string | null
  ends_at: string | null
  sessions: EventSession[]
  /** B7: null until the event has tasks (or a manual value). */
  readiness_percent: number | null
  readiness_manual: boolean
  stage: string | null
  created_at: string
  /** Uploaded cover picture (MediaController). */
  cover_url?: string | null
}

export type TaskStatus = 'pending' | 'in_progress' | 'done' | 'blocked' | 'cancelled'
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent'

export interface PrepTask {
  id: string
  name: string
  description: string | null
  status: TaskStatus
  priority: TaskPriority
  due_at: string | null
  due_local: string | null
  /** B8: event member ids assigned to this task. */
  assignee_ids: string[]
  /** Assigned to the signed-in member. */
  mine: boolean
}

export interface Preparation {
  readiness_percent: number | null
  computed_readiness_percent: number | null
  readiness_manual: boolean
  stage: string | null
  stages: string[]
  event_status: EventStatus
  /** B8: the event's active team (for assigning tasks). */
  team: import('./useTeam').EventTeamMember[]
  can: { manage_checklists: boolean, manage_tasks: boolean, override_readiness: boolean }
  checklists: { id: string, name: string, description: string | null, done: number, total: number, tasks: PrepTask[] }[]
}

export interface TimelineEntry {
  id: string
  at: string
  action: string
  message: string
  actor?: string | null
  reason: string | null
  milestone?: boolean
}

export interface ClientProgress {
  readiness_percent: number | null
  stage: string | null
  stages: string[]
  event_status: EventStatus
  milestones: TimelineEntry[]
  /** 0049: program items the organizer chose to show the client, in time order. */
  program: ClientProgramItem[]
}

export interface ClientProgramItem {
  id: string
  title: string
  description: string | null
  category: string | null
  status: 'pending' | 'in_progress' | 'completed'
  activity_at: string | null
}

export const taskStatusLabels: Record<TaskStatus, string> = {
  pending: 'To do', in_progress: 'In progress', done: 'Done', blocked: 'Blocked', cancelled: 'Not needed',
}

export const priorityClasses: Record<TaskPriority, string> = {
  low: 'bg-gray-100 text-gray-600', medium: 'bg-primary-50 text-primary-700', high: 'bg-amber-50 text-amber-700', urgent: 'bg-rose-50 text-rose-700',
}

export interface OrganizerEvent extends NaseryEvent {
  accepted_quotation_id: string | null
  /** Present on single-event responses (B8): what the signed-in member may do. */
  can?: { update: boolean, publish: boolean, complete: boolean, cancel: boolean, manage_team: boolean }
  /** Set for events made from an accepted quotation. */
  client: { name: string, email: string | null, phone: string | null } | null
}

export interface ClientEvent extends NaseryEvent {
  organizer: { display_name: string, slug: string | null, contact_email: string | null, contact_phone: string | null } | null
}

export const eventStatusLabels: Record<EventStatus, string> = {
  draft: 'Draft',
  published: 'Published',
  ongoing: 'Happening now',
  completed: 'Completed',
  cancelled: 'Cancelled',
}

/** "Sat, Feb 14, 2027 · 5:00 PM – 10:00 PM" in the event's own timezone. */
export function sessionLabel(s: Pick<EventSession, 'starts_at' | 'ends_at'>, timeZone: string): string {
  const start = new Date(s.starts_at)
  const end = new Date(s.ends_at)
  const day = (d: Date) => d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone })
  const time = (d: Date) => d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', timeZone })
  return day(start) === day(end)
    ? `${day(start)} · ${time(start)} – ${time(end)}`
    : `${day(start)} ${time(start)} – ${day(end)} ${time(end)}`
}

type Page<T> = { items: T[], pagination: { current_page: number, last_page: number, total: number } }

export const useOrganizerEvents = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}/events`
  const key = () => ({ idempotencyKey: crypto.randomUUID() })

  return {
    list: async (orgId: string, status: EventStatus[] = [], page = 1): Promise<Page<OrganizerEvent>> => {
      const r = await api.get<OrganizerEvent[]>(base(orgId), { query: { 'status[]': status.length ? status : undefined, page } })
      return { items: r.data, pagination: r.meta?.pagination as Page<OrganizerEvent>['pagination'] }
    },
    show: async (orgId: string, id: string) => (await api.get<OrganizerEvent>(`${base(orgId)}/${id}`)).data,
    create: async (orgId: string, fields: EventFields & { sessions?: SessionInput[] }) =>
      (await api.post<OrganizerEvent>(base(orgId), fields, key())).data,
    convert: async (orgId: string, quotationId: string, fields: EventFields & { sessions?: SessionInput[] }) =>
      (await api.post<OrganizerEvent>(`/organizations/${orgId}/quotations/${quotationId}/event`, fields, key())).data,
    update: async (orgId: string, id: string, fields: Partial<EventFields>) => (await api.patch<OrganizerEvent>(`${base(orgId)}/${id}`, fields)).data,
    transition: async (orgId: string, id: string, action: EventAction, reason?: string) =>
      (await api.post<OrganizerEvent>(`${base(orgId)}/${id}/${action}`, reason ? { reason } : {})).data,
    remove: (orgId: string, id: string) => api.delete(`${base(orgId)}/${id}`),
    addSession: async (orgId: string, id: string, s: SessionInput) => (await api.post<OrganizerEvent>(`${base(orgId)}/${id}/sessions`, s)).data,
    updateSession: async (orgId: string, id: string, sessionId: string, s: SessionInput) =>
      (await api.patch<OrganizerEvent>(`${base(orgId)}/${id}/sessions/${sessionId}`, s)).data,

    // B7 preparation + timeline (every call returns the whole preparation state)
    preparation: async (orgId: string, id: string) => (await api.get<Preparation>(`${base(orgId)}/${id}/preparation`)).data,
    updateTask: async (orgId: string, id: string, taskId: string, body: Partial<Omit<PrepTask, 'id'>>) =>
      (await api.patch<Preparation>(`${base(orgId)}/${id}/tasks/${taskId}`, body)).data,
    timeline: async (orgId: string, id: string) => (await api.get<TimelineEntry[]>(`${base(orgId)}/${id}/timeline`)).data,
  }
}

export const useClientEvents = () => {
  const api = useApi()
  return {
    list: async () => (await api.get<ClientEvent[]>('/events')).data,
    show: async (id: string) => (await api.get<ClientEvent>(`/events/${id}`)).data,
    progress: async (id: string) => (await api.get<ClientProgress>(`/events/${id}/progress`)).data,
  }
}
