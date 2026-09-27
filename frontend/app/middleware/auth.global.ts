// Route guard. The backend enforces every rule again; this only keeps
// people on screens that make sense for them.

const areas: Record<string, 'client' | 'business' | 'admin'> = {
  '/client': 'client',
  '/organizer': 'business',
  '/admin': 'admin',
}

const guestOnly = ['/login', '/register', '/forgot-password']
const signedInOnly = ['/verify-email', '/my-tickets', '/notifications']

export default defineNuxtRouteMiddleware(async (to) => {
  const { fetchUser, homePath } = useAuth()
  const user = await fetchUser()

  if (guestOnly.includes(to.path)) {
    return user ? navigateTo(homePath(user)) : undefined
  }

  const area = Object.entries(areas).find(([prefix]) => to.path === prefix || to.path.startsWith(`${prefix}/`))

  if (area || signedInOnly.includes(to.path)) {
    if (!user) {
      return navigateTo({ path: '/login', query: { redirect: to.fullPath } })
    }
    if (area && user.account_type !== area[1]) {
      return navigateTo(homePath(user))
    }

    // Almost everything needs a verified e-mail (the API refuses otherwise): finish that first.
    if (!user.email_verified && to.path !== '/verify-email') {
      return navigateTo('/verify-email')
    }

    // Organizers need their workspace first (B2 onboarding).
    if (area?.[1] === 'business' && user.email_verified) {
      const organization = await useOrganization().fetchOrganization()
      if (!organization && to.path !== '/organizer/onboarding') {
        return navigateTo('/organizer/onboarding')
      }
      if (organization && to.path === '/organizer/onboarding') {
        return navigateTo('/organizer/home')
      }
    }
  }
})
