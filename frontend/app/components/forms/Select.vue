<template>
    <Multiselect :close-on-select="true" :searchable="props.searchable" :options="props.options"
        :can-clear="props.canClear" :append-to-body="props.appendToBody" :close-on-scroll="props.closeOnScroll"
        :placeholder="props.placeholder" :disabled="props.disabled" no-options-text="This list is empty"
        noResultsText="No result found" :modelValue="props.modelValue"
        @update:modelValue="$emit('update:modelValue', $event)" />
</template>

<script setup lang="ts">
import Multiselect from '@vueform/multiselect'
import '@vueform/multiselect/themes/default.css'

// Any option value (string id, number, boolean…) or null.
type OptionValue = string | number | boolean | null | undefined | Array<string | number>

const props = withDefaults(defineProps<{
    appendToBody?: boolean
    canClear?: boolean
    disabled?: boolean
    closeOnScroll?: boolean
    modelValue?: OptionValue
    options: unknown[] | Record<string, unknown>
    placeholder?: string
    searchable?: boolean
}>(), {
    appendToBody: false,
    canClear: true,
    disabled: false,
    closeOnScroll: false,
    modelValue: null,
    placeholder: 'Select an option',
    searchable: true,
})

defineEmits(['update:modelValue'])
</script>
