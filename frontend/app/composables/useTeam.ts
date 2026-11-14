// Team & staff access (B8): members, roles, invitations, event teams, task assignment.

export interface TeamMember {
  id: string
  user_id: string
  name: string
  email: string | null
  phone: string | null
  status: 'active' | 'invited' | 'deactivated'
  /** Invited by e-mail and hasn't opened the set-up link yet. */
  account_pending?: boolean
  is_owner: boolean
  is_primary_owner: boolean
  role_id: string | null
  role: string
  joined_at: string | null
  invited_at: string | null
  deactivated_at: string | null
}

export interface TeamRole { id: string, name: string, description: string | null, permissions: string[], members: number }
export interface RolePreset { key: string, name: string, description: string, permissions: string[] }
export interface Permission { key: string, category: string, description: string }

export interface Team {
  members: TeamMember[]
  roles: TeamRole[]
  presets: RolePreset[]
  permissions: Permission[]
  can_manage: boolean
}

/** An open task of a member, shown before removing them. */
export interface OpenTask { id: string, name: string, status: string, due_at: string | null, event: { id: string, name: string } }

/** The team a business account was on last (removed or left). */
export interface FormerTeam { organization: string, ended_at: string | null, how: 'removed' | 'left' }

export interface MyInvitation { id: string, organization: string, role: string, invited_by: string | null, invited_at: string }

export interface EventTeamMember {
  id: string
  organization_member_id: string
  name: string
  email: string | null
  label: string | null
  status: string
  permissions: string[]
}

/** A program item where this person is the person in charge. */
export interface MyProgramItem {
  id: string
  title: string
  status: 'pending' | 'in_progress' | 'completed'
  activity_at: string | null
  event_id: string
  event: string
}

export interface MyTask {
  id: string
  name: string
  description: string | null
  status: import('./useEvents').TaskStatus
  priority: import('./useEvents').TaskPriority
  due_at: string | null
  /** When it was assigned to you (sorting by newest). */
  assigned_at?: string
  checklist: string
  event_id: string
  event: string
  /** 0044: the note left when finishing it, and the organizer's review. */
  completion_note?: string | null
  review_status?: 'pending' | 'verified' | 'changes_requested' | null
  review_note?: string | null
  event_type?: string | null
  event_starts_at?: string | null
}

/** Permissions that can be given per event (matches EventTeam::EVENT_PERMISSIONS). */
export const eventPermissionLabels: Record<string, string> = {
  'event.update': 'Edit event details',
  'checklist.manage': 'Manage checklists',
  'task.manage': 'Manage tasks',
  'ticket.manage': 'Manage tickets',
  'attendee.manage': 'Manage guest list',
  'checkin.perform': 'Check in guests',
}

export type RoleChoice = { role_id: string } | { preset: string }

export const useTeam = () => {
  const api = useApi()
  const base = (orgId: string) => `/organizations/${orgId}`

  return {
    team: async (orgId: string) => (await api.get<Team>(`${base(orgId)}/team`)).data,
    /** No NaSeRy account yet? Give their name: the invitation creates it and e-mails a set-up link. */
    invite: async (orgId: string, email: string, role: RoleChoice, name?: { first_name: string, last_name: string }) =>
      (await api.post<TeamMember[]>(`${base(orgId)}/team/invitations`, { email, ...role, ...(name ?? {}) })).data,
    resend: async (orgId: string, memberId: string) => (await api.post<TeamMember[]>(`${base(orgId)}/team/${memberId}/resend`)).data,
    openTasks: async (orgId: string, memberId: string) => (await api.get<OpenTask[]>(`${base(orgId)}/team/${memberId}/open-tasks`)).data,
    changeRole: async (orgId: string, memberId: string, role: RoleChoice) =>
      (await api.patch<TeamMember[]>(`${base(orgId)}/team/${memberId}`, role)).data,
    /** Deactivate (or cancel an invite). Their open tasks go to reassignTo, or become unassigned. */
    remove: async (orgId: string, memberId: string, reassignTo?: string | null) => {
      const r = await api.delete<TeamMember[]>(`${base(orgId)}/team/${memberId}`, reassignTo ? { query: { reassign_to: reassignTo } } : undefined)
      return { members: r.data, message: r.message }
    },
    reactivate: async (orgId: string, memberId: string) => (await api.post<TeamMember[]>(`${base(orgId)}/team/${memberId}/reactivate`)).data,
    createRole: async (orgId: string, body: { name: string, description?: string | null, permissions: string[] }) =>
      (await api.post<TeamRole[]>(`${base(orgId)}/roles`, body)).data,
    removeRole: async (orgId: string, roleId: string) => (await api.delete<TeamRole[]>(`${base(orgId)}/roles/${roleId}`)).data,

    myInvitations: async () => (await api.get<MyInvitation[]>('/me/invitations')).data,
    accept: (id: string) => api.post(`/me/invitations/${id}/accept`),
    decline: (id: string) => api.post(`/me/invitations/${id}/decline`),
    leave: () => api.post('/me/team/leave'),
    formerTeam: async () => (await api.get<FormerTeam | null>('/me/former-team')).data,

    eventTeam: async (orgId: string, eventId: string) => (await api.get<EventTeamMember[]>(`${base(orgId)}/events/${eventId}/team`)).data,
    addToEvent: async (orgId: string, eventId: string, body: { organization_member_id: string, label: string | null, permissions: string[] }) =>
      (await api.post<EventTeamMember[]>(`${base(orgId)}/events/${eventId}/team`, body)).data,
    removeFromEvent: async (orgId: string, eventId: string, eventMemberId: string) =>
      (await api.delete<EventTeamMember[]>(`${base(orgId)}/events/${eventId}/team/${eventMemberId}`)).data,
    myTasks: async (orgId: string) => (await api.get<MyTask[]>(`${base(orgId)}/my-tasks`)).data,
    myProgram: async (orgId: string) => (await api.get<MyProgramItem[]>(`${base(orgId)}/my-program`)).data,
  }
}
