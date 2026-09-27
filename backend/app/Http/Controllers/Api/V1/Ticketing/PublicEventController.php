<?php

namespace App\Http\Controllers\Api\V1\Ticketing;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Payments\Payments;
use App\Services\Ticketing\SelfRegistration;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Services\Tickets\TicketCards;
use App\Services\Tickets\TicketPdf;

/**
 * Public events (0036 views) and self-registration by signed-in accounts,
 * plus the holder's own tickets.
 */
class PublicEventController extends Controller
{
    public function __construct(
        private readonly SelfRegistration $registration,
        private readonly Payments $payments,
        private readonly TicketCards $cards,
        private readonly TicketPdf $pdf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return ApiResponse::success($this->registration->events($data));
    }

    public function show(string $event): JsonResponse
    {
        return ApiResponse::success($this->registration->event($event)
            ?? throw new ApiException(ErrorCode::NotFound, 'This event isn\'t open to the public.', 404));
    }

    public function register(Request $request, string $event): JsonResponse
    {
        $data = $request->validate([
            'ticket_type_id' => ['required', 'uuid'],
            'attendees' => ['required', 'array', 'min:1', 'max:'.SelfRegistration::MAX_PER_REGISTRATION],
            'attendees.*.full_name' => ['required', 'string', 'min:2', 'max:120'],
            'attendees.*.email' => ['nullable', 'email', 'max:254'],
        ]);

        $result = $this->registration->register($request->user(), $event, $data['ticket_type_id'], $data['attendees']);

        if ($result['status'] === 'confirmed') {
            return ApiResponse::created($result, 'You\'re registered. Your QR tickets are in My Tickets.');
        }

        // Paid: go straight to checkout (B10). The places stay held for 15 minutes.
        $checkout = $this->payments->startCheckout($request->user(), $result['registration_id']);

        return ApiResponse::created([...$result, 'checkout' => $checkout], 'Places reserved for '.$this->registration->holdMinutes().' minutes. Continue to payment.');
    }

    public function myTickets(Request $request): JsonResponse
    {
        return ApiResponse::success($this->registration->myTickets($request->user()));
    }

    /** B13: the holder's printable ticket. Only tickets they bought (404 otherwise). */
    public function myTicketPdf(Request $request, string $ticket): Response
    {
        if (! $this->registration->owns($request->user(), $ticket)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        return response($this->pdf->render($this->cards->load([$ticket])), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ticket-'.strtolower(substr(str_replace('-', '', $ticket), -8)).'.pdf"',
        ]);
    }

    public function cancelMine(Request $request, string $ticket): JsonResponse
    {
        $this->registration->cancelMine($request->user(), $ticket);

        return ApiResponse::success(null, 'Ticket cancelled.');
    }
}
