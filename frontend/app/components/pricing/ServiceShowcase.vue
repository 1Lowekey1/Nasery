<template>
	<!-- Field test follow-up (Nov 2026): clients see each package first; tapping one opens it in full. -->
	<div class="space-y-8">
		<section v-for="group in groups" :key="group.key" :aria-label="group.label">
			<div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
				<h3 class="text-base font-bold text-gray-900">{{ group.label }}</h3>
				<span class="text-xs text-gray-500">{{ group.options.length }} option{{ group.options.length === 1 ? '' : 's' }} · {{ group.single ? 'pick one' : 'add any' }}</span>
			</div>
			<ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
				<li v-for="o in group.options" :key="o.id">
					<button type="button" class="group flex h-full w-full flex-col overflow-hidden rounded-2xl border bg-white text-left transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
						:class="o.id in picks ? 'border-primary-600 ring-1 ring-primary-600' : 'border-gray-200'" :aria-label="`See ${o.name} in full`" @click="emit('open', o.id)"
						@mouseenter="hovered = o.id" @mouseleave="hovered = null" @focus="hovered = o.id" @blur="hovered = null">
						<div v-if="o.images?.length" class="relative aspect-[4/3] w-full overflow-hidden bg-gray-100">
							<PricingImageCycle :images="o.images" :autoplay="hovered === o.id" :alt="o.name" fit="contain" />
							<span v-if="(o.images?.length ?? 0) > 1" class="absolute bottom-2 right-2 inline-flex items-center gap-1 rounded-full bg-gray-900/70 px-2 py-0.5 text-[11px] font-semibold text-white">
								<IconBase name="camera" class="h-3 w-3" />{{ o.images!.length }}
							</span>
							<span v-if="o.id in picks" class="absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-primary-700 px-2 py-0.5 text-[11px] font-bold text-white">
								<IconBase name="check" class="h-3 w-3" />In your estimate
							</span>
						</div>
						<div class="flex flex-1 flex-col p-4">
							<p v-if="!o.images?.length && o.id in picks" class="mb-1.5 inline-flex w-fit items-center gap-1 rounded-full bg-primary-700 px-2 py-0.5 text-[11px] font-bold text-white"><IconBase name="check" class="h-3 w-3" />In your estimate</p>
							<p class="font-bold text-gray-900">{{ o.name }}</p>
							<p class="mt-0.5 text-sm font-semibold text-primary-800">{{ formatPeso(o.price) }}<span class="font-normal text-gray-500">{{ priceSuffix(o) }}</span></p>
							<p v-if="o.description" class="mt-2 line-clamp-2 text-sm text-gray-600">{{ o.description }}</p>
							<ul v-if="o.includes?.length" class="mt-2 space-y-0.5">
								<li v-for="(line, i) in o.includes.slice(0, 3)" :key="i" class="flex items-start gap-1.5 text-xs text-gray-600">
									<IconBase name="check" class="mt-0.5 h-3 w-3 shrink-0 text-primary-600" />{{ line }}
								</li>
								<li v-if="o.includes.length > 3" class="pl-[18px] text-xs text-gray-400">+ {{ o.includes.length - 3 }} more</li>
							</ul>
							<span class="mt-auto inline-flex items-center gap-1 pt-3 text-sm font-semibold text-primary-700">See details <IconBase name="arrow-right" class="h-3.5 w-3.5" /></span>
						</div>
					</button>
				</li>
			</ul>
		</section>
	</div>
</template>

<script setup lang="ts">
import type { PriceOption } from '~/composables/useQuotations'

const props = defineProps<{ options: PriceOption[], services: { id: string, name: string }[], picks: Record<string, number> }>()
const emit = defineEmits<{ open: [id: string] }>()
const groups = computed(() => groupOptions(props.options, props.services))
const hovered = ref<string | null>(null)
</script>
