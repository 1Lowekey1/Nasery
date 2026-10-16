// One way for every form to say what's missing.
//
//   const check = useFormCheck(() => ({
//     email: !form.email.trim() ? 'Enter your e-mail.' : '',
//     phone: form.phone && !/^\+?\d[\d\s]{9,}$/.test(form.phone) ? 'Enter a valid mobile number.' : '',
//   }))
//   <FormsTextField ... :error="check.err('email')" data-field="email" />
//   async function submit() { if (!check.ok()) return; try { ... } catch (e) { check.fromServer(e) } }
//
// Messages appear only after the first submit attempt (no red before the user tried),
// then update live as fields are fixed. The first problem is scrolled into view and
// focused. Server validation errors (422) are shown on the matching fields too.

export function useFormCheck(problems: () => Record<string, string>) {
  const attempted = ref(false)
  const server = ref<Record<string, string>>({})

  const current = computed(() => problems())
  const missing = computed(() => Object.entries(current.value).filter(([, msg]) => msg).map(([key]) => key))

  /** Message for a field: the live client check first, then the server's (cleared on the next submit). */
  const err = (key: string): string => {
    if (!attempted.value) return server.value[key] ?? ''
    return current.value[key] || server.value[key] || ''
  }

  /** A one-line summary for the top/bottom of the form. */
  const summary = computed(() => (attempted.value && missing.value.length
    ? `Please complete ${missing.value.length === 1 ? 'the highlighted field' : `the ${missing.value.length} highlighted fields`}.`
    : ''))

  function focusFirst(keys: string[]) {
    if (!import.meta.client || !keys.length) return
    nextTick(() => {
      for (const key of keys) {
        const el = document.querySelector<HTMLElement>(`[data-field="${key}"]`)
        if (!el) continue
        el.scrollIntoView({ behavior: 'smooth', block: 'center' })
        const input = el.matches('input, textarea, select, button') ? el : el.querySelector<HTMLElement>('input, textarea, select, button')
        input?.focus({ preventScroll: true })
        return
      }
    })
  }

  /** Run before submitting. Returns true when nothing is missing. */
  function ok(): boolean {
    attempted.value = true
    server.value = {}
    focusFirst(missing.value)
    return missing.value.length === 0
  }

  /** Show a 422's field messages on the form. Returns true if any field matched. */
  function fromServer(error: unknown, rename: Record<string, string> = {}): boolean {
    const fields = fieldErrors(error)
    const mapped: Record<string, string> = {}
    for (const [field, msg] of Object.entries(fields)) {
      const key = rename[field] ?? rename[field.split('.')[0]!] ?? field
      mapped[key] ??= msg
    }
    server.value = mapped
    focusFirst(Object.keys(mapped))
    return Object.keys(mapped).length > 0
  }

  function reset() {
    attempted.value = false
    server.value = {}
  }

  return { err, ok, fromServer, reset, summary, missing, attempted }
}
