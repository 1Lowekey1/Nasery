// A quotation's VAT line is NaSeRy's (added by the server at its rate), never typed.
export function isVatLine(description: string | null | undefined): boolean {
	return /^\s*VAT\b/i.test(description ?? '')
}
