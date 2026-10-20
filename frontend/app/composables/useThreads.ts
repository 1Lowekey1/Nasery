// Private client–organizer threads on inquiries (0042).

export interface ThreadSummary {
  id: string
  inquiry_id: string
  event_type: string
  requested_date: string | null
  organization: { id: string, name: string, slug: string }
  client: string
  participant_status: string
  can_send: boolean
  last: { body: string, side: 'client' | 'organizer', at: string } | null
  unread: number
  side: 'client' | 'organizer'
}

export interface ThreadMessage {
  id: string
  side: 'client' | 'organizer'
  mine: boolean
  author?: string
  body: string
  at: string
}

export interface ThreadDetail extends ThreadSummary {
  messages: ThreadMessage[]
}

/**
 * Organizer inquiry pages: unread counts per inquiry and the open drawer.
 * `?thread=` (from a message notification) opens that conversation.
 */
export function useOrganizerThreads() {
  const api = useThreads()
  const route = useRoute()
  const unread = ref<Record<string, number>>({})
  const open = ref<{ id: string, title: string, inquiryId: string } | null>(null)
  const dropThreadQuery = () => navigateTo({ query: { ...route.query, thread: undefined } }, { replace: true })

  async function loadUnread(orgId: string) {
    try {
      const list = await api.forOrganization(orgId)
      unread.value = Object.fromEntries(list.map(t => [t.inquiry_id, t.unread]))
      const wanted = typeof route.query.thread === 'string' ? list.find(t => t.id === route.query.thread) : null
      if (wanted && !open.value) open.value = { id: wanted.id, title: `${wanted.client} · ${wanted.event_type}`, inquiryId: wanted.inquiry_id }
      // Use ?thread= once: closing the drawer reloads the counts, which must not reopen it.
      if (route.query.thread) await dropThreadQuery()
    } catch {
      unread.value = {} // members without inquiry duties: no messages
    }
  }

  async function openFor(orgId: string, inquiryId: string, title: string) {
    const [thread] = await api.forOrganization(orgId, inquiryId)
    if (thread) open.value = { id: thread.id, title, inquiryId }
  }

  /** The open conversation was read: clear its inquiry's badge. */
  function markRead() {
    if (open.value) unread.value[open.value.inquiryId] = 0
  }

  return { unread, open, loadUnread, openFor, markRead }
}

export const useThreads = () => {
  const api = useApi()

  return {
    forInquiry: async (inquiryId: string) => (await api.get<ThreadSummary[]>(`/inquiries/${inquiryId}/threads`)).data,
    forOrganization: async (orgId: string, inquiryId?: string) =>
      (await api.get<ThreadSummary[]>(`/organizations/${orgId}/threads`, { query: inquiryId ? { inquiry: inquiryId } : {} })).data,
    show: async (threadId: string) => (await api.get<ThreadDetail>(`/threads/${threadId}`)).data,
    send: async (threadId: string, body: string) => (await api.post<ThreadMessage>(`/threads/${threadId}/messages`, { body })).data,
  }
}
