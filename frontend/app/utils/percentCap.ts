// Advisor's fix (Nov 2026): a service charge is 0–30%. Typing more sets it to 30 and says so.
export const SERVICE_CHARGE_MAX = 30

/** The value to keep (as typed, or the cap) and whether it was capped. */
export function capServiceCharge(value: unknown): { value: string, capped: boolean } {
	const text = String(value ?? '').trim()
	const n = Number(text)
	if (text !== '' && Number.isFinite(n) && n > SERVICE_CHARGE_MAX) return { value: String(SERVICE_CHARGE_MAX), capped: true }
	if (text !== '' && Number.isFinite(n) && n < 0) return { value: '0', capped: false }
	return { value: text, capped: false }
}
