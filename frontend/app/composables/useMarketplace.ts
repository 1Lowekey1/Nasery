// Public organizer directory (B3). Works for guests and signed-in users.

export interface OrganizerCard {
  id: string
  slug: string
  display_name: string
  business_name: string
  excerpt: string | null
  location_text: string | null
  cover_image_url: string | null
  logo_url?: string | null
  cover_url?: string | null
  latitude: number | null
  longitude: number | null
  rating_avg: number | null
  reviews_count: number
  services: string[]
  distance_km: number | null
}

export interface PublicReview {
  id: string
  rating: number
  written_review: string | null
  reviewer: string
  event_type: string | null
  created_at: string
  edited: boolean
  reply: { text: string, at: string } | null
}

export interface OrganizerPublicProfile {
  id: string
  slug: string
  display_name: string
  business_name: string
  description: string | null
  location_text: string | null
  latitude: number | null
  longitude: number | null
  contact_email: string | null
  contact_phone: string | null
  cover_image_url: string | null
  logo_url?: string | null
  cover_url?: string | null
  services: { id: string, name: string, description: string | null, base_price: string | null }[]
  /** The organizer's quotation-calculator price list (B5). */
  price_options: import('./useQuotations').PriceOption[]
  /** 0048: service charge %, VAT % and a note shown with every estimate (null = not set). */
  estimate?: { service_charge_percent: number | null, tax_percent: number | null, note: string | null }
  event_types: { id: string, name: string, description: string | null }[]
  rating: { average: number | null, count: number, distribution: Record<string, number> }
  recent_reviews: PublicReview[]
}

export interface DirectoryFilters {
  search?: string
  service?: string
  /** Event type name: organizers whose event tags include it. */
  event_type?: string
  lat?: number
  lng?: number
  radius_km?: number
  sort?: 'rating' | 'name' | 'distance'
  page?: number
}

export const useMarketplace = () => {
  const api = useApi()

  const organizers = async (filters: DirectoryFilters) => {
    const query = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== '' && v !== undefined && v !== null))
    const response = await api.get<OrganizerCard[]>('/marketplace/organizers', { query })
    return { items: response.data, pagination: response.meta?.pagination as { current_page: number, last_page: number, total: number } }
  }

  const organizer = async (slug: string) => (await api.get<OrganizerPublicProfile>(`/marketplace/organizers/${slug}`)).data

  const reviews = async (slug: string, page = 1) => {
    const response = await api.get<PublicReview[]>(`/marketplace/organizers/${slug}/reviews`, { query: { page } })
    return { items: response.data, pagination: response.meta?.pagination as { current_page: number, last_page: number, total: number } }
  }

  const serviceNames = async () => (await api.get<{ name: string, organizers: number }[]>('/marketplace/services')).data
  const eventTypes = async () => (await api.get<{ id: string, name: string, description: string | null }[]>('/marketplace/event-types')).data

  return { organizers, organizer, reviews, serviceNames, eventTypes }
}

export function formatPeso(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 }).format(Number(value))
}

export function initialsOf(name: string): string {
  return name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}
