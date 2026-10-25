<template>
	<MediaImageUploader :current="user?.avatar_url" :upload="upload" :remove="remove" shape="avatar"
		label="Profile photo" :alt="`${fullName}'s profile photo`">
		<template #placeholder><span class="text-lg font-bold text-gray-500">{{ initials }}</span></template>
	</MediaImageUploader>
</template>

<script setup lang="ts">
// The signed-in person's profile photo (any role). After a change the account is
// reloaded, so every sidebar/header shows the new photo at once.
const { user, fullName, initials, fetchUser } = useAuth()
const media = useMedia()

async function upload(file: File) {
	await media.uploadAvatar(file)
	await fetchUser(true)
}
async function remove() {
	await media.removeAvatar()
	await fetchUser(true)
}
</script>
