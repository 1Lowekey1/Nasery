<template>
	<Teleport to="body">
		<div v-if="threadId" class="fixed inset-0 z-50 flex justify-end bg-gray-900/30" @click.self="emit('close')">
			<aside class="flex h-full w-full max-w-md flex-col bg-white p-5 shadow-xl" role="dialog" aria-modal="true" :aria-label="title">
				<div class="mb-3 flex items-start justify-between gap-3">
					<div>
						<p class="text-xs font-bold uppercase tracking-wide text-gray-400">Messages</p>
						<h2 class="text-base font-bold text-gray-900">{{ title }}</h2>
					</div>
					<button type="button" class="tap-target text-gray-400 hover:text-gray-700" aria-label="Close" @click="emit('close')">
						<IconBase name="x" class="h-5 w-5" />
					</button>
				</div>
				<InquiryThread :thread-id="threadId" class="min-h-0 flex-1" @read="emit('read', $event)" />
			</aside>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
// Organizer side: a slide-over with the client thread of one inquiry.
defineProps<{ threadId: string | null, title: string }>()
const emit = defineEmits<{ close: [], read: [threadId: string] }>()
</script>
