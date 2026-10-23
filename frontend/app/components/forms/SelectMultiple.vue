<template>
    <Multiselect mode="tags" :close-on-select="true" :searchable="true" :options="props.options" :limit="props.limit"
        no-options-text="This list is empty" noResultsText="No result found" :max-height="props.maxHeight"
        :appendToBody="props.appendToBody" class="multiselect-limited" :modelValue="modelValue"
        @update:modelValue="emit('update:modelValue', $event)" />
</template>

<script setup lang="ts">
import Multiselect from '@vueform/multiselect'
import '@vueform/multiselect/themes/default.css'

const props = defineProps({
    appendToBody: {
        type: Boolean,
        default: false,
    },
    options: {
        type: Object,
        required: true,
    },
    limit: {
        type: Number,
        default: -1,
    },
    modelValue: {
        type: [Array, String, Number, Object],
        default: () => [],
    },
    maxHeight: {
        type: Number,
        default: 200,
    },
})

const emit = defineEmits(['update:modelValue'])
</script>

<style>
.multiselect-limited {
    --ms-max-height: 200px;
}

.multiselect-limited .multiselect-tags {
    max-height: 30px;
    overflow-y: auto;
}

.multiselect-limited .multiselect-dropdown {
    max-height: var(--ms-max-height) !important;
    overflow-y: auto !important;
}
</style>

<style>
/* Brand teal for the dropdown's selected / hovered options, tags and focus ring. */
.multiselect {
    --ms-option-bg-selected: #285F6b;
    --ms-option-bg-selected-pointed: #1c4f5c;
    --ms-option-bg-pointed: #eef4f5;
    --ms-tag-bg: #285F6b;
    --ms-ring-color: rgba(40, 95, 107, 0.18);
}
</style>
