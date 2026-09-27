<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Payments;
use App\Services\Ticketing\SelfRegistration;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Buyer-side payments (B10): pending reservations, (re)starting checkout,
 * the return page, cancelling a reservation; plus the PayMongo webhook and
 * the demo gateway's pay/fail endpoint.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly Payments $payments,
        private readonly SelfRegistration $registration,
        private readonly PaymentGateway $gateway,
        private readonly DatabaseContext $db,
    ) {}

    public function pending(Request $request): JsonResponse
    {
        return ApiResponse::success($this->registration->pendingFor($request->user()), meta: ['mode' => $this->gateway->mode()]);
    }

    public function checkout(Request $request, string $registration): JsonResponse
    {
        return ApiResponse::created($this->payments->startCheckout($request->user(), $registration), 'Continue to payment.');
    }

    public function cancel(Request $request, string $registration): JsonResponse
    {
        $this->payments->cancelReservation($request->user(), $registration);

        return ApiResponse::success(null, 'Reservation cancelled. The places were released.');
    }

    public function show(Request $request, string $payment): JsonResponse
    {
        return ApiResponse::success($this->payments->status($request->user(), $payment));
    }

    /**
     * PayMongo webhook. Always answers 200 for authentic deliveries (even
     * unmatched ones) so PayMongo doesn't retry forever; 401 for bad signatures.
     */
    public function webhook(Request $request): JsonResponse
    {
        $result = $this->payments->handleWebhook($request);

        return $result === 'invalid'
            ? ApiResponse::error(ErrorCode::Unauthenticated, 'Invalid webhook signature.', 401)
            : ApiResponse::success(['result' => $result]);
    }

    /**
     * Demo gateway only (PAYMONGO_DRIVER=fake): the simulated checkout page's
     * Pay / Fail buttons. The URL is signed and expires with the checkout.
     */
    public function demoComplete(Request $request, string $payment): JsonResponse
    {
        if ($this->gateway->mode() !== 'fake') {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        $data = $request->validate(['outcome' => ['required', Rule::in(['paid', 'failed'])], 'intent' => ['required', 'string']]);
        // The signed URL is the authorization (like a provider callback); the lookup ignores the caller.
        $matches = $this->db->system('payments.demo_lookup', fn () => DB::table('payments')->where('id', $payment)
            ->where('paymongo_payment_intent_id', $data['intent'])->exists());

        if (! $matches) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        $data['outcome'] === 'paid'
            ? $this->payments->settle($data['intent'], 'demo')
            : $this->payments->fail($data['intent'], 'Payment declined (demo)');

        return ApiResponse::success(['payment_id' => $payment, 'outcome' => $data['outcome']]);
    }
}
