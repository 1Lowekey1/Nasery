<script setup lang="ts">
// Guests land on the public organizer directory; signed-in users on their home.
definePageMeta({
	middleware: [async () => {
		const { fetchUser, homePath } = useAuth()
		const user = await fetchUser()
		return navigateTo(user ? homePath(user) : '/organizers')
	}],
})
</script>

<template>
	<div />
</template>
