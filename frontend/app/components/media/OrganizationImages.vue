<template>
	<div class="space-y-5">
		<MediaImageUploader :current="logo" shape="logo" label="Business logo" alt="Business logo"
			:upload="file => save('logo', file)" :remove="() => drop('logo')" />
		<MediaImageUploader :current="cover" shape="cover" label="Cover photo" alt="Cover photo"
			:upload="file => save('cover', file)" :remove="coverUploaded ? () => drop('cover') : undefined" />
		<p v-if="cover && !coverUploaded" class="text-xs text-gray-400">Your cover is currently a pasted link. Upload a picture to keep it on NaSeRy.</p>
	</div>
</template>

<script setup lang="ts">
// The organizer's logo and cover photo (owner only; the server checks too).
const props = defineProps<{ organizationId: string, logoUrl?: string | null, coverUrl?: string | null, coverUploaded?: boolean }>()
const emit = defineEmits<{ changed: [slot: 'logo' | 'cover', url: string | null] }>()
const media = useMedia()

const logo = ref(props.logoUrl ?? null)
const cover = ref(props.coverUrl ?? null)
const coverUploaded = ref(Boolean(props.coverUploaded))
watch(() => [props.logoUrl, props.coverUrl, props.coverUploaded], () => {
	logo.value = props.logoUrl ?? null
	cover.value = props.coverUrl ?? null
	coverUploaded.value = Boolean(props.coverUploaded)
})

async function save(slot: 'logo' | 'cover', file: File) {
	const url = await media.uploadOrgImage(props.organizationId, slot, file)
	if (slot === 'logo') logo.value = url
	else { cover.value = url; coverUploaded.value = true }
	emit('changed', slot, url)
	return url
}
async function drop(slot: 'logo' | 'cover') {
	await media.removeOrgImage(props.organizationId, slot)
	if (slot === 'logo') logo.value = null
	else { cover.value = null; coverUploaded.value = false }
	emit('changed', slot, null)
}
</script>
