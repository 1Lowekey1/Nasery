// Portal sidebars (client, organizer, team, super admin).
//
// Desktop (≥ 1024 px): open by default, pushes the page aside, toggled by the
// menu button (as before). Phones and small tablets: closed by default and
// slides OVER the page (with a backdrop), so the page keeps its full width;
// it closes on its own after choosing a page, tapping outside, or Escape.

export function useResponsiveSidebar() {
	const route = useRoute()
	const isMobile = ref(false)
	const sidebarOpen = ref(true)
	let media: MediaQueryList | null = null

	const sync = () => {
		const mobile = media?.matches ?? false
		if (mobile !== isMobile.value) sidebarOpen.value = !mobile
		isMobile.value = mobile
	}
	const onKey = (e: KeyboardEvent) => {
		if (e.key === 'Escape' && isMobile.value) sidebarOpen.value = false
	}

	onMounted(() => {
		media = window.matchMedia('(max-width: 1023px)')
		isMobile.value = media.matches
		sidebarOpen.value = !media.matches
		media.addEventListener('change', sync)
		window.addEventListener('keydown', onKey)
	})
	onBeforeUnmount(() => {
		media?.removeEventListener('change', sync)
		window.removeEventListener('keydown', onKey)
	})

	// Picking a page on a phone closes the menu.
	watch(() => route.fullPath, () => {
		if (isMobile.value) sidebarOpen.value = false
	})

	/** Classes for the <aside>: an overlay drawer on phones, a column on desktop. */
	const asideClass = computed(() => [
		sidebarOpen.value ? 'w-64' : 'w-0 border-r-0',
		isMobile.value ? 'fixed inset-y-0 left-0 z-[90] shadow-2xl' : '',
	])

	return { sidebarOpen, isMobile, asideClass, closeSidebar: () => { sidebarOpen.value = false } }
}
