// A private event's link key (?key=…) has to survive the trip to the payment page and
// back, or "Return to event" lands on "This event isn't open to the public". The
// registration page remembers it per event; the return pages add it back.
const storageKey = (eventId: string) => `nasery:event-key:${eventId}`

export function rememberEventKey(eventId: string, key: string | null) {
  if (!import.meta.client || !eventId || !key) return
  try { sessionStorage.setItem(storageKey(eventId), key) } catch { /* private mode: link still works from the original URL */ }
}

export function recallEventKey(eventId: string): string | null {
  if (!import.meta.client || !eventId) return null
  try { return sessionStorage.getItem(storageKey(eventId)) } catch { return null }
}

/** The event's registration page, with its private key when we know it. */
export function registrationPath(eventId: string): string {
  const key = recallEventKey(eventId)
  return `/event-registration/${eventId}${key ? `?key=${encodeURIComponent(key)}` : ''}`
}
