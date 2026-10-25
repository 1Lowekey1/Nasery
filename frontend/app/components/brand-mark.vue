<template>
	<!-- NaSeRy logo. If the image can't load, the old "N" tile shows instead (the backup). -->
	<span v-if="!failed" class="flex shrink-0 items-center justify-center overflow-hidden"
		:class="tile ? 'rounded-xl bg-white p-1 shadow-sm ring-1 ring-black/5' : ''">
		<img src="/nasery-logo.svg" alt="" class="h-full w-full object-contain" @error="failed = true">
	</span>
	<span v-else class="flex shrink-0 items-center justify-center rounded-xl bg-primary-700 font-bold text-white">N</span>
</template>

<script setup lang="ts">
// tile: white rounded backing, for dark or coloured backgrounds.
defineProps<{ tile?: boolean }>()
const failed = ref(false)
</script>
