// Unfinished form drafts, kept in this browser so a refresh, a closed tab or a
// wrong tap doesn't lose what someone typed.
//
//   const draft = useDraft(() => `inquiry:new`, form, { omit: ['password'] })
//   <FormsDraftNotice :draft="draft" />          // "Draft restored · Discard"
//   after a successful submit:  draft.clear()
//
// - Saved shortly after typing stops; restored when the form opens again (and
//   when the key changes, e.g. another inquiry's offer form).
// - Per account (`nasery:draft:<user id>:<key>`), never passwords or codes
//   (`omit`), expire after 14 days, and all of an account's drafts are removed
//   when it signs out (shared computers).
// - A null key turns saving off (e.g. while no inquiry is selected).

const PREFIX = 'nasery:draft:'
const MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000

type Stored = { v: Record<string, unknown>, at: number }

function storage(): Storage | null {
	try {
		return import.meta.client ? window.localStorage : null
	} catch {
		return null
	}
}

/** Remove every saved draft of one account (called on sign-out). */
export function clearDraftsFor(userId: string | null | undefined) {
	const s = storage()
	if (!s) return
	try {
		const prefix = `${PREFIX}${userId ?? 'guest'}:`
		Object.keys(s).filter(k => k.startsWith(prefix)).forEach(k => s.removeItem(k))
	} catch {
		// Storage blocked: nothing was saved either.
	}
}

function isBlank(value: unknown): boolean {
	if (value === null || value === undefined) return true
	if (typeof value === 'string') return value.trim() === ''
	if (typeof value === 'number' || typeof value === 'boolean') return false
	if (Array.isArray(value)) return value.every(isBlank)
	if (typeof value === 'object') return Object.values(value as Record<string, unknown>).every(isBlank)
	return true
}

export interface DraftHandle {
	/** True when the form was filled from a saved draft (shows the notice). */
	restored: Ref<boolean>
	/** When the draft was last saved (for "saved 2 min ago"). */
	savedAt: Ref<number | null>
	/** Forget the saved draft (after submitting). */
	clear: () => void
	/** Forget it and put the form back to how it started. */
	discard: () => void
	/** Load the saved draft for the current key, if any. */
	restore: () => boolean
	/** Save pending typing right now (call before a form is closed and emptied). */
	flush: () => void
}

export function useDraft<T extends Record<string, any>>(
	key: MaybeRefOrGetter<string | null>,
	state: T,
	options: {
		/** Fields never stored (passwords, codes, files). */
		omit?: (keyof T)[]
		/** Treat as empty (don't keep a draft) — default: every field blank. */
		isEmpty?: (value: Partial<T>) => boolean
		/** Restore automatically on open / key change (default true). */
		autoRestore?: boolean
	} = {},
): DraftHandle {
	const { user } = useAuth()
	const restored = ref(false)
	const savedAt = ref<number | null>(null)
	const omit = new Set((options.omit ?? []) as string[])
	let initial: Record<string, unknown> = {}
	let applying = false
	let timer: ReturnType<typeof setTimeout> | null = null
	// The storage key last written/read: the account can change mid-form (registering).
	let lastKey: string | null = null

	const fullKey = () => {
		const k = toValue(key)
		return k ? `${PREFIX}${user.value?.id ?? 'guest'}:${k}` : null
	}
	const snapshot = (): Record<string, unknown> => {
		const out: Record<string, unknown> = {}
		for (const [k, v] of Object.entries(toRaw(state))) {
			// toRaw keeps refs (e.g. reactive({ text: draftRef })); a ref in use is circular.
			if (!omit.has(k)) out[k] = JSON.parse(JSON.stringify(unref(v) ?? null))
		}
		return out
	}
	const empty = (v: Record<string, unknown>) => (options.isEmpty ? options.isEmpty(v as Partial<T>) : isBlank(v))

	function read(): Stored | null {
		const k = fullKey()
		const s = storage()
		if (!k || !s) return null
		try {
			const raw = s.getItem(k)
			if (!raw) return null
			lastKey = k
			const data = JSON.parse(raw) as Stored
			if (!data?.v || Date.now() - data.at > MAX_AGE_MS) {
				s.removeItem(k)
				return null
			}
			return data
		} catch {
			return null
		}
	}

	function save() {
		const k = fullKey()
		const s = storage()
		if (!k || !s) return
		const value = snapshot()
		try {
			if (empty(value) || JSON.stringify(value) === JSON.stringify(initial)) {
				s.removeItem(k)
				savedAt.value = null
				return
			}
			const at = Date.now()
			s.setItem(k, JSON.stringify({ v: value, at } satisfies Stored))
			lastKey = k
			savedAt.value = at
		} catch {
			// Full or blocked storage: drafts are a convenience, never an error.
		}
	}

	function restore(): boolean {
		const data = read()
		if (!data || empty(data.v)) return false
		applying = true
		for (const [k, v] of Object.entries(data.v)) {
			if (!omit.has(k) && k in state) (state as Record<string, unknown>)[k] = v
		}
		nextTick(() => { applying = false })
		restored.value = true
		savedAt.value = data.at
		return true
	}

	function flush() {
		if (!timer || applying) return
		clearTimeout(timer)
		timer = null
		save()
	}

	function clear() {
		const k = fullKey()
		if (timer) clearTimeout(timer)
		try {
			if (k) storage()?.removeItem(k)
			if (lastKey && lastKey !== k) storage()?.removeItem(lastKey)
		} catch {
			// ignore
		}
		restored.value = false
		savedAt.value = null
	}

	function discard() {
		clear()
		applying = true
		Object.assign(state, JSON.parse(JSON.stringify(initial)))
		nextTick(() => { applying = false })
	}

	if (import.meta.client) {
		onMounted(() => {
			initial = snapshot()
			if (options.autoRestore !== false) restore()
		})
		// Another key (e.g. a different inquiry's offer): its own draft.
		watch(() => toValue(key), () => {
			initial = snapshot()
			restored.value = false
			savedAt.value = null
			if (options.autoRestore !== false) restore()
		})
		// Read through the reactive form (not toRaw) so typing is noticed.
		watch(() => JSON.stringify(state), () => {
			if (applying) return
			if (timer) clearTimeout(timer)
			timer = setTimeout(() => { timer = null; save() }, 600)
		}, { deep: true })
		onBeforeUnmount(() => {
			if (timer) {
				clearTimeout(timer)
				if (!applying) save()
			}
		})
	}

	// Closing the tab or reloading: save what was typed in the last moment too.
	if (import.meta.client) {
		const onHide = () => flush()
		onMounted(() => window.addEventListener('pagehide', onHide))
		onBeforeUnmount(() => window.removeEventListener('pagehide', onHide))
	}

	return { restored, savedAt, clear, discard, restore, flush }
}
