<template>
    <Multiselect class="ns-select" :classes="classes" :close-on-select="true" :searchable="isSearchable"
        :options="props.options" :can-clear="props.canClear" :append-to-body="props.appendToBody"
        :close-on-scroll="props.closeOnScroll" :placeholder="props.placeholder" :disabled="props.disabled"
        no-options-text="Nothing to choose from yet" no-results-text="No matches" :max-height="260"
        :modelValue="props.modelValue" @update:modelValue="$emit('update:modelValue', $event)">
        <template #option="{ option, isSelected }">
            <span class="ns-select-option-label">{{ option.label }}</span>
            <svg v-if="isSelected(option)" class="ns-select-check" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M5 10.5 8.5 14 15 6.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </template>
    </Multiselect>
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
    /** Leave unset to show a search box only for longer lists (more than 8 options). */
    searchable?: boolean | null
}>(), {
    appendToBody: false,
    canClear: true,
    disabled: false,
    closeOnScroll: false,
    modelValue: null,
    placeholder: 'Select an option',
    searchable: null,
})

defineEmits(['update:modelValue'])

const optionCount = computed(() => Array.isArray(props.options) ? props.options.length : Object.keys(props.options ?? {}).length)
const isSearchable = computed(() => props.searchable ?? optionCount.value > 8)

// Extra classes so the menu keeps its styling even when teleported with append-to-body.
const classes = {
    dropdown: 'multiselect-dropdown ns-select-dropdown',
    dropdownTop: 'is-top',
    dropdownHidden: 'is-hidden',
}
</script>

<style>
/* Brand teal for every multiselect. */
.multiselect {
    --ms-option-bg-selected: #285F6b;
    --ms-option-bg-selected-pointed: #1c4f5c;
    --ms-option-bg-pointed: #eef4f5;
    --ms-tag-bg: #285F6b;
    --ms-ring-color: rgba(40, 95, 107, 0.18);
}

/* ---------- FormsSelect: field ---------- */
.multiselect.ns-select {
    --ms-font-size: 0.875rem;
    --ms-line-height: 1.375;
    --ms-py: 0.625rem;
    --ms-px: 0.875rem;
    --ms-radius: 0.75rem;
    --ms-border-color: #e5e7eb;
    --ms-border-color-active: #417f88;
    --ms-ring-width: 4px;
    --ms-ring-color: rgba(65, 127, 136, 0.12);
    --ms-placeholder-color: #9ca3af;
    --ms-caret-color: #9ca3af;
    --ms-clear-color: #9ca3af;
    --ms-clear-color-hover: #374151;
    --ms-bg-disabled: #f9fafb;
    --ms-border-color-disabled: #f3f4f6;

    min-height: 42px;
    color: #111827;
    background: #fff;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.multiselect.ns-select:hover:not(.is-disabled) { border-color: #d1d5db; }
.multiselect.ns-select.is-active { border-color: var(--ms-border-color-active); }
/* Keep the field fully rounded while open: the menu floats below it instead of attaching. */
.multiselect.ns-select.is-open,
.multiselect.ns-select.is-open-top { border-radius: var(--ms-radius); }
.multiselect.ns-select .multiselect-single-label { font-weight: 500; padding-right: 3.5rem; }
.multiselect.ns-select .multiselect-search { border-radius: var(--ms-radius); font-size: var(--ms-font-size); }
.multiselect.ns-select .multiselect-caret { width: 1rem; height: 1rem; margin-right: 0.875rem; transition: transform 0.2s ease; -webkit-mask-image: url("data:image/svg+xml;charset=utf-8,%3Csvg viewBox='0 0 20 20' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m5 7.5 5 5 5-5' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); mask-image: url("data:image/svg+xml;charset=utf-8,%3Csvg viewBox='0 0 20 20' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='m5 7.5 5 5 5-5' stroke='black' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E"); }
.multiselect.ns-select .multiselect-caret.is-open { background-color: #285F6b; }
.multiselect.ns-select .multiselect-clear { padding-right: 0.25rem; }
.multiselect.ns-select.is-disabled { color: #9ca3af; cursor: not-allowed; }

/* ---------- FormsSelect: floating menu ---------- */
.multiselect-dropdown.ns-select-dropdown {
    bottom: -6px;
    padding: 4px;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    background: #fff;
    box-shadow: 0 12px 32px -8px rgba(16, 63, 77, 0.18), 0 2px 6px -2px rgba(16, 63, 77, 0.08);
    max-height: 260px;
    overflow-y: auto;
    /* Above side panels and dialogs (z 110-200): a menu moved to <body> (append-to-body) must not open behind them. */
    z-index: 300;
    animation: ns-select-in 0.12s ease-out;
}
.multiselect-dropdown.ns-select-dropdown.is-top { bottom: auto; top: -6px; transform: translateY(-100%); border-radius: 0.75rem; }
.multiselect-dropdown.ns-select-dropdown.is-hidden { display: none; }
.ns-select-dropdown .multiselect-options { gap: 2px; }
.ns-select-dropdown .multiselect-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.5rem 0.625rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    line-height: 1.25rem;
    color: #374151;
    background: transparent;
}
.ns-select-dropdown .multiselect-option.is-pointed { background: #eef4f5; color: #111827; }
.ns-select-dropdown .multiselect-option.is-selected,
.ns-select-dropdown .multiselect-option.is-selected.is-pointed { background: #eef4f5; color: #1c4f5c; font-weight: 600; }
.ns-select-dropdown .multiselect-option.is-disabled { color: #d1d5db; background: transparent; cursor: not-allowed; }
.ns-select-dropdown .multiselect-no-options,
.ns-select-dropdown .multiselect-no-results { padding: 0.75rem; text-align: center; font-size: 0.875rem; color: #6b7280; }
.ns-select-option-label { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ns-select-check { width: 1rem; height: 1rem; flex-shrink: 0; color: #285F6b; }

@keyframes ns-select-in {
    from { opacity: 0; translate: 0 -4px; }
    to { opacity: 1; translate: 0 0; }
}
@media (prefers-reduced-motion: reduce) {
    .multiselect-dropdown.ns-select-dropdown { animation: none; }
    .multiselect.ns-select .multiselect-caret { transition: none; }
}
</style>
