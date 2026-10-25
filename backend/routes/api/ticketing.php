<?php

use App\Http\Controllers\Api\V1\Ticketing\GuestCheckoutController;
use App\Http\Controllers\Api\V1\Ticketing\PublicEventController;
use App\Http\Controllers\Api\V1\Ticketing\TicketingController;
use Illuminate\Support\Facades\Route;

/*
| B9 Ticketing. Public events come from the 0036 views; registering needs a
| signed-in, verified account. Organizer routes bind {event}, then
| {ticketType}/{ticket} scoped to it, under RLS.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

// Public (guests welcome)
Route::middleware('throttle:public')->where(['event' => $uuid])->group(function () {
    Route::get('public-events', [PublicEventController::class, 'index'])->name('public-events.index');
    Route::get('public-events/{event}', [PublicEventController::class, 'show'])->name('public-events.show');
});

// Public registration page: guests (e-mail codes) and signed-in buyers alike.
// A guest's registration is opened by its `access` key, not a session.
Route::where(['event' => $uuid, 'registration' => $uuid, 'ticket' => $uuid])->name('guest-checkout.')->group(function () {
    Route::post('public-events/{event}/email-code', [GuestCheckoutController::class, 'sendCode'])->middleware('throttle:guest-codes')->name('code.send');
    Route::post('public-events/{event}/email-code/verify', [GuestCheckoutController::class, 'verifyCode'])->middleware('throttle:checkout')->name('code.verify');
    Route::post('public-events/{event}/checkout', [GuestCheckoutController::class, 'register'])->middleware(['throttle:checkout', 'idempotent'])->name('register');
    Route::get('public-registrations/{registration}', [GuestCheckoutController::class, 'show'])->middleware('throttle:public')->name('show');
    Route::get('public-registrations/{registration}/tickets/{ticket}/pdf', [GuestCheckoutController::class, 'ticketPdf'])->middleware('throttle:public')->name('ticket.pdf');
    Route::post('public-registrations/{registration}/tickets/{ticket}/email', [GuestCheckoutController::class, 'emailTicket'])->middleware('throttle:sensitive')->name('ticket.email');
});

Route::middleware(['auth:sanctum', 'active', 'verified'])
    ->where(['event' => $uuid, 'organization' => $uuid, 'ticketType' => $uuid, 'ticket' => $uuid])
    ->group(function () {
        Route::post('public-events/{event}/register', [PublicEventController::class, 'register'])
            ->middleware(['throttle:checkout', 'idempotent'])->name('public-events.register');
        Route::get('me/tickets', [PublicEventController::class, 'myTickets'])->name('me.tickets');
        Route::post('me/tickets/{ticket}/cancel', [PublicEventController::class, 'cancelMine'])->middleware('throttle:sensitive')->name('me.tickets.cancel');
        Route::get('me/tickets/{ticket}/pdf', [PublicEventController::class, 'myTicketPdf'])->name('me.tickets.pdf');
        Route::post('me/tickets/{ticket}/email', [PublicEventController::class, 'emailMine'])->middleware('throttle:sensitive')->name('me.tickets.email');

        Route::prefix('organizations/{organization}/events/{event}')->name('organizations.events.')
            ->middleware('account:business')->scopeBindings()->group(function () {
                Route::get('ticket-types', [TicketingController::class, 'tiers'])->name('ticket-types.index');
                Route::post('ticket-types', [TicketingController::class, 'storeTier'])->middleware('throttle:sensitive')->name('ticket-types.store');
                Route::patch('ticket-types/{ticketType}', [TicketingController::class, 'updateTier'])->middleware('throttle:sensitive')->name('ticket-types.update');
                Route::delete('ticket-types/{ticketType}', [TicketingController::class, 'destroyTier'])->middleware('throttle:sensitive')->name('ticket-types.destroy');

                Route::get('guests', [TicketingController::class, 'guests'])->name('guests.index');
                Route::post('guests', [TicketingController::class, 'addGuest'])->middleware(['throttle:sensitive', 'idempotent'])->name('guests.store');
                Route::post('guests/import', [TicketingController::class, 'import'])->middleware(['throttle:sensitive', 'idempotent'])->name('guests.import');
                Route::get('guests/export', [TicketingController::class, 'export'])->name('guests.export');
                Route::get('tickets/{ticket}/qr', [TicketingController::class, 'qr'])->name('tickets.qr');
                Route::get('tickets/{ticket}/pdf', [TicketingController::class, 'ticketPdf'])->name('tickets.pdf');
                Route::post('tickets/{ticket}/email', [TicketingController::class, 'emailTicket'])->middleware('throttle:sensitive')->name('tickets.email');
                Route::post('tickets/{ticket}/reissue-qr', [TicketingController::class, 'reissueQr'])->middleware('throttle:sensitive')->name('tickets.reissue');
                Route::post('tickets/{ticket}/cancel', [TicketingController::class, 'cancelTicket'])->middleware('throttle:sensitive')->name('tickets.cancel');
            });
    });
