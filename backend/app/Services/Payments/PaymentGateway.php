<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * A card/e-wallet checkout provider. Two drivers: FakeGateway (built-in
 * simulated checkout for demos and tests) and PayMongoGateway (PayMongo
 * Checkout Sessions; test keys in the capstone build).
 */
interface PaymentGateway
{
    /** Short name stored with audit rows: fake | test | live. */
    public function mode(): string;

    /**
     * Start a hosted checkout for $payment.
     *
     * @param  list<array{name: string, amount_centavos: int, quantity: int}>  $lines
     * @param  array{name: string, email: ?string, phone: ?string}  $buyer
     */
    public function createCheckout(Payment $payment, array $lines, array $buyer, string $successUrl, string $cancelUrl): CheckoutSession;

    /** paid | pending | failed, asked from the provider (return-page fallback). */
    public function status(string $intentId): string;

    /**
     * Verify and decode a webhook. Returns null when the signature is invalid.
     *
     * @return array{id: string, type: string, intent_id: ?string, livemode: bool, payload: array<string, mixed>}|null
     */
    public function parseWebhook(Request $request): ?array;

    /** Refund part or all of a paid intent; returns the provider's refund reference. */
    public function refund(string $intentId, int $amountCentavos, string $reason): string;
}
