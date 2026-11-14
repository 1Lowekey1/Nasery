<script setup lang="ts">
import 'leaflet/dist/leaflet.css'

// Interactive pin + service-radius preview for the business profile.
// Drag the pin or click the map to move it; the circle shows the radius clients are matched within.
const props = defineProps<{
	latitude: number | null
	longitude: number | null
	radiusKm: number
	color?: string
}>()

const emit = defineEmits<{ (e: 'update:position', value: { latitude: number, longitude: number }): void }>()

const config = useRuntimeConfig()
const container = ref<HTMLElement | null>(null)

// Davao City, shown when no pin has been set yet.
const FALLBACK: [number, number] = [7.0731, 125.6128]

let L: typeof import('leaflet') | null = null
let map: import('leaflet').Map | null = null
let marker: import('leaflet').Marker | null = null
let circle: import('leaflet').Circle | null = null

const hasPin = computed(() => props.latitude !== null && props.longitude !== null)

function tileUrl() {
	const key = String(config.public.locationIqApiKey || '')
	return key
		? `https://{s}-tiles.locationiq.com/v3/streets/r/{z}/{x}/{y}.png?key=${encodeURIComponent(key)}`
		: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png'
}

function pinIcon() {
	const fill = props.color || '#285F6b'
	return L!.divIcon({
		className: '',
		iconSize: [34, 42],
		iconAnchor: [17, 40],
		html: `<svg width="34" height="42" viewBox="0 0 34 42" xmlns="http://www.w3.org/2000/svg"><path d="M17 41s14-13.2 14-24A14 14 0 0 0 3 17c0 10.8 14 24 14 24Z" fill="${fill}" stroke="white" stroke-width="2.5"/><circle cx="17" cy="17" r="5" fill="white"/></svg>`,
	})
}

function zoomForRadius(km: number) {
	if (km <= 3) return 13
	if (km <= 8) return 12
	if (km <= 20) return 11
	if (km <= 45) return 10
	if (km <= 90) return 9
	return 8
}

function moveTo(lat: number, lng: number) {
	emit('update:position', { latitude: Number(lat.toFixed(7)), longitude: Number(lng.toFixed(7)) })
}

function render(fit = false) {
	if (!L || !map) return
	const color = props.color || '#285F6b'
	if (!hasPin.value) {
		marker?.remove(); circle?.remove(); marker = null; circle = null
		return
	}
	const center: [number, number] = [props.latitude as number, props.longitude as number]
	if (!marker) {
		marker = L.marker(center, { draggable: true, icon: pinIcon(), keyboard: true, title: 'Business location' }).addTo(map)
		marker.on('dragend', () => { const p = marker!.getLatLng(); moveTo(p.lat, p.lng) })
	} else {
		marker.setLatLng(center)
		marker.setIcon(pinIcon())
	}
	const radius = Math.max(1, Number(props.radiusKm) || 1) * 1000
	if (!circle) {
		circle = L.circle(center, { radius, color, weight: 1.5, fillColor: color, fillOpacity: 0.08, interactive: false }).addTo(map)
	} else {
		circle.setLatLng(center); circle.setRadius(radius); circle.setStyle({ color, fillColor: color })
	}
	if (fit) fitCircle()
}

/** Zoom in or out so the radius circle fills the map neatly (re-measured first: the map may have just been shown or resized). */
function fitCircle() {
	if (!map || !circle) return
	map.invalidateSize()
	map.fitBounds(circle.getBounds(), { padding: [16, 16], maxZoom: 15, animate: false })
}

onMounted(async () => {
	L = await import('leaflet')
	if (!container.value) return
	const start: [number, number] = hasPin.value ? [props.latitude as number, props.longitude as number] : FALLBACK
	map = L.map(container.value, { scrollWheelZoom: false, zoomControl: true, zoomSnap: 0.25 }).setView(start, hasPin.value ? zoomForRadius(props.radiusKm) : 12)
	L.tileLayer(tileUrl(), { maxZoom: 18, attribution: '&copy; LocationIQ &copy; OpenStreetMap contributors' }).addTo(map)
	map.on('click', (event: import('leaflet').LeafletMouseEvent) => moveTo(event.latlng.lat, event.latlng.lng))
	render(true)
	window.setTimeout(fitCircle, 0)
})

watch(() => [props.latitude, props.longitude], (now, before) => {
	// Re-fit only when the pin jumps (search / my location), not while dragging nearby.
	const jumped = before?.[0] == null || Math.abs(Number(now[0]) - Number(before[0])) > 0.05 || Math.abs(Number(now[1]) - Number(before[1])) > 0.05
	render(jumped)
})
watch(() => props.radiusKm, () => render(true))
watch(() => props.color, () => render(false))

onBeforeUnmount(() => { map?.remove(); map = null })
</script>

<template>
	<div class="relative overflow-hidden rounded-xl border border-gray-200 bg-gray-100">
		<div ref="container" class="h-72 w-full" role="application" aria-label="Map. Click to place your business pin, or drag the pin to adjust it." />
		<div v-if="!hasPin" class="pointer-events-none absolute inset-0 z-[400] flex items-center justify-center bg-white/55">
			<p class="rounded-lg bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-gray-200">Click anywhere on the map to drop your pin</p>
		</div>
	</div>
</template>
