<template>
	<slot />
</template>

<script setup lang="ts">
// Gives the uploaders inside it a form's media batch (see useMedia.ts).
const props = defineProps<{ batch: MediaBatch }>()
provide(mediaBatchKey, props.batch)
</script>
