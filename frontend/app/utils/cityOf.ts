// The city of a free-text address, so location filters group places instead of
// listing every venue. Addresses are typed or come from the map, so this reads
// them loosely and falls back to "Other areas".
const DAVAO_AREAS = /\b(lanang|matina|buhangin|toril|calinan|poblacion|bajada|sasa|davao)\b/i
const NOT_A_PLACE = /^(philippines|\d+|.*\bregion\b.*|davao (del norte|del sur|de oro|oriental|occidental))$/i

export function cityOf(address: string | null | undefined): string {
	const text = (address ?? '').trim()
	if (!text) return 'Other areas'
	if (/\bsamal\b/i.test(text)) return 'Samal'

	const parts = text.split(',').map(p => p.trim()).filter(Boolean)
	const named = [...parts].reverse().find(p => /\bcity$/i.test(p))
	if (named) return named
	const province = /davao (del norte|del sur|de oro|oriental|occidental)/i.test(text)
	if (!province && DAVAO_AREAS.test(text)) return 'Davao City'

	const places = parts.filter(p => !NOT_A_PLACE.test(p))
	return parts.length > 1 && places.length ? places[places.length - 1]! : 'Other areas'
}
