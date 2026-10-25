<template>
	<div />
</template>

<script setup lang="ts">
// Merged into the client's "Event journey" (review #14): keep old links working.
definePageMeta({ layout: 'client', middleware: ['client'] })
const route = useRoute()
await navigateTo({ path: '/client/preparation-tracking', query: route.query }, { replace: true })
</script>
