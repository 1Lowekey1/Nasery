// Portal layouts are a fixed frame with one scrolling content area. If anything still
// scrolls the window (a field focus, a third-party widget), the frame slides up and
// leaves an empty band below (review finding #8): put it straight back.
export function useLockedFrame() {
  if (!import.meta.client) return
  const reset = () => { if (window.scrollY || window.scrollX) window.scrollTo(0, 0) }
  onMounted(() => window.addEventListener('scroll', reset, { passive: true }))
  onBeforeUnmount(() => window.removeEventListener('scroll', reset))
}
