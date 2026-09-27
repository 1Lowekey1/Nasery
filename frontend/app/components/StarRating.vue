<template>
	<!-- Display: read-only stars with an accessible label. Input: a radio group (arrow keys work). -->
	<span v-if="readonly" class="inline-flex text-amber-500" :aria-label="`${modelValue} out of 5 stars`" role="img">
		<span v-for="n in 5" :key="n" aria-hidden="true" :class="n <= modelValue ? '' : 'text-gray-300'">★</span>
	</span>
	<fieldset v-else class="inline-flex gap-1 border-0 p-0">
		<legend class="sr-only">{{ label }}</legend>
		<label v-for="n in 5" :key="n" class="cursor-pointer text-3xl leading-none transition-colors"
			:class="n <= (hover || modelValue) ? 'text-amber-500' : 'text-gray-300 hover:text-amber-300'"
			@mouseenter="hover = n" @mouseleave="hover = 0">
			<input type="radio" class="sr-only" :name="name" :value="n" :checked="modelValue === n" @change="emit('update:modelValue', n)">
			<span aria-hidden="true">★</span><span class="sr-only">{{ n }} star{{ n === 1 ? '' : 's' }}</span>
		</label>
		<span class="ml-2 self-center text-sm text-gray-600">{{ words[hover || modelValue] }}</span>
	</fieldset>
</template>

<script setup lang="ts">
withDefaults(defineProps<{ modelValue: number, readonly?: boolean, label?: string, name?: string }>(), { readonly: false, label: 'Rating', name: 'rating' })
const emit = defineEmits<{ 'update:modelValue': [value: number] }>()
const hover = ref(0)
const words = ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent']
</script>
