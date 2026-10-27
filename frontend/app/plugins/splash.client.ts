// Fades out the start-up screen (app/spa-loading-template.html) once the first
// page is on screen AND its initial API requests have finished, so visitors see
// the logo instead of half-loaded pages and "Loading…" placeholders.
// Never waits longer than MAX_WAIT_MS after the page is ready, or HARD_LIMIT_MS
// overall (a slow or failed request must not leave the site hidden).

const SETTLE_MS = 150        // no requests for this long = the page's data is in
const MAX_WAIT_MS = 4000     // after the first page is ready
const HARD_LIMIT_MS = 15000  // from start-up, whatever happens
const FADE_MS = 260          // matches the CSS transition in the template

export default defineNuxtPlugin((nuxtApp) => {
  const splash = document.querySelector<HTMLElement>('.nasery-splash')
  if (!splash) return

  let done = false
  const hide = () => {
    if (done) return
    done = true
    splash.classList.add('is-leaving')
    window.setTimeout(() => splash.remove(), FADE_MS)
  }

  window.setTimeout(hide, HARD_LIMIT_MS)

  nuxtApp.hook('app:suspense:resolve', () => {
    const readyAt = Date.now()
    let quietSince = apiPending.value === 0 ? Date.now() : 0
    const tick = () => {
      if (done) return
      const now = Date.now()
      if (apiPending.value > 0) quietSince = 0
      else if (!quietSince) quietSince = now
      if ((quietSince && now - quietSince >= SETTLE_MS) || now - readyAt >= MAX_WAIT_MS) hide()
      else window.setTimeout(tick, 50)
    }
    tick()
  })

  // An error page counts as "ready" too.
  nuxtApp.hook('app:error', () => { window.setTimeout(hide, SETTLE_MS) })
})
