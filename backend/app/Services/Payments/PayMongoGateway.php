<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PayMongo Checkout Sessions (ported from the old prototype's
 * PayMongoService and hardened). Amounts are sent in centavos. Webhook
 * signatures are checked against the signature for the event's own mode
 * (te = test, li = live), with a 5-minute replay window. The old code
 * accepted either, so a test-mode signature could settle a live payment.
 */
class PayMongoGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.paymongo.com/v1';

    public function __construct(private readonly string $mode) {}

    public function mode(): string
    {
        return $this->mode;
    }

    public function createCheckout(Payment $payment, array $lines, array $buyer, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $response = $this->send(fn () => $this->client()->post(self::BASE_URL.'/checkout_sessions', ['data' => ['attributes' => [
            'line_items' => array_map(fn ($l) => ['name' => $l['name'], 'amount' => $l['amount_centavos'], 'currency' => 'PHP', 'quantity' => $l['quantity']], $lines),
            'payment_method_types' => config('services.paymongo.payment_methods'),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'description' => 'NaSeRy tickets',
            'reference_number' => $payment->id,
            'billing' => array_filter(['name' => $buyer['name'], 'email' => $buyer['email'], 'phone' => $buyer['phone']]),
            'send_email_receipt' => false,
            'show_line_items' => true,
        ]]]));

        $attributes = $response['data']['attributes'] ?? [];
        $intentId = $attributes['payment_intent']['id'] ?? null;

        if (! is_string($intentId) || ! is_string($attributes['checkout_url'] ?? null)) {
            throw new ApiException(ErrorCode::ServiceUnavailable, 'The payment provider returned an unexpected response.', 503);
        }

        return new CheckoutSession($intentId, $attributes['checkout_url']);
    }

    public function status(string $intentId): string
    {
        $intent = $this->send(fn () => $this->client()->get(self::BASE_URL.'/payment_intents/'.rawurlencode($intentId)));

        // Failures arrive as payment.failed webhooks; an unfinished intent is just pending.
        return ($intent['data']['attributes']['status'] ?? null) === 'succeeded' ? 'paid' : 'pending';
    }

    public function parseWebhook(Request $request): ?array
    {
        $payload = $request->getContent();
        $secret = (string) config('services.paymongo.webhook_secret');
        $event = json_decode($payload, true);

        if ($secret === '' || ! is_array($event)) {
            return null;
        }

        $parts = collect(explode(',', (string) $request->header('Paymongo-Signature')))
            ->mapWithKeys(function (string $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

                return [$key => $value];
            });

        $livemode = (bool) ($event['data']['attributes']['livemode'] ?? false);
        $timestamp = (string) $parts->get('t', '');
        $signature = (string) $parts->get($livemode ? 'li' : 'te', '');

        // A live event is only accepted by a live gateway and vice versa.
        if ($livemode !== ($this->mode === 'live') || $timestamp === '' || $signature === '' || abs(time() - (int) $timestamp) > 300
            || ! hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $signature)) {
            return null;
        }

        $resource = $event['data']['attributes']['data'] ?? [];
        $intentId = $resource['attributes']['payment_intent']['id']      // checkout_session.payment.paid
            ?? $resource['attributes']['payment_intent_id']               // payment.paid / payment.failed
            ?? null;

        return [
            'id' => (string) ($event['data']['id'] ?? ''),
            'type' => (string) ($event['data']['attributes']['type'] ?? ''),
            'intent_id' => is_string($intentId) ? $intentId : null,
            'livemode' => $livemode,
            'payload' => $event,
        ];
    }

    public function refund(string $intentId, int $amountCentavos, string $reason): string
    {
        // Refunds target the PayMongo payment (pay_…) inside the intent.
        $intent = $this->send(fn () => $this->client()->get(self::BASE_URL.'/payment_intents/'.rawurlencode($intentId)));
        $paymentId = collect($intent['data']['attributes']['payments'] ?? [])->first(fn ($p) => ($p['attributes']['status'] ?? null) === 'paid')['id'] ?? null;

        if (! is_string($paymentId)) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'The provider has no paid charge to refund for this payment.', 409);
        }

        $refund = $this->send(fn () => $this->client()->post(self::BASE_URL.'/refunds', ['data' => ['attributes' => [
            'amount' => $amountCentavos,
            'payment_id' => $paymentId,
            'reason' => 'others',
            'notes' => mb_substr($reason, 0, 255),
        ]]]));

        return (string) ($refund['data']['id'] ?? 'unknown');
    }

    /**
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (Throwable $e) {
            Log::warning('paymongo.unreachable', ['error' => $e->getMessage()]);

            throw new ApiException(ErrorCode::ServiceUnavailable, 'The payment provider could not be reached. Please try again.', 503);
        }

        if ($response->failed()) {
            Log::warning('paymongo.error', ['status' => $response->status(), 'errors' => $response->json('errors')]);

            throw new ApiException(ErrorCode::ServiceUnavailable, 'The payment provider declined the request. Please try again.', 503);
        }

        return $response->json() ?? [];
    }

    private function client(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.paymongo.secret_key'), '')->acceptJson()->asJson()->timeout(20);
    }
}
