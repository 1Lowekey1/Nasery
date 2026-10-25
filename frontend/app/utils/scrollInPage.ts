// Scrolling that never moves the app frame (review finding #8).
//
// The portal layouts are a fixed-height frame with one scrolling content area. The
// browser's element.scrollIntoView() scrolls *every* ancestor, including the frame and
// the window, which slid the whole app up and left an empty band below. These scroll
// only the element's own scroller: the content area (or the window on public pages),
// or, for tab rows, just that row sideways.

type Block = 'start' | 'center' | 'nearest'

function scrollerOf(el: Element, axis: 'x' | 'y'): HTMLElement | null {
  let node = el.parentElement
  while (node && node !== document.body) {
    const style = getComputedStyle(node)
    const overflow = axis === 'y' ? style.overflowY : style.overflowX
    const scrollable = axis === 'y' ? node.scrollHeight > node.clientHeight : node.scrollWidth > node.clientWidth
    if (/(auto|scroll)/.test(overflow) && scrollable) return node
    node = node.parentElement
  }
  return null
}

/** Bring `el` into view vertically, moving only its scrolling area. */
export function scrollToElement(el: Element | null | undefined, options: { block?: Block, behavior?: ScrollBehavior } = {}) {
  if (!el || !import.meta.client) return
  const block = options.block ?? 'start'
  const behavior = options.behavior ?? (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth')
  const margin = Number.parseFloat(getComputedStyle(el).scrollMarginTop) || 0
  const rect = el.getBoundingClientRect()
  const scroller = scrollerOf(el, 'y')

  const viewTop = scroller ? scroller.getBoundingClientRect().top : 0
  const viewHeight = scroller ? scroller.clientHeight : window.innerHeight
  const current = scroller ? scroller.scrollTop : window.scrollY
  const offset = rect.top - viewTop
  if (block === 'nearest' && offset >= 0 && offset + rect.height <= viewHeight) return

  const top = block === 'center'
    ? current + offset - (viewHeight - rect.height) / 2
    : block === 'nearest' && offset > 0
      ? current + offset + rect.height - viewHeight
      : current + offset - margin
  ;(scroller ?? window).scrollTo({ top: Math.max(0, top), behavior })
}

/** Centre `el` in its horizontally scrolling row (tab bars), leaving the page alone. */
export function scrollRowTo(el: Element | null | undefined, behavior: ScrollBehavior = 'smooth') {
  if (!el || !import.meta.client) return
  const row = scrollerOf(el, 'x')
  if (!row) return
  const rect = el.getBoundingClientRect()
  const rowRect = row.getBoundingClientRect()
  row.scrollTo({ left: row.scrollLeft + rect.left - rowRect.left - (row.clientWidth - rect.width) / 2, behavior })
}
