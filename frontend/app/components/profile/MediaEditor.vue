<template>
  <!-- NaSeRy has no image uploads yet: the business cover photo is a link (https). Personal photos are hidden. -->
  <div v-if="modelValue !== undefined">
    <label class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3">
      <span class="flex h-10 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-primary-700 text-white">
        <img v-if="validUrl" :src="modelValue ?? ''" alt="" class="h-full w-full object-cover" @error="broken = true" @load="broken = false" />
        <IconBase v-else name="camera" class="h-4 w-4" />
      </span>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-bold text-gray-800">Cover photo</span>
        <input :value="modelValue ?? ''" type="url" placeholder="https://… (a wide image link)"
          class="mt-1 w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm"
          @input="emit('update:modelValue', ($event.target as HTMLInputElement).value.trim() || null)" />
      </span>
    </label>
    <p class="mt-2 text-xs text-gray-400">Shown on your public organizer page. Use an https link to a JPG, PNG, or WebP image.</p>
    <p v-if="modelValue && !modelValue.startsWith('https://')" class="mt-2 text-xs font-semibold text-red-600">The link must start with https://</p>
    <p v-else-if="broken" class="mt-2 text-xs font-semibold text-amber-600">That link doesn't load as an image.</p>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{ modelValue?: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>()
const broken = ref(false)
const validUrl = computed(() => Boolean(props.modelValue?.startsWith('https://')) && !broken.value)
watch(() => props.modelValue, () => { broken.value = false })
</script>
