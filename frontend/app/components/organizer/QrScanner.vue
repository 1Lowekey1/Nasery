<template>
	<div>
		<div class="relative mx-auto aspect-square w-full max-w-sm overflow-hidden rounded-2xl bg-gray-900">
			<video ref="video" class="h-full w-full object-cover" muted playsinline :class="{ invisible: !active }" />
			<div v-if="active" class="pointer-events-none absolute inset-8 rounded-2xl border-4 border-white/70" />
			<div v-if="!active" class="absolute inset-0 flex flex-col items-center justify-center p-6 text-center text-gray-300">
				<IconBase name="qr-code" class="h-14 w-14" />
				<p class="mt-3 text-sm">{{ starting ? 'Starting the camera…' : 'Camera is off' }}</p>
			</div>
		</div>
		<p v-if="error" class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ error }}</p>
		<div class="mt-3 flex justify-center">
			<button v-if="!active" type="button" :disabled="starting || disabled"
				class="flex items-center gap-2 rounded-xl bg-primary-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-900 disabled:opacity-50" @click="start">
				<IconBase name="qr-code" class="h-4 w-4" /> Start scanning
			</button>
			<button v-else type="button" class="rounded-xl border border-gray-200 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="stop">
				Stop camera
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import type { IScannerControls } from '@zxing/browser'

// Camera QR reader (ported from the old prototype's qr-checkin page).
// Emits each distinct code once; the same code is ignored for `repeatMs`
// so a QR held in front of the lens isn't submitted many times.
const props = withDefaults(defineProps<{ disabled?: boolean, repeatMs?: number }>(), { disabled: false, repeatMs: 3000 })
const emit = defineEmits<{ decode: [text: string] }>()

const video = ref<HTMLVideoElement | null>(null)
const active = ref(false)
const starting = ref(false)
const error = ref('')
let controls: IScannerControls | null = null
let last = { text: '', at: 0 }

async function start() {
	error.value = ''
	if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
		error.value = 'The camera needs a secure connection. Open NaSeRy over HTTPS (or localhost on this device), or type the code below.'
		return
	}
	starting.value = true
	try {
		const { BrowserQRCodeReader } = await import('@zxing/browser')
		let stream: MediaStream
		try {
			stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } } })
		} catch (e) {
			const name = e instanceof DOMException ? e.name : ''
			if (name !== 'OverconstrainedError' && name !== 'ConstraintNotSatisfiedError') throw e
			stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true })
		}
		try {
			controls = await new BrowserQRCodeReader().decodeFromStream(stream, video.value!, (result) => {
				if (!result) return
				const text = result.getText()
				const now = Date.now()
				if (text === last.text && now - last.at < props.repeatMs) return
				last = { text, at: now }
				emit('decode', text)
			})
		} catch (e) {
			stream.getTracks().forEach(t => t.stop())
			throw e
		}
		active.value = true
	} catch (e) {
		stop()
		error.value = cameraMessage(e)
	} finally {
		starting.value = false
	}
}

function stop() {
	controls?.stop()
	controls = null
	const stream = video.value?.srcObject as MediaStream | null
	stream?.getTracks().forEach(t => t.stop())
	if (video.value) video.value.srcObject = null
	active.value = false
}

function cameraMessage(e: unknown): string {
	const name = e instanceof DOMException ? e.name : ''
	if (['NotAllowedError', 'PermissionDeniedError', 'SecurityError'].includes(name)) {
		return 'Camera access was blocked. Allow the camera for this site in your browser (the camera icon beside the address bar), then press Start scanning again.'
	}
	if (['NotFoundError', 'DevicesNotFoundError'].includes(name)) return 'No camera was found on this device. Type the code below or search the guest list.'
	if (['NotReadableError', 'TrackStartError', 'AbortError'].includes(name)) return 'The camera is busy. Close other apps using it, then try again.'
	return 'The camera could not be started. Check the browser and system camera permissions, then try again.'
}

// Release the camera when leaving the page or when the tab is hidden.
function onVisibility() {
	if (document.hidden && active.value) stop()
}
onMounted(() => document.addEventListener('visibilitychange', onVisibility))
onBeforeUnmount(() => {
	document.removeEventListener('visibilitychange', onVisibility)
	stop()
})

defineExpose({ stop })
</script>
