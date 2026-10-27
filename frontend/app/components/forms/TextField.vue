<template>
	<div :class="attrs.class">
		<div class="relative">
			<slot name="icon">
				<IconBase v-if="icon" :name="icon"
					class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
					:class="size === 'lg' ? 'left-3.5 h-[18px] w-[18px]' : ''" />
			</slot>
			<input :id="fieldId" v-bind="inputAttrs" :value="modelValue" :type="type" :placeholder="placeholder"
				:required="required" :class="inputClasses" :aria-invalid="error ? 'true' : undefined" :aria-describedby="error ? `${fieldId}-error` : undefined" @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)" />
		</div>
		<p v-if="error" :id="`${fieldId}-error`" class="mt-1.5 text-xs font-semibold text-rose-600" role="alert">{{ error }}</p>
	</div>
</template>

<script setup lang="ts">
import type { PropType } from 'vue'
defineOptions({ inheritAttrs: false })

const props = defineProps({
	// null = empty (nullable API fields bind straight to the input).
	modelValue: { type: [String, Number] as PropType<string | number | null | undefined>, default: '' },
	required: { type: Boolean, default: false },
	type: { type: String, default: 'text' },
	placeholder: { type: String, default: '' },
	icon: { type: String, default: '' },
	size: { type: String, default: 'md' },
	// Message shown under the field (red border too), e.g. from a form's missing-fields check.
	error: { type: String, default: '' }
})

defineEmits(['update:modelValue'])

const slots = useSlots()
const attrs = useAttrs()
const fieldId = useId()

defineExpose({ fieldId })

const inputAttrs = computed(() => {
	const { class: _omit, ...rest } = attrs
	return rest
})

const hasLeftIcon = computed(() => Boolean(props.icon) || Boolean(slots.icon))

const inputClasses = computed(() => {
	const base = (props.error ? 'border-rose-400 bg-rose-50/40 ' : 'border-gray-200 bg-gray-50 ') + 'w-full rounded-xl border text-sm text-gray-900 placeholder:text-gray-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15'

	if (props.size === 'lg') {
		const left = hasLeftIcon.value ? 'pl-11' : 'pl-4'
		return `${base} py-3.5 ${left} pr-4`
	}

	const left = hasLeftIcon.value ? 'pl-9' : 'pl-3.5'
	return `${base} py-2.5 ${left} pr-3`
})
</script>
