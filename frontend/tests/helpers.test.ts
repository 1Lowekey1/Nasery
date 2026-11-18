import { describe, expect, it } from 'vitest'
import { isGuestTier } from '~/utils/guestList'
import { inquiryRedirect } from '~/utils/inquiryRedirect'
import { routeId } from '~/utils/legacy'
import { socialLink } from '~/utils/socialLink'

describe('inquiryRedirect (only our own page, never an outside link)', () => {
	it('allows the post-event page and nothing else', () => {
		expect(inquiryRedirect('/client/post-event')).toBe('/client/post-event')
		expect(inquiryRedirect('https://evil.example/client/post-event')).toBeUndefined()
		expect(inquiryRedirect('//evil.example')).toBeUndefined()
		expect(inquiryRedirect(['/client/post-event'])).toBeUndefined()
		expect(inquiryRedirect(undefined)).toBeUndefined()
	})

	it('keeps the organizer, guests and picks, and drops anything else', () => {
		const picks = '01a1b48c-107b-7346-9aad-2cbecf878e55,01a1b48c-1086-7278-a260-e27cc860f016:3'
		expect(inquiryRedirect(`/client/post-event?organizer=davao-dream-events&guests=100&options=${encodeURIComponent(picks)}`))
			.toBe(`/client/post-event?organizer=davao-dream-events&guests=100&options=${encodeURIComponent(picks)}`)
		expect(inquiryRedirect('/client/post-event?organizer=davao-dream-events&next=https://evil.example'))
			.toBe('/client/post-event?organizer=davao-dream-events')
		expect(inquiryRedirect('/client/post-event?organizer=%3Cscript%3E')).toBe('/client/post-event')
		expect(inquiryRedirect('/client/post-eventx?organizer=a')).toBeUndefined()
		expect(inquiryRedirect('/client/my-events?organizer=a')).toBeUndefined()
	})
})

describe('routeId (?event= in the address bar)', () => {
	it('takes the first non-empty string', () => {
		expect(routeId('abc')).toBe('abc')
		expect(routeId(['abc', 'def'])).toBe('abc')
		expect(routeId('  ')).toBeNull()
		expect(routeId(undefined)).toBeNull()
		expect(routeId(5)).toBeNull()
	})
})

describe('isGuestTier (private events: invitation list)', () => {
	it('matches only the free "Guest list" tier', () => {
		expect(isGuestTier({ name: 'Guest list', price: '0.00' })).toBe(true)
		expect(isGuestTier({ name: '  guest LIST ', price: 0 })).toBe(true)
		expect(isGuestTier({ name: 'Guest list', price: '0.01' })).toBe(false)
		expect(isGuestTier({ name: 'VIP', price: 0 })).toBe(false)
		expect(isGuestTier({ name: null, price: null })).toBe(false)
	})
})

describe('socialLink (organizer Facebook / Instagram: a safe link or nothing)', () => {
	it.each([
		['facebook', 'https://facebook.com/davaodream', 'https://facebook.com/davaodream'],
		['facebook', 'facebook.com/davaodream', 'https://www.facebook.com/davaodream'],
		['instagram', '@davaodream', 'https://www.instagram.com/davaodream'],
		['instagram', 'www.instagram.com/davao.dream/', 'https://www.instagram.com/davao.dream'],
		['facebook', 'javascript:alert(1)', null],
		['facebook', 'data:text/html,x', null],
		['instagram', '  ', null],
		['instagram', null, null],
	] as const)('%s %j', (site, input, expected) => {
		expect(socialLink(site, input)).toBe(expected)
	})
})
