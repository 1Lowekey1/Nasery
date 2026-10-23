<template>
	<div class="mx-auto max-w-[1400px] pb-4">
		<!-- Page header -->
		<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
			<div>
				<h1 class="text-2xl font-extrabold tracking-tight text-gray-950 sm:text-[28px]">Business profile</h1>
				<p class="mt-1 text-sm text-gray-500">This is how clients find you in the directory and decide to send you an inquiry.</p>
			</div>
			<div class="flex items-center gap-2 text-sm font-semibold" aria-live="polite">
				<span v-if="isLoading" class="text-gray-400">Loading…</span>
				<span v-else-if="justSaved" class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-3 py-1.5 text-green-700 ring-1 ring-green-200">
					<IconBase name="check-circle" class="h-4 w-4" /> Changes saved
				</span>
				<span v-else-if="isDirty" class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 text-amber-800 ring-1 ring-amber-200">
					<span class="h-1.5 w-1.5 rounded-full bg-amber-500" /> Unsaved changes
				</span>
				<span v-else class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-gray-500 ring-1 ring-gray-200">
					<IconBase name="check" class="h-4 w-4 text-primary-700" /> Up to date
				</span>
			</div>
		</div>

		<p v-if="loadError" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ loadError }}</p>
		<p v-if="!isLoading && !isOwner" class="mb-6 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">Only the owner can change the business profile. You can view it here.</p>
		<p v-if="isPublished && organization && !organization.is_operational" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Your profile is published. It appears in the directory once NaSeRy approves your organization.</p>

		<!-- Identity hero -->
		<section class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white">
			<div class="relative h-32 sm:h-40" :style="{ backgroundColor: form.bannerColor }">
				<img v-if="coverUrl" :src="coverUrl" alt="" class="h-full w-full object-cover" />
				<div v-else class="bp-dots absolute inset-0" aria-hidden="true" />
				<div class="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/20 to-transparent" aria-hidden="true" />
				<button type="button" class="absolute right-4 top-4 inline-flex items-center gap-1.5 rounded-lg bg-black/30 px-3 py-1.5 text-xs font-semibold text-white backdrop-blur-sm transition hover:bg-black/45 focus-visible:outline focus-visible:outline-2 focus-visible:outline-white" @click="goTo('images')">
					<IconBase name="camera" class="h-3.5 w-3.5" /> Change cover
				</button>
			</div>

			<div class="px-5 pb-5 sm:px-7">
				<div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
					<div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-end sm:gap-5">
						<button type="button" class="group relative z-10 -mt-12 flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-2xl text-2xl font-bold text-white shadow-lg ring-4 ring-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-600 sm:-mt-14 sm:h-28 sm:w-28" :style="{ backgroundColor: form.bannerColor }" aria-label="Change logo" @click="goTo('images')">
							<img v-if="logoUrl" :src="logoUrl" alt="" class="h-full w-full bg-white object-contain p-1.5" />
							<span v-else>{{ initials || 'O' }}</span>
							<span class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100"><IconBase name="camera" class="h-6 w-6" /></span>
						</button>
						<div class="min-w-0 sm:pb-1">
							<h2 class="line-clamp-2 break-words text-xl font-extrabold tracking-tight text-gray-950 sm:text-2xl">{{ displayName }}</h2>
							<div class="mt-1.5 flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-gray-500">
								<span class="flex min-w-0 max-w-full items-center gap-1.5 sm:max-w-md" :title="form.location || undefined">
									<IconBase name="map-pin" class="h-4 w-4 shrink-0 text-gray-400" />
									<span class="truncate">{{ shortLocation || 'No address yet' }}</span>
								</span>
								<span class="flex shrink-0 items-center gap-1.5">
									<IconBase name="star" class="h-4 w-4 shrink-0 text-amber-500" />
									<template v-if="stats.reviewsCount"><span class="font-semibold text-gray-800">{{ stats.averageRating.toFixed(1) }}</span> ({{ stats.reviewsCount }} {{ stats.reviewsCount === 1 ? 'review' : 'reviews' }})</template>
									<template v-else>No reviews yet</template>
								</span>
								<span v-if="form.yearsExperience" class="flex shrink-0 items-center gap-1.5">
									<IconBase name="clock" class="h-4 w-4 shrink-0 text-gray-400" />{{ form.yearsExperience }} in business
								</span>
							</div>
						</div>
					</div>

					<div v-if="publicUrl" class="flex shrink-0 flex-wrap gap-2">
						<button type="button" class="bp-btn-secondary" :disabled="!isOwner || isPublishing" @click="togglePublished">
							<span class="h-2 w-2 rounded-full" :class="isPublished ? 'bg-green-500' : 'bg-gray-300'" />
							{{ isPublishing ? 'Saving…' : isPublished ? 'Listed · Hide' : 'Hidden · Publish' }}
						</button>
						<button type="button" class="bp-btn-secondary" @click="copyPublicLink">
							<IconBase :name="linkCopied ? 'check' : 'send'" class="h-4 w-4" :class="linkCopied ? 'text-green-600' : ''" />
							{{ linkCopied ? 'Link copied' : 'Copy link' }}
						</button>
						<a :href="publicUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
							View public page <IconBase name="arrow-right" class="h-4 w-4 -rotate-45" />
						</a>
					</div>
				</div>

				<!-- At-a-glance facts; each jumps to its section -->
				<div class="mt-5 grid grid-cols-2 overflow-hidden rounded-xl border border-gray-100 bg-gray-50/60 sm:grid-cols-4">
					<button v-for="fact in heroFacts" :key="fact.label" type="button" class="flex flex-col items-start gap-0.5 border-gray-100 px-4 py-3 text-left transition hover:bg-white focus-visible:bg-white focus-visible:outline-none [&:not(:first-child)]:border-l max-sm:[&:nth-child(3)]:border-l-0 max-sm:[&:nth-child(n+3)]:border-t" @click="goTo(fact.section)">
						<span class="text-xs font-medium text-gray-500">{{ fact.label }}</span>
						<span class="text-base font-bold tabular-nums text-gray-950">{{ fact.value }}</span>
					</button>
				</div>
			</div>
		</section>

		<!-- Mobile section switcher -->
		<nav class="-mx-1 mb-4 flex gap-2 overflow-x-auto px-1 pb-1 lg:hidden" aria-label="Profile sections">
			<button v-for="s in sections" :key="s.id" type="button" class="shrink-0 rounded-full px-3.5 py-2 text-sm font-semibold ring-1 transition" :class="activeSection === s.id ? 'bg-primary-700 text-white ring-primary-700' : 'bg-white text-gray-600 ring-gray-200'" @click="goTo(s.id)">{{ s.label }}</button>
		</nav>

		<div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[210px_minmax(0,1fr)] xl:grid-cols-[210px_minmax(0,1fr)_320px]">
			<!-- Section nav -->
			<nav class="sticky top-0 hidden lg:block" aria-label="Profile sections">
				<ul class="space-y-0.5">
					<li v-for="s in sections" :key="s.id">
						<button type="button" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600" :class="activeSection === s.id ? 'bg-white text-gray-950 shadow-sm ring-1 ring-gray-200' : 'text-gray-500 hover:bg-white/70 hover:text-gray-800'" :aria-current="activeSection === s.id ? 'true' : undefined" @click="goTo(s.id)">
							<IconBase :name="s.icon" class="h-[18px] w-[18px] shrink-0" :class="activeSection === s.id ? 'text-primary-700' : ''" />
							<span class="flex-1">{{ s.label }}</span>
							<span v-if="sectionDone[s.id]" class="h-1.5 w-1.5 rounded-full bg-primary-500" aria-label="complete" />
						</button>
					</li>
				</ul>
			</nav>

			<!-- Form -->
			<fieldset :disabled="!isOwner" class="min-w-0 space-y-6">
				<!-- Business details -->
				<section id="details" class="bp-card scroll-mt-4">
					<header class="bp-head">
						<h3 class="bp-title">Business details</h3>
						<p class="bp-sub">Your name and story, as they appear in the directory.</p>
					</header>
					<div class="grid gap-5 p-5 sm:p-6 md:grid-cols-2">
						<div>
							<label for="bp-company" class="bp-label">Brand or trade name <span class="text-red-500">*</span></label>
							<input id="bp-company" v-model="form.company" type="text" maxlength="255" placeholder="e.g. Davao Dream Events" class="bp-input" :class="errors.company ? 'bp-invalid' : ''" />
							<p v-if="errors.company" class="bp-error">{{ errors.company }}</p>
						</div>
						<div>
							<span class="bp-label">Years in business</span>
							<FormsSelect v-model="form.yearsExperience" :options="experienceRanges" placeholder="Select a range" :searchable="false" />
						</div>
						<div class="relative md:col-span-2">
							<label for="bp-address" class="bp-label">Business address <span class="text-red-500">*</span></label>
							<div class="relative">
								<IconBase name="map-pin" class="pointer-events-none absolute left-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-gray-400" />
								<input id="bp-address" v-model="form.location" type="text" maxlength="500" autocomplete="off" placeholder="Start typing your street, barangay or city" class="bp-input !pl-10 !pr-24" :class="errors.location ? 'bp-invalid' : ''" role="combobox" :aria-expanded="showSuggestions" aria-controls="bp-address-list" @input="onAddressInput" @blur="hideSuggestionsSoon" />
								<span v-if="isSearching" class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-xs text-gray-400">Searching…</span>
							</div>
							<ul v-if="showSuggestions" id="bp-address-list" role="listbox" class="absolute z-30 mt-2 max-h-64 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white py-1 shadow-xl">
								<li v-for="place in suggestions" :key="String(place.place_id)">
									<button type="button" class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-gray-50 focus:bg-gray-50 focus:outline-none" @mousedown.prevent="pickPlace(place)">
										<IconBase name="map-pin" class="mt-0.5 h-4 w-4 shrink-0 text-primary-600" />
										<span class="min-w-0">
											<span class="block text-sm font-semibold text-gray-800">{{ place.display_place || place.display_name }}</span>
											<span class="mt-0.5 block text-xs leading-relaxed text-gray-500">{{ place.display_name }}</span>
										</span>
									</button>
								</li>
							</ul>
							<p v-if="errors.location" class="bp-error">{{ errors.location }}</p>
							<p v-else class="bp-hint">Pick a suggestion to also drop your map pin, or type the address yourself.</p>
						</div>
						<div class="md:col-span-2">
							<div class="flex items-baseline justify-between">
								<label for="bp-bio" class="bp-label">About your business</label>
								<span class="text-xs tabular-nums" :class="form.bio.length > BIO_MAX * 0.95 ? 'text-amber-600' : 'text-gray-400'">{{ form.bio.length }} / {{ BIO_MAX }}</span>
							</div>
							<textarea id="bp-bio" v-model="form.bio" rows="6" :maxlength="BIO_MAX" placeholder="What events do you run, where, and what makes working with you easy? Clients read this first." class="bp-input resize-y leading-relaxed" />
							<p class="bp-hint">Aim for two or three sentences. The first line shows on your directory card.</p>
						</div>
					</div>
				</section>

				<!-- Contact -->
				<section id="contact" class="bp-card scroll-mt-4">
					<header class="bp-head">
						<h3 class="bp-title">Contact</h3>
						<p class="bp-sub">Where clients reach you once they accept a quotation.</p>
					</header>
					<div class="grid gap-5 p-5 sm:p-6 md:grid-cols-2">
						<div>
							<label for="bp-email" class="bp-label">Business e-mail <span class="text-red-500">*</span></label>
							<input id="bp-email" v-model="form.email" type="email" maxlength="255" placeholder="hello@yourbusiness.com" class="bp-input" :class="errors.email ? 'bp-invalid' : ''" />
							<p v-if="errors.email" class="bp-error">{{ errors.email }}</p>
						</div>
						<div>
							<label for="bp-phone" class="bp-label">Business mobile</label>
							<input id="bp-phone" v-model="form.phone" type="tel" maxlength="30" placeholder="0917 123 4567" class="bp-input" :class="errors.phone ? 'bp-invalid' : ''" />
							<p v-if="errors.phone" class="bp-error">{{ errors.phone }}</p>
						</div>
						<p class="-mt-2 text-xs text-gray-400 md:col-span-2">Shown on your public page so clients can reach your business.</p>
						<div class="md:col-span-2">
							<label for="bp-website" class="bp-label">Website</label>
							<input id="bp-website" v-model="form.website" type="url" maxlength="255" placeholder="https://yourbusiness.com" class="bp-input" />
						</div>
						<div>
							<label for="bp-facebook" class="bp-label">Facebook page</label>
							<input id="bp-facebook" v-model="form.facebook" type="text" maxlength="255" placeholder="facebook.com/yourpage" class="bp-input" />
						</div>
						<div>
							<label for="bp-instagram" class="bp-label">Instagram</label>
							<input id="bp-instagram" v-model="form.instagram" type="text" maxlength="255" placeholder="@yourhandle" class="bp-input" />
						</div>
					</div>
				</section>

				<!-- Map location -->
				<section id="location" class="bp-card scroll-mt-4">
					<header class="bp-head flex flex-wrap items-start justify-between gap-3">
						<div>
							<h3 class="bp-title">Map location</h3>
							<p class="bp-sub">Lets clients find you with “Near me”. Leave it empty to show your address only.</p>
						</div>
						<div class="flex gap-2">
							<button type="button" class="bp-btn-secondary" :disabled="isLocating" @click="useMyLocation">
								<IconBase name="map-pin" class="h-4 w-4" /> {{ isLocating ? 'Locating…' : 'Use my location' }}
							</button>
							<button v-if="hasPin" type="button" class="bp-btn-ghost" @click="clearPin">Remove pin</button>
						</div>
					</header>
					<div class="space-y-5 p-5 sm:p-6">
						<p v-if="locateError" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ locateError }}</p>
						<ClientOnly>
							<BusinessProfileMapPicker :latitude="form.latitude" :longitude="form.longitude" :radius-km="Number(form.serviceRadiusKm) || 1" :color="form.bannerColor" @update:position="setPin" />
							<template #fallback><div class="h-72 animate-pulse rounded-xl bg-gray-100" /></template>
						</ClientOnly>
						<div>
							<div class="flex items-baseline justify-between">
								<label for="bp-radius" class="bp-label">Service radius</label>
								<span class="text-sm font-bold tabular-nums text-gray-900">{{ form.serviceRadiusKm }} km</span>
							</div>
							<input id="bp-radius" v-model.number="form.serviceRadiusKm" type="range" min="1" max="200" step="1" class="bp-range w-full" :style="{ '--fill': `${((form.serviceRadiusKm - 1) / 199) * 100}%` }" />
							<div class="mt-1 flex justify-between text-xs text-gray-400"><span>1 km</span><span>200 km</span></div>
							<p class="bp-hint">Clients inside the circle can find you. {{ hasPin ? 'Drag the pin to fine-tune your spot.' : '' }}</p>
						</div>
					</div>
				</section>

				<!-- Cover image -->
				<section id="images" class="bp-card scroll-mt-4">
					<header class="bp-head">
						<h3 class="bp-title">Logo and cover</h3>
						<p class="bp-sub">Your logo and cover photo. Uploads save straight away.</p>
					</header>
					<div class="space-y-6 p-5 sm:p-6">
						<MediaOrganizationImages v-if="organization" :organization-id="organization.id" :logo-url="logoUrl"
							:cover-url="coverUrl" :cover-uploaded="organization.profile?.cover_uploaded" @changed="refreshOrganization" />
						<div class="border-t border-gray-100 pt-6">
							<MediaAvatarUploader />
							<p class="mt-2 text-xs text-gray-400">Your own photo, shown with your name to your team and clients.</p>
						</div>
						<div>
							<p class="bp-label">Brand colour</p>
							<p class="mb-3 text-xs text-gray-400">Used behind your cover, logo initials and map pin when there’s no photo.</p>
							<div class="flex flex-wrap gap-3" role="radiogroup" aria-label="Brand colour">
								<button v-for="color in bannerColors" :key="color" type="button" role="radio" :aria-checked="form.bannerColor === color" :aria-label="`Colour ${color}`" class="flex h-10 w-10 items-center justify-center rounded-full ring-2 ring-offset-2 transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary-600" :class="form.bannerColor === color ? 'ring-gray-900' : 'ring-transparent hover:ring-gray-300'" :style="{ backgroundColor: color }" @click="form.bannerColor = color">
									<IconBase v-if="form.bannerColor === color" name="check" class="h-4 w-4 text-white" />
								</button>
							</div>
						</div>
					</div>
				</section>

				<!-- Services -->
				<section id="services" class="bp-card scroll-mt-4">
					<header class="bp-head">
						<h3 class="bp-title">Services</h3>
						<p class="bp-sub">What you actually provide. These show on your directory card and help clients filter.</p>
					</header>
					<div class="space-y-5 p-5 sm:p-6">
						<div class="flex flex-wrap gap-2">
							<button v-for="service in serviceChoices" :key="service" type="button" class="bp-chip" :class="form.specialties.includes(service) ? 'bp-chip-on' : ''" :aria-pressed="form.specialties.includes(service)" @click="toggle(form.specialties, service)">
								<IconBase v-if="form.specialties.includes(service)" name="check" class="h-3.5 w-3.5" />{{ service }}
							</button>
						</div>
						<form class="relative flex max-w-md gap-2" @submit.prevent="addCustomService">
							<label for="bp-new-service" class="sr-only">Add a service</label>
							<input id="bp-new-service" v-model="newService" type="text" maxlength="60" placeholder="Add a service not listed, e.g. Lights and sounds" class="bp-input" />
							<button type="submit" class="bp-btn-secondary shrink-0" :disabled="!newService.trim()"><IconBase name="plus" class="h-4 w-4" /> Add</button>
						</form>

						<div class="border-t border-gray-100 pt-5">
							<p class="bp-label">Services with a starting price</p>
							<p class="mb-3 text-xs text-gray-400">Shown on your public page; quotation lines can link to them. Saved straight away.</p>
							<ul v-if="catalog.length" class="divide-y divide-gray-100 rounded-xl ring-1 ring-gray-200">
								<li v-for="s in catalog" :key="s.id" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
									<span class="min-w-0 truncate font-semibold text-gray-900">{{ s.name }}<span v-if="s.base_price" class="font-normal text-gray-500"> · from {{ money(Number(s.base_price)) }}</span></span>
									<button type="button" class="bp-btn-ghost !px-2 !py-1 text-xs text-gray-500 hover:text-red-600" @click="removeCatalogService(s.id)">Remove</button>
								</li>
							</ul>
							<form class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_150px_auto]" @submit.prevent="addCatalogService">
								<input v-model="newCatalog.custom_name" type="text" maxlength="120" placeholder="e.g. Wedding coordination" class="bp-input" aria-label="Service name" />
								<input v-model="newCatalog.base_price" type="text" inputmode="decimal" placeholder="From (₱)" class="bp-input" aria-label="Starting price" />
								<button type="submit" class="bp-btn-secondary justify-center" :disabled="!newCatalog.custom_name.trim()"><IconBase name="plus" class="h-4 w-4" /> Add</button>
							</form>
							<p v-if="catalogError" class="bp-error">{{ catalogError }}</p>
						</div>
					</div>
				</section>

				<!-- Price list -->
				<section id="pricing" class="bp-card scroll-mt-4">
					<header class="bp-head flex flex-wrap items-start justify-between gap-3">
						<div>
							<h3 class="bp-title">Price list</h3>
							<p class="bp-sub">Your rates, used to build quotations in a few taps.</p>
						</div>
						<NuxtLink to="/organizer/price-list" class="bp-btn-secondary">{{ priceItems.length ? 'Manage price list' : 'Add prices' }}</NuxtLink>
					</header>
					<div class="p-5 sm:p-6">
						<p v-if="priceError" class="text-sm text-red-600">{{ priceError }}</p>
						<div v-else-if="!priceItems.length" class="rounded-xl border border-dashed border-gray-300 px-5 py-8 text-center">
							<p class="text-sm font-semibold text-gray-800">No prices yet</p>
							<p class="mt-1 text-sm text-gray-500">Add what you charge for, like coordination or styling, so you can quote faster.</p>
						</div>
						<template v-else>
							<ul class="divide-y divide-gray-100 rounded-xl ring-1 ring-gray-200">
								<li v-for="item in priceItems.slice(0, 5)" :key="item.id" class="flex items-center justify-between gap-4 px-4 py-3">
									<div class="min-w-0">
										<p class="truncate text-sm font-semibold text-gray-900">{{ item.name }}</p>
										<p v-if="item.service" class="truncate text-xs text-gray-500">{{ item.service }}</p>
									</div>
									<p class="shrink-0 text-right text-sm font-bold tabular-nums text-gray-950">{{ money(item.price) }}<span class="ml-1 text-xs font-medium text-gray-400">{{ item.unit === 'per_guest' ? 'per guest' : 'flat' }}</span></p>
								</li>
							</ul>
							<p v-if="priceItems.length > 5" class="mt-3 text-xs text-gray-500">And {{ priceItems.length - 5 }} more on your price list.</p>
						</template>
					</div>
				</section>

				<!-- Event types -->
				<section id="event-types" class="bp-card scroll-mt-4">
					<header class="bp-head">
						<h3 class="bp-title">Event types</h3>
						<p class="bp-sub">You’ll be matched with inquiries for the event types you pick.</p>
					</header>
					<div class="p-5 sm:p-6">
						<p v-if="!eventTypeOptions.length" class="text-sm text-gray-500">No event types are available yet.</p>
						<div v-else class="flex flex-wrap gap-2">
							<button v-for="tag in eventTypeOptions" :key="tag" type="button" class="bp-chip" :class="form.tags.includes(tag) ? 'bp-chip-on' : ''" :aria-pressed="form.tags.includes(tag)" @click="toggle(form.tags, tag)">
								<IconBase v-if="form.tags.includes(tag)" name="check" class="h-3.5 w-3.5" />{{ tag }}
							</button>
						</div>

						<div class="mt-6 border-t border-gray-100 pt-5">
							<p class="bp-label">Your own event types</p>
							<p class="mb-3 text-xs text-gray-400">For anything else you organize; you can pick them when you create an event. Saved straight away.</p>
							<div class="flex flex-wrap gap-2">
								<span v-for="type in ownTypes" :key="type.id" class="bp-chip bp-chip-on">
									{{ type.name }}
									<button type="button" class="ml-0.5 font-bold" :aria-label="`Remove ${type.name}`" @click="removeOwnType(type.id)">×</button>
								</span>
								<span v-if="!ownTypes.length" class="text-sm text-gray-400">None yet.</span>
							</div>
							<form class="mt-3 flex max-w-md gap-2" @submit.prevent="addOwnType">
								<input v-model="newOwnType" type="text" maxlength="80" placeholder="e.g. Product launch" class="bp-input" aria-label="New event type" />
								<button type="submit" class="bp-btn-secondary shrink-0" :disabled="!newOwnType.trim()"><IconBase name="plus" class="h-4 w-4" /> Add</button>
							</form>
						</div>
					</div>
				</section>
			</fieldset>

			<!-- Right rail -->
			<aside class="space-y-6 lg:col-start-2 xl:sticky xl:top-0 xl:col-start-auto">
				<!-- Profile strength -->
				<section class="bp-card p-5">
					<div class="flex items-baseline justify-between">
						<h3 class="text-sm font-bold text-gray-950">Profile strength</h3>
						<span class="text-sm font-bold tabular-nums" :class="strength === 100 ? 'text-green-700' : 'text-gray-900'">{{ doneCount }} of {{ checklist.length }}</span>
					</div>
					<div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" :aria-valuenow="strength" aria-valuemin="0" aria-valuemax="100" aria-label="Profile strength">
						<div class="h-full rounded-full transition-all duration-500" :class="strength === 100 ? 'bg-green-600' : 'bg-primary-700'" :style="{ width: `${strength}%` }" />
					</div>
					<p class="mt-2 text-xs text-gray-500">{{ strength === 100 ? 'Everything clients look for is filled in.' : 'Complete profiles get more inquiries. Next up:' }}</p>
					<ul v-if="strength < 100" class="mt-3 space-y-1">
						<li v-for="item in checklist.filter(c => !c.done).slice(0, 4)" :key="item.label">
							<button type="button" class="flex w-full items-center gap-2.5 rounded-lg px-2 py-1.5 text-left text-sm text-gray-700 transition hover:bg-gray-50 hover:text-primary-800" @click="goTo(item.section)">
								<span class="h-4 w-4 shrink-0 rounded-full border-2 border-gray-300" aria-hidden="true" />
								{{ item.label }}
							</button>
						</li>
					</ul>
				</section>

				<!-- Live directory preview -->
				<section>
					<h3 class="mb-2 px-1 text-sm font-bold text-gray-950">How clients see you</h3>
					<article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm" aria-label="Directory card preview">
						<div class="relative h-24" :style="{ backgroundColor: form.bannerColor }">
							<img v-if="coverUrl" :src="coverUrl" alt="" class="h-full w-full object-cover" />
							<div v-else class="bp-dots absolute inset-0" aria-hidden="true" />
						</div>
						<div class="p-5">
							<div class="mb-3 flex items-center gap-3">
								<span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl text-sm font-bold text-white" :style="{ backgroundColor: form.bannerColor }">
									<img v-if="logoUrl" :src="logoUrl" alt="" class="h-full w-full bg-white object-contain p-1" />
									<span v-else>{{ initials || 'O' }}</span>
								</span>
								<div class="min-w-0">
									<p class="truncate font-bold text-gray-950">{{ displayName }}</p>
									<p class="truncate text-xs text-gray-500">{{ form.location || 'Location not added' }}</p>
								</div>
							</div>
							<p class="line-clamp-3 text-sm leading-relaxed text-gray-600">{{ form.bio.trim() || 'Add an “About” description so clients know what you do.' }}</p>
							<div v-if="form.specialties.length" class="mt-3 flex flex-wrap gap-1.5">
								<span v-for="tag in form.specialties.slice(0, 4)" :key="tag" class="rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">{{ tag }}</span>
								<span v-if="form.specialties.length > 4" class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">+{{ form.specialties.length - 4 }}</span>
							</div>
						</div>
					</article>
					<p class="mt-2 px-1 text-xs text-gray-400">Updates as you type. Save to publish.</p>
				</section>
			</aside>
		</div>

		<!-- Save bar -->
		<Transition name="bp-bar">
			<div v-if="isDirty || saveError" class="sticky bottom-0 z-40 mt-6">
				<div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-gray-200 bg-white/95 px-5 py-3.5 shadow-[0_-8px_30px_-12px_rgba(16,63,77,0.25)] backdrop-blur">
					<p class="text-sm" :class="saveError ? 'font-semibold text-red-700' : 'text-gray-600'" role="status">{{ saveError || 'You have unsaved changes.' }}</p>
					<div class="flex gap-2">
						<button type="button" class="bp-btn-ghost" :disabled="isSaving || !isDirty" @click="discard">Discard</button>
						<button type="button" class="inline-flex items-center gap-2 rounded-xl bg-primary-700 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 disabled:cursor-not-allowed disabled:opacity-60" :disabled="isSaving || !isDirty" @click="save">
							{{ isSaving ? 'Saving…' : 'Save changes' }}
						</button>
					</div>
				</div>
			</div>
		</Transition>
	</div>
</template>

<script setup lang="ts">
// The organizer's business profile (directory listing): the redesign's single
// page for what used to be the profile panel and the old Business Profile page.
// Profile fields are saved together; uploads, catalog services and own event
// types save straight away (their own endpoints).
import type { OfferedService, OrganizationProfile } from '~/composables/useOrganization'
import type { PriceOption } from '~/composables/useQuotations'

definePageMeta({ layout: 'dashboard', middleware: ['organizer'] })

const { fullName } = useAuth()
const config = useRuntimeConfig()
const api = useApi()
const orgApi = useOrganization()
const organization = computed(() => orgApi.organization.value)
const isOwner = computed(() => Boolean(organization.value?.my_membership?.is_owner))

const BIO_MAX = 2000

const sections = [
	{ id: 'details', label: 'Business details', icon: 'briefcase' },
	{ id: 'contact', label: 'Contact', icon: 'phone' },
	{ id: 'location', label: 'Map location', icon: 'map-pin' },
	{ id: 'images', label: 'Logo and cover', icon: 'camera' },
	{ id: 'services', label: 'Services', icon: 'tag' },
	{ id: 'pricing', label: 'Price list', icon: 'peso' },
	{ id: 'event-types', label: 'Event types', icon: 'calendar' },
] as const
type SectionId = typeof sections[number]['id']

const experienceRanges = ['Under 1 year', '1–3 years', '3–5 years', '5–10 years', '10+ years']
const presetServices = ['Full Event Coordination', 'Catering Services', 'Audio-Visual & Lighting', 'Photography & Videography', 'Venue Sourcing', 'Decor & Styling', 'Entertainment Booking']
const bannerColors = ['#285F6b', '#7c3aed', '#be123c', '#b45309', '#15803d', '#1d4ed8']

function blank() {
	return {
		company: '',
		yearsExperience: '' as string | null,
		location: '',
		placeId: '',
		latitude: null as number | null,
		longitude: null as number | null,
		serviceRadiusKm: 25,
		bio: '',
		tags: [] as string[],
		specialties: [] as string[],
		email: '',
		phone: '',
		website: '',
		facebook: '',
		instagram: '',
		bannerColor: '#285F6b',
	}
}

const form = reactive(blank())
const savedSnapshot = ref(JSON.stringify(blank()))
const isDirty = computed(() => JSON.stringify(form) !== savedSnapshot.value)
let savedPhone = ''

const stats = reactive({ averageRating: 0, reviewsCount: 0 })
const isLoading = ref(true)
const loadError = ref('')
const isSaving = ref(false)
const saveError = ref('')
const justSaved = ref(false)
const errors = reactive<{ company?: string, location?: string, email?: string, phone?: string }>({})

const priceItems = ref<{ id: string, service: string | null, name: string, price: number, unit: 'flat' | 'per_guest' }[]>([])
const priceError = ref('')
const eventTypeOptions = ref<string[]>([])

// ---------- Derived display values ----------
const logoUrl = computed(() => organization.value?.profile?.logo_url ?? null)
const coverUrl = computed(() => organization.value?.profile?.cover_url ?? null)
const isPublished = computed(() => Boolean(organization.value?.profile?.is_published))
const displayName = computed(() => form.company.trim() || organization.value?.display_name || fullName.value || 'Your business')
const initials = computed(() => {
	const words = displayName.value.split(/\s+/).filter(Boolean)
	return ((words[0]?.[0] ?? '') + (words.length > 1 ? words[words.length - 1]?.[0] ?? '' : '')).toUpperCase()
})
const publicUrl = computed(() => organization.value?.slug ? `/organizers/${organization.value.slug}` : '')
const hasPin = computed(() => form.latitude !== null && form.longitude !== null)
// Hero shows the first two parts of the address ("Mandug, Davao City"); the full address is in the tooltip.
const shortLocation = computed(() => form.location.split(',').map(p => p.trim()).filter(Boolean).slice(0, 2).join(', '))
const heroFacts = computed(() => [
	{ label: 'Services', value: form.specialties.length, section: 'services' as SectionId },
	{ label: 'Event types', value: form.tags.length, section: 'event-types' as SectionId },
	{ label: 'Price list items', value: priceItems.value.length, section: 'pricing' as SectionId },
	{ label: 'Service area', value: hasPin.value ? `${form.serviceRadiusKm} km` : 'No pin yet', section: 'location' as SectionId },
])
const linkCopied = ref(false)
async function copyPublicLink() {
	try {
		await navigator.clipboard.writeText(`${window.location.origin}${publicUrl.value}`)
		linkCopied.value = true
		setTimeout(() => { linkCopied.value = false }, 2000)
	} catch { window.open(publicUrl.value, '_blank', 'noopener') }
}
const serviceChoices = computed(() => [...new Set([...presetServices, ...form.specialties])])
function money(value: number) {
	return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: Number.isInteger(Number(value)) ? 0 : 2 }).format(Number(value))
}

const checklist = computed(() => [
	{ label: 'Add your brand name', section: 'details' as SectionId, done: !!form.company.trim() },
	{ label: 'Add your business address', section: 'details' as SectionId, done: !!form.location.trim() },
	{ label: 'Write a short “About” (80+ characters)', section: 'details' as SectionId, done: form.bio.trim().length >= 80 },
	{ label: 'Add a website or social link', section: 'contact' as SectionId, done: !!(form.website || form.facebook || form.instagram) },
	{ label: 'Drop your map pin', section: 'location' as SectionId, done: hasPin.value },
	{ label: 'Upload your logo', section: 'images' as SectionId, done: !!logoUrl.value },
	{ label: 'Upload a cover photo', section: 'images' as SectionId, done: !!coverUrl.value },
	{ label: 'Pick the services you offer', section: 'services' as SectionId, done: form.specialties.length > 0 },
	{ label: 'Add prices to your price list', section: 'pricing' as SectionId, done: priceItems.value.length > 0 },
	{ label: 'Choose your event types', section: 'event-types' as SectionId, done: form.tags.length > 0 },
])
const doneCount = computed(() => checklist.value.filter(c => c.done).length)
const strength = computed(() => Math.round((doneCount.value / checklist.value.length) * 100))
const sectionDone = computed(() => {
	const result: Record<string, boolean> = {}
	for (const s of sections) {
		const items = checklist.value.filter(c => c.section === s.id)
		result[s.id] = items.length > 0 && items.every(c => c.done)
	}
	return result
})

// ---------- Loading ----------
function fill(p: Partial<OrganizationProfile>) {
	Object.assign(form, {
		company: p.business_name ?? organization.value?.display_name ?? '',
		yearsExperience: p.years_experience ?? '',
		location: p.location_text ?? '',
		placeId: '',
		latitude: p.latitude !== null && p.latitude !== undefined ? Number(p.latitude) : null,
		longitude: p.longitude !== null && p.longitude !== undefined ? Number(p.longitude) : null,
		serviceRadiusKm: p.service_radius_km ? Number(p.service_radius_km) : 25,
		bio: p.description ?? '',
		tags: [...(p.event_tags ?? [])],
		specialties: [...(p.specialties ?? [])],
		email: p.contact_email ?? '',
		phone: p.contact_phone ?? '',
		website: p.website ?? '',
		facebook: p.facebook ?? '',
		instagram: p.instagram ?? '',
		bannerColor: p.banner_color || '#285F6b',
	})
	savedPhone = form.phone
	savedSnapshot.value = JSON.stringify(form)
}

async function load() {
	isLoading.value = true
	loadError.value = ''
	try {
		const org = await orgApi.fetchOrganization(true)
		if (!org) throw new Error('Set up your organizer workspace first.')
		fill((await api.get<OrganizationProfile>(`/organizations/${org.id}/profile`)).data)
		void loadRating(org.slug)
	} catch (error) {
		loadError.value = authMessage(error) || 'Your profile couldn’t be loaded. Refresh the page to try again.'
	} finally {
		isLoading.value = false
	}
}

/** Rating from the public listing (only once the profile is listed). */
async function loadRating(slug: string) {
	try {
		const profile = await useMarketplace().organizer(slug)
		stats.averageRating = Number(profile.rating.average ?? 0)
		stats.reviewsCount = profile.rating.count
	} catch { /* not listed yet: no rating */ }
}

async function loadPriceItems() {
	try {
		const org = await orgApi.fetchOrganization()
		if (!org) return
		const [options, services] = await Promise.all([usePriceOptions().list(org.id), orgApi.services()])
		catalog.value = services
		const serviceName = (id: string | null) => (id ? services.find(s => s.id === id)?.name ?? null : null)
		priceItems.value = options.filter((o: PriceOption) => o.is_active !== false).map((o: PriceOption) => ({
			id: o.id, service: serviceName(o.organization_service_id), name: o.name, price: Number(o.price), unit: o.pricing_unit === 'per_guest' ? 'per_guest' : 'flat',
		}))
	} catch (error) {
		priceError.value = authMessage(error) || 'Your price list couldn’t be loaded.'
	}
}

async function loadEventTypes() {
	try {
		eventTypeOptions.value = (await useMarketplace().eventTypes()).map(type => type.name)
		const org = await orgApi.fetchOrganization()
		if (org) ownTypes.value = (await api.get<{ id: string, name: string, is_platform_default: boolean }[]>(`/organizations/${org.id}/event-types`)).data.filter(t => !t.is_platform_default)
	} catch {
		eventTypeOptions.value = []
	}
}

async function refreshOrganization() {
	await orgApi.fetchOrganization(true)
}

// ---------- Directory listing ----------
const isPublishing = ref(false)
async function togglePublished() {
	if (!organization.value) return
	isPublishing.value = true
	saveError.value = ''
	try {
		await orgApi.updateProfile({ is_published: !isPublished.value })
		await refreshOrganization()
	} catch (error) {
		saveError.value = authMessage(error) || 'The listing wasn’t changed. Try again.'
	} finally {
		isPublishing.value = false
	}
}

// ---------- Catalog services with a starting price (saved straight away) ----------
const catalog = ref<OfferedService[]>([])
const newCatalog = reactive({ custom_name: '', base_price: '' })
const catalogError = ref('')
async function addCatalogService() {
	catalogError.value = ''
	try {
		catalog.value.push(await orgApi.addService({ custom_name: newCatalog.custom_name.trim(), base_price: newCatalog.base_price || null } as never))
		Object.assign(newCatalog, { custom_name: '', base_price: '' })
	} catch (error) {
		catalogError.value = Object.values(fieldErrors(error))[0] ?? authMessage(error)
	}
}
async function removeCatalogService(id: string) {
	catalogError.value = ''
	try {
		await orgApi.removeService(id)
		catalog.value = catalog.value.filter(s => s.id !== id)
	} catch (error) {
		catalogError.value = authMessage(error)
	}
}

// ---------- The organizer's own event types (saved straight away) ----------
const ownTypes = ref<{ id: string, name: string }[]>([])
const newOwnType = ref('')
async function addOwnType() {
	if (!organization.value || !newOwnType.value.trim()) return
	try {
		ownTypes.value.push((await api.post<{ id: string, name: string }>(`/organizations/${organization.value.id}/event-types`, { name: newOwnType.value.trim() })).data)
		newOwnType.value = ''
	} catch (error) {
		saveError.value = Object.values(fieldErrors(error))[0] ?? authMessage(error)
	}
}
async function removeOwnType(id: string) {
	if (!organization.value) return
	try {
		await api.delete(`/organizations/${organization.value.id}/event-types/${id}`)
		ownTypes.value = ownTypes.value.filter(t => t.id !== id)
	} catch (error) {
		saveError.value = authMessage(error)
	}
}

// ---------- Address search (LocationIQ) ----------
interface Place { place_id: string | number, display_name: string, display_place?: string, lat: string, lon: string }
const suggestions = ref<Place[]>([])
const isSearching = ref(false)
const suggestionsOpen = ref(false)
const showSuggestions = computed(() => suggestionsOpen.value && suggestions.value.length > 0)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let searchRequest = 0
const locationIqKey = computed(() => String(config.public.locationIqApiKey || ''))

function onAddressInput() {
	errors.location = undefined
	form.placeId = ''
	if (searchTimer) clearTimeout(searchTimer)
	const query = form.location.trim()
	if (query.length < 3 || !locationIqKey.value) { suggestions.value = []; return }
	searchTimer = setTimeout(() => searchPlaces(query), 350)
}

async function searchPlaces(query: string) {
	const id = ++searchRequest
	isSearching.value = true
	try {
		const results = await $fetch<Place[]>('https://api.locationiq.com/v1/autocomplete', { query: { key: locationIqKey.value, q: query, limit: 6, countrycodes: 'ph', normalizecity: 1 } })
		if (id !== searchRequest) return
		suggestions.value = Array.isArray(results) ? results : []
		suggestionsOpen.value = true
	} catch {
		if (id === searchRequest) suggestions.value = []
	} finally {
		if (id === searchRequest) isSearching.value = false
	}
}

function pickPlace(place: Place) {
	form.location = place.display_name
	form.placeId = String(place.place_id)
	setPin({ latitude: Number(place.lat), longitude: Number(place.lon) })
	suggestions.value = []
	suggestionsOpen.value = false
}
function hideSuggestionsSoon() { setTimeout(() => { suggestionsOpen.value = false }, 120) }

// ---------- Map pin ----------
const isLocating = ref(false)
const locateError = ref('')
function setPin(position: { latitude: number, longitude: number }) {
	form.latitude = Number(position.latitude.toFixed(6))
	form.longitude = Number(position.longitude.toFixed(6))
	locateError.value = ''
}
function clearPin() { form.latitude = null; form.longitude = null }

function useMyLocation() {
	if (!navigator.geolocation) { locateError.value = 'Your browser can’t share your location. Click the map to drop your pin instead.'; return }
	isLocating.value = true
	locateError.value = ''
	navigator.geolocation.getCurrentPosition(async (pos) => {
		setPin({ latitude: pos.coords.latitude, longitude: pos.coords.longitude })
		isLocating.value = false
		if (!form.location.trim() && locationIqKey.value) {
			try {
				const place = await $fetch<{ display_name?: string }>('https://us1.locationiq.com/v1/reverse', { query: { key: locationIqKey.value, lat: pos.coords.latitude, lon: pos.coords.longitude, format: 'json' } })
				if (place.display_name && !form.location.trim()) form.location = place.display_name
			} catch { /* address stays empty; the pin is still set */ }
		}
	}, (err) => {
		isLocating.value = false
		locateError.value = err.code === err.PERMISSION_DENIED
			? 'Location access is blocked. Allow it in your browser, or click the map to drop your pin.'
			: 'Your location couldn’t be found. Click the map to drop your pin instead.'
	}, { enableHighAccuracy: true, timeout: 10000 })
}

// ---------- Chips ----------
function toggle(list: string[], value: string) {
	const i = list.indexOf(value)
	if (i === -1) list.push(value)
	else list.splice(i, 1)
}
const newService = ref('')
function addCustomService() {
	const value = newService.value.trim().replace(/\s+/g, ' ')
	if (!value) return
	const existing = serviceChoices.value.find(s => s.toLowerCase() === value.toLowerCase())
	if (existing) { if (!form.specialties.includes(existing)) form.specialties.push(existing) }
	else form.specialties.push(value)
	newService.value = ''
}

// ---------- Save / discard ----------
function validate() {
	errors.company = form.company.trim() ? undefined : 'Add the name clients know you by.'
	errors.location = form.location.trim() ? undefined : 'Add your business address.'
	errors.email = form.email.trim() ? undefined : 'Add the e-mail clients can reach your business at.'
	errors.phone = undefined
	if (errors.company || errors.location) {
		goTo('details')
		saveError.value = 'Fill in the required fields in Business details.'
		return false
	}
	if (errors.email) {
		goTo('contact')
		saveError.value = 'Add your business e-mail in Contact.'
		return false
	}
	return true
}

async function save() {
	saveError.value = ''
	if (!validate()) return
	isSaving.value = true
	try {
		const phone = form.phone.trim()
		await orgApi.updateProfile({
			business_name: form.company.trim(),
			years_experience: form.yearsExperience || null,
			location_text: form.location.trim(),
			latitude: form.latitude,
			longitude: form.longitude,
			service_radius_km: Math.min(200, Math.max(1, Math.round(Number(form.serviceRadiusKm) || 25))),
			description: form.bio.trim() || null,
			event_tags: form.tags,
			specialties: form.specialties,
			contact_email: form.email.trim(),
			// Only when edited: older profiles may hold a landline the mobile-number rule would reject.
			...(phone !== savedPhone ? { contact_phone: phone || null } : {}),
			website: form.website.trim() || null,
			facebook: form.facebook.trim() || null,
			instagram: form.instagram.trim() || null,
			banner_color: form.bannerColor || null,
		} as never)
		savedPhone = phone
		savedSnapshot.value = JSON.stringify(form)
		justSaved.value = true
		setTimeout(() => { justSaved.value = false }, 2500)
		await refreshOrganization()
		window.dispatchEvent(new CustomEvent('organizer-profile:updated'))
	} catch (error) {
		const fields = fieldErrors(error)
		errors.company = fields.business_name
		errors.location = fields.location_text
		errors.email = fields.contact_email
		errors.phone = fields.contact_phone
		saveError.value = Object.values(fields)[0] ?? (authMessage(error) || 'Your changes weren’t saved. Check your connection and try again.')
	} finally {
		isSaving.value = false
	}
}

function discard() {
	Object.assign(form, JSON.parse(savedSnapshot.value))
	errors.company = undefined
	errors.location = undefined
	errors.email = undefined
	errors.phone = undefined
	saveError.value = ''
}

// ---------- Section navigation ----------
const activeSection = ref<SectionId>('details')
let observer: IntersectionObserver | null = null

function goTo(id: SectionId) {
	activeSection.value = id
	document.getElementById(id)?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' })
}

function observeSections() {
	const root = document.querySelector('main')
	observer = new IntersectionObserver((entries) => {
		const visible = entries.filter(e => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
		if (visible[0]) activeSection.value = visible[0].target.id as SectionId
	}, { root, rootMargin: '0px 0px -65% 0px', threshold: 0 })
	for (const s of sections) {
		const el = document.getElementById(s.id)
		if (el) observer.observe(el)
	}
}

// ---------- Unsaved-changes guard ----------
function beforeUnload(event: BeforeUnloadEvent) {
	if (isDirty.value) { event.preventDefault(); event.returnValue = '' }
}
onBeforeRouteLeave(async () => {
	if (isDirty.value && !(await confirmDialog('Leave this page? Your unsaved profile changes will be lost.', { confirmLabel: 'Leave page' }))) return false
})

onMounted(() => {
	load()
	loadPriceItems()
	loadEventTypes()
	nextTick(observeSections)
	window.addEventListener('beforeunload', beforeUnload)
})
onBeforeUnmount(() => {
	observer?.disconnect()
	window.removeEventListener('beforeunload', beforeUnload)
})
</script>


<style scoped>
.bp-card { @apply overflow-hidden rounded-2xl border border-gray-200 bg-white; }
.bp-head { @apply border-b border-gray-100 px-5 py-4 sm:px-6; }
.bp-title { @apply text-base font-bold text-gray-950; }
.bp-sub { @apply mt-0.5 text-sm text-gray-500; }
.bp-label { @apply mb-1.5 block text-sm font-semibold text-gray-800; }
.bp-hint { @apply mt-1.5 text-xs text-gray-400; }
.bp-error { @apply mt-1.5 text-xs font-medium text-red-600; }
.bp-input { @apply w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10; }
.bp-invalid { @apply border-red-300 focus:border-red-400 focus:ring-red-500/10; }
.bp-readonly { @apply flex items-center gap-2 rounded-xl bg-gray-50 px-3.5 py-2.5 text-sm text-gray-700 ring-1 ring-inset ring-gray-100; }
.bp-chip { @apply inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-600 transition hover:border-gray-300 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600; }
.bp-chip-on { @apply border-primary-700 bg-primary-700 text-white hover:border-primary-800 hover:bg-primary-800 hover:text-white; }
.bp-btn-secondary { @apply inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600 disabled:cursor-not-allowed disabled:opacity-50; }
.bp-btn-ghost { @apply inline-flex items-center rounded-xl px-3.5 py-2 text-sm font-semibold text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 disabled:cursor-not-allowed disabled:opacity-50; }

/* Fine dot grid used when there is no cover photo. */
.bp-dots {
	background-image: radial-gradient(rgba(255, 255, 255, 0.22) 1px, transparent 1.2px);
	background-size: 14px 14px;
	mask-image: linear-gradient(100deg, transparent 0%, black 45%, black 100%);
}

.bp-range { appearance: none; height: 6px; border-radius: 9999px; background: linear-gradient(to right, #285F6b var(--fill), #e5e7eb var(--fill)); }
.bp-range::-webkit-slider-thumb { appearance: none; width: 20px; height: 20px; border-radius: 9999px; background: white; border: 2px solid #285F6b; box-shadow: 0 1px 3px rgba(16, 63, 77, 0.3); cursor: pointer; }
.bp-range::-moz-range-thumb { width: 18px; height: 18px; border-radius: 9999px; background: white; border: 2px solid #285F6b; cursor: pointer; }
.bp-range:focus-visible { outline: 2px solid #346f7a; outline-offset: 4px; }

.bp-bar-enter-active, .bp-bar-leave-active { transition: transform 0.22s ease, opacity 0.22s ease; }
.bp-bar-enter-from, .bp-bar-leave-to { transform: translateY(12px); opacity: 0; }
@media (prefers-reduced-motion: reduce) {
	.bp-bar-enter-active, .bp-bar-leave-active { transition: none; }
}
</style>
