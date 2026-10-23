<template>
	<div />
</template>

<script setup lang="ts">
// The business profile moved to its redesigned page; keep old links working.
definePageMeta({ layout: 'dashboard' })
await navigateTo('/organizer/business-profile', { replace: true })
</script>
