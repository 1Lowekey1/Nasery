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

/**
 * Events held over several days, one session per day (members' follow-up, Nov 2026):
 * "Dec 7 – Dec 9, 2026" with "9:00 AM – 5:00 PM daily", or the hours of each day.
 */
export function describeSessions(sessions: { starts_at: string, ends_at: string }[] | null | undefined, tz = 'Asia/Manila') {
	const list = [...(sessions ?? [])].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
	const time = (iso: string) => new Intl.DateTimeFormat('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit' }).format(new Date(iso))
	const hours = (s: { starts_at: string, ends_at: string }) => `${time(s.starts_at)} – ${time(s.ends_at)}${endsSuffix(s.starts_at, s.ends_at, tz) === ' (next day)' ? ' (next day)' : ''}`
	if (list.length < 2) return null
	const first = list[0]!
	const last = list[list.length - 1]!
	const same = list.every(s => hours(s) === hours(first))
	return {
		dateText: dateSpan(first.starts_at, last.starts_at, tz),
		timeText: same ? `${hours(first)} daily` : 'Hours vary by day',
		same,
		days: list.map(s => ({
			label: new Intl.DateTimeFormat('en-PH', { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric' }).format(new Date(s.starts_at)),
			hours: hours(s),
		})),
	}
}

/** The longest event that can have its own hours on each day (one session per day). */
export const MAX_EVENT_DAYS = 31

/** Every date from start to end (YYYY-MM-DD), up to a year; empty when not several days. */
export function daysBetween(start: string, end: string): string[] {
	if (!start || !end || end <= start) return []
	const days: string[] = []
	const d = new Date(`${start}T00:00:00Z`)
	for (let i = 0; i < 366; i++) {
		const date = d.toISOString().slice(0, 10)
		if (date > end) break
		days.push(date)
		d.setUTCDate(d.getUTCDate() + 1)
	}
	return days
}

/** An inquiry's per-day hours, kept as notes lines: "Day 2 · 2027-01-07: 13:00 – 22:00". */
export const DAY_LINE = /^Day \d+ · (\d{4}-\d{2}-\d{2}): (\d{2}:\d{2}) – (\d{2}:\d{2})$/
export function dayLines(days: string[], hours: Record<string, { start: string, end: string }>): string[] {
	return days.map((date, i) => `Day ${i + 1} · ${date}: ${hours[date]?.start || '00:00'} – ${hours[date]?.end || '00:00'}`)
}
