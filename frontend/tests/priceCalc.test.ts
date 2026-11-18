import { describe, expect, it } from 'vitest'
import type { PriceOption } from '~/composables/useQuotations'
import { buildEstimate, clampGuests, decodePicks, encodePicks, groupOptions, MAX_GUESTS, optionQuantity, priceSuffix, quantityBounds, unitName } from '~/utils/priceCalc'

function option(over: Partial<PriceOption>): PriceOption {
	return { id: 'o1', organization_service_id: null, name: 'Option', description: null, pricing_unit: 'flat', price: '0', min_guests: null, ...over }
}

describe('groupOptions (packages by service, add-ons last)', () => {
	it('groups by service in sort order, with options without a service as add-ons', () => {
		const groups = groupOptions([
			option({ id: 'video', sort_order: 1 }),
			option({ id: 'full', organization_service_id: 's1', sort_order: 2 }),
			option({ id: 'day', organization_service_id: 's1', sort_order: 1 }),
		], [{ id: 's1', name: 'Event Coordination' }])
		expect(groups.map(g => [g.label, g.single, g.options.map(o => o.id)])).toEqual([
			['Event Coordination', true, ['day', 'full']],
			['Add-ons', false, ['video']],
		])
	})
})

describe('clampGuests (calculator guest box, 1 to 100,000)', () => {
	it.each([
		[0, 1], [1, 1], [2, 2], // lower edge
		[99999, 99999], [100000, 100000], [100001, MAX_GUESTS], // upper edge
		[999999999, MAX_GUESTS], [-5, 1], [12.9, 12],
		['', 1], ['abc', 1], ['250', 250],
	])('%s gives %s', (raw, expected) => {
		expect(clampGuests(raw)).toBe(expected)
	})
})

describe('quantityBounds / optionQuantity', () => {
	it('treats a missing or zero minimum as 1 and never lets max fall below min', () => {
		expect(quantityBounds({ min_quantity: null, max_quantity: null })).toEqual({ min: 1, max: null })
		expect(quantityBounds({ min_quantity: 0, max_quantity: 5 })).toEqual({ min: 1, max: 5 })
		expect(quantityBounds({ min_quantity: 4, max_quantity: 2 })).toEqual({ min: 4, max: 4 })
	})

	it('keeps a picked quantity inside the organizer limits', () => {
		const hours = option({ pricing_unit: 'per_hour', min_quantity: 2, max_quantity: 6 })
		expect(optionQuantity(hours, null, 1)).toBe(2)
		expect(optionQuantity(hours, null, 2)).toBe(2)
		expect(optionQuantity(hours, null, 6)).toBe(6)
		expect(optionQuantity(hours, null, 7)).toBe(6)
		expect(optionQuantity(hours, null, null)).toBe(2)
	})

	it('charges per-guest options for at least the minimum guests', () => {
		const meal = option({ pricing_unit: 'per_guest', min_guests: 50 })
		expect(optionQuantity(meal, 49)).toBe(50)
		expect(optionQuantity(meal, 50)).toBe(50)
		expect(optionQuantity(meal, 51)).toBe(51)
	})

	it('charges a fixed price once', () => {
		expect(optionQuantity(option({ pricing_unit: 'flat' }), 300, 9)).toBe(1)
	})
})

describe('unit labels', () => {
	it('pluralises by count and keeps labels that already end in s', () => {
		expect(unitName(option({ pricing_unit: 'per_unit', unit_label: 'table' }), 1)).toBe('table')
		expect(unitName(option({ pricing_unit: 'per_unit', unit_label: 'table' }), 3)).toBe('tables')
		expect(unitName(option({ pricing_unit: 'per_unit', unit_label: 'chairs' }), 3)).toBe('chairs')
		expect(unitName(option({ pricing_unit: 'per_unit', unit_label: '  ' }), 2)).toBe('items')
	})

	it('adds no suffix to a fixed price', () => {
		expect(priceSuffix(option({ pricing_unit: 'flat' }))).toBe('')
		expect(priceSuffix(option({ pricing_unit: 'per_guest' }))).toBe(' / guest')
	})
})

describe('buildEstimate', () => {
	const options = [
		option({ id: 'venue', pricing_unit: 'flat', price: '25000', name: 'Venue' }),
		option({ id: 'meal', pricing_unit: 'per_guest', price: '450.50', min_guests: 50, name: 'Buffet' }),
		option({ id: 'host', pricing_unit: 'per_hour', price: '1500', min_quantity: 2, max_quantity: 5, name: 'Host' }),
	]

	it('adds only the chosen options', () => {
		const estimate = buildEstimate(options, { venue: 1 }, 100)
		expect(estimate.lines.map(l => l.id)).toEqual(['venue'])
		expect(estimate.total).toBe(25000)
	})

	it('works in centavos, then adds service charge and VAT on top', () => {
		// 25,000 + 100 x 450.50 + 3 x 1,500 = 74,550
		const estimate = buildEstimate(options, { venue: 1, meal: 1, host: 3 }, 100, { service_charge_percent: 10, tax_percent: 12 })
		expect(estimate.subtotal).toBe(74550)
		expect(estimate.serviceCharge).toBe(7455)
		expect(estimate.tax).toBe(9840.6) // 12% of 82,005
		expect(estimate.total).toBe(91845.6)
	})

	it('has no floating-point drift on centavo prices', () => {
		const estimate = buildEstimate([option({ id: 'x', pricing_unit: 'per_guest', price: '0.10' })], { x: 1 }, 3)
		expect(estimate.total).toBe(0.3)
	})

	it('is empty and zero when nothing is chosen', () => {
		expect(buildEstimate(options, {}, 100)).toMatchObject({ lines: [], total: 0 })
	})
})

describe('encodePicks / decodePicks (links to the inquiry form)', () => {
	const options = [
		option({ id: 'a', pricing_unit: 'flat' }),
		option({ id: 'b', pricing_unit: 'per_day' }),
	]

	it('round-trips, with quantities only where the client picks one', () => {
		const encoded = encodePicks(options, { a: 1, b: 3 })
		expect(encoded).toBe('a,b:3')
		expect(decodePicks(encoded)).toEqual({ a: 1, b: 3 })
	})

	it('ignores junk', () => {
		expect(decodePicks(undefined)).toEqual({})
		expect(decodePicks('')).toEqual({})
		expect(decodePicks('a:x,,b')).toEqual({ a: 1, b: 1 })
	})
})
