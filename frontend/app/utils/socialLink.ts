/**
 * A safe link for an organizer's Facebook or Instagram field, which may hold a
 * full address, "facebook.com/page" or just "@handle". Anything that isn't a
 * plain http(s) address or a handle gives null (never a script link).
 */
export function socialLink(site: 'facebook' | 'instagram', value: string | null | undefined): string | null {
  const v = (value ?? '').trim()
  if (!v) return null
  if (/^https?:\/\/[^\s]+$/i.test(v)) return v
  const handle = v.replace(/^(www\.)?(facebook|instagram|fb)\.com\//i, '').replace(/^@/, '').replace(/\/+$/, '')
  return /^[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)*$/.test(handle) ? `https://www.${site}.com/${handle}` : null
}
