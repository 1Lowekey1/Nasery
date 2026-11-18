// Where a signed-out client goes after signing in: only the request form, with
// only its own details (which organizer, guest count, calculator picks), so the
// redirect can't send anyone elsewhere.
const FORM = '/client/post-event'
const KEYS = ['organizer', 'guests', 'options'] as const
const STORE = 'nasery.pendingInquiry'

export function inquiryRedirect(value: unknown): string | undefined {
  if (typeof value !== 'string' || value.length > 4000) return undefined
  if (value === FORM) return FORM
  if (!value.startsWith(`${FORM}?`)) return undefined
  const given = new URLSearchParams(value.slice(FORM.length + 1))
  const kept = new URLSearchParams()
  for (const key of KEYS) {
    const v = given.get(key)
    if (v && /^[\w:,.-]{1,2000}$/.test(v)) kept.set(key, v)
  }
  const query = kept.toString()
  return query ? `${FORM}?${query}` : FORM
}

/**
 * Field test follow-up (Nov 2026): remember the request across sign-up, e-mail
 * verification and Google sign-in (which leave the login page), for this tab only.
 */
export function rememberInquiry(value: unknown): void {
  const next = inquiryRedirect(value)
  if (!next || !import.meta.client) return
  try { sessionStorage.setItem(STORE, next) } catch { /* private mode: the login page's own redirect still works */ }
}

export function takeInquiry(): string | undefined {
  if (!import.meta.client) return undefined
  try {
    const next = inquiryRedirect(sessionStorage.getItem(STORE))
    sessionStorage.removeItem(STORE)
    return next
  } catch {
    return undefined
  }
}
