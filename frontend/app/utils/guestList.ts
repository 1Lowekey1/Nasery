// A private event's invitations run on a free ticket type named "Guest list", kept
// closed so it is never on sale (not even through the private link). The event
// editor manages it behind the scenes; Attendees uses it for invites and imports.
export const GUEST_TIER = 'Guest list'

export function isGuestTier(t: { name?: string | null, price?: string | number | null }): boolean {
  return String(t.name ?? '').trim().toLowerCase() === GUEST_TIER.toLowerCase() && Number(t.price || 0) === 0
}
