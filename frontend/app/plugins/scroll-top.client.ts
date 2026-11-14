// Portal layouts scroll inside <main>, not the window, and the layout stays mounted
// between pages, so a new page used to open as far down as the last one was scrolled.
// Opening a different page (not a filter or tab change on the same page) starts at the top.
export default defineNuxtPlugin((nuxtApp) => {
	const route = useRoute()
	let lastPath = route.path

	const toTop = () => {
		for (const el of document.querySelectorAll('main')) el.scrollTop = 0
		if (window.scrollY) window.scrollTo(0, 0)
	}

	nuxtApp.hook('page:finish', () => {
		if (route.path === lastPath) return
		lastPath = route.path
		if (route.hash) return // a link to a spot on the page scrolls there itself
		toTop()
	})
})
