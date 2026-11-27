// Advisor's fix (Nov 2026): events that end after midnight or run several days.
// Times are stored as full start/end date-times; these helpers only describe them.

const day = (iso: string, tz: string) => new Intl.DateTimeFormat('en-CA', { timeZone: tz }).format(new Date(iso))

/** Whole days between the start's and the end's calendar dates (0 = same day). */
export function dayGap(startIso: string | null | undefined, endIso: string | null | undefined, tz = 'Asia/Manila'): number {
	if (!startIso || !endIso) return 0
	const a = Date.parse(`${day(startIso, tz)}T00:00:00Z`)
	const b = Date.parse(`${day(endIso, tz)}T00:00:00Z`)
	return Number.isFinite(a) && Number.isFinite(b) ? Math.max(0, Math.round((b - a) / 86_400_000)) : 0
}

/** After an end time: "" (same day), " (next day)", or " (until Nov 29)". */
export function endsSuffix(startIso: string | null | undefined, endIso: string | null | undefined, tz = 'Asia/Manila'): string {
	const gap = dayGap(startIso, endIso, tz)
	if (gap === 0 || !endIso) return ''
	if (gap === 1) return ' (next day)'
	return ` (until ${new Intl.DateTimeFormat('en-PH', { timeZone: tz, month: 'short', day: 'numeric' }).format(new Date(endIso))})`
}

/** "Nov 27, 2026", or "Nov 27 – Nov 29, 2026" for an event over several days. */
export function dateSpan(startIso: string | null | undefined, endIso: string | null | undefined, tz = 'Asia/Manila'): string {
	if (!startIso) return ''
	const f = (iso: string, year: boolean) => new Intl.DateTimeFormat('en-PH', { timeZone: tz, month: 'short', day: 'numeric', ...(year ? { year: 'numeric' } : {}) }).format(new Date(iso))
	return dayGap(startIso, endIso, tz) > 0 && endIso ? `${f(startIso, false)} – ${f(endIso, true)}` : f(startIso, true)
}

/** The end of a schedule typed as a date + times: an end time at or before the start means the next day. */
export function scheduleEnd(startDate: string, startTime: string, endDate: string, endTime: string): { date: string, overnight: boolean } {
	const end = endDate && endDate >= startDate ? endDate : startDate
	if (end === startDate && endTime && startTime && endTime <= startTime) {
		const d = new Date(`${startDate}T00:00:00Z`)
		d.setUTCDate(d.getUTCDate() + 1)
		return { date: d.toISOString().slice(0, 10), overnight: true }
	}
	return { date: end, overnight: false }
}
