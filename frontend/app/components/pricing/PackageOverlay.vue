<template>
	<!-- One package in full: its pictures, description and what it includes (field test follow-up, Nov 2026). -->
	<Teleport to="body">
		<div class="fixed inset-0 z-[155] flex items-stretch justify-center bg-gray-950/70 sm:items-center sm:p-6" role="dialog" aria-modal="true"
			:aria-label="option.name" tabindex="-1" @click.self="emit('close')" @keydown.esc="emit('close')" @keydown.left="photo(-1)" @keydown.right="photo(1)">
			<div ref="panel" class="flex max-h-[100dvh] w-full flex-col overflow-hidden bg-white shadow-2xl outline-none sm:max-h-[92dvh] sm:rounded-3xl lg:flex-row" tabindex="-1"
				:class="images.length ? 'max-w-6xl lg:h-[min(88dvh,46rem)]' : 'max-w-xl'">
				<!-- Pictures -->
				<div v-if="images.length" class="relative flex shrink-0 flex-col bg-gray-950 lg:w-[60%]">
					<div class="relative flex aspect-[4/3] w-full items-center justify-center lg:aspect-auto lg:flex-1" @touchstart.passive="touchX = $event.touches[0]?.clientX ?? null" @touchend="swipe">
						<div class="h-full max-h-[70dvh] w-full lg:max-h-none"><PricingImageCycle v-model:index="shown" :images="images" fit="contain" :alt="`${option.name}, picture ${shown + 1} of ${images.length}`" /></div>
						<template v-if="images.length > 1">
							<button type="button" class="absolute left-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-gray-800 shadow hover:bg-white" aria-label="Previous picture" @click="photo(-1)"><IconBase name="arrow-left" class="h-5 w-5" /></button>
							<button type="button" class="absolute right-3 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 text-gray-800 shadow hover:bg-white" aria-label="Next picture" @click="photo(1)"><IconBase name="arrow-right" class="h-5 w-5" /></button>
							<span class="absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-gray-900/70 px-2.5 py-0.5 text-xs font-semibold text-white">{{ shown + 1 }} / {{ images.length }}</span>
						</template>
						<button type="button" class="absolute right-3 top-3 flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-gray-800 shadow hover:bg-white lg:hidden" aria-label="Close" @click="emit('close')"><IconBase name="x" class="h-5 w-5" /></button>
					</div>
					<div v-if="images.length > 1" class="flex gap-2 overflow-x-auto p-3">
						<button v-for="(img, i) in images" :key="img.id" type="button" class="h-14 w-20 shrink-0 overflow-hidden rounded-lg ring-2 transition"
							:class="i === shown ? 'ring-white' : 'opacity-60 ring-transparent hover:opacity-100'" :aria-label="`Picture ${i + 1}`" :aria-current="i === shown" @click="shown = i">
							<img :src="img.url ?? ''" alt="" class="h-full w-full object-cover" loading="lazy">
						</button>
					</div>
				</div>

				<!-- Details -->
				<div class="flex min-h-0 flex-1 flex-col">
					<div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6">
						<div class="min-w-0">
							<p class="text-xs font-bold uppercase tracking-wide text-primary-700">{{ groupLabel }}</p>
							<h2 class="mt-1 text-xl font-bold text-gray-900 sm:text-2xl">{{ option.name }}</h2>
							<p class="mt-1 text-lg font-bold text-primary-800">{{ formatPeso(option.price) }}<span class="text-sm font-normal text-gray-500">{{ priceSuffix(option) }}</span></p>
							<p v-if="option.pricing_unit === 'per_guest' && option.min_guests" class="text-xs text-gray-500">Minimum {{ option.min_guests }} guests</p>
							<p v-else-if="picksQuantity(option) && (option.min_quantity || option.max_quantity)" class="text-xs text-gray-500">{{ rangeText }}</p>
						</div>
						<button type="button" class="h-10 w-10 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100" :class="images.length ? 'hidden lg:flex' : 'flex'" aria-label="Close" @click="emit('close')"><IconBase name="x" class="h-5 w-5" /></button>
					</div>
					<div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6">
						<p v-if="option.description" class="whitespace-pre-line text-sm leading-6 text-gray-700">{{ option.description }}</p>
						<div v-if="option.includes?.length" class="mt-4">
							<p class="text-xs font-bold uppercase tracking-wide text-gray-400">What's included</p>
							<ul class="mt-2 space-y-1.5">
								<li v-for="(line, i) in option.includes" :key="i" class="flex items-start gap-2 text-sm text-gray-700">
									<IconBase name="check-circle" class="mt-0.5 h-4 w-4 shrink-0 text-primary-600" />{{ line }}
								</li>
							</ul>
						</div>
						<p v-if="!option.description && !option.includes?.length" class="text-sm text-gray-500">Ask {{ organizerName }} what this package includes when you request a quotation.</p>
						<p class="mt-5 text-xs text-gray-400">Prices are the organizer's own. The final price is confirmed in their quotation.</p>
					</div>
					<div class="space-y-2 border-t border-gray-100 px-5 py-4 sm:px-6">
						<div class="grid gap-2 sm:grid-cols-2">
							<button type="button" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border px-4 text-sm font-bold transition"
								:class="picked ? 'border-primary-700 bg-primary-50 text-primary-800' : selectMode ? 'border-primary-700 bg-primary-700 text-white hover:bg-primary-900' : 'border-gray-200 text-gray-800 hover:bg-gray-50'"
								:aria-pressed="picked" @click="choose">
								<IconBase :name="picked ? 'check' : 'plus'" class="h-4 w-4" />{{ picked ? (selectMode ? 'Selected · tap to remove' : 'In your estimate') : (selectMode ? 'Select this package' : 'Add to my estimate') }}
							</button>
							<button v-if="canRequest && !selectMode" type="button" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary-700 px-4 text-sm font-bold text-white hover:bg-primary-900" @click="emit('request', option.id)">
								Request a quotation
							</button>
						</div>
						<div v-if="siblings.length > 1" class="flex items-center justify-between text-sm font-semibold text-primary-700">
							<button type="button" class="inline-flex items-center gap-1 disabled:opacity-30" :disabled="at === 0" @click="emit('open', siblings[at - 1]!.id)"><IconBase name="arrow-left" class="h-4 w-4" />Previous</button>
							<span class="text-xs font-normal text-gray-500">{{ at + 1 }} of {{ siblings.length }} in {{ groupLabel }}</span>
							<button type="button" class="inline-flex items-center gap-1 disabled:opacity-30" :disabled="at === siblings.length - 1" @click="emit('open', siblings[at + 1]!.id)">Next<IconBase name="arrow-right" class="h-4 w-4" /></button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
import type { PriceOption } from '~/composables/useQuotations'

const props = defineProps<{ option: PriceOption, siblings: PriceOption[], groupLabel: string, picked: boolean, canRequest: boolean, organizerName: string,
	/** Opened from the calculator: "Select this package" picks it and goes back. */
	selectMode?: boolean }>()
const emit = defineEmits<{ close: [], open: [id: string], toggle: [id: string], request: [id: string] }>()

function choose() {
	emit('toggle', props.option.id)
	if (props.selectMode && !props.picked) emit('close')
}

const images = computed(() => (props.option.images ?? []).filter(i => i.url))
const shown = ref(0)
const current = computed(() => images.value[shown.value] ?? null)
const at = computed(() => props.siblings.findIndex(o => o.id === props.option.id))
watch(() => props.option.id, () => { shown.value = 0 })

function photo(step: number) {
	if (images.value.length > 1) shown.value = (shown.value + step + images.value.length) % images.value.length
}
const touchX = ref<number | null>(null)
function swipe(e: TouchEvent) {
	const end = e.changedTouches[0]?.clientX
	if (touchX.value !== null && end !== undefined && Math.abs(end - touchX.value) > 40) photo(end < touchX.value ? 1 : -1)
	touchX.value = null
}
const rangeText = computed(() => {
	const { min, max } = quantityBounds(props.option)
	return max ? `${min}–${max} ${unitName(props.option, 2)}` : `At least ${min} ${unitName(props.option, min)}`
})

const panel = ref<HTMLElement | null>(null)
onMounted(() => panel.value?.focus())
</script>
