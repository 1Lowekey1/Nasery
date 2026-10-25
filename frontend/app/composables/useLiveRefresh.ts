// Close-to-live pages (review #12): anything other people change (offers, tasks,
// check-ins, sales, reviews…) refreshes on its own, for every role.
//
// `refresh` reloads the page's data quietly (no skeletons, filters and scroll kept).
// It runs every `interval` while the tab is visible, as soon as the tab comes back,
// and right away when a new notification arrives (the bell polls the count), so the
// bell and the page change together. `paused` skips a round, e.g. while a form is
// open, so nothing typed is overwritten.
export function useLiveRefresh(refresh: () => Promise<unknown> | unknown, options: { interval?: number, paused?: () => boolean } = {}) {
  if (!import.meta.client) return
  const interval = options.interval ?? 60_000
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

  let timer: ReturnType<typeof setInterval> | undefined
  const unread = useUnreadCount()
  const stopWatch = watch(unread, (now, before) => { if (now > before) void run() })

  onMounted(() => {
    timer = setInterval(run, interval)
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('focus', onVisible)
  })
  onBeforeUnmount(() => {
    clearInterval(timer)
    stopWatch()
    document.removeEventListener('visibilitychange', onVisible)
    window.removeEventListener('focus', onVisible)
  })
}
