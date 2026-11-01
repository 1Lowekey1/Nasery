<script setup lang="ts">
withDefaults(defineProps<{ color: string; label: string; compact?: boolean; image?: string | null }>(), { compact: false, image: null })
</script>

<template>
  <div class="relative isolate overflow-hidden text-white" :class="compact ? 'h-36 sm:h-44' : 'h-36 sm:h-64'" :style="{ backgroundColor: color }">
    <template v-if="image">
      <img :src="image" alt="" class="absolute inset-0 h-full w-full object-cover">
      <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent" />
    </template>
    <svg v-else aria-hidden="true" viewBox="0 0 600 280" preserveAspectRatio="xMidYMid slice" class="absolute inset-0 h-full w-full">
      <circle cx="450" cy="120" r="160" fill="none" stroke="white" stroke-opacity=".12" stroke-width="50" />
      <path d="M300 280V160a100 100 0 0 1 200 0v120" fill="none" stroke="white" stroke-opacity=".3" stroke-width="2" />
      <path d="M328 280V160a72 72 0 0 1 144 0v120" fill="white" fill-opacity=".08" />
      <path d="M0 247Q200 180 380 260T650 240V280H0" fill="black" fill-opacity=".1" />
      <path d="M530 55v30m-15-15h30M250 80v16m-8-8h16" stroke="white" stroke-opacity=".65" stroke-width="2" />
    </svg>
    <div class="absolute bottom-5 left-6 right-6">
      <p v-if="!image" class="mb-2 text-[11px] font-semibold uppercase tracking-[.22em] text-white/75">Spaces for connection</p>
      <p class="max-w-xs font-serif text-3xl leading-tight">{{ label }}</p>
    </div>
  </div>
</template>
