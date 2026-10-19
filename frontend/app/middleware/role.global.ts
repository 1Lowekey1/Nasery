// Route guard. The backend enforces every rule again; this only keeps people
// on screens that make sense for them.
//
// Roles (see useAuth): client, organizer (business owner), team_member
// (business staff: their own dashboard, plus the organizer pages their
// role permits, which the API checks), superadmin (NaSeRy admin).

type Role = 'client' | 'organizer' | 'team_member' | 'superadmin'

const areas: { prefix: string, roles: Role[] }[] = [
  { prefix: '/client', roles: ['client'] },
  { prefix: '/organizer', roles: ['organizer', 'team_member'] },
  { prefix: '/team', roles: ['team_member'] },
  { prefix: '/superadmin', roles: ['superadmin'] },
]

const guestOnly = ['/login', '/register', '/forgot-password', '/superadmin/login']
const signedInOnly = ['/verify-email', '/my-tickets', '/notifications']

const within = (path: string, prefix: string) => path === prefix || path.startsWith(`${prefix}/`)

/** Old admin portal paths (older notifications and bookmarks) → the Super Admin portal. */
function superadminPath(path: string): string | null {
  if (!within(path, '/admin')) return null
  const rest = path.slice('/admin'.length)
  const application = rest.match(/^\/organizers\/([^/]+)$/)
  if (application) return `/superadmin/approvals/${application[1]}`
  if (rest === '/organizers') return '/superadmin/approvals'
  if (rest === '/users') return '/superadmin/clients'
  return `/superadmin${rest || '/overview'}`
}

export default defineNuxtRouteMiddleware(async (to) => {
  const moved = superadminPath(to.path)
  if (moved) return navigateTo({ path: moved, query: to.query }, { replace: true })

  const { fetchUser, homePath } = useAuth()
  const user = await fetchUser()

  if (guestOnly.includes(to.path)) {
    return user ? navigateTo(homePath(user), { replace: true }) : undefined
  }

  const area = areas.find(a => within(to.path, a.prefix))
  if (!area && !signedInOnly.includes(to.path)) return

  if (!user) {
    if (area?.prefix === '/superadmin') return navigateTo('/superadmin/login', { replace: true })
    return navigateTo({ path: '/login', query: { redirect: to.fullPath } }, { replace: true })
  }

  // Almost everything needs a verified e-mail (the API refuses otherwise): finish that first.
  if (!user.email_verified) {
    return to.path === '/verify-email' ? undefined : navigateTo('/verify-email', { replace: true })
  }

  if (area && !area.roles.includes(user.role)) {
    return navigateTo(homePath(user), { replace: true })
  }

  // Team members: task notices link to /organizer/my-tasks; their task view is My Assignments.
  if (user.role === 'team_member' && to.path === '/organizer/my-tasks') {
    return navigateTo('/team/dashboard', { replace: true })
  }

  // Organizers set up their workspace first (onboarding).
  if (user.role === 'organizer' && area?.prefix === '/organizer') {
    const organization = await useOrganization().fetchOrganization()
    if (!organization && to.path !== '/organizer/onboarding') return navigateTo('/organizer/onboarding', { replace: true })
    if (organization && to.path === '/organizer/onboarding') return navigateTo('/organizer/home', { replace: true })
  }
})
