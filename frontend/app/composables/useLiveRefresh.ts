// Close-to-live pages (review #12; live updates option B, mock defense 2026-11).
//
// `refresh` reloads the page's data quietly (no skeletons, filters and scroll kept).
// Signed in, one shared check asks the server every few seconds whether anything
// this person can see has changed (GET /me/changes, a tiny stamp); only then do the
// open pages reload. So changes show within seconds, and nothing reloads for nothing.
// Pages also refresh when the tab comes back, and when a new notification arrives.
// Signed out (public pages), they fall back to a plain timer. `paused` skips a round,
// e.g. while a form is open, so nothing typed is overwritten.
const CHECK_EVERY = 8_000
const subscribers = new Set<() => Promise<void>>()
let checkTimer: ReturnType<typeof setTimeout> | undefined
let lastStamp: string | null = null
let checking = false

async function checkOnce() {
  if (checking || document.hidden || !subscribers.size) return
  checking = true
  try {
    const { stamp } = (await useApi().get<{ stamp: string }>('/me/changes')).data
    if (lastStamp !== null && stamp !== lastStamp) await Promise.all([...subscribers].map(run => run()))
    lastStamp = stamp
  } catch { /* offline or signed out: the fallback timer and focus refresh still work */ } finally {
    checking = false
  }
}

function startChecking() {
  if (checkTimer) return
  const loop = async () => {
    await checkOnce()
    checkTimer = subscribers.size ? setTimeout(loop, CHECK_EVERY) : undefined
  }
  checkTimer = setTimeout(loop, CHECK_EVERY)
}

export function useLiveRefresh(refresh: () => Promise<unknown> | unknown, options: { interval?: number, paused?: () => boolean } = {}) {
  if (!import.meta.client) return
  const { user } = useAuth()
  let running = false
  let last = Date.now()

  const run = async () => {
    if (running || document.hidden || options.paused?.()) return
    running = true
    last = Date.now()
    try { await refresh() } catch { /* a failed background refresh keeps what's on screen */ } finally { running = false }
  }
  // Coming back to the tab: refresh unless we just did.
  const onVisible = () => { if (!document.hidden && Date.now() - last > 5_000) void run() }

  // Signed out: no change check, so a plain timer (public pages). Signed in: a slow
  // safety net only, for anything the change check doesn't cover.
  const fallback = () => (user.value ? Math.max(options.interval ?? 60_000, 120_000) : options.interval ?? 60_000)
  let timer: ReturnType<typeof setInterval> | undefined
  const unread = useUnreadCount()
  const stopWatch = watch(unread, (now, before) => { if (now > before) void run() })

  onMounted(() => {
    subscribers.add(run)
    if (user.value) startChecking()
    timer = setInterval(run, fallback())
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('focus', onVisible)
  })
  onBeforeUnmount(() => {
    subscribers.delete(run)
    clearInterval(timer)
    stopWatch()
    document.removeEventListener('visibilitychange', onVisible)
    window.removeEventListener('focus', onVisible)
  })
}

/**
 * Items that just appeared in a live list, for a short highlight (class `live-fresh`).
 * The first load marks nothing; after that, keys not seen before glow for a moment.
 */
export function useFreshKeys<T>(list: () => readonly T[] | null | undefined, keyOf: (item: T) => string | number) {
  const seen = new Set<string>()
  const fresh = ref(new Set<string>())
  let first = true
  watch(() => (list() ?? []).map(i => String(keyOf(i))), (keys) => {
    if (!keys.length) return
    if (first) {
      keys.forEach(k => seen.add(k))
      first = false
      return
    }
    const added = keys.filter(k => !seen.has(k))
    keys.forEach(k => seen.add(k))
    if (!added.length) return
    fresh.value = new Set([...fresh.value, ...added])
    setTimeout(() => { fresh.value = new Set([...fresh.value].filter(k => !added.includes(k))) }, 4_000)
  }, { immediate: true })
  // A function (is this item fresh?) that also carries how many are fresh, for LivePill.
  return Object.assign((item: T) => fresh.value.has(String(keyOf(item))), { count: computed(() => fresh.value.size) })
}
