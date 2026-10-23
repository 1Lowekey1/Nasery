<template>
	<div class="sa-hero relative mb-5 overflow-hidden rounded-3xl px-5 py-5 text-white sm:mb-6 sm:rounded-[2rem] sm:px-9 sm:py-8">
		<span class="sa-orb" />
		<span class="sa-dots" />

		<div class="relative flex items-center gap-4">
			<div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white/12 ring-1 ring-white/20 backdrop-blur sm:h-12 sm:w-12">
				<IconBase :name="icon" class="h-5 w-5" />
			</div>

			<div class="min-w-0">
				<p class="text-xs font-bold uppercase tracking-[0.18em] text-white/60">Super Admin</p>
				<h1 class="text-xl font-extrabold tracking-tight sm:text-3xl">{{ title }}</h1>
			</div>
		</div>

		<p class="relative mt-4 hidden max-w-2xl text-sm leading-6 text-white/70 sm:block">
			{{ description }}
		</p>
	</div>
</template>

<script setup lang="ts">
withDefaults(defineProps<{
	title?: string
	description?: string
	icon?: string
}>(), {
	title: 'Platform Control Center',
	description: 'Manage organizers, clients, and events across the NaSeRy platform.',
	icon: 'grid',
})
</script>

<style scoped>
.sa-hero {
	background: #285f6b;
}

.sa-orb {
	position: absolute;
	top: -110px;
	right: 6%;
	height: 300px;
	width: 300px;
	border-radius: 9999px;
	background: rgba(255, 255, 255, 0.1);
	filter: blur(60px);
	pointer-events: none;
	animation: sa-drift 16s ease-in-out infinite;
}

.sa-dots {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
	-webkit-mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
}

@keyframes sa-drift {
	0%, 100% { transform: translate(0, 0) scale(1); }
	50% { transform: translate(-22px, 16px) scale(1.08); }
}

@media (prefers-reduced-motion: reduce) {
	.sa-orb { animation: none; }
}
</style>
