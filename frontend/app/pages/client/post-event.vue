<template>
	<div class="w-full pb-6">
		<NuxtLink to="/client/my-events"
			class="pe-rise mb-4 inline-flex items-center gap-2 text-sm font-semibold text-gray-500 transition hover:text-primary-700">
			<IconBase name="arrow-right" class="h-4 w-4 rotate-180" />
			Back to dashboard
		</NuxtLink>

		<section class="pe-hero pe-rise relative overflow-hidden rounded-[1.5rem] text-white sm:rounded-[2rem]" style="--d: 40ms">
			<span class="pe-orb" />
			<span class="pe-dots" />

			<div class="relative flex flex-col gap-4 px-5 py-5 sm:gap-8 sm:px-10 sm:py-10 lg:flex-row lg:items-center lg:justify-between">
				<div class="max-w-2xl">
					<div class="hidden items-center gap-2 rounded-full bg-white/12 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-white/85 ring-1 ring-white/20 backdrop-blur sm:inline-flex">
						<IconBase name="sparkles" class="h-3.5 w-3.5" />
						Plan your next occasion
					</div>
					<h1 class="text-2xl font-extrabold tracking-tight sm:mt-4 sm:text-4xl">
						Tell us about your event.
					</h1>
					<p class="mt-3 hidden max-w-xl text-sm leading-relaxed text-white/70 sm:block sm:text-base">
						Share the essentials once. Qualified organizers can review your vision and send offers that fit your plans.
					</p>
				</div>

				<div class="w-full max-w-sm rounded-2xl bg-white/10 px-4 py-3 ring-1 ring-white/20 backdrop-blur-sm sm:p-5 lg:w-80">
					<div class="flex items-end justify-between">
						<p class="text-xs font-bold uppercase tracking-[0.12em] text-white/70">Inquiry progress</p>
						<p class="text-2xl font-extrabold leading-none">{{ completion.percent }}%</p>
					</div>
					<div class="mt-3 h-2 overflow-hidden rounded-full bg-white/20">
						<div class="h-full rounded-full bg-white transition-all duration-500 ease-out" :style="{ width: completion.percent + '%' }" />
					</div>
					<p class="mt-2 text-xs text-white/70 sm:mt-3">
						{{ completion.done === completion.total ? 'All set. You can submit your inquiry.' : `${completion.done} of ${completion.total} details completed` }}
					</p>
				</div>
			</div>
		</section>

		<div v-if="target" class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary-100 bg-primary-50 px-5 py-4">
			<div>
				<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Direct quotation request</p>
				<p class="mt-1 text-sm text-primary-900">This inquiry goes only to <span class="font-bold">{{ target.display_name.replace(/\.$/, '') }}</span>.
					<template v-if="target.picks.length"> You picked {{ target.picks.length }} option{{ target.picks.length === 1 ? '' : 's' }} from their calculator<template v-if="target.estimate"> (estimate {{ formatPeso(target.estimate) }})</template>.</template>
				</p>
			</div>
			<NuxtLink to="/client/post-event" class="text-xs font-bold text-primary-700 hover:text-primary-900">Send to nearby organizers instead</NuxtLink>
		</div>

		<div class="mt-6 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
			<form class="space-y-6" novalidate @submit.prevent="requestInquirySubmission">
				<FormsDraftNotice :draft="draft" />
				<section class="pe-rise overflow-hidden rounded-3xl border border-gray-200 bg-white" style="--d: 100ms">
					<div
						class="flex flex-col justify-between gap-4 border-b border-gray-100 px-6 py-5 sm:flex-row sm:items-center sm:px-8">
						<div class="flex items-center gap-3"><span
								class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-700 text-sm font-extrabold text-white">1</span>
							<div>
								<p class="text-[10px] font-extrabold uppercase tracking-[.16em] text-primary-600">
									Getting started</p>
								<h2 class="mt-0.5 text-xl font-extrabold text-gray-900">Event basics</h2>
								<p class="mt-1 text-sm text-gray-500">Tell us what you are planning and when it will
									happen.</p>
							</div>
						</div>
						<span
							class="hidden w-fit items-center gap-1.5 sm:inline-flex rounded-full border border-primary-100 bg-white px-3 py-1.5 text-xs font-semibold text-primary-700">
							<IconBase name="clock" class="h-3.5 w-3.5" /> Takes about 2 minutes
						</span>
					</div>

					<div class="p-6 sm:p-8">
						<div class="rounded-2xl border border-gray-100 bg-gray-50/70 p-4">
							<FormsLabel text="Give your event a name" hint="(optional)" />
							<FormsTextField v-model="form.title" size="lg" placeholder="e.g. Marlo & Rena Wedding"
								class="mt-1" />
						</div>

						<div id="field-eventType" class="mb-3 mt-6 flex scroll-mt-24 items-end justify-between gap-3">
							<div><label class="block text-sm font-bold text-gray-800">What are you celebrating? <span
										class="text-rose-500">*</span></label>
								<p class="mt-1 text-xs text-gray-400">Select one event type. Click it again to clear
									your choice.</p>
							</div><span v-if="form.eventType"
								class="shrink-0 rounded-full bg-primary-50 px-2.5 py-1 text-[11px] font-bold text-primary-700">{{
									form.eventType }} selected</span>
						</div>
						<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
							<button v-for="type in eventTypes" :key="type.value" type="button"
								:aria-pressed="form.eventType === type.value"
								class="group relative z-0 flex min-h-[5.25rem] cursor-pointer flex-col items-start justify-between overflow-hidden rounded-xl border p-3 text-left transition"
								:class="form.eventType === type.value
									? 'border-primary-700 bg-primary-700 text-white shadow-md shadow-primary-900/10 -translate-y-0.5'
									: 'border-gray-200 bg-white text-gray-600 hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-sm'"
								@click.prevent="toggleEventType(type.value)">
								<span aria-hidden="true"
									class="pointer-events-none absolute -bottom-6 -right-6 h-16 w-16 rounded-full"
									:class="form.eventType === type.value ? 'bg-white/10' : 'bg-primary-50'" />
								<span
									class="pointer-events-none flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
									:class="form.eventType === type.value ? 'bg-white/15 text-white' : 'bg-gray-100 text-gray-500 group-hover:bg-primary-50 group-hover:text-primary-700'">
									<IconBase :name="type.icon" class="h-4 w-4" />
								</span>
								<span class="pointer-events-none relative text-sm font-bold">{{ type.value }}</span>
							</button>
						</div>
						<p v-if="showMissing('eventType')" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-rose-600" role="alert">Choose the kind of event.</p>

						<div id="field-services" class="mt-6 scroll-mt-24">
							<div class="mb-3 flex items-end justify-between gap-3">
								<div>
									<label class="block text-sm font-bold text-gray-800">What services do you need?
										<span v-if="servicesRequired" class="text-rose-500">*</span>
										<span v-else class="font-normal text-gray-400">(optional)</span></label>
									<p class="mt-1 text-xs text-gray-400">Pick everything you'd like organizers to quote. NaSeRy sends your inquiry to organizers who offer them.</p>
								</div>
								<span v-if="form.services.length" class="shrink-0 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700">{{ form.services.length }} selected</span>
							</div>
							<div v-if="serviceOptions.length" class="flex flex-wrap gap-2">
								<button v-for="service in serviceOptions" :key="service.name" type="button"
									:aria-pressed="form.services.includes(service.name)"
									class="rounded-full border px-3.5 py-2 text-sm font-semibold transition"
									:class="form.services.includes(service.name) ? 'border-primary-700 bg-primary-700 text-white' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-300'"
									@click="toggleService(service.name)">
									{{ service.name }}
									<span class="ml-1 text-xs font-normal opacity-70">· {{ service.organizers }} {{ service.organizers === 1 ? 'organizer' : 'organizers' }}</span>
								</button>
							</div>
							<p v-else class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500">{{ servicesLoaded ? 'No organizers have listed services yet, so open posting isn’t available. You can still request a quotation from an organizer’s page.' : 'Loading services…' }}</p>
							<p v-if="showMissing('services')" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-rose-600" role="alert">Choose at least one service so NaSeRy can find matching organizers.</p>
						</div>

						<div class="mt-6 rounded-2xl border border-primary-100 bg-primary-50/40 p-4 sm:p-5">
							<div class="mb-4 flex items-center gap-2"><span
									class="flex h-8 w-8 items-center justify-center rounded-lg bg-white text-primary-700 shadow-sm">
									<IconBase name="calendar" class="h-4 w-4" />
								</span>
								<div>
									<h3 class="text-sm font-bold text-gray-900">Schedule</h3>
									<p class="text-xs text-gray-500">Choose a future date and your expected event hours.
									</p>
								</div>
							</div>
							<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
								<div class="sm:col-span-2">
									<FormsLabel text="Event date" required />
									<FormsDateField v-model="form.date" name="event_date"
										placeholder="Choose event date" :min-date="minimumEventDate" :max-date="maximumEventDate" />
									<p class="mt-1.5 text-xs text-gray-400">Any date from today up to {{ maximumEventDateLabel }}.</p>
									<p v-if="form.date" class="mt-1.5 text-xs font-semibold text-primary-700">{{
										formattedEventDate }}</p>
								</div>
								<div>
									<FormsLabel text="Start time" required />
									<FormsTimeField v-model="form.startTime" name="start_time"
										placeholder="Select start time" />
								</div>
								<div>
									<FormsLabel text="End time" required />
									<FormsTimeField v-model="form.endTime" name="end_time" placeholder="Select end time"
										:min-time="form.startTime || undefined" />
								</div>
							</div>
							<p v-if="timeError" class="mt-3 text-xs font-semibold text-rose-600">{{ timeError }}</p>
							<p v-else-if="showMissing('schedule')" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-rose-600" role="alert">{{ form.date && form.startTime && form.endTime ? `Choose a date between today and ${maximumEventDateLabel}.` : 'Choose the event date, start time and end time.' }}</p>
						</div>

						<div id="field-guests" class="mt-5 max-w-sm scroll-mt-24">
							<FormsLabel text="Expected guests" required />
							<FormsTextField v-model="form.guests" type="number" min="1" icon="users" size="lg"
								placeholder="e.g. 150" required />
							<p v-if="showMissing('guests')" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-rose-600" role="alert">Enter how many guests you expect (at least 1).</p>
							<p v-else class="mt-1.5 text-xs text-gray-400">An estimate is okay—you can update this later.</p>
						</div>
					</div>
				</section>

				<section class="pe-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 160ms">
					<div class="mb-6 flex items-start gap-3">
						<span
							class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-700 text-sm font-extrabold text-white">2</span>
						<div>
							<h2 class="text-lg font-bold text-gray-900">Place and budget</h2>
							<p class="mt-1 text-sm text-gray-500">Choose the venue area and a comfortable spending
								range.</p>
						</div>
					</div>

					<div class="space-y-6">
						<div>
							<span id="field-location" class="block scroll-mt-24" />
							<FormsLabel text="Location" required />
							<div v-if="!locationIqApiKey" class="mb-2 flex flex-wrap items-center gap-2 rounded-xl bg-primary-50/60 px-3 py-2 text-xs text-gray-600">
								<span>Type the venue or city below, then</span>
								<button type="button" class="rounded-lg bg-primary-700 px-2.5 py-1 font-semibold text-white disabled:opacity-50" :disabled="isLocating" @click="useMyLocation">
									{{ isLocating ? 'Locating…' : form.latitude !== null ? 'Location pinned ✓' : 'Use my current location' }}
								</button>
								<span>{{ target ? 'so your organizer knows where it is.' : 'so nearby organizers can find it.' }}</span>
							</div>
							<div class="relative">
								<IconBase name="map-pin"
									class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
								<input v-model="locationQuery" type="text" autocomplete="off"
									placeholder="Search venue, city, or landmark"
									class="w-full rounded-xl border border-gray-200 bg-gray-50 py-2.5 pl-9 pr-20 text-sm text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15"
									@input="handleLocationInput" @focus="handleLocationFocus" @blur="handleLocationBlur"
									@keydown.down.prevent="moveLocationSuggestion(1)"
									@keydown.up.prevent="moveLocationSuggestion(-1)"
									@keydown.enter.prevent="selectActiveLocationSuggestion"
									@keydown.esc="closeLocationSuggestions">
								<span v-if="isSearchingLocation"
									class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-gray-400">Searching…</span>
								<div v-if="showLocationSuggestions"
									class="absolute z-30 mt-2 max-h-72 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl">
									<button v-for="(place, index) in locationSuggestions"
										:key="locationSuggestionKey(place, index)" type="button"
										class="flex w-full items-start gap-3 border-b border-gray-100 px-3.5 py-3 text-left last:border-b-0 hover:bg-gray-50"
										:class="activeLocationSuggestionIndex === index ? 'bg-primary-50' : ''"
										@mousedown.prevent="selectLocation(place)">
										<IconBase name="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" />
										<div class="min-w-0">
											<p class="truncate text-sm font-semibold text-gray-800">{{
												place.display_place || place.address?.name || place.display_name }}</p>
											<p v-if="place.display_address"
												class="mt-0.5 line-clamp-2 text-xs text-gray-500">{{
													place.display_address }}</p>
										</div>
									</button>
									<div v-if="!isSearchingLocation && locationSuggestions.length === 0"
										class="px-4 py-3 text-sm text-gray-500">No matching locations found.</div>
									<div
										class="border-t border-gray-100 px-3.5 py-2 text-right text-[11px] text-gray-400">
										Search by LocationIQ</div>
								</div>
							</div>
							<p class="mt-1.5 text-xs text-gray-400">{{ target ? 'Select a result so your organizer sees the exact venue.' : 'Select a result so nearby organizers can be matched accurately.' }}</p>
						</div>
						<div class="border-t border-gray-100 pt-5">
							<div class="mb-4 flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
								<div id="field-budget" class="scroll-mt-24"><label class="block text-sm font-semibold text-gray-700">What is your estimated
										budget? <span class="text-rose-500">*</span></label>
									<p class="mt-1 text-xs text-gray-400">This helps organizers prepare offers that fit
										your plans.</p>
								</div>
								<span v-if="form.budget"
									class="inline-flex w-fit items-center gap-1.5 rounded-full bg-primary-50 px-3 py-1 text-xs font-bold text-primary-700">
									<IconBase name="check-circle" class="h-3.5 w-3.5" /> Budget selected
								</span>
							</div>
							<p v-if="showMissing('budget')" class="-mt-2 mb-3 flex items-center gap-1.5 text-xs font-semibold text-rose-600" role="alert">Choose a budget range.</p>
							<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" role="radiogroup"
								aria-label="Budget range">
								<button v-for="option in budgetOptions" :key="option.value" type="button" role="radio"
									:aria-checked="form.budget === option.value"
									class="group relative min-h-[6.25rem] overflow-hidden rounded-2xl border p-4 text-left transition duration-200 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
									:class="form.budget === option.value ? 'border-primary-700 bg-primary-700 text-white shadow-md shadow-primary-900/10 -translate-y-0.5' : 'border-gray-200 bg-white hover:-translate-y-0.5 hover:border-primary-300 hover:shadow-sm'"
									@click="form.budget = form.budget === option.value ? '' : option.value">
									<span aria-hidden="true"
										class="absolute -bottom-7 -right-7 h-20 w-20 rounded-full transition"
										:class="form.budget === option.value ? 'bg-white/10' : 'bg-primary-50 group-hover:bg-primary-100/70'" />
									<span class="block text-[10px] font-extrabold uppercase tracking-[.14em]"
										:class="form.budget === option.value ? 'text-primary-100' : 'text-primary-600'">{{
											option.tier }}</span>
									<span class="mt-2 block pr-7 text-base font-extrabold tracking-tight"
										:class="form.budget === option.value ? 'text-white' : 'text-gray-900'">{{
											option.label }}</span>
									<span class="mt-1 block text-xs leading-4"
										:class="form.budget === option.value ? 'text-white/70' : 'text-gray-400'">{{
											option.hint }}</span>
									<span
										class="absolute right-3.5 top-3.5 flex h-5 w-5 items-center justify-center rounded-full border transition"
										:class="form.budget === option.value ? 'border-white bg-white text-primary-700' : 'border-gray-300 bg-white'">
										<IconBase v-if="form.budget === option.value" name="check-circle"
											class="h-3.5 w-3.5" />
									</span>
								</button>
							</div>
						</div>
					</div>

					<div v-if="locationError"
						class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{
							locationError }}</div>
					<p v-if="showMissing('location')" class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-rose-600" role="alert">{{ target ? 'Enter where the event will be.' : 'Choose a place from the suggestions (or use your current location) so NaSeRy can find organizers near it.' }}</p>
					<div v-if="hasSelectedLocation"
						class="mt-5 overflow-hidden rounded-2xl border border-primary-100 bg-primary-50/40">
						<div class="flex items-start gap-3 p-4"><span
								class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-700 text-white">
								<IconBase name="map-pin" class="h-5 w-5" />
							</span>
							<div class="min-w-0 flex-1">
								<div class="flex flex-wrap items-center gap-2">
									<p class="font-semibold text-gray-900">{{ form.venueName || 'Selected location' }}
									</p><span
										class="rounded-full bg-primary-50 px-2 py-0.5 text-[11px] font-bold text-primary-700">Location
										confirmed</span>
								</div>
								<p class="mt-1 text-sm text-gray-500">{{ form.venueAddress }}</p>
							</div><button type="button"
								class="shrink-0 text-xs font-bold text-primary-700 hover:text-primary-900"
								@click="resetLocation">Change</button>
						</div>
						<img v-if="showStaticMap" :src="staticMapUrl" :alt="`Map showing ${form.venueName || 'the selected venue'}`"
							class="h-[280px] w-full border-t border-primary-100 bg-gray-100 object-cover"
							@load="locationError = ''" @error="handleStaticMapError">
						<div v-else ref="mapContainer" class="h-[280px] w-full border-t border-primary-100 bg-gray-100" />
						<div
							class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-primary-100 bg-white px-4 py-3 text-xs text-gray-500">
							<span class="flex items-center gap-2"><svg width="12" height="16" viewBox="0 0 30 40" aria-hidden="true"><path d="M15 39C15 39 28 24.5 28 14.5A13 13 0 0 0 2 14.5C2 24.5 15 39 15 39Z" fill="#285F6b"/><circle cx="15" cy="14.5" r="5" fill="#ffffff"/></svg>Your
								venue</span><span class="flex items-center gap-2"><span
									class="h-2.5 w-2.5 rounded-full bg-amber-600 ring-2 ring-white shadow" />Nearby
								organizer</span><span v-if="!showStaticMap" class="flex items-center gap-2"><span
									class="h-3 w-3 rounded-full border border-dashed border-primary-700 bg-primary-700/10" />Search area ({{ nearbyRadiusKm }} km)</span><span class="ml-auto">Map data by LocationIQ &amp; OpenStreetMap</span>
						</div>
					</div>
				</section>

				<section class="pe-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8" style="--d: 220ms">
					<div class="mb-6 flex items-start gap-3"><span
							class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-700 text-sm font-extrabold text-white">3</span>
						<div>
							<h2 class="text-lg font-bold text-gray-900">Your vision</h2>
							<p class="mt-1 text-sm text-gray-500">A little context helps organizers prepare a more
								useful offer.</p>
						</div>
					</div>
					<label class="mb-1.5 block text-sm font-semibold text-gray-700">Additional details <span
							class="font-normal text-gray-400">(optional)</span></label>
					<textarea v-model="form.details" rows="5"
						placeholder="Describe the mood, theme, must-haves, special requests, or anything else organizers should know…"
						class="w-full resize-y rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-3 text-sm leading-relaxed text-gray-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/15" />
				</section>

				<!-- A direct request goes to one organizer only: no "organizers near you" (#10) -->
				<section v-if="hasSelectedLocation && !target"
					class="pe-rise rounded-3xl border border-gray-200 bg-white p-6 sm:p-8">
					<div class="mb-4 flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
						<div>
							<h2 class="text-lg font-bold text-gray-900">Organizers near your event</h2>
							<p class="mt-1 text-sm text-gray-500">Based on each organizer's service area, ratings, and
								your selected venue.</p>
						</div>
						<div class="flex shrink-0 items-center gap-2"><label
								class="text-xs font-semibold text-gray-500">Search within</label>
							<FormsSelect v-model="nearbyRadiusKm" class="min-w-28" :options="nearbyRadiusOptions"
								:can-clear="false" :searchable="false" @update:model-value="refreshNearbyOrganizers" />
						</div>
					</div>
					<div v-if="!isLoadingNearby && !nearbyError"
						class="mb-4 flex items-center justify-between rounded-xl bg-primary-50 px-4 py-3">
						<p class="text-sm text-primary-900"><span class="font-bold">{{ nearbyOrganizers.length }}</span>
							{{ nearbyOrganizers.length === 1 ? 'organizer' : 'organizers' }} available in this area</p>
						<button type="button" class="text-xs font-bold text-primary-700 hover:text-primary-900"
							@click="refreshNearbyOrganizers">Refresh</button>
					</div>
					<div v-if="isLoadingNearby"
						class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-5 text-center text-sm text-gray-500">
						Searching for nearby organizers…</div>
					<div v-else-if="nearbyError"
						class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">{{
							nearbyError }}</div>
					<div v-else-if="nearbyOrganizers.length === 0"
						class="rounded-xl border border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center">
						<IconBase name="map-pin" class="mx-auto h-6 w-6 text-gray-400" />
						<p class="mt-2 text-sm font-semibold text-gray-700">No nearby organizers found</p>
						<p class="mt-1 text-xs text-gray-500">Try a larger distance above, or submit anyway: new organizers in range will see it.</p>
						<NuxtLink to="/organizers" target="_blank" class="mt-2 inline-block text-xs font-semibold text-primary-700 hover:underline">Browse all organizers ↗</NuxtLink>
					</div>
					<div v-else class="grid gap-3 sm:grid-cols-2">
						<article v-for="organizer in nearbyOrganizers" :key="organizer.id"
							class="flex flex-col rounded-xl border border-gray-200 p-4 transition hover:border-primary-300 hover:shadow-sm">
							<div class="flex items-start gap-3"><span
									class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-primary-50 text-sm font-extrabold text-primary-700"><img
										v-if="organizer.avatar_url" :src="organizer.avatar_url"
										:alt="`${organizerDisplayName(organizer)} profile`"
										class="h-full w-full bg-white object-contain"><span v-else>{{ organizerInitials(organizer)
										}}</span></span>
								<div class="min-w-0 flex-1">
									<p class="truncate font-bold text-gray-900">{{ organizerDisplayName(organizer) }}
									</p>
									<p v-if="organizer.location" class="mt-0.5 truncate text-xs text-gray-500">{{
										organizer.location }}</p>
									<div class="mt-2 flex flex-wrap items-center gap-3 text-xs"><span
											class="flex items-center gap-1 font-semibold text-gray-800">
											<IconBase name="star" class="h-3.5 w-3.5 fill-amber-400 text-amber-500" />{{
												formatRating(organizer.reviews_avg_rating) }} <span
												class="font-normal text-gray-400">({{ organizer.reviews_count }} {{
													organizer.reviews_count === 1 ? 'review' : 'reviews' }})</span>
										</span><span
											class="rounded-full bg-gray-100 px-2 py-1 font-semibold text-gray-600">{{
												formatDistance(organizer.distance_km) }}</span></div>
								</div>
							</div>
							<div v-if="organizer.specialties?.length" class="mt-3 flex flex-wrap gap-1.5"><span
									v-for="specialty in organizer.specialties.slice(0, 3)" :key="specialty"
									class="rounded-full bg-primary-50 px-2.5 py-1 text-[11px] font-semibold text-primary-700">{{
										specialty }}</span></div>
							<NuxtLink :to="organizerProfilePath(organizer)"
								class="mt-4 inline-flex items-center justify-center gap-1.5 rounded-lg border border-primary-200 px-3 py-2 text-xs font-bold text-primary-700 hover:border-primary-700 hover:bg-primary-50">
								View profile and reviews
								<IconBase name="arrow-right" class="h-3.5 w-3.5" />
							</NuxtLink>
						</article>
					</div>
				</section>

				<div v-if="errorMessage"
					class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ errorMessage }}
				</div>
				<!-- Stays clickable while incomplete: pressing it lists what's missing and jumps there. -->
				<button type="submit"
					class="flex w-full items-center justify-center gap-2 rounded-2xl bg-primary-700 py-4 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-primary-800 hover:shadow-md active:translate-y-0 active:scale-[0.99] disabled:translate-y-0 disabled:cursor-not-allowed disabled:opacity-50"
					:class="{ 'opacity-75': !isValid }"
					:disabled="isSubmitting">
					<template v-if="isSubmitting">Submitting inquiry…</template><template v-else>
						<IconBase name="send" class="h-4 w-4" />{{ target ? `Send to ${target.display_name}` : 'Submit inquiry & find organizers' }}
					</template>
				</button>
			</form>

			<aside class="space-y-4 xl:sticky xl:top-6">
				<div class="pe-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 160ms">
					<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Inquiry preview</p>
					<h2 class="mt-3 text-lg font-bold text-gray-900">{{ form.title || form.eventType || 'Your event' }}
					</h2>
					<p class="mt-1 text-sm text-gray-500">Review the essentials as you complete the form.</p>
					<dl class="mt-5 space-y-4 border-t border-gray-100 pt-5 text-sm">
						<div class="flex items-start justify-between gap-3">
							<dt class="text-gray-400">Type</dt>
							<dd class="text-right font-semibold text-gray-800">{{ form.eventType || 'Not selected' }}
							</dd>
						</div>
						<div class="flex items-start justify-between gap-3">
							<dt class="text-gray-400">Date</dt>
							<dd class="text-right font-semibold text-gray-800">{{ form.date || 'Not selected' }}</dd>
						</div>
						<div class="flex items-start justify-between gap-3">
							<dt class="text-gray-400">Time</dt>
							<dd class="text-right font-semibold text-gray-800">{{ form.startTime && form.endTime ?
								`${formatTime(form.startTime)} – ${formatTime(form.endTime)}` : 'Not selected' }}</dd>
						</div>
						<div class="flex items-start justify-between gap-3">
							<dt class="text-gray-400">Guests</dt>
							<dd class="text-right font-semibold text-gray-800">
								{{ form.guests || 'Not set' }}
							</dd>
						</div>
						<div class="flex items-start justify-between gap-3">
							<dt class="text-gray-400">Budget</dt>
							<dd class="text-right font-semibold text-gray-800">
								{{ form.budget || 'Not selected' }}
							</dd>
						</div>
						<div class="flex items-start justify-between gap-3">
							<dt class="text-gray-400">Location</dt>
							<dd class="max-w-[11rem] text-right font-semibold text-gray-800">
								{{ form.venueName || locationQuery.trim() || 'Not selected' }}
							</dd>
						</div>
					</dl>
				</div>
				<div class="pe-rise rounded-3xl border border-gray-200 bg-white p-6" style="--d: 220ms">
					<p class="text-xs font-bold uppercase tracking-widest text-primary-700">Checklist</p>
					<ul class="mt-4 space-y-3">
						<li v-for="item in completion.items" :key="item.label" class="flex items-center gap-3 text-sm">
							<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border transition duration-300"
								:class="item.done ? 'border-primary-700 bg-primary-700 text-white' : 'border-gray-300 bg-white text-transparent'">
								<svg viewBox="0 0 20 20" class="h-3 w-3" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
							</span>
							<span :class="item.done ? 'font-semibold text-gray-900' : 'text-gray-500'">{{ item.label }}</span>
						</li>
					</ul>
				</div>

				<div class="pe-rise rounded-3xl border border-primary-100 bg-primary-50/60 p-6" style="--d: 280ms">
					<div class="flex gap-3"><span
							class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-primary-700">
							<IconBase name="check-circle" class="h-4 w-4" />
						</span>
						<div>
							<h3 class="text-sm font-bold text-primary-900">No booking commitment</h3>
							<p class="mt-1 text-xs leading-relaxed text-primary-800/70">Submitting lets organizers send
								offers. You
								choose whether to accept one.</p>
						</div>
					</div>
				</div>
			</aside>
		</div>

		<FormsConfirmationModal :open="showSubmitConfirmation" eyebrow="Ready to send"
			:title="target ? `Send this to ${target.display_name}?` : 'Submit this event inquiry?'"
			:description="target ? `Only ${target.display_name} receives these details and can send you a quotation.` : 'Nearby organizers will be able to review these details and send you quotations. You can compare every offer before choosing one.'"
			confirm-label="Yes, submit inquiry" cancel-label="Keep editing" loading-label="Submitting inquiry..."
			:loading="isSubmitting" @cancel="showSubmitConfirmation = false" @confirm="submitInquiry">
			<div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
				<p class="truncate font-extrabold text-gray-900">{{ form.title || `${form.eventType} event` }}</p>
				<div class="mt-3 grid grid-cols-2 gap-3 text-xs">
					<div><p class="text-gray-400">Schedule</p><p class="mt-1 font-bold text-gray-700">{{ formattedEventDate }}</p></div>
					<div><p class="text-gray-400">Guests</p><p class="mt-1 font-bold text-gray-700">{{ form.guests }}</p></div>
					<div class="col-span-2"><p class="text-gray-400">Venue</p><p class="mt-1 line-clamp-2 font-bold text-gray-700">{{ form.venueName || form.venueAddress || locationQuery.trim() }}</p></div>
				</div>
			</div>
		</FormsConfirmationModal>

		<FormsConfirmationModal :open="showSubmissionSuccess" variant="success" eyebrow="Inquiry submitted"
			:title="target ? `Sent to ${target.display_name}` : 'Your event is ready for matching'"
			:description="target ? `${target.display_name} can now review your request and send a quotation. Track it from My Events.` : 'Organizers can now review your inquiry and send quotations. Track new offers from My Events.'"
			confirm-label="Go to My Events" :show-cancel="false" @cancel="goToMyEvents" @confirm="goToMyEvents">
			<div class="flex items-start gap-3 rounded-2xl bg-emerald-50 p-4 text-emerald-800">
				<IconBase name="check-circle" class="mt-0.5 h-5 w-5 shrink-0" />
				<p class="text-sm font-semibold leading-6">Your inquiry was saved successfully. There is no booking commitment until you accept an organizer's quotation.</p>
			</div>
		</FormsConfirmationModal>
	</div>
</template>

<script setup lang="ts">
import 'leaflet/dist/leaflet.css'

import type {
	CreateInquiryPayload,
	CreateInquiryResponse,
} from '~/types/inquiry'

import type {
	NearbyOrganizer,
	NearbyOrganizersResponse,
} from '~/types/organizer'

type LocationIqResult = {
	place_id?: string | number
	osm_id?: string | number
	osm_type?: string
	lat: string
	lon: string
	display_name: string
	display_place?: string
	display_address?: string
	address?: {
		name?: string
		[key: string]: unknown
	}
}

definePageMeta({
	layout: 'client',
})

const {
	token,
} = useAuth()

const config = useRuntimeConfig()

const mapContainer =
	ref<HTMLElement | null>(null)

const showStaticMap = ref(false)

const isSubmitting =
	ref(false)

const showSubmitConfirmation = ref(false)
const showSubmissionSuccess = ref(false)

const errorMessage =
	ref('')

const locationError =
	ref('')

const nearbyError =
	ref('')

const isLoadingNearby =
	ref(false)

const nearbyOrganizers =
	ref<NearbyOrganizer[]>([])

const locationQuery =
	ref('')

const locationSuggestions =
	ref<LocationIqResult[]>([])

const isSearchingLocation =
	ref(false)

const isLocationInputFocused =
	ref(false)

const activeLocationSuggestionIndex =
	ref(-1)

const nearbyRadiusKm = ref(25)
const locationSearchDelayMs = 400

let locationSearchTimer:
	ReturnType<typeof setTimeout> | null = null

let locationSearchController:
	AbortController | null = null

let leafletModule:
	any = null

let leafletMap:
	any = null

let leafletMapContainer:
	HTMLElement | null = null

let leafletMarkers:
	any[] = []

const eventTypes = [
	{
		value: 'Wedding',
		icon: 'heart',
	},
	{
		value: 'Corporate',
		icon: 'briefcase',
	},
	{
		value: 'Birthday',
		icon: 'gift',
	},
	{
		value: 'Debut',
		icon: 'star',
	},
	{
		value: 'Concert',
		icon: 'music',
	},
	{
		value: 'Conference',
		icon: 'users',
	},
	{
		value: 'Reunion',
		icon: 'users',
	},
	{
		value: 'Seminar',
		icon: 'award',
	},
]

const budgetOptions = [
	{ value: 'Under ₱50,000', tier: 'Essential', label: 'Under ₱50K', hint: 'For simple, meaningful occasions' },
	{ value: '₱50,000 – ₱150,000', tier: 'Intimate', label: '₱50K – ₱150K', hint: 'For intimate, styled celebrations' },
	{ value: '₱150,000 – ₱300,000', tier: 'Signature', label: '₱150K – ₱300K', hint: 'For complete event experiences' },
	{ value: '₱300,000 – ₱500,000', tier: 'Grand', label: '₱300K – ₱500K', hint: 'For larger guest lists and details' },
	{ value: '₱500,000 – ₱800,000', tier: 'Premium', label: '₱500K – ₱800K', hint: 'For elevated event productions' },
	{ value: 'Over ₱800,000', tier: 'Luxury', label: 'Over ₱800K', hint: 'For bespoke, large-scale occasions' },
]

const nearbyRadiusOptions = [
	{ value: 10, label: '10 km' },
	{ value: 25, label: '25 km' },
	{ value: 50, label: '50 km' },
	{ value: 100, label: '100 km' },
]

const form = reactive({
	title: '',
	eventType: '',
	date: '',
	startTime: '',
	endTime: '',

	location: '',
	venueName: '',
	venueAddress: '',
	locationIqPlaceId: '',
	latitude: null as number | null,
	longitude: null as number | null,

	guests: '',
	budget: '',
	details: '',
	services: [] as string[],
})

// Services listed organizers offer (open posting matches on these).
const serviceOptions = ref<{ name: string, organizers: number }[]>([])
const servicesLoaded = ref(false)
function toggleService(name: string) {
	form.services = form.services.includes(name) ? form.services.filter(s => s !== name) : [...form.services, name]
}
onMounted(async () => {
	try {
		serviceOptions.value = (await useMarketplace().serviceNames()).filter(s => s.organizers > 0)
	} catch {
		serviceOptions.value = []
	} finally {
		servicesLoaded.value = true
	}
})

function toggleEventType(eventType: string) {
	form.eventType = form.eventType === eventType
		? ''
		: eventType
	// The nearby list depends on the event type (organizers' event tags).
	if (hasSelectedLocation.value && !target.value) void refreshNearbyOrganizers()
}

const locationIqApiKey =
	computed(() => {
		return String(
			config.public
				.locationIqApiKey
			|| ''
		)
	})

// A chosen place (LocationIQ), or typed text + the browser's location when there's no key.
const hasSelectedLocation =
	computed(() => {
		return Boolean(
			(form.venueAddress || locationQuery.value.trim()) &&
			form.latitude !== null &&
			form.longitude !== null
		)
	})

const isLocating = ref(false)

function useMyLocation() {
	if (!navigator.geolocation) {
		locationError.value = 'This browser can\'t share its location. Add a LocationIQ key to search places instead.'
		return
	}
	isLocating.value = true
	navigator.geolocation.getCurrentPosition((position) => {
		form.latitude = Number(position.coords.latitude.toFixed(6))
		form.longitude = Number(position.coords.longitude.toFixed(6))
		form.location = locationQuery.value.trim() || 'Pinned location'
		form.venueAddress = form.location
		locationError.value = ''
		isLocating.value = false
		void fetchNearbyOrganizers()
	}, () => {
		locationError.value = 'Location access was blocked. Allow it for this site, or add a LocationIQ key to search places.'
		isLocating.value = false
	}, { timeout: 10000 })
}

const staticMapUrl = computed(() => {
	if (form.latitude === null || form.longitude === null || !locationIqApiKey.value) return ''

	const coordinates = `${form.latitude},${form.longitude}`
	const params = new URLSearchParams({
		key: locationIqApiKey.value,
		center: coordinates,
		zoom: '14',
		size: '1200x560',
		format: 'png',
		maptype: 'streets',
		markers: `icon:large-blue-cutout|${coordinates}`,
	})

	return `https://maps.locationiq.com/v3/staticmap?${params.toString()}`
})

// Open posting needs services to match organizers; a direct request already names the organizer.
const servicesRequired = computed(() => !target.value)

// What's still missing, in page order (drives the notes under each field and the summary).
const attempted = ref(false)
const missing = computed(() => {
	const list: { key: string, label: string }[] = []
	if (!form.eventType) list.push({ key: 'eventType', label: 'Event type' })
	if (servicesRequired.value && !form.services.length) list.push({ key: 'services', label: 'Services needed' })
	if (!form.date || !form.startTime || !form.endTime || timeError.value) list.push({ key: 'schedule', label: 'Date and time' })
	else if (form.date < minimumEventDate.value || form.date > maximumEventDate.value) list.push({ key: 'schedule', label: `A date on or before ${maximumEventDateLabel.value}` })
	if (!form.guests || Number(form.guests) < 1) list.push({ key: 'guests', label: 'Expected guests' })
	if (!(hasSelectedLocation.value || Boolean(target.value && locationQuery.value.trim()))) list.push({ key: 'location', label: 'Location' })
	if (!form.budget) list.push({ key: 'budget', label: 'Budget' })
	return list
})
const showMissing = (key: string) => attempted.value && missing.value.some(m => m.key === key)
const isValid = computed(() => missing.value.length === 0)

// The side checklist: the same rules as `missing`.
const completion = computed(() => {
	const keys = new Set(missing.value.map(m => m.key))
	const items = [
		{ label: 'Event type', done: !keys.has('eventType') },
		...(servicesRequired.value ? [{ label: 'Services needed', done: !keys.has('services') }] : []),
		{ label: 'Date and time', done: !keys.has('schedule') },
		{ label: 'Expected guests', done: !keys.has('guests') },
		{ label: 'Location', done: !keys.has('location') },
		{ label: 'Budget', done: !keys.has('budget') },
	]
	const done = items.filter(item => item.done).length
	return { items, done, total: items.length, percent: Math.round((done / items.length) * 100) }
})

function pointToFirstMissing() {
	attempted.value = true
	errorMessage.value = `Still needed: ${missing.value.map(m => m.label).join(', ')}.`
	scrollToElement(document.getElementById(`field-${missing.value[0]?.key}`))
}

const minimumEventDate = computed(() => {
	const now = new Date()
	const localDate = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
	return localDate.toISOString().slice(0, 10)
})

// Inquiries accept dates up to December 31, three years from now (same rule as the server).
const maximumEventDate = computed(() => `${Number(minimumEventDate.value.slice(0, 4)) + 3}-12-31`)
const maximumEventDateLabel = computed(() => `December 31, ${maximumEventDate.value.slice(0, 4)}`)

const formattedEventDate = computed(() => {
	if (!form.date) return ''
	return new Intl.DateTimeFormat('en-PH', { dateStyle: 'full' }).format(new Date(`${form.date}T00:00:00`))
})

const timeError = computed(() => {
	if (!form.startTime || !form.endTime) return ''
	return form.endTime <= form.startTime ? 'End time must be later than start time.' : ''
})

const showLocationSuggestions =
	computed(() => {
		return Boolean(
			isLocationInputFocused.value &&
			locationQuery.value.trim().length >= 3 &&
			(
				isSearchingLocation.value ||
				locationSuggestions.value.length > 0
			)
		)
	})

// Direct request from an organizer's page (?organizer=slug), with their calculator picks (&guests=&options=id,id).
const route = useRoute()
const target = ref<{ id: string, display_name: string, picks: { custom_service_name: string, notes: string }[], estimate: number | null } | null>(null)
// Unfinished inquiry kept in this browser (one per organizer for direct requests).
const draft = useDraft(() => (target.value ? `inquiry:to:${target.value.id}` : 'inquiry:new'), form)

onMounted(async () => {
	if (typeof route.query.organizer !== 'string') return
	try {
		const org = await useMarketplace().organizer(route.query.organizer)
		const guests = Number(route.query.guests)
		if (guests > 0) form.guests = String(guests)
		// Picks arrive as "id" or "id:quantity" (hours/days/items), see utils/priceCalc.ts.
		const chosen = decodePicks(route.query.options)
		const picks = org.price_options.filter(o => o.id in chosen)
		const estimate = buildEstimate(org.price_options, chosen, guests > 0 ? guests : null, org.estimate ?? {})
		target.value = {
			id: org.id,
			display_name: org.display_name,
			picks: picks.map((o) => {
				const service = org.services.find(sv => sv.id === o.organization_service_id)?.name
				const line = estimate.lines.find(l => l.id === o.id)!
				const detail = `${line.detail} = ${formatPeso(line.subtotal)}`
				return { custom_service_name: service ?? o.name, notes: service ? `${o.name}: ${detail}` : detail }
			}),
			estimate: picks.length ? estimate.total : null,
		}
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'That organizer could not be found. You can still post to nearby organizers.'
	}
})

onBeforeUnmount(() => {
	if (locationSearchTimer) {
		clearTimeout(locationSearchTimer)
		locationSearchTimer = null
	}

	locationSearchController?.abort()
	locationSearchController = null

	clearMarkers()

	if (leafletMap) {
		leafletMap.remove()
		leafletMap = null
		leafletMapContainer = null
	}
})

function handleLocationFocus() {
	isLocationInputFocused.value = true
}

function handleLocationBlur() {
	window.setTimeout(() => {
		isLocationInputFocused.value = false
		activeLocationSuggestionIndex.value = -1
	}, 150)
}

function closeLocationSuggestions() {
	isLocationInputFocused.value = false
	activeLocationSuggestionIndex.value = -1
}

function handleLocationInput() {
	locationError.value = ''
	activeLocationSuggestionIndex.value = -1

	if (
		hasSelectedLocation.value &&
		locationQuery.value.trim() !== form.venueAddress
	) {
		clearSelectedLocation()
	}

	if (locationSearchTimer) {
		clearTimeout(locationSearchTimer)
		locationSearchTimer = null
	}

	locationSearchController?.abort()
	locationSearchController = null

	const query =
		locationQuery.value.trim()

	if (query.length < 3) {
		locationSuggestions.value = []
		isSearchingLocation.value = false
		return
	}

	// No LocationIQ key: the typed text is the location (pin it with "Use my current location").
	if (!locationIqApiKey.value) {
		return
	}

	locationSearchTimer =
		setTimeout(() => {
			void searchLocations(query)
		}, locationSearchDelayMs)
}

async function searchLocations(
	query: string
) {
	locationSearchController?.abort()

	const controller =
		new AbortController()

	locationSearchController =
		controller

	isSearchingLocation.value = true
	locationError.value = ''

	try {
		const params =
			new URLSearchParams({
				key:
					locationIqApiKey.value,
				q:
					query,
				countrycodes:
					'ph',
				limit:
					'7',
				normalizecity:
					'1',
				dedupe:
					'1',
				'accept-language':
					'en',
			})

		const response =
			await fetch(
				`https://api.locationiq.com/v1/autocomplete?${params.toString()}`,
				{
					signal:
						controller.signal,
				}
			)

		if (response.status === 404) {
			locationSuggestions.value = []
			return
		}

		if (!response.ok) {
			throw new Error(
				`LocationIQ request failed with status ${response.status}.`
			)
		}

		const data =
			await response.json()

		locationSuggestions.value =
			Array.isArray(data)
				? data as LocationIqResult[]
				: []

		activeLocationSuggestionIndex.value =
			locationSuggestions.value.length
				? 0
				: -1

	} catch (error: unknown) {
		if (
			error instanceof DOMException &&
			error.name === 'AbortError'
		) {
			return
		}

		console.error(
			'LocationIQ autocomplete failed:',
			error
		)

		locationSuggestions.value = []
		locationError.value =
			'Unable to search locations. Please check your LocationIQ configuration.'

	} finally {
		if (
			locationSearchController === controller
		) {
			isSearchingLocation.value = false
			locationSearchController = null
		}
	}
}

function moveLocationSuggestion(
	direction: number
) {
	const total =
		locationSuggestions.value.length

	if (!total) {
		return
	}

	isLocationInputFocused.value = true

	const next =
		activeLocationSuggestionIndex.value +
		direction

	if (next < 0) {
		activeLocationSuggestionIndex.value =
			total - 1
		return
	}

	if (next >= total) {
		activeLocationSuggestionIndex.value = 0
		return
	}

	activeLocationSuggestionIndex.value =
		next
}

function selectActiveLocationSuggestion() {
	const index =
		activeLocationSuggestionIndex.value

	if (
		index < 0 ||
		!locationSuggestions.value[index]
	) {
		return
	}

	void selectLocation(
		locationSuggestions.value[index]
	)
}

function locationSuggestionKey(
	place: LocationIqResult,
	index: number
): string {
	return String(
		place.place_id
		|| `${place.osm_type || 'place'}:${place.osm_id || index}`
	)
}

async function selectLocation(
	place: LocationIqResult
) {
	const latitude =
		Number(place.lat)

	const longitude =
		Number(place.lon)

	if (
		!Number.isFinite(latitude) ||
		!Number.isFinite(longitude)
	) {
		locationError.value =
			'The selected location does not contain valid coordinates.'
		return
	}

	const venueName =
		String(
			place.display_place
			|| place.address?.name
			|| place.display_name
			|| 'Selected Location'
		)

	const venueAddress =
		String(
			place.display_name
			|| [
				place.display_place,
				place.display_address,
			]
				.filter(Boolean)
				.join(', ')
			|| venueName
		)

	form.location =
		venueAddress

	form.venueName =
		venueName

	form.venueAddress =
		venueAddress

	form.locationIqPlaceId =
		String(
			place.place_id
			|| `${place.osm_type || 'place'}:${place.osm_id || ''}`
		)

	form.latitude =
		latitude

	form.longitude =
		longitude

	locationQuery.value =
		venueAddress

	locationSuggestions.value = []
	activeLocationSuggestionIndex.value = -1
	isLocationInputFocused.value = false
	locationError.value = ''
	nearbyError.value = ''
	nearbyOrganizers.value = []
	showStaticMap.value = false

	await nextTick()
	await fetchNearbyOrganizers()
	await renderMap()
}

function clearSelectedLocation() {
	form.location = ''
	form.venueName = ''
	form.venueAddress = ''
	form.locationIqPlaceId = ''
	form.latitude = null
	form.longitude = null

	nearbyOrganizers.value = []
	nearbyError.value = ''

	clearMarkers()

	if (leafletMap) {
		leafletMap.remove()
		leafletMap = null
		leafletMapContainer = null
	}
}

function resetLocation() {
	locationQuery.value = ''
	locationSuggestions.value = []
	locationError.value = ''
	clearSelectedLocation()

	nextTick(() => {
		const input = document.querySelector<HTMLInputElement>(
			'input[placeholder="Search venue, city, or landmark"]'
		)

		input?.focus()
	})
}

async function refreshNearbyOrganizers() {
	await fetchNearbyOrganizers()
	await nextTick()
	await renderMap()
}

async function fetchNearbyOrganizers() {
	if (
		target.value ||
		form.latitude === null ||
		form.longitude === null
	) {
		return
	}
	isLoadingNearby.value = true
	nearbyError.value = ''
	try {
		// Same rule as the request itself: only organizers who handle this event type (their event tags).
		const eventType = form.eventType ? await standardEventTypeName(form.eventType) : undefined
		const { items } = await useMarketplace().organizers({ lat: form.latitude, lng: form.longitude, radius_km: nearbyRadiusKm.value, sort: 'distance', event_type: eventType })
		nearbyOrganizers.value = items.map(o => ({
			id: o.id as unknown as number,
			user_id: 0,
			company_name: o.display_name,
			avatar_url: o.logo_url ?? null,
			location: o.location_text,
			google_place_id: null,
			latitude: o.latitude ?? 0,
			longitude: o.longitude ?? 0,
			service_radius_km: nearbyRadiusKm.value,
			distance_km: o.distance_km ?? 0,
			reviews_avg_rating: o.rating_avg ?? 0,
			reviews_count: o.reviews_count,
			specialties: o.services,
			tags: o.services,
		}))
	} catch (error: unknown) {
		console.error(
			'Failed to retrieve nearby organizers:',
			error
		)

		nearbyOrganizers.value = []

		const apiError =
			error as {
				data?: {
					message?: string
				}
			}

		nearbyError.value =
			authMessage(error)
			|| 'Unable to retrieve nearby organizers.'

	} finally {
		isLoadingNearby.value = false
	}
}

/** The picked event type as NaSeRy's standard name ("Birthday" → "Birthday Party"), as submit() matches it. */
let standardTypes: { id: string, name: string }[] | null = null
async function standardEventTypeName(picked: string): Promise<string | undefined> {
	standardTypes ??= await useMarketplace().eventTypes().catch(() => [])
	const p = picked.toLowerCase()
	return (standardTypes.find(t => t.name.toLowerCase() === p) ?? standardTypes.find(t => t.name.toLowerCase().startsWith(p)))?.name
}

async function loadLeaflet() {
	if (leafletModule) {
		return leafletModule
	}

	if (!import.meta.client) {
		throw new Error(
			'Leaflet can only load in the browser.'
		)
	}

	const module =
		await import('leaflet')

	leafletModule =
		(module as any).default
		|| module

	return leafletModule
}

async function renderMap() {
	if (
		!mapContainer.value ||
		form.latitude === null ||
		form.longitude === null
	) {
		return
	}

	// The map tiles come from LocationIQ: no key, no map (the pinned location still works).
	if (!locationIqApiKey.value) {
		return
	}

	try {
		locationError.value = ''
		const L =
			await loadLeaflet()

		const center:
			[number, number] = [
				Number(form.latitude),
				Number(form.longitude),
			]

		if (
			leafletMap &&
			leafletMapContainer !== mapContainer.value
		) {
			leafletMap.remove()
			leafletMap = null
			leafletMapContainer = null
		}

		if (!leafletMap) {
			// Leaflet stores its instance id on the element. Clear a stale id left
			// behind by development hot reload before creating a new map instance.
			delete (mapContainer.value as HTMLElement & { _leaflet_id?: number })._leaflet_id
			leafletMap =
				L.map(
					mapContainer.value,
					{
						zoomControl:
							true,
					}
				)
					.setView(
						center,
						14
					)
			leafletMapContainer = mapContainer.value

			L.tileLayer(
				`https://{s}-tiles.locationiq.com/v3/streets/r/{z}/{x}/{y}.png?key=${encodeURIComponent(locationIqApiKey.value)}`,
				{
					maxZoom:
						18,

					attribution:
						'&copy; <a href="https://locationiq.com" target="_blank" rel="noopener noreferrer">LocationIQ</a> &copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
				}
			)
				.addTo(
					leafletMap
				)
		} else {
			leafletMap.setView(
				center,
				14
			)
		}

		clearMarkers()

		const bounds =
			L.latLngBounds([
				center,
			])

		// Venue: a teardrop pin (different shape and colour from the organizer dots).
		const venueMarker =
			L.marker(center, {
				zIndexOffset: 1000,
				title: form.venueName || 'Your venue',
				icon: L.divIcon({
					className: '',
					iconSize: [30, 40],
					iconAnchor: [15, 39],
					popupAnchor: [0, -34],
					html: '<svg width="30" height="40" viewBox="0 0 30 40" aria-hidden="true"><path d="M15 39C15 39 28 24.5 28 14.5A13 13 0 0 0 2 14.5C2 24.5 15 39 15 39Z" fill="#285F6b" stroke="#ffffff" stroke-width="2.5"/><circle cx="15" cy="14.5" r="5" fill="#ffffff"/></svg>',
				}),
			})
				.addTo(
					leafletMap
				)
				.bindPopup(
					`<div style="padding:4px"><strong>${escapeHtml(
						form.venueName || 'Selected Venue'
					)}</strong><br><span>${escapeHtml(
						form.venueAddress
					)}</span></div>`
				)

		leafletMarkers.push(
			venueMarker
		)

		// The search area: organizers inside this circle can receive the request.
		const searchArea = L.circle(center, {
			radius: nearbyRadiusKm.value * 1000,
			color: '#285F6b', weight: 1.5, dashArray: '6 6',
			fillColor: '#285F6b', fillOpacity: 0.07, interactive: false,
		}).addTo(leafletMap)
		leafletMarkers.push(searchArea)
		bounds.extend(searchArea.getBounds())

		for (
			const organizer
			of nearbyOrganizers.value
		) {
			const position:
				[number, number] = [
					Number(
						organizer.latitude
					),
					Number(
						organizer.longitude
					),
				]

			if (
				!Number.isFinite(position[0]) ||
				!Number.isFinite(position[1])
			) {
				continue
			}

			const name =
				escapeHtml(
					organizerDisplayName(
						organizer
					)
				)

			const rating =
				formatRating(
					organizer.reviews_avg_rating
				)

			const distance =
				formatDistance(
					organizer.distance_km
				)

			const marker =
				L.circleMarker(
					position,
					{
						radius:
							7,
						weight:
							2,
						color:
							'#ffffff',
						fillColor:
							'#d97706',
						fillOpacity:
							1,
					}
				)
					.addTo(
						leafletMap
					)
					.bindPopup(
						`<div style="padding:4px;min-width:180px"><strong>${name}</strong><div style="margin-top:5px">★ ${rating} (${organizer.reviews_count} reviews)</div><div style="margin-top:3px">${distance}</div></div>`
					)

			leafletMarkers.push(
				marker
			)

			bounds.extend(
				position
			)
		}

		// Show the whole search area (and any organizer just outside its edge).
		leafletMap.fitBounds(bounds, { padding: [24, 24], maxZoom: 14 })

		window.setTimeout(() => {
			leafletMap
				?.invalidateSize()
		}, 0)
		showStaticMap.value = false

	} catch (error) {
		console.error(
			'Failed to display LocationIQ map:',
			error
		)

		// Keep the venue visible if Leaflet cannot initialize (for example after
		// hot reload or a browser-specific canvas/container failure).
		showStaticMap.value = true
		locationError.value = ''
	}
}

function handleStaticMapError() {
	locationError.value = 'Unable to display the location map. Please try selecting the location again.'
}

function clearMarkers() {
	for (
		const marker
		of leafletMarkers
	) {
		marker.remove()
	}

	leafletMarkers = []
}

type OrganizerWithIdentity =
	NearbyOrganizer & {
		display_name?: string | null
		full_name?: string | null
		user?: {
			firstname?: string | null
			middlename?: string | null
			lastname?: string | null
		} | null
	}

function organizerInitials(
	organizer: NearbyOrganizer
): string {
	return organizerDisplayName(organizer)
		.split(/\s+/)
		.filter(Boolean)
		.slice(0, 2)
		.map((part) => part.charAt(0))
		.join('')
		.toUpperCase()
}

function organizerProfilePath(
	organizer: NearbyOrganizer
): string {
	return `/organizers/${organizer.slug || organizer.id}`
}

function organizerFullName(
	organizer: NearbyOrganizer
): string {
	const record =
		organizer as OrganizerWithIdentity

	const backendFullName =
		String(
			record.full_name || ''
		).trim()

	if (backendFullName) {
		return backendFullName
	}

	return [
		record.user?.firstname,
		record.user?.middlename,
		record.user?.lastname,
	]
		.filter((value) =>
			Boolean(
				String(value || '').trim()
			)
		)
		.map((value) =>
			String(value).trim()
		)
		.join(' ')
}

function organizerDisplayName(
	organizer: NearbyOrganizer
): string {
	const record =
		organizer as OrganizerWithIdentity

	const backendDisplayName =
		String(
			record.display_name || ''
		).trim()

	if (backendDisplayName) {
		return backendDisplayName
	}

	const companyName =
		String(
			organizer.company_name || ''
		).trim()

	if (companyName) {
		return companyName
	}

	const fullName =
		organizerFullName(
			organizer
		)

	if (fullName) {
		return fullName
	}

	return 'Organizer'
}

function formatRating(
	value:
		number
		| string
		| null
		| undefined
): string {
	const rating =
		Number(
			value || 0
		)

	return rating.toFixed(1)
}

function formatTime(value: string): string {
	const [hours, minutes] = value.split(':').map(Number)

	if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
		return value
	}

	return new Intl.DateTimeFormat('en-PH', {
		hour: 'numeric',
		minute: '2-digit',
	}).format(new Date(2000, 0, 1, hours, minutes))
}

function formatDistance(
	value:
		number
		| string
): string {
	const distance =
		Number(value)

	if (
		!Number.isFinite(
			distance
		)
	) {
		return ''
	}

	if (distance < 1) {
		return `${Math.round(
			distance * 1000
		)} m away`
	}

	return `${distance.toFixed(1)} km away`
}

function escapeHtml(
	value: string
): string {
	return value
		.replaceAll(
			'&',
			'&amp;'
		)
		.replaceAll(
			'<',
			'&lt;'
		)
		.replaceAll(
			'>',
			'&gt;'
		)
		.replaceAll(
			'"',
			'&quot;'
		)
		.replaceAll(
			"'",
			'&#039;'
		)
}

function requestInquirySubmission() {
	if (!isValid.value) {
		pointToFirstMissing()
		return
	}

	errorMessage.value = ''
	showSubmitConfirmation.value = true
}

function goToMyEvents() {
	showSubmissionSuccess.value = false
	navigateTo('/client/my-events')
}

// The draft made by a failed attempt is reused on retry (no pile of unsent drafts).
let draftId: string | null = null

async function submitInquiry() {
	if (!isValid.value) {
		showSubmitConfirmation.value = false
		pointToFirstMissing()
		return
	}


	if (
		!target.value &&
		(form.latitude === null ||
		form.longitude === null)
	) {
		showSubmitConfirmation.value = false
		errorMessage.value =
			'Please select a valid location.'

		return
	}

	isSubmitting.value = true
	errorMessage.value = ''

	try {
		// Event type: NaSeRy's standard list, matched by name ("Other" when there's no match).
		const types = await useMarketplace().eventTypes()
		const type = types.find(t => t.name.toLowerCase() === form.eventType.toLowerCase())
			?? types.find(t => t.name.toLowerCase().startsWith(form.eventType.toLowerCase()))
			?? types.find(t => t.name === 'Other')
		// Budget range → min / max.
		const amounts = (form.budget.match(/[\d,]+/g) ?? []).map(v => Number(v.replace(/,/g, '')))
		const budget = form.budget.startsWith('Under')
			? { budget_min: null, budget_max: amounts[0] ?? null }
			: form.budget.startsWith('Over')
				? { budget_min: amounts[0] ?? null, budget_max: null }
				: { budget_min: amounts[0] ?? null, budget_max: amounts[1] ?? null }
		const details = [
			form.title.trim() ? `Event: ${form.title.trim()}` : '',
			`Time: ${form.startTime} – ${form.endTime}`,
			form.venueName ? `Venue: ${form.venueName}` : '',
			form.details.trim(),
		].filter(Boolean).join('\n')

		const clientInquiries = useInquiries()
		const payload = {
			event_type_id: type?.id ?? null,
			requested_date: form.date,
			guest_count: Number(form.guests),
			...budget,
			location_text: form.venueAddress || form.location || locationQuery.value.trim(),
			latitude: form.latitude,
			longitude: form.longitude,
			additional_requirements: target.value?.estimate
				? `${details}
Estimate from ${target.value.display_name}${/s$/i.test(target.value.display_name) ? '’' : '’s'} quotation calculator: ${formatPeso(target.value.estimate)} for ${form.guests} guests.`
				: details,
			services: target.value?.picks.length
				? target.value.picks
				: form.services.map(name => ({ custom_service_name: name, notes: null })),
		}
		draftId = draftId
			? (await clientInquiries.update(draftId, payload)).id
			: (await clientInquiries.create(payload)).id
		// Straight to the chosen organizer, or to listed organizers within the chosen distance.
		await clientInquiries.submit(draftId, target.value
			? { mode: 'direct', organization_ids: [target.value.id] }
			: { mode: 'open', match_radius_km: nearbyRadiusKm.value })

		draftId = null
		draft.clear()
		showSubmitConfirmation.value = false
		showSubmissionSuccess.value = true

	} catch (error: unknown) {
		showSubmitConfirmation.value = false
		if (error instanceof ApiError) {
			// Point at the field the server objected to, when we have one on this form.
			const fields = Object.keys(((error.details as { fields?: Record<string, string[]> })?.fields) ?? {})
			const onForm: Record<string, string> = { services: 'services', guest_count: 'guests', requested_date: 'schedule', budget_min: 'budget', budget_max: 'budget', location_text: 'location', latitude: 'location', event_type_id: 'eventType' }
			const key = fields.map(f => onForm[f.split('.')[0]!]).find(Boolean)
			// Say what the server objected to, not just "The given data was invalid".
			const firstReason = Object.values(error.fields).flat()[0]
			errorMessage.value = error.code === 'VALIDATION_ERROR' && firstReason ? firstReason : authMessage(error)
			if (key) scrollToElement(document.getElementById(`field-${key}`))
			return
		}
		console.error(
			'Failed to submit inquiry:',
			error
		)

		const apiError =
			error as {
				data?: {
					message?: string

					errors?: Record<
						string,
						string[]
					>
				}
			}

		if (
			apiError
				?.data
				?.errors
		) {
			const firstError =
				Object
					.values(
						apiError
							.data
							.errors
					)
					.flat()
					.at(0)

			if (firstError) {
				errorMessage.value =
					String(
						firstError
					)

				return
			}
		}
		errorMessage.value =
			apiError
				?.data
				?.message
			|| 'Unable to submit your inquiry. Please try again.'

	} finally {
		isSubmitting.value = false
	}
}
</script>

<style scoped>
.pe-hero {
	background: #285f6b;
}

.pe-orb {
	position: absolute;
	top: -120px;
	right: 6%;
	height: 320px;
	width: 320px;
	border-radius: 9999px;
	background: rgba(255, 255, 255, 0.1);
	filter: blur(60px);
	pointer-events: none;
	animation: pe-drift 16s ease-in-out infinite;
}

.pe-dots {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
	-webkit-mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
}

.pe-rise {
	/* `backwards` (not `both`) so no transform lingers afterwards and open dropdowns can overlap later sections */
	animation: pe-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) backwards;
	animation-delay: var(--d, 0ms);
}

@keyframes pe-rise {
	from { opacity: 0; transform: translateY(14px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes pe-drift {
	0%, 100% { transform: translate(0, 0) scale(1); }
	50% { transform: translate(-22px, 16px) scale(1.08); }
}

@media (prefers-reduced-motion: reduce) {
	.pe-orb, .pe-rise { animation: none; }
}
</style>
