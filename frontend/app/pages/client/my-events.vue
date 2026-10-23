<template>
	<div>
		<OrganizerThreadDrawer :thread-id="openThreadId" :title="openThreadTitle" @close="openThreadId = null; loadThreadUnread()" />
		<div v-if="threadError" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ threadError }}</div>
		<div v-if="showQuotations && selectedEvent">
			<div class="mb-5 flex flex-wrap gap-y-2">
				<button type="button"
					class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:border-gray-300 hover:bg-gray-50"
					@click="backToMyEvents">
					<IconBase name="arrow-left" class="h-4 w-4" />
					Back to My Events
				</button>
				<NuxtLink :to="`/client/inquiries/${selectedEvent.id}`"
					class="ml-2 inline-flex items-center gap-2 rounded-xl border border-primary-200 bg-primary-50 px-4 py-2.5 text-sm font-semibold text-primary-700 transition hover:bg-primary-100">
					<IconBase name="edit" class="h-4 w-4" />
					{{ ['open', 'receiving_quotations'].includes(selectedEvent.status) ? 'Edit or cancel request' : 'View request' }}
				</NuxtLink>
			</div>

			<div v-if="isLoadingQuotations" class="rounded-2xl border border-gray-200 bg-white p-10 text-center">
				<div class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-gray-50 text-primary-700">
					<IconBase name="refresh-cw" class="h-5 w-5 animate-spin" />
				</div>

				<p class="mt-3 text-sm font-medium text-gray-500">
					Loading quotations...
				</p>
			</div>

			<!-- Quotation error -->
			<div v-else-if="quotationError"
				class="rounded-2xl border border-red-200 bg-red-50 p-5 text-sm text-red-700">
				{{ quotationError }}
			</div>

			<!-- Organizer quotations -->
			<div v-else-if="quotations.length" class="space-y-5">
				<article v-for="quotation in quotations" :key="quotation.id"
					class="rounded-2xl border border-gray-200 bg-white p-5 sm:p-6" :class="{
						'border-green-300 bg-green-50/30':
							quotation.quotation_status === 'accepted',
					}">
					<div class="flex flex-wrap items-start justify-between gap-4">
						<div class="flex items-start gap-3">
							<div
								class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-50 font-bold text-primary-700">
								{{ organizerInitials(quotation) }}
							</div>

							<div>
								<h2 class="text-lg font-bold text-gray-900">
									{{ organizerName(quotation) }}
								</h2>

								<p class="mt-0.5 text-sm text-gray-500">
									Organizer
								</p>
							</div>
						</div>

						<span class="rounded-full px-3 py-1 text-xs font-semibold"
							:class="statusClass(quotation.quotation_status)">
							{{ formatStatus(quotation.quotation_status) }}
						</span>
					</div>

					<div class="mt-6 rounded-xl bg-gray-50 p-5">
						<div class="text-xs font-medium uppercase tracking-wide text-gray-500">
							Quotation Amount
						</div>

						<div class="mt-1 text-3xl font-extrabold text-gray-900">
							{{ formatQuotationCurrency(quotation.quotation_amount) }}
						</div>
					</div>

					<div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
						<div class="rounded-xl border border-gray-200 p-4">
							<div class="text-xs text-gray-500">
								Package / Offer Name
							</div>

							<div class="mt-1 font-semibold text-gray-900">
								{{ quotation.package_name || 'Not specified' }}
							</div>
						</div>

						<div class="rounded-xl border border-gray-200 p-4">
							<div class="text-xs text-gray-500">
								Timeline / Lead Time
							</div>

							<div class="mt-1 font-semibold text-gray-900">
								{{ quotation.timeline || 'Not specified' }}
							</div>
						</div>
					</div>

					<div v-if="quotation.inclusions?.length" class="mt-5">
						<h3 class="text-sm font-bold text-gray-900">
							Inclusions / Services
						</h3>

						<div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
							<div v-for="item in quotation.inclusions" :key="item.id"
								class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">
								<IconBase name="check-circle" class="h-4 w-4 shrink-0 text-green-600" />

								{{ item.description }}
							</div>
						</div>
					</div>

					<div v-if="quotation.quotation_details" class="mt-5 rounded-xl bg-gray-50 p-4">
						<h3 class="text-sm font-bold text-gray-900">
							Organizer's Pitch
						</h3>

						<p class="mt-2 text-sm leading-6 text-gray-600">
							{{ quotation.quotation_details }}
						</p>
					</div>

					<div class="mt-6 flex flex-wrap items-center justify-end gap-3">
						<button v-if="quotation.quotation_status !== 'rejected' && quotation.quotation_status !== 'withdrawn'" type="button"
							class="relative inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700"
							@click="openThread(quotation)">
							<IconBase name="message" class="h-4 w-4" />
							Message
							<span v-if="threadUnread[String(quotation.organizer?.id)]" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-[#285F6b] px-1.5 text-[11px] font-bold text-white">{{ threadUnread[String(quotation.organizer?.id)] }}</span>
						</button>

						<button v-if="quotation.quotation_status === 'pending'" type="button"
							class="flex items-center gap-2 rounded-xl bg-[#285F6b] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1f4a54] disabled:cursor-not-allowed disabled:opacity-60"
							:disabled="acceptingQuotationId === quotation.id" @click="requestQuotationAcceptance(quotation)">
							<IconBase name="award" class="h-4 w-4" />

							{{
								acceptingQuotationId === quotation.id
									? 'Accepting...'
									: 'Accept Quotation'
							}}
						</button>

						<div v-else-if="quotation.quotation_status === 'accepted'"
							class="flex items-center gap-2 rounded-xl bg-green-100 px-4 py-2.5 text-sm font-semibold text-green-700">
							<IconBase name="check-circle" class="h-4 w-4" />
							Selected Organizer
						</div>

						<div v-else-if="quotation.quotation_status === 'rejected'"
							class="rounded-xl bg-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-500">
							Not Selected
						</div>

						<div v-else-if="quotation.quotation_status === 'withdrawn'"
							class="rounded-xl bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-600">
							Withdrawn
						</div>
					</div>
				</article>
			</div>

			<!-- No quotations -->
			<div v-else class="rounded-2xl border border-gray-200 bg-white px-6 py-20 text-center">
				<div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
					<IconBase name="file-text" class="h-6 w-6" />
				</div>

				<h2 class="mt-4 text-lg font-bold text-gray-900">
					No offers yet
				</h2>

				<template v-if="['open', 'receiving_quotations'].includes(selectedEvent.status)">
					<p class="mx-auto mt-1 max-w-md text-sm text-gray-500">
						Your request is open. Organizers you invite, and those near your event, send their quotations here.
					</p>
					<div class="mt-5 flex flex-wrap justify-center gap-2">
						<NuxtLink :to="`/client/inquiries/${selectedEvent.id}#invite`"
							class="rounded-xl bg-primary-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-900">
							Invite organizers
						</NuxtLink>
						<NuxtLink :to="`/client/inquiries/${selectedEvent.id}`"
							class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50">
							View request
						</NuxtLink>
					</div>
				</template>
				<template v-else>
					<p class="mt-1 text-sm text-gray-500">
						No organizer quotations came in for this request.
					</p>
					<NuxtLink :to="`/client/inquiries/${selectedEvent.id}`" class="mt-3 inline-block text-sm font-semibold text-primary-700 hover:underline">View request</NuxtLink>
				</template>
			</div>
		</div>

		<!-- ===================================================== -->
		<!-- NORMAL MY EVENTS DASHBOARD -->
		<!-- ===================================================== -->
		<div v-else>

			<header class="me-hero me-rise relative mb-4 overflow-hidden rounded-[1.5rem] text-white sm:mb-6 sm:rounded-[2rem]">
				<span class="me-orb" />
				<span class="me-dots" />

				<div class="relative flex flex-col gap-4 px-5 pb-5 pt-6 sm:gap-6 sm:px-9 sm:pb-6 sm:pt-8 lg:flex-row lg:items-center lg:justify-between">
					<div class="max-w-2xl">
						<div class="inline-flex items-center gap-2 rounded-full bg-white/12 px-3 py-1 text-xs font-bold uppercase tracking-[0.16em] text-white/85 ring-1 ring-white/20 backdrop-blur">
							<span class="h-1.5 w-1.5 rounded-full bg-white/80"></span>
							Your event workspace
						</div>
						<h1 class="mt-3 text-2xl font-extrabold tracking-tight sm:mt-4 sm:text-4xl">
							Welcome back, {{ clientName }}
						</h1>
						<p class="mt-2 max-w-xl text-sm leading-6 text-white/70 sm:text-base">
							Track every inquiry, compare organizer offers, and move your event forward from one place.
						</p>
					</div>

					<div class="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
						<button type="button"
							class="group inline-flex w-full items-center justify-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-primary-800 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-lg active:translate-y-0 active:scale-[0.98] sm:w-auto"
							@click="navigateTo('/client/post-event')">
							<IconBase name="plus" class="h-4 w-4" />
							Post a new inquiry
							<IconBase name="arrow-right" class="h-4 w-4 transition group-hover:translate-x-0.5" />
						</button>
					</div>
				</div>

				<div class="relative hidden gap-3 px-6 pb-7 sm:grid sm:grid-cols-3 sm:px-9">
					<div v-for="(step, index) in eventJourneySteps" :key="step.title"
						class="me-step me-rise flex items-center gap-3.5 rounded-2xl bg-white/10 p-4 ring-1 ring-white/20 backdrop-blur-sm"
						:style="{ '--d': 120 + index * 80 + 'ms' }">
						<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-white text-sm font-extrabold text-primary-700">
							{{ index + 1 }}
						</span>
						<div class="min-w-0">
							<p class="text-sm font-bold">{{ step.title }}</p>
							<p class="mt-0.5 text-xs text-white/70">{{ step.description }}</p>
						</div>
					</div>
				</div>
			</header>

			<div class="mb-8 grid grid-cols-2 gap-2.5 sm:gap-4 lg:grid-cols-4">
				<div v-for="(stat, index) in stats" :key="stat.label"
					class="me-card me-rise group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-3.5 sm:p-5"
					:style="{ '--d': 100 + index * 60 + 'ms' }">
					<span class="me-line absolute inset-x-0 top-0 h-[2px] bg-[#285F6b]" />

					<div class="flex items-start justify-between gap-3">
						<div class="min-w-0">
							<p class="text-[10px] font-bold uppercase tracking-[0.08em] text-gray-400 sm:text-xs sm:tracking-[0.1em]">{{ stat.label }}</p>
							<p class="mt-1 text-xl font-extrabold tracking-tight text-gray-950 sm:mt-2 sm:text-4xl">{{ stat.value }}</p>
						</div>
						<div class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-primary-50 text-primary-700 transition duration-200 group-hover:scale-105 sm:flex">
							<IconBase :name="stat.icon" class="h-5 w-5" />
						</div>
					</div>
				</div>
			</div>

			<div class="me-rise mb-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between" style="--d: 300ms">
				<div>
					<h2 class="text-xl font-extrabold tracking-tight text-gray-950">Your events</h2>
					<p class="mt-1 text-sm text-gray-500">Choose an event to review offers or continue planning.</p>
				</div>

				<div class="chip-row flex max-w-full gap-2 overflow-x-auto sm:pb-1">
					<button v-for="filter in eventFilters" :key="filter.value" type="button"
						class="inline-flex min-h-9 shrink-0 items-center whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-bold transition duration-200"
						:class="eventFilter === filter.value ? 'bg-primary-700 text-white' : 'border border-gray-200 bg-white text-gray-600 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700'"
						@click="eventFilter = filter.value">
						{{ filter.label }}
						<span class="ml-1 opacity-60">{{ eventFilterCount(filter.value) }}</span>
					</button>
				</div>
			</div>

			<!-- Loading -->
			<div v-if="isLoading"
				class="rounded-3xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-500">
				Loading your inquiries...
			</div>

			<!-- Error -->
			<div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
				{{ errorMessage }}
			</div>

			<!-- Events -->
			<div v-else-if="filteredEvents.length" class="grid gap-5 xl:grid-cols-2">
				<article v-for="(event, index) in filteredEvents" :key="event.id"
					class="me-event me-rise group flex flex-col overflow-hidden rounded-3xl border border-gray-200 bg-white"
					:style="{ '--d': 340 + Math.min(index, 8) * 50 + 'ms' }">
					<div class="flex flex-1 items-stretch">
						<div class="flex w-20 shrink-0 flex-col items-center justify-center border-r border-gray-100 bg-primary-50/60 px-2 py-5 text-center sm:w-24">
							<span class="text-[11px] font-extrabold uppercase tracking-[0.15em] text-primary-700">{{ eventMonth(event.event_date) }}</span>
							<span class="mt-1 text-3xl font-black leading-none text-gray-950">{{ eventDay(event.event_date) }}</span>
							<span class="mt-2 text-[11px] font-semibold text-gray-500">{{ event.event_type }}</span>
						</div>

						<div class="flex min-w-0 flex-1 flex-col p-5">
							<div class="flex items-start justify-between gap-3">
								<div class="min-w-0">
									<h3 class="truncate text-lg font-extrabold tracking-tight text-gray-950">{{ event.event_title || `${event.event_type} Inquiry` }}</h3>
									<p class="mt-1 flex items-start gap-1.5 text-xs leading-5 text-gray-500">
										<IconBase name="map-pin" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary-600" />
										<span class="line-clamp-1">{{ event.location }}</span>
									</p>
								</div>

								<span class="shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-bold" :class="eventStatusClass(event)">
									{{ eventStatusLabel(event) }}
								</span>
							</div>

							<div class="mb-5 mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
								<span class="flex items-center gap-1.5 font-semibold text-gray-800">
									<IconBase name="users" class="h-4 w-4 text-primary-600" />{{ event.expected_guests }} guests
								</span>
								<span class="flex min-w-0 items-center gap-1.5 font-semibold text-gray-800">
									<IconBase name="cash" class="h-4 w-4 shrink-0 text-primary-600" /><span class="truncate">{{ event.budget_range }}</span>
								</span>
								<span class="flex items-center gap-1.5 font-semibold text-gray-800">
									<IconBase name="file-text" class="h-4 w-4 text-primary-600" />{{ event.quotations_count }} offer{{ event.quotations_count === 1 ? '' : 's' }}
								</span>
							</div>

							<div class="mt-auto flex flex-col gap-3 border-t border-gray-100 pt-4">
								<div class="flex min-w-0 items-center gap-2 text-xs text-gray-500">
									<span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-700">
										<IconBase :name="event.status === 'awarded' ? 'check-circle' : event.quotations_count ? 'file-text' : 'clock'" class="h-3.5 w-3.5" />
									</span>
									<span class="truncate">{{ eventProgressCopy(event) }}</span>
								</div>

								<div class="flex flex-wrap items-center justify-end gap-2">
									<NuxtLink :to="`/client/inquiries/${event.id}#messages`"
										class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-xs font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700">
										<IconBase name="message" class="h-3.5 w-3.5" />
										Messages
									</NuxtLink>

									<button v-if="canRateOrganizer(event)" type="button"
										class="inline-flex items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-xs font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700"
										@click="rateOrganizer(event)">
										<IconBase name="star" class="h-3.5 w-3.5" />
										Rate
									</button>

									<button type="button"
										class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-xs font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md active:translate-y-0 active:scale-[0.98]"
										@click="viewQuotations(event)">
										{{ eventActionLabel(event) }}
										<IconBase name="arrow-right" class="h-3.5 w-3.5" />
									</button>
								</div>
							</div>
						</div>
					</div>
				</article>
			</div>

			<!-- Empty State -->
			<div v-else
				class="flex flex-col items-center justify-center rounded-3xl border-2 border-dashed border-gray-200 bg-gray-50/60 px-5 py-16 text-center sm:px-6 sm:py-20">
				<div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gray-100 text-gray-400">
					<IconBase name="sparkles" class="h-6 w-6" />
				</div>

				<h2 class="mt-4 text-lg font-bold text-gray-900">
					{{ clientEvents.length ? 'No events in this stage' : 'No event inquiries yet' }}
				</h2>

				<p class="mt-1 max-w-sm text-sm text-gray-500">
					{{ clientEvents.length
						? 'Choose another filter to see the rest of your events.'
						: 'Post your first event inquiry and qualified organizers will submit quotations for you to review.' }}
				</p>

				<button v-if="!clientEvents.length" type="button"
					class="mt-5 flex items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1f4a54]"
					@click="navigateTo('/client/post-event')">
					<IconBase name="plus" class="h-4 w-4" />
					Post My First Event
				</button>

				<button v-else type="button" class="mt-5 text-sm font-bold text-primary-700 hover:text-primary-900"
					@click="eventFilter = 'all'">
					View all events
				</button>
			</div>
		</div>

		<Teleport to="body">
			<Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0"
				enter-to-class="opacity-100" leave-active-class="transition duration-150 ease-in"
				leave-from-class="opacity-100" leave-to-class="opacity-0">
				<div v-if="confirmationQuotation" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
					role="dialog" aria-modal="true" aria-labelledby="accept-quotation-title" @click.self="closeQuotationConfirmation">
					<div class="w-full max-w-md overflow-hidden rounded-3xl border border-white/70 bg-white shadow-2xl">
						<div class="relative bg-[#285F6b] px-6 pb-7 pt-6 text-white">
							<button type="button" aria-label="Close confirmation"
								class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-white/10 transition hover:bg-white/20"
								:disabled="acceptingQuotationId !== null" @click="closeQuotationConfirmation">
								<IconBase name="x" class="h-4 w-4" />
							</button>
							<div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/20">
								<IconBase name="award" class="h-6 w-6" />
							</div>
							<p class="mt-5 text-xs font-bold uppercase tracking-[0.18em] text-white/65">Final selection</p>
							<h2 id="accept-quotation-title" class="mt-1 text-2xl font-extrabold tracking-tight">Accept this quotation?</h2>
							<p class="mt-2 text-sm leading-6 text-white/75">This organizer will be awarded your event and the other quotations will be closed.</p>
						</div>

						<div class="p-6">
							<div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-4">
								<div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#285F6b]/10 font-bold text-[#285F6b]">
									{{ organizerInitials(confirmationQuotation) }}
								</div>
								<div class="min-w-0 flex-1">
									<p class="truncate font-bold text-gray-900">{{ organizerName(confirmationQuotation) }}</p>
									<p class="mt-0.5 text-xs text-gray-500">{{ confirmationQuotation.package_name || 'Custom Package' }}</p>
								</div>
								<p class="shrink-0 text-lg font-extrabold text-[#285F6b]">{{ formatQuotationCurrency(confirmationQuotation.quotation_amount) }}</p>
							</div>

							<div class="mt-6 grid grid-cols-2 gap-3">
								<button type="button" class="h-12 rounded-xl border border-gray-200 bg-white text-sm font-bold text-gray-700 transition hover:bg-gray-50 disabled:opacity-50"
									:disabled="acceptingQuotationId !== null" @click="closeQuotationConfirmation">Keep reviewing</button>
								<button type="button" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-[#285F6b] text-sm font-bold text-white shadow-lg shadow-[#285F6b]/20 transition hover:bg-[#1f4a54] disabled:cursor-wait disabled:opacity-60"
									:disabled="acceptingQuotationId !== null" @click="acceptQuotation(confirmationQuotation)">
									<IconBase :name="acceptingQuotationId !== null ? 'refresh-cw' : 'check-circle'" :class="['h-4 w-4', acceptingQuotationId !== null && 'animate-spin']" />
									{{ acceptingQuotationId !== null ? 'Accepting...' : 'Yes, accept offer' }}
								</button>
							</div>
						</div>
					</div>
				</div>
			</Transition>
		</Teleport>

		<FormsConfirmationModal :open="quotationAccepted" variant="success" eyebrow="Organizer confirmed"
			title="Quotation accepted successfully"
			description="This organizer is now assigned to your event. You can open the event workspace now or stay here."
			confirm-label="Open event details" cancel-label="Stay on My Events"
			@cancel="quotationAccepted = false" @confirm="openAcceptedEvent">
			<div v-if="selectedEvent" class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 p-4">
				<div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#285F6b]/10 text-[#285F6b]">
					<IconBase name="calendar" class="h-5 w-5" />
				</div>
				<div class="min-w-0">
					<p class="truncate font-extrabold text-gray-900">{{ selectedEvent.event_title || `${selectedEvent.event_type} event` }}</p>
					<p class="mt-0.5 text-xs font-semibold text-emerald-700">Planning can begin</p>
				</div>
			</div>
		</FormsConfirmationModal>


	</div>
</template>

<script setup lang="ts">
interface ClientInquiry {
	id: number
	awarded_quotation?: { organizer_id: number } | null
	client_id: number
	event_title: string | null
	event_type: string
	event_date: string
	location: string
	expected_guests: number
	budget_range: string
	additional_details: string | null
	status:
	| 'open'
	| 'receiving_quotations'
	| 'awarded'
	| 'cancelled'
	awarded_quotation_id: number | null
	quotations_count: number
	created_at: string
	updated_at: string
}

interface ClientInquiryResponse {
	data: ClientInquiry[]
}

interface NotificationData {
	type?: string
	quotation_id?: number
	inquiry_id?: number
	event_title?: string
	organizer_name?: string
	quotation_amount?: string | number
	message?: string
}

interface ClientNotification {
	id: string
	type: string
	data: NotificationData
	read_at: string | null
	created_at: string
	updated_at: string
}

interface NotificationResponse {
	data: ClientNotification[]
	unread_count: number
	meta: {
		current_page: number
		last_page: number
		total: number
	}
}

type NotificationFilter = 'all' | 'unread'
type EventFilter = 'all' | 'open' | 'offers' | 'awarded'

interface QuotationInclusion {
	id: number
	quotation_id: number
	description: string
}

interface OrganizerUser {
	id?: number
	firstname?: string
	middlename?: string | null
	lastname?: string
}

interface Organizer {
	id: number
	user_id?: number
	user?: OrganizerUser
	name?: string
	company_name?: string
}

interface Quotation {
	id: number
	inquiry_id: number
	organizer_id: number
	quotation_amount: string | number
	package_name: string | null
	timeline: string | null
	quotation_details: string | null
	quotation_status:
	| 'pending'
	| 'accepted'
	| 'rejected'
	| 'withdrawn'
	organizer?: Organizer
	inclusions: QuotationInclusion[]
	created_at: string
	updated_at: string
}

interface QuotationResponse {
	data: Quotation[]
}

definePageMeta({
	layout: 'client',
	middleware: ['client'],
})

const config = useRuntimeConfig()

const {
	firstName,
	token,
	user,
	isAuthenticated,
} = useAuth()

const clientName = computed(() => {
	return firstName.value || 'Client'
})

const clientEvents = ref<ClientInquiry[]>([])
const notifications = ref<ClientNotification[]>([])
const unreadNotificationCount = ref(0)
const notificationFilter = ref<NotificationFilter>('all')
const notificationPage = ref(1)
const notificationLastPage = ref(1)
const isLoadingNotifications = ref(false)
const notificationActionId = ref<string | null>(null)
const notificationFilters: Array<{ label: string; value: NotificationFilter }> = [
	{ label: 'All', value: 'all' },
	{ label: 'Unread', value: 'unread' },
]
const eventFilter = ref<EventFilter>('all')
const eventFilters: Array<{ label: string; value: EventFilter }> = [
	{ label: 'All', value: 'all' },
	{ label: 'Awaiting', value: 'open' },
	{ label: 'Offers ready', value: 'offers' },
	{ label: 'Confirmed', value: 'awarded' },
]
const eventJourneySteps = [
	{ title: 'Post an inquiry', description: 'Share your event details' },
	{ title: 'Compare offers', description: 'Choose the right organizer' },
	{ title: 'Plan with confidence', description: 'Track your confirmed event' },
]

const isLoading = ref(false)
const errorMessage = ref('')

const showQuotations = ref(false)
const selectedEvent = ref<ClientInquiry | null>(null)

const quotations = ref<Quotation[]>([])
const isLoadingQuotations = ref(false)
const quotationError = ref('')
const acceptingQuotationId = ref<number | null>(null)
const confirmationQuotation = ref<Quotation | null>(null)
const quotationAccepted = ref(false)

const eventTypeIcons: Record<string, string> = {
	Wedding: 'heart',
	Corporate: 'briefcase',
	Birthday: 'gift',
	Debut: 'star',
	Concert: 'music',
	Conference: 'users',
	Reunion: 'users',
	Seminar: 'award',
}

function eventTypeIcon(type: string): string {
	return eventTypeIcons[type] ?? 'sparkles'
}

function formatDate(dateStr: string): string {
	if (!dateStr) {
		return ''
	}

	const date = dateStr.includes('T')
		? new Date(dateStr)
		: new Date(`${dateStr}T00:00:00`)

	return date.toLocaleDateString('en-US', {
		month: 'long',
		day: 'numeric',
		year: 'numeric',
	})
}

function eventMonth(dateStr: string): string {
	if (!dateStr) return 'TBD'

	const date = dateStr.includes('T')
		? new Date(dateStr)
		: new Date(`${dateStr}T00:00:00`)

	return date.toLocaleDateString('en-US', { month: 'short' })
}

function eventDay(dateStr: string): string {
	if (!dateStr) return '—'

	const date = dateStr.includes('T')
		? new Date(dateStr)
		: new Date(`${dateStr}T00:00:00`)

	return date.toLocaleDateString('en-US', { day: '2-digit' })
}

function eventStatusLabel(event: ClientInquiry): string {
	if (event.status === 'awarded') return 'Confirmed'
	if (event.status === 'cancelled') return 'Cancelled'
	if (event.quotations_count > 0) return 'Offers ready'
	return 'Awaiting offers'
}

function eventStatusClass(event: ClientInquiry): string {
	if (event.status === 'awarded') return 'border-transparent bg-[#285F6b] text-white'
	if (event.status === 'cancelled') return 'border-red-200 bg-red-50 text-red-600'
	if (event.quotations_count > 0) return 'border-primary-100 bg-primary-50 text-primary-700'
	return 'border-gray-200 bg-gray-50 text-gray-600'
}

function eventProgressCopy(event: ClientInquiry): string {
	if (event.status === 'awarded') return 'Organizer selected — continue planning'
	if (event.status === 'cancelled') return 'This inquiry has been cancelled'
	if (event.quotations_count > 0) return `${event.quotations_count} organizer offer${event.quotations_count === 1 ? '' : 's'} ready to compare`
	return 'Shared with matching organizers'
}

function eventActionLabel(event: ClientInquiry): string {
	if (event.status === 'awarded') return 'Open event'
	if (event.quotations_count > 0) return 'Review offers'
	return 'View status'
}

function matchesEventFilter(event: ClientInquiry, filter: EventFilter): boolean {
	if (filter === 'awarded') return event.status === 'awarded'
	if (filter === 'offers') return event.status !== 'awarded' && event.quotations_count > 0
	if (filter === 'open') return event.status !== 'awarded' && event.status !== 'cancelled' && event.quotations_count === 0
	return true
}

function eventFilterCount(filter: EventFilter): number {
	return clientEvents.value.filter(event => matchesEventFilter(event, filter)).length
}

const filteredEvents = computed(() =>
	clientEvents.value.filter(event => matchesEventFilter(event, eventFilter.value)),
)

function formatCurrency(
	value: string | number,
): string {
	const amount = Number(value)

	if (Number.isNaN(amount)) {
		return String(value)
	}

	return new Intl.NumberFormat('en-PH', {
		style: 'currency',
		currency: 'PHP',
		maximumFractionDigits: 0,
	}).format(amount)
}

function formatQuotationCurrency(
	value: string | number,
): string {
	const amount = Number(value)

	if (Number.isNaN(amount)) {
		return '₱0.00'
	}

	return new Intl.NumberFormat('en-PH', {
		style: 'currency',
		currency: 'PHP',
		minimumFractionDigits: 2,
	}).format(amount)
}

function formatRelativeTime(
	dateString: string,
): string {
	const timestamp =
		new Date(dateString).getTime()

	const diff =
		Math.max(
			0,
			Date.now() - timestamp,
		)

	const minutes =
		Math.floor(diff / 60000)

	if (minutes < 1) {
		return 'Just now'
	}

	if (minutes < 60) {
		return `${minutes}m ago`
	}

	const hours =
		Math.floor(minutes / 60)

	if (hours < 24) {
		return `${hours}h ago`
	}

	const days =
		Math.floor(hours / 24)

	return `${days}d ago`
}

function organizerName(
	quotation: Quotation,
): string {
	const organizer =
		quotation.organizer

	if (!organizer) {
		return 'Organizer'
	}

	const organizerUser =
		organizer.user

	if (organizerUser) {
		const fullName = [
			organizerUser.firstname,
			organizerUser.middlename,
			organizerUser.lastname,
		]
			.filter(Boolean)
			.join(' ')

		if (fullName) {
			return fullName
		}
	}

	return (
		organizer.company_name ||
		organizer.name ||
		'Organizer'
	)
}

function organizerInitials(
	quotation: Quotation,
): string {
	const words =
		organizerName(quotation)
			.split(' ')
			.filter(Boolean)

	if (!words.length) {
		return 'OR'
	}

	if (words.length === 1) {
		return (
			words[0]
				?.charAt(0)
				.toUpperCase() ||
			'OR'
		)
	}

	const first =
		words[0] ?? ''

	const last =
		words[
		words.length - 1
		] ?? ''

	return (
		first.charAt(0).toUpperCase() +
		last.charAt(0).toUpperCase()
	)
}

function formatStatus(
	status: Quotation['quotation_status'],
): string {
	switch (status) {
		case 'accepted':
			return 'Accepted'

		case 'rejected':
			return 'Not Selected'

		case 'withdrawn':
			return 'Withdrawn'

		default:
			return 'Pending'
	}
}

function statusClass(
	status: Quotation['quotation_status'],
): string {
	switch (status) {
		case 'accepted':
			return 'bg-green-100 text-green-700'

		case 'rejected':
			return 'bg-gray-100 text-gray-500'

		case 'withdrawn':
			return 'bg-red-50 text-red-600'

		default:
			return 'bg-amber-50 text-amber-700'
	}
}

/** /me/notifications in this panel's shape (links point at the inquiry). */
async function loadNotifications(
	page = 1,
	append = false,
) {
	isLoadingNotifications.value = true
	try {
		const api = useNotifications()
		const { items, meta } = await api.list({ unread: notificationFilter.value === 'unread', page })
		const mapped = items.map(n => ({
			id: n.id,
			type: n.type,
			data: {
				type: n.type,
				message: n.title,
				event_title: n.body ?? undefined,
				inquiry_id: (n.url?.match(/inquiries\/([0-9a-f-]{36})/)?.[1] ?? undefined) as unknown as number | undefined,
			},
			read_at: n.read ? n.created_at : null,
			created_at: n.created_at,
			updated_at: n.created_at,
		}))
		notifications.value = append ? [...notifications.value, ...mapped] : mapped
		await api.refreshCount()
		unreadNotificationCount.value = useUnreadCount().value
		const pagination = meta?.pagination as { current_page?: number, last_page?: number } | undefined
		notificationPage.value = pagination?.current_page ?? page
		notificationLastPage.value = pagination?.last_page ?? 1
	} catch {
		// The panel stays as it was.
	} finally {
		isLoadingNotifications.value = false
	}
}

async function setNotificationFilter(
	filter: NotificationFilter,
) {
	if (notificationFilter.value === filter) {
		return
	}

	notificationFilter.value = filter
	await loadNotifications()
}

async function loadMoreNotifications() {
	if (
		isLoadingNotifications.value ||
		notificationPage.value >= notificationLastPage.value
	) {
		return
	}

	await loadNotifications(
		notificationPage.value + 1,
		true,
	)
}

async function markNotificationAsRead(
	notificationId: string,
) {
	await useNotifications().read(notificationId)
}

/** Our notifications can be marked read (not unread again). */
async function toggleNotificationRead(
	notification: ClientNotification,
) {
	if (notificationActionId.value || notification.read_at) return
	notificationActionId.value = notification.id
	try {
		await useNotifications().read(notification.id)
		await loadNotifications()
	} finally {
		notificationActionId.value = null
	}
}

/** Notifications are kept; "remove" hides it from this panel. */
async function removeNotification(
	notificationId: string,
) {
	notifications.value = notifications.value.filter(n => n.id !== notificationId)
}

async function markAllNotificationsAsRead() {
	try {
		await useNotifications().readAll()
		await loadNotifications()
	} catch {
		// Nothing to do.
	}
}

async function openNotification(
	notification: ClientNotification,
) {
	try {
		if (!notification.read_at) {
			await markNotificationAsRead(
				notification.id,
			)
		}

		await loadNotifications()

		const inquiryId =
			notification.data.inquiry_id

		if (!inquiryId) {
			return
		}

		const event =
			clientEvents.value.find(
				item =>
					item.id === inquiryId,
			)

		if (event) {
			await viewQuotations(event)
		}
	} catch (error) {
		console.error(
			'Failed to open notification:',
			error,
		)
	}
}

type ApiClientQuotation = import('~/composables/useQuotations').ClientQuotation

/** Our quotation in this page's shape (the organizer's price lines become inclusions). */
function toQuotation(q: ApiClientQuotation): Quotation {
	const status = q.status === 'submitted' ? 'pending' : q.status === 'accepted' ? 'accepted' : q.status === 'withdrawn' ? 'withdrawn' : 'rejected'
	return {
		id: q.id as unknown as number,
		inquiry_id: q.inquiry_id as unknown as number,
		organizer_id: 0,
		quotation_amount: q.total_amount,
		package_name: q.package_name,
		timeline: q.terms,
		quotation_details: q.proposal,
		quotation_status: status,
		organizer: { id: q.organizer.id as unknown as number, name: q.organizer.display_name, company_name: q.organizer.display_name },
		inclusions: q.items.map((item, i) => ({ id: i, quotation_id: 0, description: `${item.description}${Number(item.quantity) !== 1 ? ` × ${item.quantity}` : ''} — ₱${Number(item.subtotal ?? Number(item.quantity) * Number(item.unit_price)).toLocaleString('en-PH')}` })),
		created_at: q.submitted_at ?? '',
		updated_at: q.decided_at ?? q.submitted_at ?? '',
	}
}

// Private messages with each organizer on this inquiry (one thread per organizer).
const inquiryThreads = ref<import('~/composables/useThreads').ThreadSummary[]>([])
const openThreadId = ref<string | null>(null)
const openThreadTitle = ref('')
const threadError = ref('')
const threadUnread = computed<Record<string, number>>(() => Object.fromEntries(inquiryThreads.value.map(t => [t.organization.id, t.unread])))

async function loadThreadUnread() {
	if (!selectedEvent.value) return
	try {
		inquiryThreads.value = await useThreads().forInquiry(String(selectedEvent.value.id))
	} catch {
		inquiryThreads.value = []
	}
}

async function openThread(quotation: Quotation) {
	threadError.value = ''
	if (!inquiryThreads.value.length) await loadThreadUnread()
	const thread = inquiryThreads.value.find(t => t.organization.id === String(quotation.organizer?.id))
	if (!thread) {
		threadError.value = 'There is no conversation with this organizer yet.'
		return
	}
	openThreadTitle.value = `${thread.organization.name} · ${thread.event_type}`
	openThreadId.value = thread.id
}

async function loadQuotations() {
	quotationError.value = ''
	if (!selectedEvent.value) {
		quotationError.value = 'No inquiry was selected.'
		return
	}
	isLoadingQuotations.value = true
	try {
		quotations.value = (await useClientQuotations().forInquiry(String(selectedEvent.value.id))).map(toQuotation)
		void loadThreadUnread()
	} catch (error: unknown) {
		quotationError.value = authMessage(error) || 'Unable to load quotations.'
	} finally {
		isLoadingQuotations.value = false
	}
}

// Reviews open once an awarded event's date has passed.
function canRateOrganizer(event: ClientInquiry): boolean {
	if (event.status !== 'awarded' || !event.event_date) return false

	const day = new Date(event.event_date.includes('T') ? event.event_date : `${event.event_date}T00:00:00`)
	const today = new Date()
	today.setHours(0, 0, 0, 0)

	return day.getTime() < today.getTime()
}

async function rateOrganizer(event: ClientInquiry) {
	await navigateTo({
		path: '/client/event-details',
		query: { inquiry: event.id, review: '1' },
	})
}

async function viewQuotations(
	event: ClientInquiry,
) {
	if (event.status === 'awarded') {
		await navigateTo({
			path: '/client/event-details',
			query: {
				inquiry: event.id,
			},
		})

		return
	}

	selectedEvent.value = event
	showQuotations.value = true

	quotations.value = []
	quotationError.value = ''

	await loadQuotations()
}

function backToMyEvents() {
	showQuotations.value = false
	selectedEvent.value = null
	quotations.value = []
	quotationError.value = ''
	acceptingQuotationId.value = null
	confirmationQuotation.value = null
}

function requestQuotationAcceptance(quotation: Quotation) {
	confirmationQuotation.value = quotation
}

function closeQuotationConfirmation() {
	if (acceptingQuotationId.value !== null) {
		return
	}

	confirmationQuotation.value = null
}

function openAcceptedEvent() {
	quotationAccepted.value = false

	if (!selectedEvent.value) return

	navigateTo({
		path: '/client/event-details',
		query: { inquiry: selectedEvent.value.id },
	})
}

async function acceptQuotation(
	quotation: Quotation,
) {
	acceptingQuotationId.value = quotation.id
	try {
		await useClientQuotations().accept(String(quotation.id))
		await Promise.all([loadQuotations(), loadClientInquiries()])
		confirmationQuotation.value = null
		if (selectedEvent.value) {
			const selectedEventId = selectedEvent.value.id
			const updatedEvent = clientEvents.value.find(event => event.id === selectedEventId)
			if (updatedEvent) selectedEvent.value = updatedEvent
		}
		quotationAccepted.value = true
	} catch (error: unknown) {
		quotationError.value = authMessage(error) || 'Unable to accept quotation. Please try again.'
		confirmationQuotation.value = null
	} finally {
		acceptingQuotationId.value = null
	}
}

/** The client's inquiries (their "events" here), with how many quotations each has. */
async function loadClientInquiries() {
	errorMessage.value = ''
	isLoading.value = true
	try {
		const inquiries = await allItems(page => useInquiries().list(page))
		const quotes = await Promise.all(inquiries.map(i => ['draft', 'cancelled'].includes(i.status)
			? Promise.resolve([] as ApiClientQuotation[])
			: useClientQuotations().forInquiry(i.id).catch(() => [] as ApiClientQuotation[])))
		const peso = (v: string | null) => (v ? `₱${Number(v).toLocaleString('en-PH')}` : null)
		clientEvents.value = inquiries.map((i, index) => {
			const list = quotes[index] ?? []
			const accepted = list.find(q => q.status === 'accepted')
			const status = i.status === 'cancelled' ? 'cancelled'
				: ['accepted', 'converted_to_event'].includes(i.status) ? 'awarded'
					: list.length ? 'receiving_quotations' : 'open'
			return {
				id: i.id as unknown as number,
				client_id: 0,
				event_title: i.event_type ? `${i.event_type}${i.guest_count ? ` · ${i.guest_count} guests` : ''}` : 'Event inquiry',
				event_type: i.event_type ?? 'Other',
				event_date: i.requested_date ?? '',
				location: i.location_text ?? '',
				expected_guests: i.guest_count ?? 0,
				budget_range: [peso(i.budget_min), peso(i.budget_max)].filter(Boolean).join(' – ') || 'Open budget',
				additional_details: i.additional_requirements ?? null,
				status,
				awarded_quotation_id: (accepted?.id ?? null) as unknown as number | null,
				quotations_count: list.filter(q => q.status !== 'draft').length,
				created_at: i.created_at,
				updated_at: i.created_at,
			} as ClientInquiry
		})
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to load your events.'
	} finally {
		isLoading.value = false
	}
}

const stats = computed(() => {
	const receivingQuotations =
		clientEvents.value.filter(
			event =>
				event.status ===
				'receiving_quotations' &&
				event.quotations_count > 0,
		).length

	const awardedEvents =
		clientEvents.value.filter(
			event =>
				event.status === 'awarded',
		).length

	const openEvents =
		clientEvents.value.filter(
			event =>
				event.status === 'open' ||
				event.status ===
				'receiving_quotations',
		).length

	return [
		{
			label: 'Total Inquiries',
			value: clientEvents.value.length,
			icon: 'file-text',
			iconBg: 'bg-gray-100',
			iconColor: 'text-gray-500',
		},
		{
			label: 'Receiving Quotations',
			value: receivingQuotations,
			icon: 'sparkles',
			iconBg: 'bg-blue-50',
			iconColor: 'text-blue-600',
		},
		{
			label: 'Awarded Events',
			value: awardedEvents,
			icon: 'award',
			iconBg: 'bg-green-50',
			iconColor: 'text-green-600',
		},
		{
			label: 'Open Events',
			value: openEvents,
			icon: 'clock',
			iconBg: 'bg-amber-50',
			iconColor: 'text-amber-600',
		},
	]
})

onMounted(() => {
	loadClientInquiries()
	loadNotifications()
})
</script>

<style scoped>
.me-hero {
	background: #285f6b;
}

.me-orb {
	position: absolute;
	top: -120px;
	right: 6%;
	height: 320px;
	width: 320px;
	border-radius: 9999px;
	background: rgba(255, 255, 255, 0.1);
	filter: blur(60px);
	pointer-events: none;
	animation: me-drift 16s ease-in-out infinite;
}

.me-dots {
	position: absolute;
	inset: 0;
	pointer-events: none;
	background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
	background-size: 22px 22px;
	mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
	-webkit-mask-image: linear-gradient(115deg, transparent 25%, #000 90%);
}

.me-rise {
	animation: me-rise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
	animation-delay: var(--d, 0ms);
}

.me-step {
	transition: background-color 0.25s ease, transform 0.25s ease;
}

.me-step:hover {
	background-color: rgba(255, 255, 255, 0.16);
	transform: translateY(-2px);
}

.me-card, .me-event {
	transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
}

.me-card:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 16px 32px -22px rgba(17, 24, 39, 0.35);
}

.me-event:hover {
	transform: translateY(-3px);
	border-color: rgba(40, 95, 107, 0.28);
	box-shadow: 0 20px 38px -26px rgba(17, 24, 39, 0.4);
}

.me-line {
	transform: scaleX(0);
	transform-origin: left;
	transition: transform 0.45s cubic-bezier(0.22, 1, 0.36, 1);
}

.me-card:hover .me-line {
	transform: scaleX(1);
}

@keyframes me-rise {
	from { opacity: 0; transform: translateY(14px); }
	to { opacity: 1; transform: translateY(0); }
}

@keyframes me-drift {
	0%, 100% { transform: translate(0, 0) scale(1); }
	50% { transform: translate(-22px, 16px) scale(1.08); }
}

@media (prefers-reduced-motion: reduce) {
	.me-orb, .me-rise { animation: none; }
	.me-step, .me-card, .me-event, .me-line { transition: none; }
}
</style>
