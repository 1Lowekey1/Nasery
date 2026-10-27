<template>
	<!-- Terms / Privacy over the sign-up form, so reading them never loses what was typed. -->
	<Teleport to="body">
		<Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0"
			leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
			<div v-if="doc" class="fixed inset-0 z-[120] flex items-end justify-center bg-slate-950/55 backdrop-blur-sm sm:items-center sm:p-4"
				@click.self="close">
				<div class="flex max-h-[92dvh] w-full max-w-2xl flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:max-h-[85dvh] sm:rounded-3xl"
					role="dialog" aria-modal="true" :aria-label="doc === 'terms' ? 'Terms of Service' : 'Privacy Policy'">
					<header class="flex items-center gap-3 border-b border-gray-100 px-4 py-3 sm:px-6">
						<div class="flex min-w-0 flex-1 gap-1 rounded-xl bg-gray-100 p-1" role="tablist">
							<button v-for="t in TABS" :key="t.id" type="button" role="tab" :aria-selected="doc === t.id"
								class="flex-1 truncate rounded-lg px-3 py-2 text-sm font-bold transition"
								:class="doc === t.id ? 'bg-white text-primary-800 shadow-sm' : 'text-gray-500 hover:text-gray-800'"
								@click="show(t.id)">{{ t.label }}</button>
						</div>
						<button ref="closeButton" type="button" aria-label="Close"
							class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 hover:text-gray-800"
							@click="close">
							<IconBase name="x" class="h-4 w-4" />
						</button>
					</header>
					<div ref="scroller" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5 sm:px-8">
						<LegalDocument :doc="doc" @open="show" />
					</div>
					<footer class="border-t border-gray-100 px-4 py-3 sm:px-6">
						<button type="button" class="h-11 w-full rounded-xl bg-primary-700 text-sm font-bold text-white transition hover:bg-primary-900" @click="close">
							Back to sign-up
						</button>
					</footer>
				</div>
			</div>
		</Transition>
	</Teleport>
</template>

<script setup lang="ts">
type Doc = 'terms' | 'privacy'
const doc = defineModel<Doc | null>({ default: null })

const TABS: { id: Doc, label: string }[] = [
	{ id: 'terms', label: 'Terms of Service' },
	{ id: 'privacy', label: 'Privacy Policy' },
]
const scroller = ref<HTMLElement | null>(null)
const closeButton = ref<HTMLButtonElement | null>(null)

function show(next: Doc) {
	doc.value = next
	scroller.value?.scrollTo({ top: 0 })
}
function close() { doc.value = null }
function onKey(e: KeyboardEvent) { if (e.key === 'Escape') close() }

// Keyboard users land on the dialog, and the page behind doesn't scroll.
watch(doc, (open, wasOpen) => {
	if (open && !wasOpen) {
		document.addEventListener('keydown', onKey)
		document.documentElement.style.overflow = 'hidden'
		nextTick(() => closeButton.value?.focus())
	} else if (!open) {
		document.removeEventListener('keydown', onKey)
		document.documentElement.style.overflow = ''
	}
})
onBeforeUnmount(() => {
	document.removeEventListener('keydown', onKey)
	document.documentElement.style.overflow = ''
})
</script>
