<template>
	<div class="flex shrink-0 items-center justify-center overflow-hidden rounded-full font-bold"
		:class="[sizeClass, soft ? 'bg-primary-100 text-primary-700' : 'bg-primary-700 text-white']">
		<img v-if="src" :src="src" alt="" class="h-full w-full object-cover">
		<template v-else>
		{{ initials }}</template>
	</div>
</template>

<script setup lang="ts">
const props = withDefaults(defineProps<{
	name: string
	size?: 'sm' | 'md' | 'lg'
	soft?: boolean
	/** Uploaded profile photo, when there is one. */
	src?: string | null
}>(), {
	size: 'md',
	soft: false,
})

const sizeMap = {
	sm: 'h-8 w-8 text-xs',
	md: 'h-9 w-9 text-xs',
	lg: 'h-12 w-12 text-base',
}

const sizeClass = computed(() => sizeMap[props.size])

const initials = computed(() =>
	props.name.trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?',
)
</script>
