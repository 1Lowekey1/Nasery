// frontend/app/composables/useAuth.ts
//
// Signed-in user state + every auth call (Laravel /api/v1/auth/*).
// Sessions are Sanctum HttpOnly cookies; nothing secret lives in the browser.
//
// Frontend merge (2026-09-29): the redesigned pages were written for a
// token-based API with roles client / organizer / team_member / superadmin
// and `firstname`-style fields. This composable serves both vocabularies from
// the one real session, so those pages keep working unchanged:
//   admin account                       → superadmin
//   business account, organization owner → organizer (also before onboarding)
//   business account, staff member       → team_member
//   client account                      → client
// `token` is only a signed-in marker: the cookie is the credential.

export type AccountType = 'client' | 'business' | 'admin'
export type SocialProvider = 'google' | 'facebook' | 'apple'
export type AuthRole = 'client' | 'organizer' | 'team_member' | 'superadmin'

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

/** AuthUser plus the field names and role the redesigned pages use. */
export interface SessionUser extends AuthUser {
  firstname: string
  middlename: string | null
  lastname: string
  role: AuthRole
  address: string | null
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

/** The redesigned register form's shape. */
export interface LegacyRegisterPayload {
  firstname: string
  middlename?: string | null
  lastname: string
  email: string
  phone?: string | null
  password: string
  password_confirmation?: string
  role: AuthRole
  [extra: string]: unknown
}

export interface SocialProviderInfo {
  provider: SocialProvider
  label: string
  enabled: boolean
}

const roleLabels: Record<AuthRole, string> = {
  client: 'Client',
  organizer: 'Organizer',
  team_member: 'Team Member',
  superadmin: 'Super Admin',
}

export const useAuth = (requiredRole?: AuthRole) => {
  const api = useApi()
  const account = useState<AuthUser | null>('auth.user', () => null)
  const loaded = useState<boolean>('auth.loaded', () => false)
  /** null = no organization yet (or not a business account). */
  const membership = useState<{ is_owner: boolean } | null>('auth.membership', () => null)
  const isLoading = ref(false)
  const errorMessage = ref('')

  const roleOf = (u: AuthUser | null): AuthRole | null => {
    if (!u) return null
    if (u.account_type === 'admin') return 'superadmin'
    if (u.account_type === 'client') return 'client'
    return membership.value && !membership.value.is_owner ? 'team_member' : 'organizer'
  }

  const user = computed<SessionUser | null>(() => account.value && {
    ...account.value,
    firstname: account.value.first_name,
    middlename: account.value.middle_name,
    lastname: account.value.last_name,
    role: roleOf(account.value)!,
    address: null,
  })

  const isLoggedIn = computed(() => account.value !== null)
  const token = computed(() => (account.value ? 'session' : null))
  const role = computed(() => roleOf(account.value))
  const hasRequiredRole = computed(() => !requiredRole || role.value === requiredRole)
  const fullName = computed(() => account.value?.full_name ?? '')
  const firstName = computed(() => account.value?.first_name ?? '')
  const initials = computed(() => account.value
    ? `${account.value.first_name.charAt(0)}${account.value.last_name.charAt(0)}`.toUpperCase()
    : '')
  const roleLabel = computed(() => (role.value ? roleLabels[role.value] : ''))

  /** Business accounts: owner or staff decides organizer vs team_member. */
  const loadMembership = async () => {
    membership.value = null
    if (account.value?.account_type !== 'business' || !account.value.email_verified) return
    try {
      const { data } = await api.get<{ my_membership: { is_owner: boolean } | null }[]>('/organizations/mine')
      membership.value = data[0]?.my_membership ?? null
    } catch {
      membership.value = null
    }
  }

  const setUser = async (value: AuthUser | null) => {
    // Another person (or nobody) is now signed in: drop per-user state.
    if (value?.id !== account.value?.id) {
      clearNuxtState(['org.current', 'org.loaded'])
    }
    account.value = value
    loaded.value = true
    await loadMembership()
    if (import.meta.client) {
      // Other tabs reload when the signed-in person changes.
      localStorage.setItem('auth_session_changed', JSON.stringify({ userId: value?.id ?? null, timestamp: Date.now() }))
    }
  }

  /** Load the current session's user once (or again with force). */
  const fetchUser = async (force = false): Promise<SessionUser | null> => {
    if (loaded.value && !force) return user.value
    try {
      account.value = (await api.get<AuthUser>('/auth/me')).data
      await loadMembership()
    } catch (e) {
      if (!(e instanceof ApiError) || ![401, 403].includes(e.status)) throw e
      account.value = null
      membership.value = null
    } finally {
      loaded.value = true
    }
    return user.value
  }

  /** Runs an auth call with the redesigned pages' isLoading / errorMessage. */
  const tracked = async <T>(call: () => Promise<T>): Promise<T> => {
    isLoading.value = true
    errorMessage.value = ''
    try {
      return await call()
    } catch (e) {
      errorMessage.value = authMessage(e)
      throw e
    } finally {
      isLoading.value = false
    }
  }

  /** login(email, password) or login({ email, password }). */
  const login = (emailOrPayload: string | { email?: string, phone?: string, password: string }, password?: string) =>
    tracked(async () => {
      const body = typeof emailOrPayload === 'string'
        ? { email: emailOrPayload, password: password! }
        : { email: emailOrPayload.email ?? '', password: emailOrPayload.password }
      await setUser((await api.post<AuthUser>('/auth/login', body)).data)
      return user.value!
    })

  const register = (payload: RegisterPayload | LegacyRegisterPayload) => tracked(async () => {
    const body: RegisterPayload = 'first_name' in payload
      ? payload
      : {
          first_name: payload.firstname.trim(),
          middle_name: payload.middlename?.trim() || null,
          last_name: payload.lastname.trim(),
          email: payload.email.trim().toLowerCase(),
          phone: payload.phone?.trim() || null,
          password: payload.password,
          password_confirmation: payload.password_confirmation ?? payload.password,
          account_type: payload.role === 'organizer' ? 'business' : 'client',
        }
    await setUser((await api.post<AuthUser>('/auth/register', body)).data)
    return user.value!
  })

  const logout = async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Already signed out on the server: still clear this browser.
    } finally {
      await setUser(null)
    }
  }

  const sendVerificationCode = () => api.post<{ resend_in_seconds: number }>('/auth/email/verification-code')

  const verifyEmail = async (code: string) => {
    await setUser((await api.post<AuthUser>('/auth/email/verify', { code })).data)
    return user.value!
  }

  const forgotPassword = (email: string) => api.post('/auth/password/forgot', { email })

  const resetPassword = (payload: { email: string, code: string, password: string, password_confirmation: string }) =>
    api.post('/auth/password/reset', payload)

  const changePassword = (payload: { current_password?: string, password: string, password_confirmation: string }) =>
    api.put('/auth/password', payload)

  const updateProfile = async (payload: Partial<Pick<AuthUser, 'first_name' | 'middle_name' | 'last_name' | 'phone'>>) => {
    await setUser((await api.patch<AuthUser>('/auth/me', payload)).data)
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
    await setUser((await api.post<AuthUser>('/auth/social/exchange', { code })).data)
    return user.value!
  }

  /** Landing page for a signed-in user. */
  const homePath = (u: AuthUser | SessionUser | null = account.value): string => {
    if (!u) return '/login'
    if (!u.email_verified) return '/verify-email'
    switch (roleOf(u)) {
      case 'superadmin': return '/superadmin/overview'
      case 'team_member': return '/team/dashboard'
      case 'organizer': return '/organizer/home'
      default: return '/client/my-events'
    }
  }

  const redirectByRole = (u: AuthUser | SessionUser | null = account.value) => navigateTo(homePath(u))

  return {
    user,
    loaded: readonly(loaded),
    token,
    role,
    isLoggedIn,
    isAuthenticated: isLoggedIn,
    hasRequiredRole,
    fullName,
    firstName,
    initials,
    roleLabel,
    isLoading,
    errorMessage,
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
    redirectByRole,
  }
}
