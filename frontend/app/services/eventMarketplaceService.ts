// Public events listing (/api/v1/public-events), mapped onto the shape the
// redesigned marketplace page uses. "Register" opens the registration page
// for the event (/event-registration/{event id}).

import type { MarketplaceEvent, MarketplaceResponse } from '~/types/marketplace'
import type { PublicEventCard } from '~/composables/useTicketing'

type ListedEvent = PublicEventCard & {
  excerpt: string | null
  ticket_types: { id: string, name: string, price: string }[]
}

const bannerColors = ['#285F6b', '#346f7a', '#1c4f5c', '#4d8f97', '#7a5c3e', '#5b4b8a', '#8a4b5c', '#3e6b4f']
const colorFor = (slug: string) => {
  let hash = 0
  for (const ch of slug) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0
  return bannerColors[hash % bannerColors.length]!
}

/** "HH:MM" in the event's own time zone. */
const timeIn = (iso: string | null, timezone: string) => iso
  ? new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: timezone }).format(new Date(iso))
  : null

const dateIn = (iso: string | null, timezone: string) => iso
  ? new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date(iso))
  : null

function toMarketplace(e: ListedEvent): MarketplaceEvent {
  return {
    id: e.id as unknown as number,
    name: e.name,
    event_type: e.event_type ?? 'Event',
    description: e.excerpt,
    event_date: dateIn(e.starts_at, e.timezone),
    start_time: timeIn(e.starts_at, e.timezone),
    end_time: timeIn(e.ends_at, e.timezone),
    status: e.status,
    location: e.venue_name || e.venue_address,
    organizer: { id: 0, slug: e.organizer.slug, name: e.organizer.name, banner_color: colorFor(e.organizer.slug) },
    ticket_types: e.ticket_types.map(t => ({ id: t.id as unknown as number, name: t.name, price: Number(t.price), capacity: null })),
    registration_open: e.ticket_types.length > 0,
    registration_path: `/event-registration/${e.id}`,
  }
}

export function useEventMarketplace() {
  const api = useApi()

  return useAsyncData<MarketplaceResponse>('public-event-marketplace', async () => {
    const all: ListedEvent[] = []
    for (let page = 1, last = 1; page <= last && page <= 10; page++) {
      const response = await api.get<ListedEvent[]>('/public-events', { query: { page } })
      all.push(...response.data)
      last = (response.meta?.pagination as { last_page?: number } | undefined)?.last_page ?? 1
    }
    return { data: all.map(toMarketplace) }
  })
}
