<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Built-in simulated checkout (PAYMONGO_DRIVER=fake). The "checkout page"
 * is the SPA's /pay/demo page, which calls a signed backend endpoint to
 * pay or fail the payment through the same settlement path a PayMongo
 * webhook uses. No money, no network: safe for the capstone panel demo.
 */
class FakeGateway implements PaymentGateway
{
    public function mode(): string
    {
        return 'fake';
    }

    public function createCheckout(Payment $payment, array $lines, array $buyer, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $intentId = 'fake_pi_'.Str::lower(Str::random(24));

        // The SPA page gets a signed API URL for the pay / fail buttons.
        $action = URL::temporarySignedRoute('api.v1.payments.fake.complete', now()->addMinutes(30), ['payment' => $payment->id, 'intent' => $intentId]);
        $query = http_build_query(['payment' => $payment->id, 'action' => $action, 'success' => $successUrl, 'cancel' => $cancelUrl]);

        return new CheckoutSession($intentId, rtrim((string) config('nasery.auth.frontend_url'), '/').'/pay/demo?'.$query);
    }

    /** The fake provider has no state of its own; the payment row is the truth. */
    public function status(string $intentId): string
    {
        return Payment::query()->where('paymongo_payment_intent_id', $intentId)->value('status') === 'paid' ? 'paid' : 'pending';
    }

    public function parseWebhook(Request $request): ?array
    {
        return null; // no webhooks in fake mode
    }

    public function refund(string $intentId, int $amountCentavos, string $reason): string
    {
        return 'fake_ref_'.Str::lower(Str::random(20));
    }
}
