<template>
	<!-- eslint-disable-next-line vue/no-v-html -- SVG generated locally by the qrcode library from our own token -->
	<div class="qr-code bg-white" role="img" :aria-label="label" v-html="svg" />
</template>

<script setup lang="ts">
import QRCode from 'qrcode'

/** Renders a ticket's QR token as an SVG (generated in the browser). */
const props = withDefaults(defineProps<{ value: string, label?: string, size?: number }>(), { label: 'Ticket QR code', size: 220 })

const svg = ref('')

watchEffect(async () => {
	svg.value = props.value
		? await QRCode.toString(props.value, { type: 'svg', errorCorrectionLevel: 'M', margin: 2, width: props.size })
		: ''
})
</script>

<style scoped>
.qr-code :deep(svg) {
	display: block;
	height: auto;
	max-width: 100%;
}
</style>
