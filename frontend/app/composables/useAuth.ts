// frontend/app/composables/useAuth.ts
//
// Signed-in user state + every B1 auth call (Laravel /api/v1/auth/*).
// Sessions are Sanctum cookies; nothing is kept in localStorage.

export type AccountType = 'client' | 'business' | 'admin'
export type SocialProvider = 'google' | 'facebook' | 'apple'

export interface AuthUser {
  id: string
  email: string
  first_name: string
  middle_name: string | null
  last_name: string
  full_name: string
  phone: string | null
  account_type: AccountType
  status: 'active' | 'suspended' | 'deactivated'
  email_verified: boolean
  email_verified_at: string | null
  has_password: boolean
  social_providers: SocialProvider[]
  created_at: string
}

export interface RegisterPayload {
  first_name: string
  middle_name?: string | null
  last_name: string
  email: string
  phone?: string | null
  password: string
  password_confirmation: string
  account_type: 'client' | 'business'
}

export interface SocialProviderInfo {
  provider: SocialProvider
  label: string
  enabled: boolean
}

export const useAuth = () => {
  const api = useApi()
  const user = useState<AuthUser | null>('auth.user', () => null)
  const loaded = useState<boolean>('auth.loaded', () => false)

  const isLoggedIn = computed(() => user.value !== null)

  /** Load the current session's user once (or again with force). */
  const fetchUser = async (force = false): Promise<AuthUser | null> => {
    if (loaded.value && !force) return user.value
    try {
      user.value = (await api.get<AuthUser>('/auth/me')).data
    } catch (e) {
      if (!(e instanceof ApiError) || ![401, 403].includes(e.status)) throw e
      user.value = null
    } finally {
      loaded.value = true
    }
    return user.value
  }

  const setUser = (value: AuthUser | null) => {
    // Another person (or nobody) is now signed in: drop per-user state.
    if (value?.id !== user.value?.id) {
      clearNuxtState(['org.current', 'org.loaded'])
    }
    user.value = value
    loaded.value = true
  }

  const login = async (email: string, password: string) => {
    setUser((await api.post<AuthUser>('/auth/login', { email, password })).data)
    return user.value!
  }

  const register = async (payload: RegisterPayload) => {
    setUser((await api.post<AuthUser>('/auth/register', payload)).data)
    return user.value!
  }

  const logout = async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      setUser(null)
    }
  }

  const sendVerificationCode = () => api.post<{ resend_in_seconds: number }>('/auth/email/verification-code')

  const verifyEmail = async (code: string) => {
    setUser((await api.post<AuthUser>('/auth/email/verify', { code })).data)
    return user.value!
  }

  const forgotPassword = (email: string) => api.post('/auth/password/forgot', { email })

  const resetPassword = (payload: { email: string, code: string, password: string, password_confirmation: string }) =>
    api.post('/auth/password/reset', payload)

  const changePassword = (payload: { current_password?: string, password: string, password_confirmation: string }) =>
    api.put('/auth/password', payload)

  const updateProfile = async (payload: Partial<Pick<AuthUser, 'first_name' | 'middle_name' | 'last_name' | 'phone'>>) => {
    setUser((await api.patch<AuthUser>('/auth/me', payload)).data)
    return user.value!
  }

  const socialProviders = async (): Promise<SocialProviderInfo[]> =>
    (await api.get<SocialProviderInfo[]>('/auth/social/providers')).data

  /** Leaves the SPA for the provider's consent screen. */
  const startSocial = async (provider: SocialProvider, mode: 'login' | 'register', accountType?: 'client' | 'business') => {
    const { data } = await api.get<{ redirect_url: string }>(`/auth/social/${provider}/redirect`, {
      query: { mode, ...(mode === 'register' ? { account_type: accountType } : {}) },
    })
    window.location.assign(data.redirect_url)
  }

  const exchangeSocial = async (code: string) => {
    setUser((await api.post<AuthUser>('/auth/social/exchange', { code })).data)
    return user.value!
  }

  /** Landing page for a signed-in user. */
  const homePath = (u: AuthUser | null = user.value): string => {
    if (!u) return '/login'
    if (u.account_type === 'admin') return '/admin/overview'
    if (u.account_type === 'business') return '/organizer/home'
    return '/client/my-events'
  }

  return {
    user: readonly(user),
    loaded: readonly(loaded),
    isLoggedIn,
    fetchUser,
    login,
    register,
    logout,
    sendVerificationCode,
    verifyEmail,
    forgotPassword,
    resetPassword,
    changePassword,
    updateProfile,
    socialProviders,
    startSocial,
    exchangeSocial,
    homePath,
  }
}
