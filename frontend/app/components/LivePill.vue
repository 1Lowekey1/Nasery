<template>
	<!-- Live updates: when new items arrive above where someone is reading, say so instead of
	     moving the page; a tap brings them to the top. Hidden when they're already at the top. -->
	<Transition name="live-pill">
		<button v-if="count > 0 && scrolledDown" type="button"
			class="fixed left-1/2 top-24 z-40 -translate-x-1/2 rounded-full bg-[#285F6b] px-4 py-2 text-sm font-bold text-white shadow-lg shadow-[#285F6b]/30 transition hover:bg-[#1f4a54]"
			@click="toTop">
			↑ {{ count }} {{ count === 1 ? one : many }}
		</button>
	</Transition>
</template>

<script setup lang="ts">
const props = defineProps<{ count: number, one: string, many: string }>()

const scrolledDown = ref(false)
function scroller(): HTMLElement | null { return document.querySelector('main') }
function check() { scrolledDown.value = (scroller()?.scrollTop ?? window.scrollY) > 240 }
function toTop() {
	const el = scroller()
	if (el) el.scrollTo({ top: 0, behavior: 'smooth' })
	else window.scrollTo({ top: 0, behavior: 'smooth' })
}

watch(() => props.count, check)
onMounted(() => scroller()?.addEventListener('scroll', check, { passive: true }))
onBeforeUnmount(() => scroller()?.removeEventListener('scroll', check))
</script>

<style scoped>
.live-pill-enter-active, .live-pill-leave-active { transition: opacity .2s ease, transform .2s ease; }
.live-pill-enter-from, .live-pill-leave-to { opacity: 0; transform: translate(-50%, -6px); }
</style>
