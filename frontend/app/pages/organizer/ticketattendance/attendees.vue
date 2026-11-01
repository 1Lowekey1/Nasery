<template>
	<div class="rounded-3xl border border-gray-200 bg-white p-4 sm:p-8">
		<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
			<div>
				<h2 class="text-xl font-extrabold tracking-tight text-gray-950">
					Attendee Management
				</h2>

				<p class="mt-1 text-sm text-gray-500">
					<span v-if="selectedEvent">
						View and manage attendees for
						<span class="font-semibold text-gray-700">
							{{ selectedEvent.name }}
						</span>
					</span>

					<span v-else>
						View and manage registered attendees
					</span>
				</p>
			</div>

			<!-- Phones: Register full width, Import + Export side by side -->
			<div v-if="selectedEventId" class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:shrink-0 sm:flex-wrap sm:gap-3">
				<button type="button"
					class="col-span-2 flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:bg-[#1f4a54] hover:shadow-md active:translate-y-0 active:scale-[0.98]"
					@click="openRegisterAttendee">
					<IconBase name="user-plus" class="h-4 w-4" />

					Register Attendee
				</button>

				<button type="button"
					class="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 sm:px-4"
					@click="openImport">
					<IconBase name="upload" class="h-4 w-4" />

					Bulk Import
				</button>

				<button type="button"
					class="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm font-bold text-gray-700 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 disabled:cursor-not-allowed disabled:opacity-50 sm:px-4"
					:disabled="!attendees.length" @click="exportAttendees">
					<IconBase name="download" class="h-4 w-4" />

					Export List
				</button>
			</div>
		</div>

		<div v-if="!selectedEventId" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4">
			<div class="flex items-start gap-3">
				<IconBase name="calendar" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />

				<div>
					<p class="text-sm font-bold text-gray-900">
						Select an Event
					</p>

					<p class="mt-0.5 text-sm text-gray-600">
						Choose an event from the selector above to view and register attendees.
					</p>
				</div>
			</div>
		</div>

		<template v-else>
			<div v-if="successMessage" class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3">
				<div class="flex items-start justify-between gap-3">
					<div class="flex items-start gap-3">
						<IconBase name="check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-green-600" />

						<div>
							<p class="text-sm font-semibold text-green-800">
								{{ successMessage }}
							</p>

							<p v-if="registeredTicket" class="mt-1 text-xs text-green-700">
								Ticket
								{{ registeredTicket.ticket_id }}
								was generated successfully.
							</p>
						</div>
					</div>

					<button type="button" class="tap-target text-green-600 hover:text-green-800" @click="clearSuccess">
						<IconBase name="x" class="h-4 w-4" />
					</button>
				</div>
			</div>

			<div v-if="errorMessage"
				class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
				{{ errorMessage }}
			</div>

			<div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
				<div class="w-full lg:max-w-md">
					<FormsTextField v-model="search" icon="search" placeholder="Search attendees..." />
				</div>

				<div class="chip-row flex flex-wrap items-center gap-2">
					<button v-for="filter in filters" :key="filter.value" type="button"
						class="inline-flex min-h-9 items-center rounded-full px-3.5 py-1.5 text-xs font-bold transition duration-200" :class="activeFilter ===
							filter.value
							? 'bg-primary-700 text-white'
							: 'border border-gray-200 bg-white text-gray-600 hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700'
							" @click="
								activeFilter =
								filter.value
								">
						{{ filter.label }}

						<span v-if="
							getFilterCount(
								filter.value,
							) !== null
						" class="ml-1 opacity-70">
							{{
								getFilterCount(
									filter.value,
								)
							}}
						</span>
					</button>
				</div>
			</div>

			<div v-if="isLoading" class="py-12 text-center">
				<IconBase name="refresh-cw" class="mx-auto h-5 w-5 animate-spin text-gray-400" />

				<p class="mt-2 text-sm text-gray-500">
					Loading attendees...
				</p>
			</div>

			<div v-else-if="
				filteredAttendees.length
			" class="overflow-x-auto">
				<table class="w-full whitespace-nowrap text-left text-sm">
					<thead>
						<tr
							class="bg-gray-50/80 text-[11px] font-bold uppercase tracking-[0.1em] text-gray-400 [&>th]:py-3 [&>th:first-child]:rounded-l-xl [&>th:first-child]:pl-3 [&>th:last-child]:rounded-r-xl [&>th:last-child]:pr-3">
							<th class="pr-4">
								Name
							</th>

							<th class="pr-4">
								Email
							</th>

							<th class="pr-4">
								Ticket Type
							</th>

							<th class="pr-4">
								Ticket ID
							</th>

							<th class="pr-4">
								Category
							</th>

							<th class="pr-4">
								Source
							</th>

							<th class="pr-4">
								Status
							</th>

							<th>
								Payment
							</th>

							<th class="pl-4 text-right">Actions</th>
						</tr>
					</thead>

					<tbody class="divide-y divide-gray-100">
						<tr v-for="attendee in filteredAttendees" :key="attendee.id"
							class="row-in group transition-colors hover:bg-gray-50">
							<td class="py-3.5 pl-3 pr-4">
								<div class="flex items-center gap-3">
									<div
										class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-bold text-primary-700">
										{{
											getInitials(
												attendee.name,
											)
										}}
									</div>

									<div>
										<p class="font-semibold text-gray-900">
											{{
												attendee.name
											}}
										</p>

										<p v-if="
											attendee.checked_in_at
										" class="mt-0.5 text-xs text-gray-400">
											Checked in
											{{
												formatCheckInTime(
													attendee.checked_in_at,
												)
											}}
										</p>
									</div>
								</div>
							</td>

							<td class="py-3.5 pr-4 text-gray-500">
								{{
									attendee.email ||
									'—'
								}}
							</td>

							<td class="py-3.5 pr-4 text-gray-700">
								{{
									attendee.ticket_type
										?.name ||
									'General Admission'
								}}
							</td>

							<td class="py-3.5 pr-4">
								<span class="rounded-md bg-gray-100 px-2 py-1 font-mono text-[11px] font-semibold tracking-tight text-gray-600">
									{{
										attendee.ticket_id
									}}
								</span>
							</td>

							<td class="py-3.5 pr-4">
								<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
									:class="categoryClass(attendee.attendee_category)">
									{{ formatCategory(attendee.attendee_category) }}
								</span>
							</td>

							<td class="py-3.5 pr-4">
								<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="sourceClass(
									attendee.source,
								)
									">
									{{
										formatSource(
											attendee.source,
										)
									}}
								</span>
							</td>

							<td class="py-3.5 pr-4">
								<span
									class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
									:class="statusClass(attendee.status)">
									<IconBase v-if="
										attendee.status ===
										'checked_in'
									" name="check-circle" class="h-3 w-3" />

									{{
										formatStatus(
											attendee.status,
										)
									}}
								</span>
							</td>

							<td class="py-3.5">
								<span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" :class="paymentClass(
									attendee.payment_status,
								)
									">
									{{
										formatPaymentStatus(
											attendee.payment_status,
										)
									}}
								</span>
							</td>

							<td class="py-3.5 pl-4 pr-3 text-right">
								<div v-if="attendee.qr_token" class="flex justify-end gap-2">
									<button type="button" title="Download ticket"
										class="rounded-lg border border-gray-200 bg-white p-2 text-gray-500 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700"
										@click="downloadAttendeeTicket(attendee)">
										<IconBase name="download" class="h-4 w-4" />
									</button>
									<button type="button" :title="attendee.email ? 'Email ticket' : 'No email on file'"
										class="rounded-lg border border-gray-200 bg-white p-2 text-gray-500 transition hover:border-primary-200 hover:bg-primary-50 hover:text-primary-700 disabled:cursor-not-allowed disabled:opacity-40"
										:disabled="!attendee.email || emailingAttendeeId === attendee.id"
										@click="emailAttendeeTicket(attendee)">
										<IconBase :name="emailingAttendeeId === attendee.id ? 'refresh-cw' : (emailedAttendeeIds.has(attendee.id) ? 'check-circle' : 'send')"
											class="h-4 w-4" :class="{ 'animate-spin': emailingAttendeeId === attendee.id, 'text-green-600': emailedAttendeeIds.has(attendee.id) }" />
									</button>
								</div>
								<span v-else class="text-xs text-gray-300">—</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div v-else class="rounded-xl border border-dashed border-gray-300 px-5 py-12 text-center">
				<IconBase name="users" class="mx-auto h-7 w-7 text-gray-400" />

				<p class="mt-2 text-sm font-semibold text-gray-700">
					{{
						search ||
							activeFilter !==
							'all'
							? 'No matching attendees'
							: 'No attendees yet'
					}}
				</p>

				<p class="mt-1 text-xs text-gray-500">
					{{
						search ||
							activeFilter !==
							'all'
							? 'Try changing your search or filter.'
							: 'Online registrations, organizer registrations, and paid walk-ins will appear here.'
					}}
				</p>

				<button v-if="
					!search &&
					activeFilter ===
					'all'
				" type="button" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-[#285F6b] px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-[#1f4a54]"
					@click="openRegisterAttendee">
					<IconBase name="user-plus" class="h-4 w-4" />

					Register First Attendee
				</button>
			</div>
		</template>

		<Transition name="fade">
			<div v-if="showRegisterForm" class="fixed inset-0 z-40 bg-gray-900/40" @click="closeRegisterAttendee" />
		</Transition>

		<Transition name="slide">
			<aside v-if="showRegisterForm" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-2xl" role="dialog" aria-label="Register attendee">
				<header class="flex shrink-0 items-center gap-3.5 border-b border-gray-200 px-6 py-5">
					<span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#285F6b] text-white">
						<IconBase name="user-plus" class="h-5 w-5" />
					</span>

					<div class="min-w-0 flex-1">
						<h2 class="text-base font-extrabold tracking-tight text-gray-950">Register Attendee</h2>
						<p class="mt-0.5 truncate text-sm text-gray-500">{{ selectedEvent?.name || 'Selected event' }}</p>
					</div>

					<button type="button" class="-m-1 rounded-lg p-2.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600" aria-label="Close" @click="closeRegisterAttendee">
						<IconBase name="x" class="h-5 w-5" />
					</button>
				</header>

				<div ref="registerBody" class="flex-1 space-y-7 overflow-y-auto px-6 py-6">
					<p v-if="registerError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700">
						{{ registerError }}
					</p>

					<!-- Attendee -->
					<section class="reg-step">
						<h3 class="mb-3 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-gray-400">
							<span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-50 text-[11px] text-primary-700">1</span>
							Attendee details
						</h3>

						<FormsLabel text="Full Name" required />
						<FormsTextField v-model="registerForm.attendeeName" icon="user" placeholder="e.g. Juan Dela Cruz" required />

						<div class="mt-4">
							<FormsLabel text="Email Address (optional)" />
							<FormsTextField v-model="registerForm.attendeeEmail" type="email" icon="send" placeholder="attendee@example.com" />
							<p class="mt-1.5 text-xs text-gray-500">The QR ticket is e-mailed here.</p>
						</div>
					</section>

					<!-- Ticket -->
					<section class="reg-step" style="--d: 60ms">
						<h3 class="mb-3 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-gray-400">
							<span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-50 text-[11px] text-primary-700">2</span>
							Ticket type <span class="text-red-500">*</span>
						</h3>

						<div v-if="isLoadingTicketTypes" class="space-y-2">
							<div v-for="n in 2" :key="n" class="h-[62px] animate-pulse rounded-2xl bg-gray-100" />
						</div>

						<p v-else-if="!ticketTypes.length" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-700">
							No ticket types are configured for this event. Configure ticket types in Event Management first.
						</p>

						<div v-else class="space-y-2" role="group" aria-label="Ticket type">
							<button v-for="ticketType in ticketTypes" :key="ticketType.id" type="button"
								:aria-pressed="registerForm.ticketTypeId === ticketType.id"
								class="reg-option flex w-full items-center gap-3.5 rounded-2xl border bg-white px-4 py-3.5 text-left"
								:class="registerForm.ticketTypeId === ticketType.id ? 'border-[#285F6b] bg-primary-50/50 ring-1 ring-[#285F6b]' : 'border-gray-200 hover:border-primary-200 hover:bg-gray-50'"
								@click="registerForm.ticketTypeId = registerForm.ticketTypeId === ticketType.id ? null : ticketType.id">
								<span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 transition-colors"
									:class="registerForm.ticketTypeId === ticketType.id ? 'border-[#285F6b] bg-[#285F6b] text-white' : 'border-gray-300'">
									<IconBase v-if="registerForm.ticketTypeId === ticketType.id" name="check" class="h-3 w-3" />
								</span>

								<span class="min-w-0 flex-1">
									<span class="block truncate text-sm font-bold text-gray-950">{{ ticketType.name }}</span>
									<span class="mt-0.5 block text-xs text-gray-500">
										{{ ticketType.capacity ? `${ticketType.capacity} spots` : 'Unlimited capacity' }}
									</span>
								</span>

								<span class="shrink-0 text-sm font-extrabold text-gray-950">
									{{ Number(ticketType.price ?? 0) > 0 ? formatCurrency(ticketType.price ?? 0) : 'Free' }}
								</span>
							</button>
						</div>
					</section>

					<!-- Category -->
					<section class="reg-step" style="--d: 120ms">
						<h3 class="mb-3 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.12em] text-gray-400">
							<span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-50 text-[11px] text-primary-700">3</span>
							Attendee category <span class="text-red-500">*</span>
						</h3>

						<div class="grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1" role="radiogroup" aria-label="Attendee category">
							<button v-for="option in categoryOptions" :key="option.value" type="button" role="radio"
								:aria-checked="registerForm.attendeeCategory === option.value"
								class="rounded-lg py-2 text-sm font-bold transition duration-200"
								:class="registerForm.attendeeCategory === option.value ? 'bg-white text-[#285F6b] shadow-sm' : 'text-gray-500 hover:text-gray-800'"
								@click="registerForm.attendeeCategory = option.value">
								{{ option.label }}
							</button>
						</div>

						<p class="mt-2 px-1 text-xs leading-5 text-gray-500">
							{{ categoryOptions.find(option => option.value === registerForm.attendeeCategory)?.hint }}
						</p>

					</section>

					<div class="flex items-start gap-3 rounded-2xl bg-gray-50 px-4 py-3.5">
						<span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-primary-700 ring-1 ring-gray-200">
							<IconBase name="qr-code" class="h-4 w-4" />
						</span>

						<div>
							<p class="text-sm font-bold text-gray-900">QR ticket will be generated</p>
							<p class="mt-0.5 text-xs leading-5 text-gray-500">
								The attendee gets a unique ticket ID and QR token to scan at check-in.
							</p>
						</div>
					</div>
				</div>

				<footer class="shrink-0 border-t border-gray-200 bg-white px-4 py-4 sm:px-6">
					<div class="mb-3 flex items-center justify-between gap-3 text-sm">
						<p class="min-w-0 truncate text-gray-500">
							<template v-if="selectedTicketType">{{ selectedTicketType.name }}</template>
							<template v-else>No ticket selected</template>
						</p>

						<p class="shrink-0 font-extrabold text-gray-950">
							{{ registerAmount > 0 ? formatCurrency(registerAmount) : 'No charge' }}
						</p>
					</div>

					<p v-if="registerBlocker" class="mb-3 rounded-lg bg-gray-50 px-3 py-2 text-xs font-medium text-gray-500">{{ registerBlocker }}</p>

					<div class="flex gap-3">
						<FormsButton variant="secondary" class="flex-1" :disabled="isRegistering" @click="closeRegisterAttendee">
							Cancel
						</FormsButton>

						<FormsButton class="flex-[2] whitespace-nowrap" :disabled="isRegistering || Boolean(registerBlocker)" @click="submitRegisterAttendee">
							<IconBase v-if="isRegistering" name="refresh-cw" class="h-4 w-4 animate-spin" />
							<IconBase v-else name="user-plus" class="h-4 w-4" />
							{{ isRegistering ? 'Registering...' : 'Register Attendee' }}
						</FormsButton>
					</div>
				</footer>
			</aside>
		</Transition>

		<Transition name="fade">
			<div v-if="showTicketResult" class="fixed inset-0 z-40 bg-gray-900/40" @click="closeTicketResult" />
		</Transition>

		<Transition name="slide">
			<div v-if="
				showTicketResult &&
				registeredTicket
			" class="fixed inset-y-0 right-0 z-50 flex w-full max-w-lg flex-col overflow-hidden bg-[#f4f8f8] shadow-2xl">
				<div
					class="relative overflow-hidden bg-[#285F6b] px-6 py-5 text-white">
					<div class="pointer-events-none absolute -right-12 -top-16 h-36 w-36 rounded-full bg-white/10" />
					<div class="relative flex items-start justify-between gap-4">
						<div>
							<span
								class="inline-flex items-center gap-1.5 rounded-full border border-white/15 bg-white/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.16em] text-white/85">
								<span class="h-1.5 w-1.5 rounded-full bg-emerald-300" /> Official Event Pass
							</span>
							<h2 class="mt-3 text-2xl font-black tracking-tight">
								QR Ticket
							</h2>

							<p class="mt-1 font-mono text-xs font-bold tracking-[0.12em] text-white/70">
								{{ registeredTicket.ticket_id }}
							</p>
						</div>

						<button type="button"
							class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/15 bg-white/10 text-white/80 hover:bg-white/20"
							@click="closeTicketResult">
							<IconBase name="x" class="h-5 w-5" />
						</button>
					</div>
				</div>

				<div class="flex-1 overflow-y-auto px-5 py-5 sm:px-6">
					<div
						class="overflow-hidden rounded-[24px] border border-[#285F6b]/10 bg-white p-5 shadow-[0_12px_40px_rgba(40,95,107,0.08)]">
						<div class="flex items-center gap-4">
							<div
								class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-[#285F6b]/10 text-base font-bold text-[#285F6b]">
								{{
									getInitials(
										registeredTicket.name,
									)
								}}
							</div>

							<div>
								<h3 class="text-base font-bold text-gray-900">
									{{ registeredTicket.name }}
								</h3>

								<p class="mt-0.5 text-sm text-gray-500">
									{{
										registeredTicket.email ||
										'No email provided'
									}}
								</p>
							</div>
						</div>

						<div class="my-5 border-t border-gray-100" />

						<div class="grid grid-cols-2 gap-2.5">
							<div class="rounded-xl bg-gray-50 px-3.5 py-3">
								<p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Category</p>
								<span class="mt-1.5 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
									:class="categoryClass(registeredTicket.attendee_category)">{{
										formatCategory(registeredTicket.attendee_category) }}</span>
							</div>

							<div class="rounded-xl bg-gray-50 px-3.5 py-3">
								<p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Ticket ID</p>
								<p class="mt-1.5 font-mono text-sm font-bold text-gray-900">
									{{ registeredTicket.ticket_id }}
								</p>
							</div>

							<div class="col-span-2 rounded-xl bg-gray-50 px-3.5 py-3">
								<p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Ticket Type</p>
								<p class="mt-1.5 text-sm font-semibold text-gray-900">
									{{
										registeredTicket.ticket_type
											?.name ||
										'General Admission'
									}}
								</p>
							</div>

							<div class="rounded-xl bg-gray-50 px-3.5 py-3">
								<p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Source</p>
								<span
									class="mt-1.5 inline-flex rounded-full bg-violet-50 px-2.5 py-1 text-xs font-semibold text-violet-700">
									Organizer
								</span>
							</div>

							<div class="rounded-xl bg-gray-50 px-3.5 py-3">
								<p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Status</p>
								<span
									class="mt-1.5 inline-flex rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-semibold text-gray-600">
									Registered
								</span>
							</div>

							<div class="col-span-2 rounded-xl bg-gray-50 px-3.5 py-3">
								<p class="text-[10px] font-bold uppercase tracking-wide text-gray-400">Payment</p>
								<span class="mt-1.5 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="paymentClass(
									registeredTicket.payment_status,
								)
									">
									{{
										formatPaymentStatus(
											registeredTicket.payment_status,
										)
									}}
								</span>
							</div>
						</div>
					</div>

					<div
						class="relative mt-5 overflow-hidden rounded-[24px] border border-[#285F6b]/10 bg-white p-5 text-center shadow-[0_12px_40px_rgba(40,95,107,0.08)]">
						<div
							class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-green-600 shadow-sm">
							<IconBase name="qr-code" class="h-7 w-7" />
						</div>

						<h3 class="mt-3 text-lg font-extrabold text-gray-900">
							Ready for event check-in
						</h3>

						<p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-gray-500">
							Present this secure QR code to the organizer scanner at the event.
						</p>

						<div
							class="mx-auto mt-5 flex h-[230px] w-[230px] items-center justify-center rounded-[24px] border border-gray-200 bg-white p-3 shadow-inner">
							<IconBase v-if="isGeneratingTicketQr" name="refresh-cw"
								class="h-7 w-7 animate-spin text-[#285F6b]" />
							<img v-else-if="registeredTicketQrDataUrl" :src="registeredTicketQrDataUrl"
								:alt="`QR code for ${registeredTicket.ticket_id}`"
								class="h-full w-full rounded-xl object-contain">
							<IconBase v-else name="qr-code" class="h-16 w-16 text-gray-300" />
						</div>
					</div>

					<details v-if="registeredTicket.qr_value" class="mt-4 rounded-2xl border border-gray-200 bg-white">
						<summary class="cursor-pointer px-4 py-3.5 text-xs font-bold text-gray-600">Secure QR Value
						</summary>
						<p
							class="break-all border-t border-gray-100 px-4 py-3 font-mono text-[10px] leading-5 text-gray-500">
							{{
								registeredTicket.qr_value }}</p>
					</details>
				</div>

				<div class="border-t border-gray-200/80 bg-white px-5 py-5 sm:px-6">
					<div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
						<button type="button"
							class="rounded-2xl border border-[#285F6b]/20 bg-white px-4 py-3 text-sm font-extrabold text-[#285F6b] hover:bg-[#285F6b]/5"
							:disabled="!registeredTicket.qr_token" @click="downloadRegisteredTicket">Download
							Ticket</button>
						<button type="button"
							class="rounded-2xl bg-[#285F6b] px-4 py-3 text-sm font-extrabold text-white transition hover:bg-[#1f4a54] disabled:opacity-50"
							:disabled="!registeredTicket.email || !registeredTicket.qr_token || isEmailingTicket"
							@click="emailRegisteredTicket">{{ isEmailingTicket ? 'Sending...' : 'Email Ticket'
							}}</button>
					</div>
					<button type="button"
						class="mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-semibold text-gray-500 hover:bg-gray-50"
						@click="closeTicketResult">Done</button>
				</div>
			</div>
		</Transition>
		<OrganizerGuestImportDialog :open="showImport" :tiers="ticketTypes" :event-name="selectedEventName"
			:upload="importGuests" @close="showImport = false" @imported="afterImport" />
	</div>
</template>

<script setup lang="ts">
import QRCode from 'qrcode'
interface OrganizerEvent {
	id: number
	name: string
	event_type: string
	event_date: string | null
	location: string | null
	status: string
	ticket_types?: TicketType[]
}

interface TicketType {
	id: number
	name: string
	price?: string | number
	capacity?: number
}

interface Attendee {
	id: number
	name: string
	email: string | null

	ticket_type:
	| TicketType
	| null

	ticket_id: string

	qr_token: string | null

	attendee_category:
	| 'invited'
	| 'free'
	| 'paid'

	source:
	| 'online'
	| 'walk_in'
	| 'organizer'
	| string

	payment_status:
	| null
	| 'pending'
	| 'paid'
	| 'refunded'
	| string

	status:
	| 'registered'
	| 'checked_in'
	| 'pending_approval'
	| 'rejected'

	checked_in_at:
	| string
	| null

	created_at: string
}

interface RegisteredTicket
	extends Attendee {
	qr_value?: string
}

interface AttendeesResponse {
	data: Attendee[]
}

interface CreateAttendeeResponse {
	message?: string
	data: RegisteredTicket
}

interface EventDetailResponse {
	data?: OrganizerEvent
	id?: number
	name?: string
	event_type?: string
	event_date?: string | null
	location?: string | null
	status?: string
	ticket_types?: TicketType[]
}

interface AttendeeSummary {
	total_registered: number
	checked_in: number
	walk_ins: number
	capacity: number
	capacity_percent: number
}

interface AttendeeSummaryResponse {
	data: AttendeeSummary
}

interface ApiErrorResponse {
	data?: {
		message?: string

		errors?: Record<
			string,
			string[]
		>
	}
}

type AttendeeFilter =
	| 'all'
	| 'pending_approval'
	| 'registered'
	| 'checked_in'
	| 'online'
	| 'walk_in'
	| 'organizer'
	| 'invited'
	| 'free'
	| 'paid'

type PaymentStatus =
	| 'paid'
	| 'pending'

type AttendeeCategory =
	| 'invited'
	| 'free'
	| 'paid'

definePageMeta({
	layout: 'dashboard',
	middleware: ['organizer'],
})

const config =
	useRuntimeConfig()

const {
	token,
} = useAuth()

const organizerEvents =
	useState<OrganizerEvent[]>(
		'ticketingOrganizerEvents',
		() => [],
	)

const selectedEventId =
	useState<number | null>(
		'ticketingSelectedEventId',
		() => null,
	)

const emailVerificationSendUrl = computed(
	() => `${config.public.apiBaseURL}/organizer/events/${selectedEventId.value}/attendees/email-verification/send`,
)

const emailVerificationVerifyUrl = computed(
	() => `${config.public.apiBaseURL}/organizer/events/${selectedEventId.value}/attendees/email-verification/verify`,
)

const ticketingSummary =
	useState<AttendeeSummary>(
		'ticketingAttendeeSummary',
		() => ({
			total_registered: 0,
			checked_in: 0,
			walk_ins: 0,
			capacity: 0,
			capacity_percent: 0,
		}),
	)

const selectedEvent =
	computed<OrganizerEvent | null>(
		() => {
			if (
				!selectedEventId.value
			) {
				return null
			}

			return (
				organizerEvents.value.find(
					event =>
						event.id ===
						selectedEventId.value,
				) ?? null
			)
		},
	)

const search =
	ref('')

const activeFilter =
	ref<AttendeeFilter>(
		'all',
	)

const attendees =
	ref<Attendee[]>([])

const ticketTypes =
	ref<TicketType[]>([])

const isLoading =
	ref(false)

const isLoadingTicketTypes =
	ref(false)

const errorMessage =
	ref('')

const successMessage =
	ref('')

const showRegisterForm =
	ref(false)

const showTicketResult =
	ref(false)

const registerError =
	ref('')

const isRegistering =
	ref(false)

const registeredTicket =
	ref<RegisteredTicket | null>(
		null,
	)

const registeredTicketQrDataUrl = ref('')
const isGeneratingTicketQr = ref(false)
const isEmailingTicket = ref(false)
const emailingAttendeeId = ref<number | null>(null)
const emailedAttendeeIds = ref<Set<number>>(new Set())

const filters: {
	label: string
	value: AttendeeFilter
}[] = [
		{
			label: 'All',
			value: 'all',
		},
	{
		label: 'Registered',
		value: 'registered',
	},
		{
			label: 'Checked-in',
			value: 'checked_in',
		},
		{
			label: 'Online',
			value: 'online',
		},
		{
			label: 'Walk-ins',
			value: 'walk_in',
		},
		{
			label: 'Organizer',
			value: 'organizer',
		},
		{
			label: 'Invited',
			value: 'invited',
		},
		{
			label: 'Free',
			value: 'free',
		},
		{
			label: 'Paid',
			value: 'paid',
		},
	]

function emptyRegisterForm() {
	return {
		attendeeName: '',

		attendeeEmail: '',

		emailVerificationToken: '',

		ticketTypeId:
			null as number | null,

		attendeeCategory:
			'invited' as AttendeeCategory,

		paymentStatus:
			'paid' as PaymentStatus,
	}
}

const registerForm =
	reactive(
		emptyRegisterForm(),
	)

// Organizers add guests directly (the ticket goes to the e-mail given); no code needed.
const registerEmailUnverified = computed(() => false)
const orgIdOf = async () => (await useOrganization().fetchOrganization())?.id ?? ''
const eventKey = () => String(selectedEventId.value)
const ticketingApi = useOrganizerTicketing()

type GuestRowApi = import('~/composables/useTicketing').GuestRow

/** A guest-list row in this page's Attendee shape. */
function toAttendee(g: GuestRowApi): Attendee {
	const category = g.admission_type === 'paid' ? 'paid' : g.admission_type === 'free' || g.admission_type === 'complimentary' ? 'free' : 'invited'
	return {
		id: g.id as unknown as number,
		name: g.name,
		email: g.email,
		ticket_type: { id: 0, name: g.tier },
		ticket_id: g.id.replace(/-/g, '').slice(-10).toUpperCase(),
		// Download / e-mail work by ticket id; any non-empty value enables them.
		qr_token: ['valid', 'used'].includes(g.status) ? g.id : null,
		attendee_category: category,
		source: g.source,
		payment_status: g.admission_type === 'paid' ? 'paid' : null,
		status: g.status === 'used' ? 'checked_in' : g.status === 'valid' ? 'registered' : 'rejected',
		checked_in_at: null,
		created_at: g.issued_at ?? '',
	} as Attendee
}

async function openPdf(ticketId: string) {
	const blob = await ticketingApi.ticketPdf(await orgIdOf(), eventKey(), ticketId)
	window.open(URL.createObjectURL(blob), '_blank')
}

// Added here: invited or complimentary guests only. Paying guests buy online, or
// you sell at the door in the Walk-in Counter (so the cash is recorded).
const categoryOptions = [
	{ value: 'invited', label: 'Invited', hint: 'A guest you invited. No payment. Paying guests buy online or at the Walk-in Counter.' },
	{ value: 'free', label: 'Free', hint: 'Complimentary entry. No payment. Paying guests buy online or at the Walk-in Counter.' },
] as const

const registerBody = ref<HTMLElement | null>(null)

const selectedTicketType =
	computed<TicketType | null>(
		() => {
			if (
				!registerForm.ticketTypeId
			) {
				return null
			}

			return (
				ticketTypes.value.find(
					ticketType =>
						ticketType.id ===
						registerForm.ticketTypeId,
				) ?? null
			)
		},
	)

const filteredAttendees =
	computed(() => {
		const q =
			search.value
				.trim()
				.toLowerCase()

		return attendees.value.filter(
			attendee => {
				let matchesFilter =
					true

				switch (
				activeFilter.value
				) {
					case 'registered':
						matchesFilter =
							attendee.status ===
							'registered'
						break

					case 'pending_approval':
						matchesFilter = attendee.status === 'pending_approval'
						break

					case 'checked_in':
						matchesFilter =
							attendee.status ===
							'checked_in'
						break

					case 'online':
						matchesFilter =
							attendee.source ===
							'online'
						break

					case 'walk_in':
						matchesFilter =
							attendee.source ===
							'walk_in'
						break

					case 'organizer':
						matchesFilter =
							attendee.source ===
							'organizer'
						break

					case 'invited':
					case 'free':
					case 'paid':
						matchesFilter =
							attendee.attendee_category === activeFilter.value
						break
				}

				if (!matchesFilter) {
					return false
				}

				if (!q) {
					return true
				}

				return (
					attendee.name
						.toLowerCase()
						.includes(q) ||
					(
						attendee.email ??
						''
					)
						.toLowerCase()
						.includes(q) ||
					attendee.ticket_id
						.toLowerCase()
						.includes(q) ||
					(
						attendee.ticket_type
							?.name ??
						''
					)
						.toLowerCase()
						.includes(q)
				)
			},
		)
	})

function getFilterCount(
	filter: AttendeeFilter,
): number | null {
	switch (filter) {
		case 'all':
			return attendees.value.length

		case 'registered':
			return attendees.value.filter(
				attendee =>
					attendee.status ===
					'registered',
			).length

		case 'pending_approval':
			return attendees.value.filter(attendee => attendee.status === 'pending_approval').length

		case 'checked_in':
			return attendees.value.filter(
				attendee =>
					attendee.status ===
					'checked_in',
			).length

		case 'online':
			return attendees.value.filter(
				attendee =>
					attendee.source ===
					'online',
			).length

		case 'walk_in':
			return attendees.value.filter(
				attendee =>
					attendee.source ===
					'walk_in',
			).length

		case 'organizer':
			return attendees.value.filter(
				attendee =>
					attendee.source ===
					'organizer',
			).length

		case 'invited':
		case 'free':
		case 'paid':
			return attendees.value.filter(
				attendee => attendee.attendee_category === filter,
			).length

		default:
			return null
	}
}

function getApiErrorMessage(
	error: unknown,
	fallback: string,
): string {
	const apiError =
		error as ApiErrorResponse

	const validationMessage =
		Object.values(
			apiError.data?.errors ??
			{},
		)[0]?.[0]

	return (
		validationMessage ??
		apiError.data?.message ??
		fallback
	)
}

async function loadAttendees(quiet = false) {
	if (!selectedEventId.value) {
		attendees.value = []
		return
	}
	if (!quiet) isLoading.value = true
	errorMessage.value = ''
	try {
		const orgId = await orgIdOf()
		const rows = await allItems(page => ticketingApi.guests(orgId, eventKey(), { page }))
		attendees.value = rows.map(toAttendee)
	} catch (error: unknown) {
		attendees.value = []
		errorMessage.value = authMessage(error) || 'Unable to load attendees.'
	} finally {
		isLoading.value = false
	}
}
// Close to live during the event (review #12): check-ins and sales appear on their own.
useLiveRefresh(() => loadAttendees(true), { interval: 20000 })

function downloadAttendeeTicket(attendee: Attendee) {
	if (!attendee.qr_token) return
	openPdf(String(attendee.id)).catch(error => { errorMessage.value = authMessage(error) })
}

async function emailAttendeeTicket(attendee: Attendee) {
	if (!attendee.qr_token || !attendee.email || emailingAttendeeId.value !== null) return
	emailingAttendeeId.value = attendee.id
	errorMessage.value = ''
	try {
		await ticketingApi.emailTicket(await orgIdOf(), eventKey(), String(attendee.id))
		emailedAttendeeIds.value.add(attendee.id)
		successMessage.value = `Ticket sent to ${attendee.email}.`
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to email this ticket.'
	} finally {
		emailingAttendeeId.value = null
	}
}

const registerAmount = computed(() =>
	registerForm.attendeeCategory === 'paid' ? Number(selectedTicketType.value?.price ?? 0) : 0,
)

// Why the submit button is disabled, so the organizer is never left guessing.
const registerBlocker = computed(() => {
	if (!registerForm.attendeeName.trim()) return 'Enter the attendee\'s full name to continue.'
	if (!registerForm.ticketTypeId) return 'Select a ticket type to continue.'
	if (registerEmailUnverified.value) return 'Verify the email address, or clear it, to continue.'
	return ''
})

function onRegisterKeydown(event: KeyboardEvent) {
	if (event.key === 'Escape') closeRegisterAttendee()
}

watch(showRegisterForm, async (open) => {
	if (!import.meta.client) return

	if (!open) {
		window.removeEventListener('keydown', onRegisterKeydown)
		return
	}

	window.addEventListener('keydown', onRegisterKeydown)
	await nextTick()
	setTimeout(() => registerBody.value?.querySelector<HTMLInputElement>('input')?.focus({ preventScroll: true }), 320)
})

onBeforeUnmount(() => {
	if (import.meta.client) window.removeEventListener('keydown', onRegisterKeydown)
})

async function loadTicketTypes() {
	if (!selectedEventId.value) {
		ticketTypes.value = []
		return
	}
	isLoadingTicketTypes.value = true
	try {
		const { tiers } = await ticketingApi.tiers(await orgIdOf(), eventKey())
		// Closed types can't take new guests, except a private event's invitation list (always closed).
		ticketTypes.value = tiers.filter(t => t.status !== 'closed' || isGuestTier(t)).map(t => ({ id: t.id as unknown as number, name: t.name, price: t.price, capacity: t.capacity }))
		const invites = ticketTypes.value.find(t => isGuestTier(t))
		if (invites || ticketTypes.value.length === 1) registerForm.ticketTypeId = (invites ?? ticketTypes.value[0])?.id ?? null
	} catch (error: unknown) {
		ticketTypes.value = []
		registerError.value = authMessage(error) || 'Unable to load ticket types.'
	} finally {
		isLoadingTicketTypes.value = false
	}
}

async function refreshTopSummary() {
	if (!selectedEventId.value) return
	try {
		const data = await useCheckIn().summary(await orgIdOf(), eventKey())
		const capacity = ticketingSummary.value.capacity || data.expected
		ticketingSummary.value = {
			total_registered: data.expected,
			checked_in: data.checked_in,
			walk_ins: data.walk_ins + (data.door_sales ?? 0),
			capacity,
			capacity_percent: capacity ? Math.round((data.arrived / capacity) * 100) : 0,
		}
	} catch {
		// The summary is a nicety; the list still works.
	}
}

// ---- Bulk import (CSV) ----------------------------------------------------------
const showImport = ref(false)
const selectedEventName = computed(() => selectedEvent.value?.name ?? 'event')

async function openImport() {
	if (!selectedEventId.value) return
	if (!ticketTypes.value.length) await loadTicketTypes()
	showImport.value = true
}

async function importGuests(file: File, tierId: string, admission: 'invited' | 'complimentary', sendEmail: boolean) {
	return (await ticketingApi.importGuests(await orgIdOf(), eventKey(), file, tierId, admission, sendEmail)).imported
}

async function afterImport() {
	await Promise.all([loadAttendees(), refreshTopSummary()])
}

async function openRegisterAttendee() {
	if (
		!selectedEventId.value
	) {
		return
	}

	Object.assign(
		registerForm,
		emptyRegisterForm(),
	)

	registerError.value =
		''

	showRegisterForm.value =
		true

	await loadTicketTypes()
}

function closeRegisterAttendee() {
	if (
		isRegistering.value
	) {
		return
	}

	showRegisterForm.value =
		false

	registerError.value =
		''
}

async function submitRegisterAttendee() {
	if (
		!token.value ||
		!selectedEventId.value
	) {
		return
	}

	if (
		!registerForm.attendeeName.trim()
	) {
		registerError.value =
			'Full name is required.'

		return
	}

	if (
		!registerForm.ticketTypeId
	) {
		registerError.value =
			'Please select a ticket type.'

		return
	}

	if (
		registerEmailUnverified.value
	) {
		registerError.value =
			'Please verify the attendee email address before registering.'

		return
	}

	isRegistering.value =
		true

	registerError.value =
		''

	errorMessage.value =
		''

	try {
		const email = registerForm.attendeeEmail.trim() || null
		const created = await ticketingApi.addGuest(await orgIdOf(), eventKey(), {
			full_name: registerForm.attendeeName.trim(),
			email,
			contact_number: null,
			ticket_type_id: String(registerForm.ticketTypeId),
			admission_type: registerForm.attendeeCategory === 'free' ? 'complimentary' : 'invited',
			send_email: Boolean(email),
		})
		const tier = ticketTypes.value.find(t => String(t.id) === String(registerForm.ticketTypeId)) ?? null
		const response = { message: email ? `Guest added. The QR ticket was e-mailed to ${email}.` : 'Guest added with a QR ticket.' }
		registeredTicket.value = {
			id: created.ticket_id as unknown as number,
			name: registerForm.attendeeName.trim(),
			email,
			ticket_type: tier,
			ticket_id: created.ticket_id.replace(/-/g, '').slice(-10).toUpperCase(),
			qr_token: created.qr_token,
			qr_value: created.qr_token,
			attendee_category: registerForm.attendeeCategory,
			source: 'organizer',
			payment_status: null,
			status: 'registered',
			checked_in_at: null,
			created_at: new Date().toISOString(),
		} as RegisteredTicket
		await generateRegisteredTicketQr()

		successMessage.value =
			response.message ??
			'Attendee registered successfully.'

		showRegisterForm.value =
			false

		Object.assign(
			registerForm,
			emptyRegisterForm(),
		)

		await Promise.all([
			loadAttendees(),
			refreshTopSummary(),
		])

		showTicketResult.value =
			true

	} catch (error: unknown) {
		console.error(
			'Failed to register attendee:',
			error,
		)

		registerError.value =
			authMessage(error) ||
			'Unable to register attendee.'

	} finally {
		isRegistering.value =
			false
	}
}

function closeTicketResult() {
	showTicketResult.value =
		false
}

async function generateRegisteredTicketQr() {
	registeredTicketQrDataUrl.value = ''

	if (!registeredTicket.value?.qr_value) {
		return
	}

	isGeneratingTicketQr.value = true

	try {
		registeredTicketQrDataUrl.value = await QRCode.toDataURL(
			registeredTicket.value.qr_value,
			{ width: 420, margin: 2, errorCorrectionLevel: 'H' },
		)
	} finally {
		isGeneratingTicketQr.value = false
	}
}

function downloadRegisteredTicket() {
	if (!registeredTicket.value || import.meta.server) return
	openPdf(String(registeredTicket.value.id)).catch(error => { errorMessage.value = authMessage(error) })
}

async function emailRegisteredTicket() {
	const ticket = registeredTicket.value
	if (!ticket?.email) return
	isEmailingTicket.value = true
	errorMessage.value = ''
	try {
		await ticketingApi.emailTicket(await orgIdOf(), eventKey(), String(ticket.id))
		successMessage.value = `Ticket sent to ${ticket.email}.`
	} catch (error: unknown) {
		errorMessage.value = authMessage(error) || 'Unable to email the ticket.'
	} finally {
		isEmailingTicket.value = false
	}
}

function clearSuccess() {
	successMessage.value =
		''

	registeredTicket.value =
		null

	registeredTicketQrDataUrl.value = ''
}

function formatStatus(
	status: Attendee['status'],
): string {
	if (
		status ===
		'checked_in'
	) {
		return 'Checked-in'
	}

	if (status === 'pending_approval') {
		return 'Needs approval'
	}

	if (status === 'rejected') {
		return 'Rejected'
	}

	return 'Registered'
}

function statusClass(status: Attendee['status']): string {
	if (status === 'checked_in') return 'bg-[#285F6b] text-white'
	if (status === 'pending_approval') return 'border border-amber-200 bg-amber-50 text-amber-700'
	if (status === 'rejected') return 'border border-red-200 bg-red-50 text-red-600'
	return 'border border-primary-100 bg-primary-50 text-primary-700'
}

function formatSource(
	source: string,
): string {
	if (
		source ===
		'walk_in'
	) {
		return 'Walk-in'
	}

	if (
		source ===
		'online'
	) {
		return 'Online'
	}

	if (
		source ===
		'organizer'
	) {
		return 'Organizer'
	}

	return source ||
		'Unknown'
}

function sourceClass(
	source: string,
): string {
	if (
		source ===
		'walk_in'
	) {
		return 'bg-gray-100 text-gray-700'
	}

	if (
		source ===
		'online'
	) {
		return 'bg-primary-50 text-primary-700'
	}

	if (
		source ===
		'organizer'
	) {
		return 'bg-gray-100 text-gray-600'
	}

	return 'bg-gray-100 text-gray-600'
}

function formatPaymentStatus(
	status: string | null,
): string {
	if (!status) {
		return 'Not required'
	}

	return (
		status.charAt(0).toUpperCase() +
		status.slice(1)
	)
}

function paymentClass(
	status: string | null,
): string {
	switch (status) {
		case 'paid':
			return 'bg-primary-50 text-primary-700'

		case 'pending':
			return 'bg-amber-50 text-amber-700'

		case 'refunded':
			return 'bg-red-50 text-red-600'

		default:
			return 'bg-gray-100 text-gray-600'
	}
}

function formatCategory(category: AttendeeCategory): string {
	return category.charAt(0).toUpperCase() + category.slice(1)
}

function categoryClass(category: AttendeeCategory): string {
	switch (category) {
		case 'invited':
			return 'bg-gray-100 text-gray-600'
		case 'free':
			return 'bg-gray-100 text-gray-600'
		case 'paid':
			return 'bg-primary-50 text-primary-700'
	}
}

function formatCheckInTime(
	value: string,
): string {
	const date =
		new Date(value)

	return date.toLocaleTimeString(
		'en-US',
		{
			hour: 'numeric',
			minute: '2-digit',
		},
	)
}

function formatCurrency(
	value: string | number,
): string {
	const amount =
		Number(value)

	return new Intl.NumberFormat(
		'en-PH',
		{
			style: 'currency',
			currency: 'PHP',
			maximumFractionDigits: 0,
		},
	).format(
		Number.isNaN(amount)
			? 0
			: amount,
	)
}

function getInitials(
	name: string,
): string {
	return name
		.split(' ')
		.filter(Boolean)
		.slice(0, 2)
		.map(
			part =>
				part.charAt(0),
		)
		.join('')
		.toUpperCase()
}

function escapeCsvValue(
	value:
		| string
		| number
		| null
		| undefined,
): string {
	return `"${String(
		value ?? '',
	).replace(
		/"/g,
		'""',
	)}"`
}

function exportAttendees() {
	if (
		!attendees.value.length
	) {
		return
	}

	const rows = [
		[
			'Name',
			'Email',
			'Ticket Type',
			'Ticket ID',
			'Category',
			'Source',
			'Status',
			'Payment Status',
			'Checked In At',
		],

		...attendees.value.map(
			attendee => [
				attendee.name,
				attendee.email ?? '',
				attendee.ticket_type
					?.name ??
				'General Admission',
				attendee.ticket_id,
				formatCategory(attendee.attendee_category),
				formatSource(
					attendee.source,
				),
				formatStatus(
					attendee.status,
				),
				formatPaymentStatus(
					attendee.payment_status,
				),
				attendee.checked_in_at ??
				'',
			],
		),
	]

	const csv =
		rows
			.map(row =>
				row
					.map(
						escapeCsvValue,
					)
					.join(','),
			)
			.join('\r\n')

	const blob =
		new Blob(
			// The UTF-8 mark tells Excel the encoding (names like "Niño" stay readable).
			['﻿', csv],
			{
				type:
					'text/csv;charset=utf-8;',
			},
		)

	const url =
		URL.createObjectURL(
			blob,
		)

	const link =
		document.createElement(
			'a',
		)

	const eventName =
		(
			selectedEvent.value
				?.name ??
			'attendees'
		)
			.trim()
			.replace(
				/[^a-zA-Z0-9]+/g,
				'-',
			)
			.replace(
				/^-|-$/g,
				'',
			)
			.toLowerCase()

	link.href =
		url

	link.download =
		`${eventName}-attendees.csv`

	document.body.appendChild(
		link,
	)

	link.click()

	document.body.removeChild(
		link,
	)

	URL.revokeObjectURL(
		url,
	)
}

watch(
	selectedEventId,
	async () => {
		search.value =
			''

		activeFilter.value =
			'all'

		attendees.value =
			[]

		ticketTypes.value =
			[]

		showRegisterForm.value =
			false

		showTicketResult.value =
			false

		successMessage.value =
			''

		registeredTicket.value =
			null

		await loadAttendees()
	},
	{
		immediate: true,
	},
)
</script>

<style scoped>
.row-in {
	animation: row-in 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.row-in:nth-child(2) { animation-delay: 50ms; }
.row-in:nth-child(3) { animation-delay: 100ms; }
.row-in:nth-child(4) { animation-delay: 150ms; }
.row-in:nth-child(5) { animation-delay: 200ms; }
.row-in:nth-child(n + 6) { animation-delay: 250ms; }

@keyframes row-in {
	from { opacity: 0; transform: translateY(6px); }
	to { opacity: 1; transform: translateY(0); }
}


.reg-step {
	animation: reg-rise 0.45s cubic-bezier(0.22, 1, 0.36, 1) backwards;
	animation-delay: calc(var(--d, 0ms) + 120ms);
}

.reg-option {
	transition: border-color 0.18s ease, background-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.reg-option:active {
	transform: scale(0.99);
}

@keyframes reg-rise {
	from { opacity: 0; transform: translateY(10px); }
	to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
	.row-in, .reg-step { animation: none; }
	.reg-option { transition: none; }
}

.slide-enter-active,
.slide-leave-active {
	transition: transform 0.25s ease;
}

.slide-enter-from,
.slide-leave-to {
	transform: translateX(100%);
}

.fade-enter-active,
.fade-leave-active {
	transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
	opacity: 0;
}
</style>
