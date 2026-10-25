<script setup lang="ts">
// Public pages (marketplace, organizers, events, My Tickets). Signed-in people
// get an account menu (who they are, their dashboard, notifications, sign out);
// on phones the links fold into a menu so the header stays one row.
const { isAuthenticated, user, fullName, initials, homePath, logout } = useAuth()
const route = useRoute()

const role = computed(() => user.value?.role)
const dashboard = computed(() => homePath(user.value))
const dashboardLabel = computed(() => role.value === 'superadmin' ? 'Admin portal' : role.value === 'team_member' ? 'My assignments' : 'My dashboard')
const canPostEvent = computed(() => !isAuthenticated.value || role.value === 'client')

const accountOpen = ref(false)
const menuOpen = ref(false)
const accountRef = ref<HTMLElement | null>(null)

watch(() => route.fullPath, () => { accountOpen.value = false; menuOpen.value = false })

function onDocClick(e: MouseEvent) {
  if (accountOpen.value && accountRef.value && !accountRef.value.contains(e.target as Node)) accountOpen.value = false
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') { accountOpen.value = false; menuOpen.value = false }
}
onMounted(() => { document.addEventListener('click', onDocClick); window.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { document.removeEventListener('click', onDocClick); window.removeEventListener('keydown', onKey) })

async function signOut() {
  accountOpen.value = false
  menuOpen.value = false
  const wasAdmin = role.value === 'superadmin'
  await logout()
  await navigateTo(wasAdmin ? '/superadmin/login' : '/', { replace: true })
}
</script>

<template>
  <div class="public-site min-h-screen bg-[#f8f9f7] text-gray-900">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>
    <!-- Sticky: sign in / out, the dashboard and the menu stay one tap away while scrolling (user review). -->
    <header class="sticky top-0 z-40 border-b border-gray-200 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/85" style="padding-top: env(safe-area-inset-top, 0px)">
      <nav aria-label="Main navigation" class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-8 sm:py-4">
        <NuxtLink to="/" aria-label="NaSeRy home" class="flex shrink-0 items-center gap-2.5">
          <BrandMark class="h-10 w-10 text-xl" />
          <span class="text-xl font-extrabold tracking-tight">NaSeRy<span class="text-primary-600">.</span></span>
        </NuxtLink>

        <!-- Everything else sits together on the right: desktop links, then the account menu / phone menu. -->
        <div class="flex items-center gap-2 md:gap-7">
          <div class="hidden items-center gap-7 text-sm font-semibold md:flex">
            <NuxtLink to="/marketplace" class="text-primary-700 hover:underline">Event marketplace</NuxtLink>
            <NuxtLink to="/organizers" class="text-primary-700 hover:underline">Browse organizers</NuxtLink>
            <NuxtLink v-if="!isAuthenticated" to="/login" class="hover:text-primary-700">Sign in</NuxtLink>
            <NuxtLink v-if="canPostEvent" to="/client/post-event" class="rounded-lg bg-primary-700 px-4 py-2.5 text-white hover:bg-primary-900">Post an event <span aria-hidden="true">↗</span></NuxtLink>
          </div>

          <NuxtLink v-if="!isAuthenticated" to="/login" class="rounded-lg px-3 py-2 text-sm font-semibold hover:bg-gray-50 md:hidden">Sign in</NuxtLink>

          <!-- Account menu (signed in) -->
          <div v-if="isAuthenticated" ref="accountRef" class="relative">
            <button type="button" class="flex items-center gap-2 rounded-full border border-gray-200 py-1 pl-1 pr-3 text-sm font-semibold hover:bg-gray-50" aria-label="Account menu"
              :aria-expanded="accountOpen" aria-haspopup="menu" @click="accountOpen = !accountOpen">
              <span class="flex h-8 w-8 items-center justify-center overflow-hidden rounded-full bg-primary-700 text-xs font-bold text-white">
                <img v-if="user?.avatar_url" :src="user.avatar_url" alt="" class="h-full w-full object-cover">
                <template v-else>{{ initials }}</template>
              </span>
              <span class="hidden max-w-[10rem] truncate sm:inline">{{ fullName }}</span>
              <IconBase name="chevron-down" class="h-4 w-4 text-gray-400" />
            </button>
            <div v-if="accountOpen" role="menu" class="absolute right-0 top-12 w-64 overflow-hidden rounded-2xl border border-gray-200 bg-white text-sm shadow-2xl">
              <div class="border-b border-gray-100 px-4 py-3">
                <p class="truncate font-bold text-gray-900">{{ fullName }}</p>
                <p class="truncate text-xs text-gray-500">{{ user?.email }}</p>
              </div>
              <NuxtLink :to="dashboard" role="menuitem" class="flex items-center gap-2.5 px-4 py-2.5 font-semibold text-gray-700 hover:bg-gray-50"><IconBase name="home" class="h-4 w-4 text-gray-400" />{{ dashboardLabel }}</NuxtLink>
              <NuxtLink v-if="role === 'client'" to="/my-tickets" role="menuitem" class="flex items-center gap-2.5 px-4 py-2.5 font-semibold text-gray-700 hover:bg-gray-50"><IconBase name="ticket" class="h-4 w-4 text-gray-400" />My tickets</NuxtLink>
              <NuxtLink to="/notifications" role="menuitem" class="flex items-center gap-2.5 px-4 py-2.5 font-semibold text-gray-700 hover:bg-gray-50"><IconBase name="bell" class="h-4 w-4 text-gray-400" />Notifications</NuxtLink>
              <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 border-t border-gray-100 px-4 py-2.5 text-left font-semibold text-rose-700 hover:bg-rose-50" @click="signOut"><IconBase name="log-out" class="h-4 w-4" />Sign out</button>
            </div>
          </div>

          <!-- Phone menu -->
          <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg text-gray-600 hover:bg-gray-100 md:hidden"
            :aria-label="menuOpen ? 'Close menu' : 'Open menu'" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen">
            <IconBase :name="menuOpen ? 'x' : 'menu'" class="h-5 w-5" />
          </button>
        </div>
      </nav>
      <div v-if="menuOpen" class="border-t border-gray-100 px-4 pb-4 pt-2 text-sm font-semibold md:hidden">
        <NuxtLink to="/marketplace" class="block rounded-lg px-3 py-3 text-primary-700 hover:bg-gray-50">Event marketplace</NuxtLink>
        <NuxtLink to="/organizers" class="block rounded-lg px-3 py-3 text-primary-700 hover:bg-gray-50">Browse organizers</NuxtLink>
        <NuxtLink v-if="canPostEvent" to="/client/post-event" class="mt-2 block rounded-lg bg-primary-700 px-3 py-3 text-center text-white">Post an event <span aria-hidden="true">↗</span></NuxtLink>
      </div>
    </header>
    <main id="main-content"><slot /></main>
    <footer class="mt-16 border-t border-gray-200">
      <div class="mx-auto flex max-w-7xl flex-col justify-between gap-3 px-5 py-8 text-sm text-gray-500 sm:flex-row sm:px-8">
        <span class="font-bold text-primary-800">NaSeRy · Bring your event to life.</span>
        <span>Explore freely. Sign in when you’re ready to plan.</span>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.public-site :deep(a:focus-visible), .public-site :deep(button:focus-visible),
.public-site :deep(input:focus-visible), .public-site :deep(select:focus-visible),
.public-site :deep(summary:focus-visible) {
  outline: 3px solid #417f88;
  outline-offset: 4px;
}
</style>
