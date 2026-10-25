<template>
	<div class="space-y-6">
		<div v-if="isLoading"
			class="rounded-2xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
			Loading event details...
		</div>

		<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
			{{ errorMessage }}
		</div>

		<template v-else>
			<section v-if="event" class="relative overflow-hidden rounded-3xl bg-primary-800 px-5 py-5 text-white sm:px-8 sm:py-7">
				<img v-if="event.cover_url" :src="event.cover_url" alt="" class="pointer-events-none absolute inset-0 h-full w-full object-cover opacity-25">
				<div class="ed-dots" aria-hidden="true" />
				<div class="relative flex flex-wrap items-end justify-between gap-5">
					<div class="min-w-0">
						<p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/80 ring-1 ring-white/15">
							<span class="h-1.5 w-1.5 rounded-full" :class="isEditMode ? 'animate-pulse bg-amber-300' : 'bg-green-400'" />{{ isEditMode ? 'Editing event' : 'Event details' }}
						</p>
						<h2 class="mt-3 line-clamp-2 break-words text-2xl font-extrabold tracking-tight sm:text-3xl">{{ isEditMode && editForm.name.trim() ? editForm.name : event.name }}</h2>
						<div class="mt-2 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-sm text-white/70">
							<span class="flex items-center gap-1.5"><IconBase name="calendar" class="h-4 w-4" />{{ formatDate(event.event_date) }}</span>
							<span class="flex min-w-0 items-center gap-1.5" :title="event.location || undefined"><IconBase name="map-pin" class="h-4 w-4 shrink-0" /><span class="max-w-[420px] truncate">{{ event.location || 'No venue yet' }}</span></span>
							<span class="flex items-center gap-1.5"><IconBase name="users" class="h-4 w-4" />{{ event.expected_guests ?? 0 }} guests</span>
						</div>
					</div>
					<div class="flex flex-wrap items-center gap-2">
						<span class="rounded-full px-3 py-1 text-xs font-bold" :class="event.status === 'published' ? 'bg-green-400/20 text-green-100 ring-1 ring-green-300/40' : 'bg-white/10 text-white/80 ring-1 ring-white/20'">{{ formatStatus(event.status) }}</span>
						<button v-if="!isEditMode && event.id && !isLocked" type="button" class="inline-flex h-10 items-center gap-2 rounded-xl bg-white px-4 text-sm font-bold text-primary-800 shadow-sm transition hover:bg-primary-25" @click="enterEditMode"><IconBase name="edit" class="h-4 w-4" /> Edit event</button>
					</div>
				</div>
			</section>

			<!-- Event lifecycle: go live and finish (the server checks event.update / event.complete) -->
			<section v-if="event && event.id && !isEditMode && canEditCover && showLifecycle" class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white px-6 py-4">
				<div class="min-w-0">
					<p class="text-sm font-bold text-gray-950">{{ event.status === 'ongoing' ? 'The event is live' : 'Event day' }}</p>
					<p class="mt-0.5 text-sm text-gray-500">{{ event.status === 'ongoing' ? 'Mark it completed when it ends. The client can then leave a review.' : hasStarted ? 'The start time has passed. Start it now, or mark it completed if it is over.' : 'Start it when doors open. Check-in opens 3 hours before the start time either way.' }}</p>
					<p v-if="lifecycleError" class="mt-1 text-sm font-semibold text-red-600" role="alert">{{ lifecycleError }}</p>
				</div>
				<div class="flex shrink-0 flex-wrap gap-2">
					<button v-if="event.status === 'published'" type="button" class="inline-flex items-center gap-2 rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-primary-800 disabled:opacity-50" :disabled="lifecycleBusy" @click="runLifecycle('start')">
						<span class="h-2 w-2 rounded-full bg-green-300" /> Start event
					</button>
					<button v-if="event.status === 'ongoing' || hasStarted" type="button" class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50" :disabled="lifecycleBusy" @click="runLifecycle('complete')">
						<IconBase name="check-circle" class="h-4 w-4" /> Mark completed
					</button>
				</div>
			</section>

			<!-- What the client asked for (events booked through a request) -->
			<OrganizerClientRequest v-if="event && event.id && !isEditMode && event.inquiry_id" :organization-id="coverOrgId"
				:inquiry-id="String(event.inquiry_id)" :quotation-id="event.quotation_id ? String(event.quotation_id) : null" />

			<section v-if="event && event.id && canEditCover" class="rounded-2xl border border-gray-200 bg-white p-6">
				<h3 class="text-base font-bold text-gray-900">Event cover photo</h3>
				<p class="mb-4 mt-1 text-sm text-gray-500">Shown on the Events page and the event's public page when the event is public.</p>
				<MediaImageUploader shape="event" alt="Event cover" :current="event.cover_url"
					:upload="saveCover" :remove="removeCover" />
			</section>

			<div v-if="event && isEditMode" class="grid items-start gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
				<!-- Section menu -->
				<!-- Phones/tablets: one scrollable row of section tabs; large screens: the side menu -->
				<nav class="flex gap-1.5 overflow-x-auto rounded-2xl border border-gray-200 bg-white p-1.5 lg:sticky lg:top-0 lg:block lg:overflow-visible lg:p-2" aria-label="Edit sections">
					<button v-for="tab in editTabs" :key="tab.label" type="button" class="ed-nav" :class="activeEditTab === tab.label ? 'is-on' : ''" :aria-current="activeEditTab === tab.label ? 'step' : undefined" @click="activeEditTab = tab.label">
						<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl transition lg:h-9 lg:w-9" :class="activeEditTab === tab.label ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-500'"><IconBase :name="tab.icon" class="h-4 w-4" /></span>
						<span class="min-w-0 lg:flex-1">
							<span class="block text-sm font-semibold text-gray-950">{{ sectionMeta[tab.label]?.title ?? tab.label }}</span>
							<span class="hidden truncate text-xs text-gray-500 lg:block">{{ sectionMeta[tab.label]?.summary }}</span>
						</span>
						<span v-if="sectionDone[tab.label]" class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-50 text-green-600" aria-label="complete"><IconBase name="check" class="h-3 w-3" /></span>
					</button>
				</nav>

				<!-- Form -->
				<div class="rounded-2xl border border-gray-200 bg-white">
					<div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-6 py-5">
						<div>
							<h3 class="text-base font-bold text-gray-950">{{ sectionMeta[activeEditTab]?.title }}</h3>
							<p class="mt-0.5 text-sm text-gray-500">{{ sectionMeta[activeEditTab]?.description }}</p>
						</div>
						<span class="text-xs font-medium text-gray-400">Step {{ editTabs.findIndex(t => t.label === activeEditTab) + 1 }} of {{ editTabs.length }}</span>
					</div>

					<div class="px-6 py-6">
						<div v-if="saveError" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">{{ saveError }}</div>
						<div v-if="saveSuccess" class="mb-5 flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700" role="status"><IconBase name="check-circle" class="h-4 w-4" />{{ saveSuccess }}</div>

						<Transition name="ed-step" mode="out-in">
							<!-- Basic info -->
							<div v-if="activeEditTab === 'Basic Info'" key="basic" class="space-y-5">
								<div>
									<label for="ed-name" class="ed-label">Event name <span class="text-red-500">*</span></label>
									<input id="ed-name" v-model="editForm.name" type="text" maxlength="255" class="ed-field" placeholder="e.g. Tanduay Festival" />
								</div>
								<div class="grid gap-4 sm:grid-cols-2">
									<div>
										<span class="ed-label">Event type <span class="text-red-500">*</span></span>
										<FormsSelect v-model="editForm.eventType" :options="eventTypeOptions" placeholder="Choose a type" :can-clear="false" />
									</div>
									<div>
										<span class="ed-label">Event date <span class="text-red-500">*</span></span>
										<FormsDateField v-model="editForm.eventDate" name="event_date" placeholder="Choose a date" />
									</div>
								</div>
								<div>
									<div class="flex items-baseline justify-between">
										<label for="ed-desc" class="ed-label">Description</label>
										<span class="text-xs tabular-nums text-gray-400">{{ editForm.description.length }} characters</span>
									</div>
									<textarea id="ed-desc" v-model="editForm.description" rows="4" class="ed-field min-h-[110px] resize-y py-3 leading-6" placeholder="What’s the event about? Guests and your team will see this." />
								</div>
								<div class="grid gap-4 sm:grid-cols-2">
									<div>
										<span class="ed-label">Start time</span>
										<FormsTimeField v-model="editForm.startTime" name="start_time" placeholder="Start time" />
									</div>
									<div>
										<span class="ed-label">End time</span>
										<FormsTimeField v-model="editForm.endTime" name="end_time" placeholder="End time" :min-time="editForm.startTime || undefined" />
									</div>
								</div>
								<div>
									<span class="ed-label">Visibility</span>
									<div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Visibility">
										<button v-for="opt in statusChoices" :key="opt.value" type="button" role="radio" class="ed-choice" :class="editForm.status === opt.value ? 'is-on' : ''" :aria-checked="editForm.status === opt.value" @click="editForm.status = opt.value">
											<span class="ed-radio" :class="editForm.status === opt.value ? 'is-on' : ''" />
											<span class="min-w-0 text-left"><span class="block text-sm font-semibold text-gray-950">{{ opt.label }}</span><span class="block text-xs text-gray-500">{{ opt.hint }}</span></span>
										</button>
									</div>
								</div>
							</div>

							<!-- Venue -->
							<div v-else-if="activeEditTab === 'Venue'" key="venue" class="space-y-5">
								<div>
									<label for="ed-loc" class="ed-label">Venue or address</label>
									<div class="relative">
										<IconBase name="map-pin" class="ed-icon" />
										<input id="ed-loc" v-model="editForm.location" type="text" maxlength="500" class="ed-field !pl-10" placeholder="e.g. SMX Convention Center, Lanang" />
									</div>
								</div>
								<div>
									<label for="ed-guests" class="ed-label">Expected guests</label>
									<div class="relative max-w-xs">
										<IconBase name="users" class="ed-icon" />
										<input id="ed-guests" v-model="editForm.expectedGuests" type="number" min="0" class="ed-field !pl-10" placeholder="e.g. 150" />
									</div>
									<p class="mt-1.5 text-xs text-gray-500">Used to plan seating, catering and check-in.</p>
								</div>
							</div>

							<!-- Ticket types -->
							<div v-else-if="activeEditTab === 'Ticket Types'" key="tickets" class="space-y-4">
								<div>
									<p class="ed-label">Who can come?</p>
									<div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Who can come?">
										<button v-for="opt in privacyOptions" :key="opt.value" type="button" role="radio" :aria-checked="editForm.visibility === opt.value"
											class="flex items-start gap-3 rounded-2xl border px-4 py-3.5 text-left transition"
											:class="editForm.visibility === opt.value ? 'border-primary-600 bg-primary-25 ring-1 ring-primary-600' : 'border-gray-200 hover:border-gray-300'"
											@click="setVisibility(opt.value)">
											<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl" :class="editForm.visibility === opt.value ? 'bg-primary-700 text-white' : 'bg-gray-100 text-gray-500'"><IconBase :name="opt.icon" class="h-4 w-4" /></span>
											<span class="min-w-0"><span class="block text-sm font-bold text-gray-950">{{ opt.title }}</span><span class="mt-0.5 block text-xs leading-5 text-gray-500">{{ opt.hint }}</span></span>
										</button>
									</div>
								</div>

								<p v-if="editForm.visibility === 'public'" class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl bg-amber-50 px-3 py-2.5 text-xs text-amber-900">
									<span>Shown publicly as <strong class="font-semibold">“{{ editForm.name || 'Untitled event' }}”</strong>. Check it doesn't include private details like the client's name.</span>
									<button type="button" class="font-semibold text-primary-700 underline" @click="activeEditTab = 'Basic Info'">Change name</button>
								</p>

								<template v-if="editForm.visibility === 'private'">
									<div class="space-y-1.5 rounded-2xl bg-gray-50 p-4 text-sm text-gray-600">
										<p class="flex items-center gap-2 font-semibold text-gray-900"><IconBase name="users" class="h-4 w-4 text-primary-700" />Invite your guests</p>
										<p>After saving, add guests in <strong class="font-semibold text-gray-900">Ticketing &amp; Attendance → Attendees</strong>, one by one or with <strong class="font-semibold text-gray-900">Bulk Import</strong> from a spreadsheet. Each guest gets a QR invite your team scans at the door.</p>
										<p class="text-xs text-gray-500">Room for {{ guestListCapacity }} invited guests (Expected guests under Venue).</p>
									</div>
									<button type="button" role="switch" :aria-checked="editForm.privateTickets" class="group flex w-full items-center justify-between gap-4 rounded-2xl border border-gray-200 px-4 py-3.5 text-left transition hover:border-gray-300" @click="togglePrivateTickets">
										<span class="flex items-start gap-3">
											<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-700"><IconBase name="ticket" class="h-4 w-4" /></span>
											<span><span class="block text-sm font-semibold text-gray-950">Also sell or give tickets <span class="font-normal text-gray-400">(optional)</span></span><span class="mt-0.5 block text-xs text-gray-500">People with the event's private link can get a ticket themselves, e.g. paid seats. It stays off the Event Marketplace.</span></span>
										</span>
										<span class="ed-switch" :class="editForm.privateTickets ? 'is-on' : ''"><span class="ed-knob" /></span>
									</button>
									<p v-if="!editForm.privateTickets && editForm.ticketTypes.some(t => t.id)" class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">Saving removes this event's ticket types. Any that already have tickets issued are kept.</p>
								</template>

								<template v-if="editForm.visibility === 'public' || editForm.privateTickets">
								<p v-if="editForm.visibility === 'private'" class="text-xs text-gray-500">Only people with the private link see these tickets. The link is on the event page after you save.</p>
								<div class="grid grid-cols-3 gap-2">
									<div class="rounded-xl bg-gray-50 px-4 py-3"><p class="text-xs text-gray-500">Ticket types</p><p class="text-lg font-extrabold tabular-nums text-gray-950">{{ ticketSummary.types }}</p></div>
									<div class="rounded-xl bg-gray-50 px-4 py-3">
										<p class="text-xs text-gray-500">Total seats</p>
										<p class="text-lg font-extrabold tabular-nums text-gray-950">{{ ticketSummary.capacity }}<span v-if="ticketSummary.guests" class="text-xs font-medium text-gray-400"> / {{ ticketSummary.guests }} guests</span></p>
									</div>
									<div class="rounded-xl bg-gray-50 px-4 py-3"><p class="text-xs text-gray-500">If all sell</p><p class="text-lg font-extrabold tabular-nums text-primary-700">{{ formatCurrency(ticketSummary.revenue) }}</p></div>
								</div>
								<p v-if="ticketSummary.guests && ticketSummary.capacity < ticketSummary.guests" class="flex items-center gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800"><IconBase name="sparkles" class="h-3.5 w-3.5" />You have {{ ticketSummary.guests - ticketSummary.capacity }} fewer seats than expected guests.</p>

								<TransitionGroup tag="div" name="ed-card" class="space-y-3">
									<div v-for="(type, index) in editForm.ticketTypes" :key="ticketKey(type, index)" class="relative scroll-mt-24 overflow-hidden rounded-2xl border border-gray-200 p-4 pl-5" data-ticket-card>
										<span class="absolute inset-y-0 left-0 w-1 bg-primary-600" aria-hidden="true" />
										<div class="mb-3 flex items-center justify-between">
											<p class="flex items-center gap-2 text-sm font-bold text-gray-950"><IconBase name="ticket" class="h-4 w-4 text-primary-700" />{{ type.name || `Ticket type ${index + 1}` }}</p>
											<button v-if="editForm.ticketTypes.length > 1" type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-red-50 hover:text-red-600" :aria-label="`Remove ${type.name || 'ticket type'}`" @click="removeTicketType(index)"><IconBase name="trash" class="h-4 w-4" /></button>
										</div>
										<div class="grid gap-3 sm:grid-cols-[minmax(0,1.5fr)_minmax(7.5rem,1fr)_minmax(5rem,0.7fr)]">
											<div><span class="ed-label">Name</span><input v-model="type.name" type="text" class="ed-field" placeholder="e.g. VIP" /></div>
											<div><span class="ed-label">Price</span><div class="relative"><span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400">₱</span><input v-model="type.price" type="text" inputmode="decimal" class="ed-field !pl-8" placeholder="2500" /></div></div>
											<div><span class="ed-label">Seats</span><input v-model="type.capacity" type="number" min="0" class="ed-field" placeholder="50" /></div>
										</div>
									</div>
								</TransitionGroup>
								<button type="button" class="flex h-11 w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-gray-200 text-sm font-semibold text-gray-600 transition hover:border-primary-300 hover:bg-primary-25 hover:text-primary-800" @click="addTicketType"><IconBase name="plus" class="h-4 w-4" /> Add ticket type</button>
								</template>
							</div>

							<!-- Contact -->
							<div v-else-if="activeEditTab === 'Contact'" key="contact" class="space-y-5">
								<p class="text-sm text-gray-500">Who guests and suppliers should reach on the day.</p>
								<div>
									<label for="ed-cname" class="ed-label">Contact person</label>
									<div class="relative"><IconBase name="user" class="ed-icon" /><input id="ed-cname" v-model="editForm.contactName" type="text" class="ed-field !pl-10" placeholder="e.g. Maria Santos" /></div>
								</div>
								<div class="grid gap-4 sm:grid-cols-2">
									<div>
										<label for="ed-cemail" class="ed-label">Email</label>
										<div class="relative"><IconBase name="send" class="ed-icon" /><input id="ed-cemail" v-model="editForm.contactEmail" type="email" class="ed-field !pl-10" placeholder="name@example.com" /></div>
									</div>
									<div>
										<label for="ed-cphone" class="ed-label">Phone</label>
										<div class="relative"><IconBase name="phone" class="ed-icon" /><input id="ed-cphone" v-model="editForm.contactPhone" type="tel" class="ed-field !pl-10" placeholder="+63 912 345 6789" /></div>
									</div>
								</div>
							</div>
						</Transition>
					</div>

					<!-- Save bar -->
					<div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-b-2xl border-t border-gray-100 bg-white/95 px-4 py-3 backdrop-blur sm:px-6 sm:py-4">
						<Transition name="ed-fade" mode="out-in">
							<p v-if="isDirty" key="dirty" class="flex items-center gap-2 text-sm font-medium text-amber-700"><span class="h-2 w-2 animate-pulse rounded-full bg-amber-500" />Unsaved changes</p>
							<p v-else key="clean" class="text-sm text-gray-400">No changes yet</p>
						</Transition>
						<!-- Phones: the buttons share the full width -->
						<div class="flex w-full gap-2 sm:w-auto [&>button]:flex-1 sm:[&>button]:flex-none">
							<button v-if="activeEditTab !== editTabs[editTabs.length - 1]?.label" type="button" class="ed-btn" @click="nextSection">Next <IconBase name="arrow-right" class="h-4 w-4" /></button>
							<button type="button" class="ed-btn" :disabled="isSaving" @click="cancelEdit">Cancel</button>
							<button type="button" class="ed-btn ed-btn-primary" :disabled="isSaving" @click="saveEvent">
								<IconBase :name="isSaving ? 'refresh-cw' : 'check-circle'" class="h-4 w-4" :class="{ 'animate-spin': isSaving }" />
								{{ isSaving ? 'Saving…' : 'Save changes' }}
							</button>
						</div>
					</div>
				</div>
			</div>

		<div v-else-if="event" class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
			<!-- Details -->
			<section class="ed-view rounded-2xl border border-gray-200 bg-white">
				<div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4">
					<h3 class="text-base font-bold text-gray-950">Event details</h3>
					<button v-if="!isLocked" type="button" class="ed-btn !h-9" @click="enterEditMode"><IconBase name="edit" class="h-4 w-4" /> Edit</button>
				</div>
				<dl class="divide-y divide-gray-100">
					<div v-for="(row, i) in viewRows" :key="row.label" class="ed-row grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] items-start gap-3 px-4 py-3.5 xl:grid-cols-[190px_minmax(0,1fr)_auto] sm:gap-4 sm:px-6 sm:py-4" :style="{ '--i': i }">
						<dt class="flex min-w-0 items-start gap-2.5 text-sm leading-6 text-gray-500"><IconBase :name="row.icon" class="mt-1 h-4 w-4 shrink-0 text-gray-400" /><span class="min-w-0 break-words">{{ row.label }}</span></dt>
						<dd class="min-w-0 break-words text-sm font-semibold leading-6" :class="row.empty ? 'font-normal text-gray-400' : 'text-gray-950'">{{ row.value }}</dd>
						<button v-if="!isLocked" type="button" class="tap-target -my-1 rounded-lg px-2 py-1 text-xs font-semibold text-primary-700 transition hover:bg-primary-25" :aria-label="`${row.empty ? 'Add' : 'Edit'} ${row.label.toLowerCase()}`" @click="openSection(row.section)">{{ row.empty ? 'Add' : 'Edit' }}</button>
					</div>
				</dl>
				<div class="border-t border-gray-100 px-6 py-5">
					<p class="text-sm text-gray-500">Description</p>
					<p v-if="event.description" class="mt-1.5 whitespace-pre-line text-sm leading-6 text-gray-800">{{ event.description }}</p>
					<p v-else class="mt-1.5 text-sm text-gray-400">No description yet.<template v-if="!isLocked"> <button type="button" class="font-semibold text-primary-700 hover:underline" @click="enterEditMode">Add one</button></template></p>
				</div>
			</section>

			<!-- Readiness + shortcuts -->
			<aside class="space-y-4 lg:sticky lg:top-0" :class="setupLeft && !isLocked ? 'max-lg:order-first' : ''">
				<section class="rounded-2xl border border-gray-200 bg-white p-5">
					<div class="flex items-center gap-4">
						<div class="relative h-20 w-20 shrink-0">
							<svg viewBox="0 0 80 80" class="h-full w-full -rotate-90" aria-hidden="true">
								<circle cx="40" cy="40" r="34" fill="none" stroke="#eef0f2" stroke-width="7" />
								<circle cx="40" cy="40" r="34" fill="none" stroke="#285F6b" stroke-width="7" stroke-linecap="round" :stroke-dasharray="VIEW_RING" :stroke-dashoffset="VIEW_RING * (1 - (viewReady ? ringPercent : 0) / 100)" class="transition-[stroke-dashoffset] duration-1000 ease-out" />
							</svg>
							<span class="absolute inset-0 flex items-center justify-center text-lg font-extrabold tabular-nums text-gray-950">{{ ringPercent }}%</span>
						</div>
						<div>
							<p class="text-sm font-bold text-gray-950">{{ isLocked ? (event.status === 'completed' ? 'Event completed' : 'Event cancelled') : setupLeft ? 'Set up this event' : 'Preparation' }}</p>
							<p class="mt-0.5 text-xs text-gray-500">{{ isLocked ? 'It can no longer be changed.' : setupLeft ? `${setupLeft} ${setupLeft === 1 ? 'step' : 'steps'} left. Tap one to do it.` : 'Set up. The ring shows verified preparation tasks.' }}</p>
						</div>
					</div>
					<ul v-if="!isLocked" class="-mx-2 mt-4 space-y-0.5">
						<li v-for="check in readinessChecks" :key="check.label">
							<p v-if="check.done" class="flex items-center gap-2.5 px-2 py-1 text-sm text-gray-500">
								<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-50 text-green-600"><IconBase name="check" class="h-3 w-3" /></span>
								<span class="line-through decoration-gray-300">{{ check.label }}</span>
							</p>
							<button v-else type="button" class="group flex min-h-9 w-full items-center gap-2.5 rounded-lg px-2 py-1 text-left text-sm font-medium text-gray-900 transition hover:bg-primary-25" @click="openSection(check.section)">
								<span class="h-5 w-5 shrink-0 rounded-full border-2 border-gray-200 group-hover:border-primary-400" />
								<span class="min-w-0 flex-1">{{ check.label }}</span>
								<IconBase name="arrow-right" class="h-4 w-4 shrink-0 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-primary-700" />
							</button>
						</li>
					</ul>
				</section>

				<section v-if="privateLink" class="rounded-2xl border border-gray-200 bg-white p-5">
					<p class="flex items-center gap-2 text-sm font-bold text-gray-950"><IconBase name="link" class="h-4 w-4 text-primary-700" />Private ticket link</p>
					<p class="mt-1 text-xs text-gray-500">Send it to the people who may get tickets. Anyone with the link can open the event, so share it only with your guests.</p>
					<p class="mt-3 truncate rounded-xl bg-gray-50 px-3 py-2 font-mono text-xs text-gray-600" :title="privateLink">{{ privateLink }}</p>
					<div class="mt-3 flex gap-2">
						<button type="button" class="ed-btn ed-btn-primary !h-10 flex-1" @click="copyPrivateLink"><IconBase :name="linkCopied ? 'check' : 'copy'" class="h-4 w-4" />{{ linkCopied ? 'Copied' : 'Copy link' }}</button>
						<a :href="privateLink" target="_blank" rel="noopener" class="ed-btn !h-10">Open</a>
					</div>
				</section>

				<nav class="overflow-hidden rounded-2xl border border-gray-200 bg-white" aria-label="More for this event">
					<NuxtLink v-for="link in viewLinks" :key="link.to" :to="{ path: link.to, query: { event: event.id } }" class="group flex items-center gap-3 border-b border-gray-100 px-5 py-3.5 transition last:border-b-0 hover:bg-gray-50">
						<span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary-50 text-primary-700"><IconBase :name="link.icon" class="h-4 w-4" /></span>
						<span class="min-w-0 flex-1"><span class="block text-sm font-semibold text-gray-950">{{ link.label }}</span><span class="block text-xs text-gray-500">{{ link.hint }}</span></span>
						<IconBase name="arrow-right" class="h-4 w-4 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-primary-700" />
					</NuxtLink>
				</nav>
			</aside>
		</div>

		<!-- ===================================================== -->
		<!-- AWARDED QUOTATION DETAILS -->
		<!-- ===================================================== -->
		<div v-else-if="quotationData" class="rounded-2xl border border-green-200 bg-white p-6">
			<div class="flex flex-wrap items-start justify-between gap-4">
				<div>
					<p class="text-xs font-bold uppercase tracking-wide text-green-600">
						Client Awarded Your Bid
					</p>

					<h2 class="mt-2 text-xl font-bold text-gray-900">
						{{
							quotationData.inquiry.event_title ||
							`${quotationData.inquiry.event_type} Event`
						}}
					</h2>

					<p class="mt-1 text-sm text-gray-500">
						{{ formatDate(quotationData.inquiry.event_date) }}

						<template v-if="quotationData.inquiry.location">
							&bull;
							{{ quotationData.inquiry.location }}
						</template>
					</p>
				</div>

				<span
					class="rounded-full border border-green-200 bg-green-50 px-3 py-1 text-xs font-semibold text-green-700">
					Awarded
				</span>
			</div>

			<!-- The request and the accepted offer (receipt), with Message / Call / E-mail -->
			<OrganizerClientRequest v-if="apiQuotationIds" class="mt-6" :organization-id="coverOrgId"
				:inquiry-id="apiQuotationIds.inquiry" :quotation-id="apiQuotationIds.quotation" />

			<div class="mt-6 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600">
				<p class="font-semibold text-gray-900">Ready to start preparing?</p>
				<p class="mt-0.5">We'll create the event from what the client told you (date, venue, guests and contact). Then you confirm the rest step by step.</p>
			</div>

			<div class="mt-5">
				<button type="button"
					class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-[#285F6b] px-5 text-sm font-bold text-white hover:bg-[#1f4a54] disabled:cursor-not-allowed disabled:opacity-60"
					:disabled="isCreatingEvent" @click="editAwardedEvent">
					<IconBase :name="isCreatingEvent ? 'refresh-cw' : 'arrow-right'" class="h-4 w-4"
						:class="{ 'animate-spin': isCreatingEvent }" />
					{{ isCreatingEvent ? 'Setting up the event…' : 'Start preparing' }}
				</button>
			</div>
		</div>
		</template>
	</div>
</template>

<script setup lang="ts">
interface EventTicketType {
	id: number
	event_id?: number
	name: string
	price: string | number
	capacity: number
	status?: string
}

// Event cover: anyone who may edit the event (the server checks event.update again).
const coverOrgId = computed(() => useOrganization().organization.value?.id ?? '')
// Event day bar: from 24 hours before the start (and while live), so Start event can't be
// tapped weeks early by mistake.
const showLifecycle = computed(() => {
	const e = event.value
	if (!e || !['published', 'ongoing'].includes(e.status)) return false
	if (e.status === 'ongoing' || !e.event_date) return true
	const start = new Date(`${e.event_date}T${e.start_time || '00:00'}`).getTime()
	return Number.isNaN(start) || start - Date.now() <= 24 * 3600 * 1000
})
const canEditCover = computed(() => {
	const m = useOrganization().organization.value?.my_membership
	return Boolean(m && (m.is_owner || m.permissions?.includes('event.update') || m.event_permissions?.includes('event.update')))
})
async function saveCover(file: File) {
	if (!event.value) return null
	const url = await useMedia().uploadEventCover(coverOrgId.value, String(event.value.id), file)
	event.value = { ...event.value, cover_url: url }
	return url
}
// Going live and finishing the event (no other screen does this).
const lifecycleBusy = ref(false)
const lifecycleError = ref('')
async function runLifecycle(action: 'start' | 'complete') {
	if (!event.value) return
	const ask = action === 'start'
		? { text: 'Start the event now? It shows as live to you and your team.', label: 'Start event' }
		: { text: 'Mark the event completed? It can no longer be changed after this, and the client can leave a review.', label: 'Mark completed' }
	if (!(await confirmDialog(ask.text, { confirmLabel: ask.label }))) return
	lifecycleBusy.value = true
	lifecycleError.value = ''
	try {
		await useOrganizerEvents().transition(coverOrgId.value, String(event.value.id), action)
		event.value = await loadEvent(String(event.value.id)) as unknown as typeof event.value
	} catch (e: unknown) {
		lifecycleError.value = authMessage(e) || 'That didn’t work. Try again.'
	} finally {
		lifecycleBusy.value = false
	}
}
async function removeCover() {
	if (!event.value) return
	await useMedia().removeEventCover(coverOrgId.value, String(event.value.id))
	event.value = { ...event.value, cover_url: null }
}

interface OrganizerEvent {
	id: number
	/** Uploaded cover picture (MediaController). */
	cover_url?: string | null
	inquiry_id: number | null
	quotation_id: number | null

	name: string
	event_type: string
	event_type_id?: string | null
	description: string | null

	event_date: string | null
	location: string | null
	expected_guests: number | null

	start_time: string | null
	end_time: string | null

	status: string

	public_registration: boolean
	waitlist_enabled: boolean

	contact_name: string | null
	contact_email: string | null
	contact_phone: string | null

	ticket_types?: EventTicketType[]
}

type EventResponse =
	| OrganizerEvent
	| {
		data: OrganizerEvent
	}
function extractEvent(
	response: EventResponse,
): OrganizerEvent {
	if (
		'data' in response &&
		response.data
	) {
		return response.data
	}

	return response as OrganizerEvent
}

interface EventInclusion {
	id: number
	description: string
}

interface QuotationEventData {
	planning_started?: boolean

	quotation: {
		id: number
		quotation_amount: string | number
		package_name: string | null
		timeline: string | null
		quotation_details: string | null
		quotation_status: string
		inclusions: EventInclusion[]
	}

	inquiry: {
		id: number
		event_title: string | null
		event_type: string
	event_type_id?: string | null
		event_date: string | null
		location: string | null
		expected_guests: number
		budget_range: string | null
		additional_details: string | null
		status: string
	}

	event: OrganizerEvent | null
}

interface QuotationEventResponse {
	message: string
	data: QuotationEventData
}

interface StartPlanningResponse {
	message: string
	data: {
		planning_started: boolean
		event: OrganizerEvent
	}
}

interface TicketTypeForm {
	id?: number
	name: string
	price: string
	capacity: string
}

definePageMeta({
	layout: 'dashboard',
	middleware: ['organizer'],
})

const route = useRoute()
const config = useRuntimeConfig()

const {
	token,
} = useAuth()

const event =
	ref<OrganizerEvent | null>(null)

const quotationData =
	ref<QuotationEventData | null>(null)

const isCreatingEvent =
	ref(false)

const isLoading =
	ref(false)

const isSaving =
	ref(false)

const errorMessage =
	ref('')

const saveError =
	ref('')

const saveSuccess =
	ref('')

const editTabs = [
	{
		label: 'Basic Info',
		icon: 'file-text',
	},
	{
		label: 'Venue',
		icon: 'map-pin',
	},
	{
		label: 'Ticket Types',
		icon: 'tag',
	},
	{
		label: 'Contact',
		icon: 'phone',
	},
]

// NaSeRy's standard event types plus the organizer's own (value = id).
const eventTypes = ref<{ value: string, label: string }[]>([])
let orgId = ''
// The API's quotation behind a won-but-not-planned event ("start planning").
let apiQuotation: import('~/composables/useQuotations').OrganizerQuotation | null = null
const apiQuotationIds = ref<{ inquiry: string, quotation: string } | null>(null)

const activeEditTab =
	ref('Basic Info')
// Keep the current section visible in the phone tab row (e.g. after Next).
watch(activeEditTab, () => nextTick(() => scrollRowTo(document.querySelector('nav[aria-label="Edit sections"] .ed-nav.is-on'))))

const eventId =
	computed(() => routeId(route.query.event))

const quotationId =
	computed(() => routeId(route.query.quotation))


const isEditMode =
	computed(
		() =>
			route.query.mode ===
			'edit',
	)

const editForm =
	reactive({
		name: '',
		eventType: '',
		description: '',
		eventDate: '',
		status: 'draft',

		startTime: '',
		endTime: '',

		location: '',
		expectedGuests: '',

		ticketTypes: [
			{
				name: '',
				price: '',
				capacity: '',
			},
		] as TicketTypeForm[],

		visibility: 'private' as Visibility,
		privateTickets: false,

		contactName: '',
		contactEmail: '',
		contactPhone: '',
	})

const readiness =
	computed(() => {
		if (!event.value) {
			return 0
		}

		let score = 20

		if (event.value.name) {
			score += 10
		}

		if (event.value.event_date) {
			score += 10
		}

		if (event.value.location) {
			score += 15
		}

		if (
			event.value.expected_guests
		) {
			score += 10
		}

		if (
			event.value.start_time
		) {
			score += 10
		}

		if (
			event.value.end_time
		) {
			score += 10
		}

		if (
			event.value.description
		) {
			score += 5
		}

		return Math.min(
			score,
			100,
		)
	})

function fillEditForm() {
	if (!event.value) {
		return
	}

	editForm.name =
		event.value.name ?? ''

	editForm.eventType =
		event.value.event_type_id ?? ''

	editForm.description =
		event.value.description ?? ''

	editForm.eventDate =
		event.value.event_date
			? event.value.event_date.substring(
				0,
				10,
			)
			: ''

	editForm.status =
		event.value.status ||
		'draft'

	editForm.startTime =
		event.value.start_time
			? event.value.start_time.substring(0, 5)
			: ''

	editForm.endTime =
		event.value.end_time
			? event.value.end_time.substring(0, 5)
			: ''

	editForm.location =
		event.value.location ?? ''

	editForm.expectedGuests =
		event.value.expected_guests !== null &&
			event.value.expected_guests !== undefined
			? String(event.value.expected_guests)
			: ''

	const sellable = (event.value.ticket_types ?? []).filter(t => !isGuestTier(t))
	editForm.visibility = event.value.public_registration ? 'public' : 'private'
	editForm.privateTickets = editForm.visibility === 'private' && sellable.length > 0

	editForm.ticketTypes =
		sellable.length
			? Array.from(
				new Map(
					sellable.map(type => [
						`${type.name.trim().toLowerCase()}|${Number(type.price).toFixed(2)}|${type.capacity}`,
						type,
					]),
				).values(),
			).map(
				type => ({
					id: type.id,

					name:
						type.name ?? '',

					price:
						String(type.price ?? ''),

					capacity:
						String(type.capacity ?? ''),
				}),
			)
			: editForm.visibility === 'public'
				? [{ name: '', price: '', capacity: '' }]
				: []

	editForm.contactName =
		event.value.contact_name ?? ''

	editForm.contactEmail =
		event.value.contact_email ?? ''

	editForm.contactPhone =
		event.value.contact_phone ?? ''
}

function formatDate(
	dateString: string | null,
): string {
	if (!dateString) {
		return 'Date not set'
	}

	const date =
		new Date(
			dateString.includes('T')
				? dateString
				: `${dateString}T00:00:00`,
		)

	return date.toLocaleDateString(
		'en-US',
		{
			month: 'long',
			day: 'numeric',
			year: 'numeric',
		},
	)
}

function formatCurrency(
	value: string | number,
): string {
	const amount =
		Number(value)

	if (Number.isNaN(amount)) {
		return String(value)
	}

	return new Intl.NumberFormat(
		'en-PH',
		{
			style: 'currency',
			currency: 'PHP',
			maximumFractionDigits: 0,
		},
	).format(amount)
}


function formatStatus(
	status: string,
): string {
	if (!status) {
		return 'Draft'
	}

	return (
		status.charAt(0).toUpperCase() +
		status.slice(1)
	)
}

async function loadDetails() {
	errorMessage.value = ''
	if (!eventId.value && !quotationId.value) {
		await navigateTo('/organizer/eventmanagement/all-events', { replace: true })
		return
	}
	isLoading.value = true
	event.value = null
	quotationData.value = null
	try {
		const org = await useOrganization().fetchOrganization()
		if (!org) throw new Error('Set up your organizer workspace first.')
		orgId = org.id
		if (!eventTypes.value.length) {
			const { data } = await useApi().get<{ id: string, name: string }[]>(`/organizations/${orgId}/event-types`).catch(() => ({ data: [] }))
			eventTypes.value = data.map(t => ({ value: t.id, label: t.name }))
		}

		if (eventId.value) {
			event.value = await loadEvent(eventId.value) as unknown as OrganizerEvent
			fillEditForm()
			return
		}

		if (quotationId.value) {
			apiQuotation = await useOrganizerQuotations().show(orgId, quotationId.value)
			apiQuotationIds.value = apiQuotation?.inquiry?.id ? { inquiry: String(apiQuotation.inquiry.id), quotation: String(apiQuotation.id) } : null
			const q = toLegacyQuotation(apiQuotation)
			const ev = apiQuotation.event_id ? await loadEvent(apiQuotation.event_id) : null
			quotationData.value = {
				planning_started: Boolean(ev),
				quotation: {
					id: q.id as unknown as number,
					quotation_amount: q.quotation_amount,
					package_name: q.package_name,
					timeline: q.timeline,
					quotation_details: q.quotation_details,
					quotation_status: q.quotation_status,
					inclusions: apiQuotation.items.map((item, i) => ({ id: (item.id ?? i) as unknown as number, description: `${item.description} × ${item.quantity}` })) as unknown as EventInclusion[],
				},
				inquiry: {
					id: q.inquiry_id as unknown as number,
					event_title: q.inquiry?.event_title ?? null,
					event_type: q.inquiry?.event_type ?? 'Event',
					event_date: q.inquiry?.event_date ?? null,
					location: q.inquiry?.location || null,
					expected_guests: q.inquiry?.expected_guests ?? 0,
					budget_range: q.inquiry?.budget_min || q.inquiry?.budget_max ? `₱${Number(q.inquiry?.budget_min ?? 0).toLocaleString()} – ₱${Number(q.inquiry?.budget_max ?? 0).toLocaleString()}` : null,
					additional_details: null,
					status: apiQuotation.inquiry?.status ?? '',
				},
				event: ev as unknown as OrganizerEvent | null,
			}
			if (ev) {
				event.value = ev as unknown as OrganizerEvent
				fillEditForm()
			}
		}
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load event details.'
	} finally {
		isLoading.value = false
	}
}

/** The API event + its ticket types, in this page's shape. */
async function loadEvent(id: string) {
	const [apiEvent, tiers] = await Promise.all([
		useOrganizerEvents().show(orgId, id),
		useApi().get<{ tiers: { id: string, name: string, price: string, capacity: number, status: string }[] }>(`/organizations/${orgId}/events/${id}/ticket-types`)
			.then(r => r.data.tiers).catch(() => []),
	])
	return { ...toLegacyEvent(apiEvent), private_link_key: (apiEvent as { private_link_key?: string | null }).private_link_key ?? null, ticket_types: tiers.map(t => ({ id: t.id, name: t.name, price: t.price, capacity: t.capacity, status: t.status })) }
}

function addTicketType() {
	editForm.ticketTypes.push({
		name: '',
		price: '',
		capacity: '',
	})
}

function removeTicketType(
	index: number,
) {
	editForm.ticketTypes.splice(
		index,
		1,
	)
}

async function enterEditMode() {
	if (!event.value) {
		return
	}

	fillEditForm()

	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			event:
				event.value.id,

			mode:
				'edit',
		},
	})
}

async function cancelEdit() {
	if (!event.value) {
		return
	}

	await navigateTo({
		path:
			'/organizer/eventmanagement/event-details',

		query: {
			event:
				event.value.id,
		},
	})
}


// A request has a date but no time: the new event starts 9:00–17:00 on that day as a
// placeholder. The server refuses a start in the past, so for a request dated today
// (or earlier) it starts at the next full hour instead, for 4 hours.
function placeholderSession(day: string, requested?: { start: string, end: string } | null) {
	const pad = (n: number) => String(n).padStart(2, '0')
	const local = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
	// The client's own time, when they gave one and it hasn't passed (an end before the start runs past midnight).
	if (requested) {
		const start = new Date(`${day}T${requested.start}`)
		let end = new Date(`${day}T${requested.end}`)
		if (end <= start) end = new Date(end.getTime() + 24 * 3600 * 1000)
		if (start.getTime() > Date.now() + 5 * 60 * 1000) return { startsAt: local(start), endsAt: local(end) }
	}
	const nine = new Date(`${day}T09:00`)
	if (nine.getTime() > Date.now() + 30 * 60 * 1000) return { startsAt: `${day}T09:00`, endsAt: `${day}T17:00` }
	const start = new Date()
	start.setMinutes(0, 0, 0)
	start.setHours(start.getHours() + 1)
	return { startsAt: local(start), endsAt: local(new Date(start.getTime() + 4 * 3600 * 1000)) }
}

async function editAwardedEvent() {
	if (
		!token.value ||
		!quotationData.value
	) {
		return
	}

	// The event exists already: open it.
	if (quotationData.value.event) {
		await navigateTo({
			path:
				'/organizer/eventmanagement/event-details',

			query: {
				event:
					quotationData.value.event.id,
			},
		})

		return
	}
	isCreatingEvent.value = true
	errorMessage.value = ''
	try {
		const q = apiQuotation!
		const day = q.inquiry?.requested_date ?? new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10)
		// The request form writes the client's event name and time as "Event: …" / "Time: HH:MM – HH:MM" lines.
		const notes = q.inquiry?.id ? ((await useOrganizerInquiries().show(orgId, q.inquiry.id).catch(() => null))?.additional_requirements ?? '') : ''
		const title = notes.match(/^Event: (.+)$/m)?.[1]?.trim()
		const time = notes.match(/^Time: (\d{1,2}:\d{2}) [–-] (\d{1,2}:\d{2})$/m)
		const { startsAt, endsAt } = placeholderSession(day, time ? { start: time[1]!.padStart(5, '0'), end: time[2]!.padStart(5, '0') } : null)
		// Never the client's full name: event names can end up public (marketplace, portfolio).
		const firstName = q.client?.name?.trim().split(/\s+/)[0]
		const created = await useOrganizerEvents().convert(orgId, q.id, {
			name: title || `${q.inquiry?.event_type ?? 'Event'}${firstName ? ` for ${firstName}` : ''}`,
			event_type_id: q.inquiry?.event_type_id ?? null,
			description: q.package_name,
			overall_capacity: q.inquiry?.guest_count ?? null,
			venue_name: q.inquiry?.location_text ?? null,
			venue_address: null,
			latitude: null,
			longitude: null,
			visibility: 'private',
			contact_name: q.client?.name ?? null,
			contact_email: q.client?.email ?? null,
			contact_phone: q.client?.phone ?? null,
			sessions: [{ name: null, starts_at: startsAt, ends_at: endsAt }],
		})
		const response = { data: { event: { id: created.id } } }
		await navigateTo({
			path:
				'/organizer/eventmanagement/event-details',

			// The event page's "Set up this event" checklist walks through the rest.
			query: {
				event:
					response.data.event.id,
			},
		})

	} catch (error: unknown) {
		console.error(
			'Failed to prepare event for editing:',
			error,
		)

		const apiError =
			error as {
				data?: {
					message?: string
				}
			}

		errorMessage.value =
			Object.values(fieldErrors(error))[0] ||
			authMessage(error) ||
			apiError.data?.message ||
			'Unable to prepare this event for editing.'

	} finally {
		isCreatingEvent.value = false
	}
}

async function saveEvent() {
	if (
		!token.value ||
		!event.value
	) {
		return
	}

	if (
		!editForm.name.trim() ||
		!editForm.eventType ||
		!editForm.eventDate
	) {
		saveError.value =
			'Event name, event type, and event date are required.'

		activeEditTab.value =
			'Basic Info'

		return
	}

	isSaving.value = true

	saveError.value = ''
	saveSuccess.value = ''

	try {
		const current = event.value as unknown as LegacyEvent & { ticket_types?: { id: string, name: string, price: string | number }[] }
		const events = useOrganizerEvents()
		const locationChanged = editForm.location.trim() !== (current.location ?? '')

		await events.update(orgId, current.id, {
			name: editForm.name.trim(),
			event_type_id: editForm.eventType || null,
			description: editForm.description.trim() || null,
			overall_capacity: editForm.expectedGuests ? Number(editForm.expectedGuests) : null,
			...(locationChanged ? { venue_name: editForm.location.trim() || null, venue_address: null } : {}),
			...((editForm.visibility === 'public') !== current.public_registration ? { visibility: editForm.visibility } : {}),
			contact_name: editForm.contactName.trim() || null,
			contact_email: editForm.contactEmail.trim() || null,
			contact_phone: editForm.contactPhone.trim() || null,
		})

		// Date and time live on the event's (first) session.
		const startsAt = `${editForm.eventDate}T${editForm.startTime || '09:00'}`
		const endsAt = `${editForm.eventDate}T${editForm.endTime || '17:00'}`
		if (current.first_session_id && (editForm.eventDate !== current.event_date || editForm.startTime !== current.start_time || editForm.endTime !== current.end_time)) {
			await events.updateSession(orgId, current.id, current.first_session_id, { name: null, starts_at: startsAt, ends_at: endsAt })
		} else if (!current.first_session_id) {
			await events.addSession(orgId, current.id, { name: null, starts_at: startsAt, ends_at: endsAt })
		}

		// Ticket types: update, add, and remove the ones taken out (the API keeps any with tickets sold).
		const base = `/organizations/${orgId}/events/${current.id}/ticket-types`
		const kept = new Set<string>()
		const tiers = editForm.visibility === 'public' || editForm.privateTickets ? editForm.ticketTypes : []
		// Private events keep a closed "Guest list" type behind the scenes for the QR invites
		// (closed: never on sale, not even through the private link).
		if (editForm.visibility === 'private') {
			const guestTier = (current.ticket_types ?? []).find(t => isGuestTier(t))
			const body = { name: GUEST_TIER, price: 0, capacity: guestListCapacity.value, status: 'closed' }
			if (guestTier) {
				kept.add(String(guestTier.id))
				await useApi().patch(`${base}/${guestTier.id}`, body)
			} else {
				await useApi().post(base, body)
			}
		}
		for (const type of tiers.filter(t => t.name.trim())) {
			const body = { name: type.name.trim(), price: Number(type.price || 0), capacity: Number(type.capacity || 0) || 1 }
			if (type.id) {
				kept.add(String(type.id))
				await useApi().patch(`${base}/${type.id}`, body)
			} else {
				await useApi().post(base, { ...body, status: current.status === 'draft' ? 'draft' : 'on_sale' })
			}
		}
		for (const old of current.ticket_types ?? []) {
			if (!kept.has(old.id)) await useApi().delete(`${base}/${old.id}`).catch(() => null)
		}

		// Status: publish / unpublish / complete through the event lifecycle.
		const transitions: Record<string, Record<string, 'publish' | 'unpublish' | 'complete' | 'start'>> = {
			draft: { published: 'publish' },
			published: { draft: 'unpublish', ongoing: 'start', completed: 'complete' },
			ongoing: { completed: 'complete' },
		}
		const action = transitions[current.status]?.[editForm.status]
		if (action) await events.transition(orgId, current.id, action)

		// The editor has no per-ticket status: tickets follow the event. Ones added while it
		// was a draft go on sale once it's published, or guests would have nothing to claim.
		if (editForm.status !== 'draft') {
			const drafts = (await useApi().get<{ tiers: { id: string, status: string }[] }>(base)).data.tiers.filter(t => t.status === 'draft')
			for (const t of drafts) await useApi().patch(`${base}/${t.id}`, { status: 'on_sale' })
		}

		const response = { data: await loadEvent(current.id) }
		event.value =
			response.data as unknown as OrganizerEvent

		fillEditForm()

		saveSuccess.value =
			'Event updated successfully.'

		window.setTimeout(
			async () => {
				await navigateTo({
					path:
						'/organizer/eventmanagement/event-details',

					query: {
						event:
							event.value?.id,
					},
				})
			},
			700,
		)

	} catch (error: unknown) {
		console.error(
			'Failed to update event:',
			error,
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

		const validationError =
			apiError.data?.errors
				? Object.values(
					apiError.data.errors,
				)[0]?.[0]
				: null

		saveError.value =
			validationError ??
			apiError.data?.message ??
			'Unable to save event.'

	} finally {
		isSaving.value = false
	}
}

watch(
	[
		eventId,
		quotationId,
	],
	() => {
		loadDetails()
	},
)

onMounted(() => {
	loadDetails()
})

// ---------- Edit view helpers ----------
const statusChoices = [
	{ value: 'draft', label: 'Draft', hint: 'Only you and your team can see it' },
	{ value: 'published', label: 'Published', hint: 'Open for registration and tickets' },
]
type Visibility = 'private' | 'public'
// Who can come (review: the organizer decides private or public while setting up).
const privacyOptions = [
	{ value: 'private' as const, title: 'Private', hint: 'Invited guests only, e.g. birthdays and weddings. Not listed on NaSeRy.', icon: 'shield-check' },
	{ value: 'public' as const, title: 'Public', hint: 'Listed on the Event Marketplace. Anyone can get a ticket, e.g. concerts and fairs.', icon: 'globe' },
]
const guestListCapacity = computed(() => Math.max(1, Number(editForm.expectedGuests) || 100))
function setVisibility(value: Visibility) {
	editForm.visibility = value
	if (value === 'public' && !editForm.ticketTypes.length) addTicketType()
	if (value === 'private') editForm.privateTickets = editForm.ticketTypes.some(t => t.id || t.name.trim())
}
function togglePrivateTickets() {
	editForm.privateTickets = !editForm.privateTickets
	if (editForm.privateTickets && !editForm.ticketTypes.length) addTicketType()
}
const sectionMeta: Record<string, { title: string, summary: string, description: string }> = {
	'Basic Info': { title: 'Basic info', summary: 'Name, type, date and time', description: 'The essentials guests and your team will see first.' },
	'Venue': { title: 'Venue', summary: 'Where and how many', description: 'Where the event happens and how many people to expect.' },
	'Ticket Types': { title: 'Guests & tickets', summary: 'Private or public', description: 'Who can come, and how they get in.' },
	'Contact': { title: 'Contact', summary: 'Who to reach on the day', description: 'Contact details shown to guests and suppliers.' },
}
const sectionDone = computed<Record<string, boolean>>(() => ({
	'Basic Info': !!(editForm.name.trim() && editForm.eventType && editForm.eventDate && editForm.startTime && editForm.endTime),
	'Venue': !!(String(editForm.location ?? '').trim() && Number(editForm.expectedGuests) > 0),
	'Ticket Types': editForm.visibility === 'private' && !editForm.privateTickets
		|| (editForm.ticketTypes.length > 0 && editForm.ticketTypes.every((t: any) => String(t.name ?? '').trim() && Number(t.capacity) > 0)),
	'Contact': !!(String(editForm.contactName ?? '').trim() && (String(editForm.contactEmail ?? '').trim() || String(editForm.contactPhone ?? '').trim())),
}))
function nextSection() {
	const i = editTabs.findIndex(t => t.label === activeEditTab.value)
	if (i >= 0 && i < editTabs.length - 1) activeEditTab.value = editTabs[i + 1]!.label
}

// Event types: NaSeRy's standard types plus the organizer's own (loaded with the event).
const eventTypeOptions = computed(() => eventTypes.value)

// Ticket summary
const ticketKeys = new WeakMap<object, number>()
let ticketSeq = 0
function ticketKey(type: object, index: number) {
	if (typeof type !== 'object' || !type) return index
	if (!ticketKeys.has(type)) ticketKeys.set(type, ++ticketSeq)
	return ticketKeys.get(type)!
}
const ticketSummary = computed(() => {
	const types = editForm.ticketTypes as Array<{ price: string | number, capacity: string | number }>
	return {
		types: types.length,
		capacity: types.reduce((s, t) => s + (Number(t.capacity) || 0), 0),
		revenue: types.reduce((s, t) => s + (Number(String(t.price).replace(/[^\d.]/g, '')) || 0) * (Number(t.capacity) || 0), 0),
		guests: Number(editForm.expectedGuests) || 0,
	}
})

// Unsaved-changes indicator
const editSnapshot = ref('')
const isDirty = computed(() => !!editSnapshot.value && JSON.stringify(editForm) !== editSnapshot.value)
watch(() => [isEditMode.value, event.value?.id, isLoading.value], () => {
	if (isEditMode.value && event.value && !isLoading.value) nextTick(() => { editSnapshot.value = JSON.stringify(editForm) })
}, { immediate: true })
watch(saveSuccess, (v) => { if (v) editSnapshot.value = JSON.stringify(editForm) })

// Deep links into the editor (?section=tickets&new=1, &ticket=<id>) so "Add ticket type"
// and "Edit" buttons elsewhere land on the right section, not on Basic info (finding #5).
const sectionTabs: Record<string, string> = { basic: 'Basic Info', venue: 'Venue', tickets: 'Ticket Types', settings: 'Ticket Types', contact: 'Contact' }
watch(() => [isEditMode.value, event.value?.id, isLoading.value, route.query.section], () => {
	const tab = sectionTabs[String(route.query.section ?? '')]
	if (!tab || !isEditMode.value || !event.value || isLoading.value) return
	activeEditTab.value = tab
	let focusIndex = -1
	const adding = !!route.query.new
	if (tab === 'Ticket Types') {
		if ((route.query.new || route.query.ticket) && editForm.visibility === 'private') editForm.privateTickets = true
		const ticketId = route.query.ticket ? String(route.query.ticket) : ''
		if (route.query.new) {
			nextTick(() => {
				// Reuse the editor's empty starter row instead of adding a second blank one.
				const last: any = editForm.ticketTypes[editForm.ticketTypes.length - 1]
				if (!last || last.id || String(last.name ?? '').trim() || String(last.price ?? '').trim() || String(last.capacity ?? '').trim()) addTicketType()
				focusTicket(editForm.ticketTypes.length - 1)
			})
		} else if (ticketId) {
			focusIndex = editForm.ticketTypes.findIndex((t: any) => String(t.id) === ticketId)
		}
	}
	const { section: _s, ticket: _t, new: _n, ...rest } = route.query
	navigateTo({ path: route.path, query: rest }, { replace: true })
	if (focusIndex >= 0) focusTicket(focusIndex)
	// An instant jump: a smooth scroll here gets cancelled by the section switch's own scroll.
	else if (!adding) setTimeout(() => scrollToElement(document.querySelector('nav[aria-label="Edit sections"]'), { behavior: 'auto' }), 600)
}, { immediate: true })
function focusTicket(index: number) {
	// Wait for the section swap transition before scrolling to the card.
	setTimeout(() => {
		const card = document.querySelectorAll<HTMLElement>('[data-ticket-card]')[index]
		if (!card) return
		scrollToElement(card, { block: 'center', behavior: 'auto' })
		card.querySelector<HTMLInputElement>('input')?.focus({ preventScroll: true })
	}, 350)
}


// ---------- View mode ----------
const VIEW_RING = 2 * Math.PI * 34
const viewReady = ref(false)
watch(() => [isLoading.value, isEditMode.value], () => {
	viewReady.value = false
	if (!isLoading.value && !isEditMode.value) setTimeout(() => { viewReady.value = true }, 120)
}, { immediate: true })
function clockTime(value: string | null | undefined) {
	if (!value) return ''
	const [h, m] = value.split(':').map(Number)
	if (!Number.isFinite(h)) return value
	const d = new Date(); d.setHours(h ?? 0, m ?? 0, 0, 0)
	return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })
}
const viewRows = computed(() => {
	const e = event.value
	if (!e) return []
	const start = clockTime(e.start_time)
	const end = clockTime(e.end_time)
	const time = start && end ? `${start} – ${end}` : start || end
	const tickets = (e.ticket_types ?? []).filter(t => !isGuestTier(t))
	const seats = `${tickets.length} ticket ${tickets.length === 1 ? 'type' : 'types'} · ${tickets.reduce((s, t) => s + (Number(t.capacity) || 0), 0)} seats`
	const who = e.public_registration
		? (tickets.length ? `Public · ${seats}` : 'Public · no tickets yet')
		: (tickets.length ? `Private · invitations + ${seats} by private link` : 'Private · invitations')
	return [
		{ label: 'Event type', icon: 'tag', section: 'basic', value: e.event_type || 'Not set', empty: !e.event_type },
		{ label: 'Date', icon: 'calendar', section: 'basic', value: e.event_date ? formatDate(e.event_date) : 'Not set', empty: !e.event_date },
		{ label: 'Time', icon: 'clock', section: 'basic', value: time || 'Not set', empty: !time },
		{ label: 'Venue', icon: 'map-pin', section: 'venue', value: e.location || 'Not set', empty: !e.location },
		{ label: 'Expected guests', icon: 'users', section: 'venue', value: e.expected_guests ? `${e.expected_guests} guests` : 'Not set', empty: !e.expected_guests },
		{ label: 'Who can come', icon: 'ticket', section: 'tickets', value: e.ticket_types?.length ? who : 'Not chosen yet', empty: !e.ticket_types?.length },
		{ label: 'Contact', icon: 'phone', section: 'contact', value: [e.contact_name, e.contact_phone || e.contact_email].filter(Boolean).join(' · ') || 'Not set', empty: !e.contact_name && !e.contact_phone && !e.contact_email },
	]
})
const readinessChecks = computed(() => {
	const e = event.value
	if (!e) return []
	// The setup steps after "Start event planning" (review findings #4/#6), in order.
	return [
		{ label: 'Confirm the date and time', section: 'basic', done: !!(e.event_date && e.start_time && e.end_time) },
		{ label: 'Confirm the venue and guests', section: 'venue', done: !!(e.location && e.expected_guests) },
		{ label: 'Choose private or public', section: 'tickets', done: !!e.ticket_types?.length },
		{ label: 'Add a contact for the day', section: 'contact', done: !!(e.contact_name && (e.contact_phone || e.contact_email)) },
		{ label: 'Describe the event', section: 'basic', done: !!e.description },
		{ label: 'Publish it', section: 'basic', done: e.status !== 'draft' },
	]
})
// Private events with tickets: the link that opens their page and checkout (EventGate key).
const privateLink = computed(() => {
	const e = event.value as (OrganizerEvent & { private_link_key?: string | null }) | null
	if (!e?.private_link_key || !(e.ticket_types ?? []).some(t => !isGuestTier(t)) || !import.meta.client) return ''
	return `${window.location.origin}/event-registration/${e.id}?key=${encodeURIComponent(e.private_link_key)}`
})
const linkCopied = ref(false)
async function copyPrivateLink() {
	try { await navigator.clipboard.writeText(privateLink.value) } catch { window.prompt('Copy this link', privateLink.value); return }
	linkCopied.value = true
	setTimeout(() => { linkCopied.value = false }, 2000)
}
const isLocked = computed(() => ['completed', 'cancelled'].includes(String(event.value?.status ?? '')))
const hasStarted = computed(() => {
	const e = event.value
	if (!e?.event_date) return false
	const start = new Date(`${e.event_date}T${e.start_time || '00:00'}`).getTime()
	return !Number.isNaN(start) && start <= Date.now()
})
// While setting up: share of setup steps done. Afterwards: verified preparation tasks.
const ringPercent = computed(() => {
	const checks = readinessChecks.value
	if (setupLeft.value && checks.length) return Math.round(((checks.length - setupLeft.value) / checks.length) * 100)
	const pct = (event.value as { readiness_percent?: number | null } | null)?.readiness_percent
	return typeof pct === 'number' ? Math.round(pct) : 100
})
const setupLeft = computed(() => readinessChecks.value.filter(c => !c.done).length)
async function openSection(section: string) {
	if (!event.value) return
	fillEditForm()
	await navigateTo({ path: '/organizer/eventmanagement/event-details', query: { event: event.value.id, mode: 'edit', section } })
}
const viewLinks = [
	{ to: '/organizer/eventmanagement/ticket-tiers', label: 'Ticket tiers', hint: 'Prices, seats and sales', icon: 'ticket' },
	{ to: '/organizer/eventmanagement/preparation-tracking', label: 'Preparation tracking', hint: 'Tasks and assignments', icon: 'clipboard-list' },
]
</script>

<style scoped>
.ed-row { animation: ed-rise 0.4s cubic-bezier(0.2, 0.7, 0.2, 1) both; animation-delay: calc(var(--i, 0) * 40ms); }
@keyframes ed-rise { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .ed-row { animation: none; } }

.ed-label { @apply mb-1.5 block text-sm font-semibold text-gray-800; }
.ed-field { @apply h-11 w-full rounded-xl border border-gray-200 bg-white px-3.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 hover:border-gray-300 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/10; }
.ed-icon { @apply pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400; }
.ed-nav { @apply flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl px-2 py-1.5 text-left lg:w-full lg:gap-3 lg:whitespace-normal lg:px-2.5 lg:py-2.5 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600; }
.ed-nav.is-on { @apply bg-primary-25 ring-1 ring-primary-200; }
.ed-choice { @apply flex items-center gap-3 rounded-xl border border-gray-200 px-4 py-3 transition hover:border-gray-300; }
.ed-choice.is-on { @apply border-primary-600 bg-primary-25 ring-1 ring-primary-600; }
.ed-radio { @apply relative h-4 w-4 shrink-0 rounded-full border-2 border-gray-300 transition; }
.ed-radio.is-on { @apply border-primary-700; }
.ed-radio.is-on::after { content: ''; @apply absolute inset-[3px] rounded-full bg-primary-700; }
.ed-switch { @apply relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-gray-200 transition-colors duration-200 group-hover:bg-gray-300; }
.ed-switch.is-on { @apply bg-primary-700 group-hover:bg-primary-800; }
.ed-knob { @apply absolute left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform duration-200; }
.ed-switch.is-on .ed-knob { transform: translateX(20px); }
.ed-btn { @apply inline-flex h-11 items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-gray-200 bg-white px-3 sm:px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600 disabled:cursor-not-allowed disabled:opacity-50; }
.ed-btn-primary { @apply border-primary-700 bg-primary-700 px-3 sm:px-5 text-white hover:bg-primary-800; }
.ed-dots { position: absolute; inset: 0; pointer-events: none; background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1px, transparent 1.3px); background-size: 16px 16px; mask-image: linear-gradient(105deg, transparent 20%, black 75%); }
.ed-step-enter-active, .ed-step-leave-active { transition: opacity 0.22s ease, transform 0.22s ease; }
.ed-step-enter-from { opacity: 0; transform: translateX(12px); }
.ed-step-leave-to { opacity: 0; transform: translateX(-8px); }
.ed-card-enter-active, .ed-card-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.ed-card-enter-from, .ed-card-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }
.ed-card-move { transition: transform 0.3s ease; }
.ed-fade-enter-active, .ed-fade-leave-active { transition: opacity 0.18s ease; }
.ed-fade-enter-from, .ed-fade-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
	.ed-step-enter-active, .ed-step-leave-active, .ed-card-enter-active, .ed-card-leave-active, .ed-card-move { transition: none; }
}
</style>
