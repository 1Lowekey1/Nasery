// What the public search boxes suggest, built from the lists the pages already load.
import type { SearchSuggestion } from '~/components/forms/SearchSuggest.vue'

type SuggestOrganizer = { name: string, location: string | null, specialties: string[] }
type SuggestEvent = { name: string, event_type: string | null, location: string | null, organizer: { name: string } }

const add = (label: string | null | undefined, kind: string): SearchSuggestion[] => (label ? [{ label, kind }] : [])

export function organizerSuggestions(list: SuggestOrganizer[]): SearchSuggestion[] {
	return list.flatMap(o => [...add(o.name, 'Organizer'), ...o.specialties.flatMap(s => add(s, 'Service')), ...add(o.location, 'Location')])
}

export function eventSuggestions(list: SuggestEvent[]): SearchSuggestion[] {
	return list.flatMap(e => [...add(e.name, 'Event'), ...add(e.organizer.name, 'Organizer'), ...add(e.event_type, 'Event type'), ...add(e.location, 'Venue')])
}
