// Notifications (B13): the bell, the notifications page and e-mail settings.

export interface AppNotification {
  id: string
  type: string
  title: string
  body: string | null
  url: string | null
  critical: boolean
  read: boolean
  created_at: string
}

export interface NotificationPreference {
  type: string
  label: string
  group: 'organizer' | 'client' | 'tickets' | 'account' | 'admin'
  critical: boolean
  email: boolean
}

// Shared across the bell and the page, so marking read in one updates the other.
export const useUnreadCount = () => useState('notificationsUnread', () => 0)

export const useNotifications = () => {
  const api = useApi()
  const unread = useUnreadCount()

  return {
    list: async (opts: { unread?: boolean, page?: number } = {}) => {
      const r = await api.get<AppNotification[]>('/me/notifications', { query: { unread: opts.unread ? 1 : undefined, page: opts.page } })
      unread.value = (r.meta?.unread_count as number) ?? unread.value
      return { items: r.data, meta: r.meta }
    },
    refreshCount: async () => {
      unread.value = (await api.get<{ count: number }>('/me/notifications/unread-count')).data.count
    },
    read: async (id: string) => {
      unread.value = (await api.post<{ count: number }>(`/me/notifications/${id}/read`)).data.count
    },
    readAll: async () => {
      await api.post('/me/notifications/read-all')
      unread.value = 0
    },
    preferences: async () => (await api.get<NotificationPreference[]>('/me/notification-preferences')).data,
    savePreferences: async (prefs: { type: string, email: boolean }[]) =>
      (await api.put<NotificationPreference[]>('/me/notification-preferences', { preferences: prefs })).data,
  }
}

export function timeAgo(at: string): string {
  const s = Math.round((Date.now() - new Date(at).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)} min ago`
  if (s < 86400) return `${Math.floor(s / 3600)} h ago`
  if (s < 7 * 86400) return `${Math.floor(s / 86400)} d ago`
  return new Date(at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}
