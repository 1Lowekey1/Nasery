// frontend/app/composables/useApi.ts
//
// Single client for the NaSeRy Laravel API (/api/v1).
// Auth is Sanctum SPA: an HttpOnly session cookie plus a CSRF token that
// Laravel hands out as the XSRF-TOKEN cookie and expects back in the
// X-XSRF-TOKEN header on state-changing requests.
//
//   const api = useApi()
//   const { data } = await api.get<Health>('/health')
//   await api.post('/checkout', body, { idempotencyKey: crypto.randomUUID() })
//
// Every response uses the backend envelope; failures throw ApiError with the
// machine-readable `code` (branch on that, never on `message`).

export interface ApiSuccess<T> {
  success: true
  data: T
  message: string | null
  meta?: Record<string, any>
}

export interface ApiErrorBody {
  success: false
  error: { code: string, message: string, details: Record<string, any> }
}

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, any> = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** Laravel validation messages keyed by field, when code === 'VALIDATION_ERROR'. */
  get fields(): Record<string, string[]> {
    return this.details.fields ?? {}
  }
}

interface RequestOptions {
  query?: Record<string, any>
  headers?: Record<string, string>
  signal?: AbortSignal
  /** Send for retry-sensitive POSTs (checkout, payments, acceptance...). Reuse the same key when retrying. */
  idempotencyKey?: string
}

const XSRF_COOKIE = 'XSRF-TOKEN'

function readCookie(name: string): string | null {
  if (import.meta.server) return null
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return match ? decodeURIComponent(match[1]!) : null
}

export const useApi = () => {
  const config = useRuntimeConfig()
  const apiBase = config.public.apiBase as string
  const backendUrl = config.public.backendUrl as string
  // Who the app believes is signed in (owned by useAuth; read here to spot dead sessions).
  const authUser = useState<unknown>('auth.user', () => null)

  const ensureCsrfCookie = async (force = false) => {
    if (!force && readCookie(XSRF_COOKIE)) return
    await $fetch('/sanctum/csrf-cookie', { baseURL: backendUrl, credentials: 'include' })
  }

  const send = async <T>(method: string, url: string, body?: unknown, options: RequestOptions = {}, retried = false): Promise<ApiSuccess<T>> => {
    const mutating = method !== 'GET' && method !== 'HEAD'
    if (mutating) await ensureCsrfCookie()

    const headers: Record<string, string> = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...options.headers,
    }
    const xsrf = readCookie(XSRF_COOKIE)
    if (mutating && xsrf) headers['X-XSRF-TOKEN'] = xsrf
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey

    try {
      return await $fetch<ApiSuccess<T>>(url, {
        baseURL: apiBase,
        method: method as any,
        body: body as any,
        query: options.query,
        headers,
        signal: options.signal,
        credentials: 'include',
      })
    } catch (err: any) {
      if (err?.name === 'AbortError') throw err

      const status: number = err?.response?.status ?? 0
      const payload = err?.data as ApiErrorBody | undefined

      // CSRF token rotated/expired: fetch a fresh one and retry once.
      if (status === 419 && !retried) {
        await ensureCsrfCookie(true)
        return send<T>(method, url, body, options, true)
      }

      // The session ended (signed out elsewhere, suspended, or the account was removed)
      // while the app still showed a signed-in user: start over at the sign-in page
      // instead of leaving a stale sidebar and badges on screen.
      if (status === 401 && authUser.value && import.meta.client) {
        authUser.value = null
        const here = window.location.pathname + window.location.search
        window.location.href = `/login?redirect=${encodeURIComponent(here)}`
      }

      if (payload?.error) {
        throw new ApiError(status, payload.error.code, payload.error.message, payload.error.details ?? {})
      }

      throw new ApiError(status, status === 0 ? 'NETWORK_ERROR' : 'SERVER_ERROR',
        status === 0 ? 'Cannot reach the server. Check your connection.' : 'Something went wrong. Please try again.')
    }
  }

  return {
    ensureCsrfCookie,
    get: <T>(url: string, options?: RequestOptions) => send<T>('GET', url, undefined, options),
    post: <T>(url: string, body?: unknown, options?: RequestOptions) => send<T>('POST', url, body, options),
    put: <T>(url: string, body?: unknown, options?: RequestOptions) => send<T>('PUT', url, body, options),
    patch: <T>(url: string, body?: unknown, options?: RequestOptions) => send<T>('PATCH', url, body, options),
    delete: <T>(url: string, options?: RequestOptions) => send<T>('DELETE', url, undefined, options),
    /** File downloads (CSV exports): same session cookie and SPA headers, raw body. */
    blob: (url: string, options: RequestOptions = {}) => $fetch<Blob>(url, {
      baseURL: apiBase,
      query: options.query,
      headers: { 'X-Requested-With': 'XMLHttpRequest', ...options.headers },
      credentials: 'include',
      responseType: 'blob',
    }),
  }
}
