// Public organizer directory and portfolio pages, served by the marketplace
// API (/api/v1/marketplace/organizers). The redesigned pages use the
// OrganizerPortfolio shape, so the API responses are mapped onto it here.

import type {
  OrganizerDirectoryResponse,
  OrganizerPortfolio,
  OrganizerPortfolioResponse,
  OrganizerPortfolioSummary,
} from '~/types/portfolio'
import type { OrganizerCard, OrganizerPublicProfile } from '~/composables/useMarketplace'

const bannerColors = ['#285F6b', '#346f7a', '#1c4f5c', '#4d8f97', '#7a5c3e', '#5b4b8a', '#8a4b5c', '#3e6b4f']

/** A stable color per organizer, when they haven't picked one. */
function bannerColor(slug: string): string {
  let hash = 0
  for (const ch of slug) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0
  return bannerColors[hash % bannerColors.length]!
}

type CardWithCount = OrganizerCard & { completed_events?: number }

function summary(card: CardWithCount | (OrganizerPublicProfile & { completed_events?: number })): OrganizerPortfolioSummary {
  const services = card.services.map(s => (typeof s === 'string' ? s : s.name))
  const rating = 'rating' in card ? card.rating.average : card.rating_avg
  const reviews = 'rating' in card ? card.rating.count : card.reviews_count
  // 0046: the organizer's own profile details (single profile only; cards fall back).
  const extra = card as Partial<Record<'years_experience' | 'banner_color' | 'website' | 'facebook' | 'instagram', string | null>> & { event_tags?: string[], specialties?: string[] }

  return {
    id: card.id as unknown as number,
    slug: card.slug,
    name: card.display_name || card.business_name,
    location: card.location_text,
    bio: 'description' in card ? card.description : card.excerpt,
    years_experience: extra.years_experience ?? null,
    specialties: extra.specialties?.length ? extra.specialties : services,
    banner_color: extra.banner_color || bannerColor(card.slug),
    portfolio_events_count: card.completed_events ?? 0,
    average_rating: rating ?? 0,
    reviews_count: reviews,
    tags: extra.event_tags?.length ? extra.event_tags : services,
    website: extra.website ?? null,
    facebook: extra.facebook ?? null,
    instagram: extra.instagram ?? null,
  }
}

export function useOrganizerDirectory() {
  const api = useApi()

  return useAsyncData<OrganizerDirectoryResponse>('public-organizer-directory', async () => {
    // The redesigned directory filters in the browser: load every page.
    const all: CardWithCount[] = []
    for (let page = 1, last = 1; page <= last && page <= 10; page++) {
      const response = await api.get<CardWithCount[]>('/marketplace/organizers', { query: { page, per_page: 48, sort: 'name' } })
      all.push(...response.data)
      last = (response.meta?.pagination as { last_page?: number } | undefined)?.last_page ?? 1
    }
    return { data: all.map(summary) }
  })
}

export function useOrganizerPortfolio(identifier: MaybeRefOrGetter<string>) {
  const api = useApi()

  return useAsyncData<OrganizerPortfolioResponse>(
    () => `public-organizer-${toValue(identifier)}`,
    async () => {
      const profile = (await api.get<OrganizerPublicProfile & {
        completed_events: number
        portfolio: { id: string | null, title: string, category: string, date: string | null, venue: string | null, description: string | null }[]
      }>(`/marketplace/organizers/${encodeURIComponent(toValue(identifier))}`)).data

      const portfolio: OrganizerPortfolio = {
        ...summary(profile),
        events: profile.portfolio.map((e, i) => ({
          id: (e.id ?? `private-${i}`) as unknown as number,
          title: e.title,
          category: e.category,
          date: e.date,
          year: e.date ? Number(e.date.slice(0, 4)) : null,
          venue: e.venue,
          description: e.description,
        })),
      }
      return { data: portfolio }
    },
    { watch: [() => toValue(identifier)] },
  )
}
