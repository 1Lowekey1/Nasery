import { describe, expect, it } from 'vitest'
import { cityOf } from '../app/utils/cityOf'

describe('cityOf', () => {
	it.each([
		['Lanang, Davao City', 'Davao City'],
		['The View, Magtuod-Tugbok Road, Upper Dampa, Magtuod, Davao City, Davao Region, 8000, Philippines', 'Davao City'],
		['SMX Convention Center, Lanang', 'Davao City'],
		['SM Lanang Premier Event Center', 'Davao City'],
		['Marco Polo Davao', 'Davao City'],
		['Tagum City, Davao del Norte', 'Tagum City'],
		['Island Garden City of Samal', 'Samal'],
		['Kaputian Beach, Samal', 'Samal'],
		['Town Plaza, Panabo, Davao del Norte, Philippines', 'Panabo'],
		['Grand Hall', 'Other areas'],
		['', 'Other areas'],
		[null, 'Other areas'],
	])('%s → %s', (address, city) => {
		expect(cityOf(address)).toBe(city)
	})
})
