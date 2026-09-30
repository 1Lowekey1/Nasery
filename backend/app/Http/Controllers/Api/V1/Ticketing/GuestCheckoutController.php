<?php

namespace App\Http\Controllers\Api\V1\Ticketing;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Services\Ticketing\GuestCheckout;
use App\Services\Ticketing\SelfRegistration;
use App\Services\Tickets\TicketCards;
use App\Services\Tickets\TicketMailer;
use App\Services\Tickets\TicketPdf;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public registration page (/event-registration/{event}): guests confirm
 * attendee e-mails with codes; signed-in buyers skip them. See GuestCheckout.
 */
class GuestCheckoutController extends Controller
{
    public function __construct(
        private readonly GuestCheckout $checkout,
        private readonly TicketCards $cards,
        private readonly TicketPdf $pdf,
        private readonly TicketMailer $mailer,
    ) {}

    public function sendCode(Request $request, string $event): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);

        return ApiResponse::success($this->checkout->sendCode($event, $data['email']), 'We sent a 6-digit code to that address.');
    }

    public function verifyCode(Request $request, string $event): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'code' => ['required', 'string', 'size:6']]);

        return ApiResponse::success($this->checkout->verifyCode($event, $data['email'], $data['code']), 'E-mail confirmed.');
    }

    public function register(Request $request, string $event): JsonResponse
    {
        // Only an active, verified account skips the e-mail codes.
        $user = $request->user();
        $user = $user && $user->email_verified_at !== null && $user->status === UserStatus::Active ? $user : null;
        $data = $request->validate([
            'buyer.name' => ['nullable', 'string', 'min:2', 'max:120'],
            'buyer.email' => ['nullable', 'email', 'max:254'],
            'buyer.phone' => ['nullable', 'string', 'max:30'],
            'attendees' => ['required', 'array', 'min:1', 'max:'.SelfRegistration::MAX_PER_REGISTRATION],
            'attendees.*.full_name' => ['required', 'string', 'min:2', 'max:120'],
            'attendees.*.email' => [$user ? 'nullable' : 'required', 'email', 'max:254'],
            'attendees.*.ticket_type_id' => ['required', 'uuid'],
            'attendees.*.verification_token' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $this->checkout->register($user, $event, $data['attendees'], $data['buyer'] ?? []);

        return ApiResponse::created($result, $result['checkout']
            ? 'Places reserved. Continue to payment.'
            : 'You\'re registered. The tickets are on their way by e-mail.');
    }

    public function show(Request $request, string $registration): JsonResponse
    {
        $data = $request->validate(['access' => ['required', 'string', 'size:64'], 'payment' => ['nullable', 'uuid']]);

        return ApiResponse::success($this->checkout->show($registration, $data['access'], $data['payment'] ?? null));
    }

    public function ticketPdf(Request $request, string $registration, string $ticket): Response
    {
        $this->checkout->ticketFor($registration, (string) $request->query('access'), $ticket);

        return response($this->pdf->render($this->cards->load([$ticket])), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ticket-'.strtolower(substr(str_replace('-', '', $ticket), -8)).'.pdf"',
        ]);
    }

    public function emailTicket(Request $request, string $registration, string $ticket): JsonResponse
    {
        $this->checkout->ticketFor($registration, (string) $request->input('access'), $ticket);
        $result = $this->mailer->send([$ticket]);

        return ApiResponse::success($result, $result['queued'] ? 'Ticket sent again.' : 'We can\'t send e-mail to this address.');
    }
}
