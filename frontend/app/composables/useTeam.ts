// Team & staff access (B8): members, roles, invitations, event teams, task assignment.

export interface TeamMember {
  id: string
  user_id: string
  name: string
  email: string | null
  phone: string | null
  status: 'active' | 'invited' | 'deactivated'
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

export interface MyTask {
  id: string
  name: string
  description: string | null
  status: import('./useEvents').TaskStatus
  priority: import('./useEvents').TaskPriority
  due_at: string | null
  checklist: string
  event_id: string
  event: string
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
    invite: async (orgId: string, email: string, role: RoleChoice) =>
      (await api.post<TeamMember[]>(`${base(orgId)}/team/invitations`, { email, ...role })).data,
    changeRole: async (orgId: string, memberId: string, role: RoleChoice) =>
      (await api.patch<TeamMember[]>(`${base(orgId)}/team/${memberId}`, role)).data,
    remove: async (orgId: string, memberId: string) => (await api.delete<TeamMember[]>(`${base(orgId)}/team/${memberId}`)).data,
    reactivate: async (orgId: string, memberId: string) => (await api.post<TeamMember[]>(`${base(orgId)}/team/${memberId}/reactivate`)).data,
    createRole: async (orgId: string, body: { name: string, description?: string | null, permissions: string[] }) =>
      (await api.post<TeamRole[]>(`${base(orgId)}/roles`, body)).data,
    updateRole: async (orgId: string, roleId: string, body: { name?: string, description?: string | null, permissions?: string[] }) =>
      (await api.patch<TeamRole[]>(`${base(orgId)}/roles/${roleId}`, body)).data,
    removeRole: async (orgId: string, roleId: string) => (await api.delete<TeamRole[]>(`${base(orgId)}/roles/${roleId}`)).data,

    myInvitations: async () => (await api.get<MyInvitation[]>('/me/invitations')).data,
    accept: (id: string) => api.post(`/me/invitations/${id}/accept`),
    decline: (id: string) => api.post(`/me/invitations/${id}/decline`),

    eventTeam: async (orgId: string, eventId: string) => (await api.get<EventTeamMember[]>(`${base(orgId)}/events/${eventId}/team`)).data,
    addToEvent: async (orgId: string, eventId: string, body: { organization_member_id: string, label: string | null, permissions: string[] }) =>
      (await api.post<EventTeamMember[]>(`${base(orgId)}/events/${eventId}/team`, body)).data,
    removeFromEvent: async (orgId: string, eventId: string, eventMemberId: string) =>
      (await api.delete<EventTeamMember[]>(`${base(orgId)}/events/${eventId}/team/${eventMemberId}`)).data,
    assignTask: (orgId: string, eventId: string, taskId: string, eventMemberIds: string[]) =>
      api.put(`${base(orgId)}/events/${eventId}/tasks/${taskId}/assignees`, { event_member_ids: eventMemberIds }),
    myTasks: async (orgId: string) => (await api.get<MyTask[]>(`${base(orgId)}/my-tasks`)).data,
  }
}
