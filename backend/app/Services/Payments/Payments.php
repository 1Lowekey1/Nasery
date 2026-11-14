<?php

namespace App\Services\Payments;

use App\Enums\HoldStatus;
use App\Enums\PaymentStatus;
use App\Enums\RegistrationStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Billing\Billing;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\Alerts;
use App\Services\Ticketing\CapacityGuard;
use App\Services\Ticketing\TicketIssuer;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * B10 checkout and settlement. A paid registration (B9) holds its places
 * for 15 minutes with PENDING tickets; checkout creates a payments row and
 * a provider session; settlement (webhook, return-page check, or the demo
 * page) turns it into valid QR tickets exactly once.
 *
 * Settlement rules:
 *  - idempotent: an already-paid payment is left alone (locked first)
 *  - the charged amount must equal the registration's ticket subtotal
 *  - if the hold ran out, the places are re-checked; if they're gone (or the
 *    registration is no longer payable) the money is refunded automatically
 *  - the platform fee is deducted and snapshotted (PlatformFee)
 * All writes run in the system context: the app role has no write grant on
 * payments, refunds, fee snapshots or other people's tickets.
 */
class Payments
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly PaymentGateway $gateway,
        private readonly CapacityGuard $capacity,
        private readonly TicketIssuer $issuer,
        private readonly PlatformFee $fee,
        private readonly Alerts $alerts,
        private readonly RefundRequests $refundRequests,
        private readonly AdminAlerts $adminAlerts,
    ) {}

    /**
     * @return array{payment_id: string, checkout_url: string, amount: string, mode: string}
     */
    public function startCheckout(User $user, string $registrationId): array
    {
        $owned = $this->db->system('payments.owner', fn () => Registration::query()->whereKey($registrationId)->where('purchaser_user_id', $user->id)->exists());

        if (! $owned) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        $return = rtrim((string) config('nasery.auth.frontend_url'), '/').'/payments/return?payment=';

        return $this->checkout($registrationId, fn (string $paymentId) => $return.$paymentId);
    }

    /**
     * Opens a checkout for a registration waiting for payment (signed-in
     * buyers and guests). $returnUrl builds the page PayMongo sends the buyer
     * back to, from the new payment's id.
     *
     * @param  Closure(string): string  $returnUrl
     * @return array{payment_id: string, checkout_url: string, amount: string, mode: string}
     */
    public function checkout(string $registrationId, Closure $returnUrl): array
    {
        $prepared = $this->db->system('payments.prepare', function () use ($registrationId) {
            $registration = Registration::query()->whereKey($registrationId)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

            if ($registration->status !== RegistrationStatus::Pending) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'This registration is not waiting for payment.', 409);
            }

            $hold = DB::table('ticket_holds')->where('registration_id', $registration->id)->where('status', HoldStatus::Active->value)
                ->where('expires_at', '>', now())->orderByDesc('expires_at')->first();

            if ($hold === null) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'Your reservation has expired. Please register again.', 409);
            }

            $tickets = DB::table('tickets')->where('registration_id', $registration->id)->where('status', TicketStatus::Pending->value)->get(['purchased_price', 'ticket_type_id']);
            $amountCents = (int) $tickets->sum(fn ($t) => (int) round((float) $t->purchased_price * 100));

            if ($tickets->isEmpty() || $amountCents <= 0) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'There is nothing to pay for this registration.', 409);
            }

            // One open attempt at a time: earlier unfinished checkouts are abandoned.
            DB::table('payments')->where('registration_id', $registration->id)->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value, 'failure_reason' => 'replaced by a new checkout', 'updated_at' => now()]);

            $tiers = TicketType::query()->whereIn('id', $tickets->pluck('ticket_type_id')->unique())->get()->keyBy('id');
            $event = Event::query()->find($registration->event_id);

            return compact('registration', 'tickets', 'amountCents', 'tiers', 'event');
        });

        $payment = (new Payment)->forceFill([
            'id' => (string) Str::uuid7(),
            'registration_id' => $prepared['registration']->id,
            'amount' => number_format($prepared['amountCents'] / 100, 2, '.', ''),
            'currency' => 'PHP',
            'status' => PaymentStatus::Pending,
        ]);

        $return = $returnUrl($payment->id);
        // One line per ticket type (a registration can mix them).
        $lines = $prepared['tickets']->groupBy('ticket_type_id')->map(fn ($group, $tierId) => [
            'name' => "{$prepared['event']->name}: {$prepared['tiers'][$tierId]->name}",
            'amount_centavos' => (int) round((float) $group->first()->purchased_price * 100),
            'quantity' => $group->count(),
        ])->values()->all();
        $session = $this->gateway->createCheckout($payment, $lines, [
            'name' => (string) $prepared['registration']->purchaser_name,
            'email' => $prepared['registration']->purchaser_email,
            'phone' => $prepared['registration']->purchaser_contact,
        ], $return, $return.(str_contains($return, '?') ? '&' : '?').'cancelled=1');

        $this->db->system('payments.create', fn () => $payment->forceFill(['paymongo_payment_intent_id' => $session->intentId])->save());
        $this->audit->record('payment.checkout_started', $payment,
            new: ['registration_id' => $payment->registration_id, 'amount' => $payment->amount, 'mode' => $this->gateway->mode()],
            organizationId: $prepared['event']->organization_id);

        return ['payment_id' => $payment->id, 'checkout_url' => $session->checkoutUrl, 'amount' => $payment->amount, 'mode' => $this->gateway->mode()];
    }

    /**
     * Mark paid and issue the tickets (idempotent). $source is for the audit
     * trail: webhook | return | demo.
     */
    public function settle(string $intentId, string $source): ?Payment
    {
        $result = $this->db->system('payments.settle', function () use ($intentId) {
            $payment = Payment::query()->where('paymongo_payment_intent_id', $intentId)->lockForUpdate()->first();

            if ($payment === null || $payment->status === PaymentStatus::Paid) {
                return ['payment' => $payment, 'refund' => null];
            }

            // B18: a plan payment. No tickets or platform fee; Billing activates the plan.
            if ($payment->subscription_invoice_id !== null) {
                $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'failure_reason' => null])->save();

                return ['payment' => $payment, 'refund' => null, 'subscription' => true];
            }

            $registration = Registration::query()->whereKey($payment->registration_id)->lockForUpdate()->first();
            $expected = (int) DB::table('tickets')->where('registration_id', $registration->id)->where('status', TicketStatus::Pending->value)
                ->get(['purchased_price'])->sum(fn ($t) => (int) round((float) $t->purchased_price * 100));

            $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'failure_reason' => null])->save();

            // Money arrived but can't become tickets: refund it in full.
            $problem = match (true) {
                $registration->status !== RegistrationStatus::Pending => 'the registration was no longer waiting for payment',
                $expected !== (int) round((float) $payment->amount * 100) => 'the paid amount did not match the tickets',
                ! $this->placesStillAvailable($registration) => 'the places were taken after the reservation expired',
                default => null,
            };

            if ($problem !== null) {
                DB::table('tickets')->where('registration_id', $registration->id)->where('status', TicketStatus::Pending->value)
                    ->update(['status' => TicketStatus::Cancelled->value, 'cancelled_at' => now(), 'cancellation_reason' => 'payment refunded: '.$problem, 'updated_at' => now()]);
                if ($registration->status === RegistrationStatus::Pending) {
                    $registration->forceFill(['status' => RegistrationStatus::Cancelled])->save();
                }

                return ['payment' => $payment, 'refund' => $problem, 'registration' => $registration];
            }

            DB::table('ticket_holds')->where('registration_id', $registration->id)->where('status', HoldStatus::Active->value)
                ->update(['status' => HoldStatus::Converted->value, 'released_at' => now(), 'updated_at' => now()]);
            $pending = DB::table('tickets')->where('registration_id', $registration->id)->where('status', TicketStatus::Pending->value)->orderBy('id')->get(['id', 'purchased_price']);
            $this->issuer->activate($registration, $this->fee->apply($payment, $pending));
            $registration->forceFill(['status' => RegistrationStatus::Confirmed])->save();

            return ['payment' => $payment, 'refund' => null, 'registration' => $registration, 'tickets' => $pending->pluck('id')->all()];
        });

        $payment = $result['payment'];

        if ($payment !== null && isset($result['subscription'])) {
            app(Billing::class)->settleInvoice($payment, $source); // resolved lazily: Billing depends on the gateway too

            return $payment;
        }

        if ($payment !== null && isset($result['registration'])) {
            $orgId = DB::table('events')->where('id', $result['registration']->event_id)->value('organization_id');
            $this->audit->record('payment.paid', $payment, new: ['source' => $source, 'amount' => $payment->amount, 'mode' => $this->gateway->mode()], organizationId: $orgId);

            if ($result['refund'] !== null) {
                $this->autoRefund($payment, $result['registration'], $result['refund'], $orgId);
            } else {
                $event = Event::query()->find($result['registration']->event_id);
                $this->alerts->ticketsIssued($result['tickets'], $result['registration']->purchaser_user_id, (string) $event?->name);
                if ($event !== null) {
                    $this->alerts->capacity($event);
                }
            }
        }

        return $payment;
    }

    public function fail(string $intentId, string $reason): void
    {
        $this->db->system('payments.fail', fn () => DB::table('payments')->where('paymongo_payment_intent_id', $intentId)
            ->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Failed->value, 'failure_reason' => mb_substr($reason, 0, 300), 'updated_at' => now()]));
        // The hold stays: the buyer can retry until it expires.
    }

    /**
     * Where a returning buyer's payment stands; asks the provider when we
     * haven't heard back yet (webhook fallback, as in the old prototype).
     *
     * @return array<string, mixed>
     */
    public function status(User $user, string $paymentId): array
    {
        // Ticket payments: the buyer. Plan payments (B18): an owner of the organization.
        $payment = $this->db->system('payments.status', fn () => Payment::query()->whereKey($paymentId)
            ->where(fn ($q) => $q->whereIn('registration_id', DB::table('registrations')->where('purchaser_user_id', $user->id)->select('id'))
                ->orWhereIn('subscription_invoice_id', DB::table('subscription_invoices as i')->join('subscriptions as s', 's.id', '=', 'i.subscription_id')
                    ->whereIn('s.organization_id', DB::table('organization_members')->where('user_id', $user->id)->where('is_owner', true)
                        ->where('status', 'active')->whereNull('deleted_at')->select('organization_id'))->select('i.id')))
            ->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

        if ($payment->status === PaymentStatus::Pending && $this->gateway->mode() !== 'fake') {
            try {
                if ($this->gatewayFor($payment)->status($payment->paymongo_payment_intent_id) === 'paid') {
                    $payment = $this->settle($payment->paymongo_payment_intent_id, 'return') ?? $payment;
                }
            } catch (Throwable $e) {
                Log::info('payments.status_check_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        if ($payment->subscription_invoice_id !== null) {
            return [
                'payment_id' => $payment->id,
                'kind' => 'subscription',
                'status' => $payment->status->value,
                'amount' => $payment->amount,
                'failure_reason' => $payment->failure_reason,
                'mode' => $this->gateway->mode(),
            ];
        }

        $registration = $this->db->system('payments.status_registration', fn () => DB::table('registrations')->where('id', $payment->registration_id)->first(['status', 'event_id']));
        $hold = $this->db->system('payments.status_hold', fn () => DB::table('ticket_holds')->where('registration_id', $payment->registration_id)
            ->where('status', HoldStatus::Active->value)->max('expires_at'));

        return [
            'payment_id' => $payment->id,
            'kind' => 'tickets',
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'registration_status' => $registration->status,
            'event_id' => $registration->event_id,
            'hold_expires_at' => $hold,
            'failure_reason' => $payment->failure_reason,
            'mode' => $this->gateway->mode(),
        ];
    }

    /**
     * Guest checkout return page: if this registration's payment is still
     * pending, ask the gateway (the webhook may be late or unavailable).
     */
    public function refreshPending(string $registrationId, string $paymentId): void
    {
        $payment = $this->db->system('payments.refresh', fn () => Payment::query()->whereKey($paymentId)
            ->where('registration_id', $registrationId)->first());

        if ($payment === null || $payment->status !== PaymentStatus::Pending || $this->gateway->mode() === 'fake') {
            return;
        }

        try {
            if ($this->gatewayFor($payment)->status($payment->paymongo_payment_intent_id) === 'paid') {
                $this->settle($payment->paymongo_payment_intent_id, 'return');
            }
        } catch (Throwable $e) {
            Log::info('payments.status_check_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    /** The buyer gives up a reservation: places, pending tickets and open checkouts are released. */
    public function cancelReservation(User $user, string $registrationId): void
    {
        $this->db->system('payments.cancel_reservation', function () use ($user, $registrationId) {
            $registration = Registration::query()->whereKey($registrationId)->where('purchaser_user_id', $user->id)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

            if ($registration->status !== RegistrationStatus::Pending) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'Only an unpaid reservation can be cancelled here.', 409);
            }

            DB::table('ticket_holds')->where('registration_id', $registration->id)->where('status', HoldStatus::Active->value)
                ->update(['status' => HoldStatus::Cancelled->value, 'released_at' => now(), 'updated_at' => now()]);
            DB::table('tickets')->where('registration_id', $registration->id)->where('status', TicketStatus::Pending->value)
                ->update(['status' => TicketStatus::Cancelled->value, 'cancelled_at' => now(), 'cancellation_reason' => 'reservation cancelled by buyer', 'updated_at' => now()]);
            DB::table('payments')->where('registration_id', $registration->id)->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Cancelled->value, 'failure_reason' => 'reservation cancelled', 'updated_at' => now()]);
            $registration->forceFill(['status' => RegistrationStatus::Cancelled])->save();
        });

        $this->audit->record('registration.reservation_cancelled', ['registrations', $registrationId]);
    }

    /**
     * Organizer/admin refund of a settled payment (0014: explicit, audited;
     * the payment row itself is never edited). Optionally cancels the tickets.
     */
    public function refund(Payment $payment, float $amount, RefundReason $category, string $reason, User $by, bool $cancelTickets): void
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a paid payment can be refunded.', 409);
        }

        $amountCents = (int) round($amount * 100);
        $refunded = (int) round((float) DB::table('payment_refunds')->where('payment_id', $payment->id)->sum('amount') * 100);
        $remaining = (int) round((float) $payment->amount * 100) - $refunded;

        if ($amountCents <= 0 || $amountCents > $remaining) {
            throw ValidationException::withMessages(['amount' => 'You can refund at most '.number_format($remaining / 100, 2).'.']);
        }

        $feeCents = $this->feeToReturn($payment, $category, $amountCents, $remaining);
        $reason = $category->label().': '.$reason;
        $reference = $this->gatewayFor($payment)->refund($payment->paymongo_payment_intent_id, $amountCents, $reason);

        $this->db->system('payments.refund', function () use ($payment, $amountCents, $feeCents, $reason, $by, $reference, $cancelTickets) {
            $this->checkRefundTotalNow();
            DB::table('payment_refunds')->insert([
                'id' => (string) Str::uuid7(),
                'payment_id' => $payment->id,
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'fee_portion_refunded' => number_format($feeCents / 100, 2, '.', ''),
                'reason' => $reason,
                'recorded_by' => $by->id,
                'external_reference' => $reference,
                'refunded_at' => now(),
                'created_at' => now(),
            ]);
            // A refund answers the buyer's open request, if any (0061).
            $this->refundRequests->markRefunded($payment->id, $by->id);

            if ($cancelTickets) {
                $ids = DB::table('tickets')->where('registration_id', $payment->registration_id)->where('status', TicketStatus::Valid->value)->pluck('id');
                DB::table('tickets')->whereIn('id', $ids)->update(['status' => TicketStatus::Cancelled->value, 'cancelled_at' => now(), 'cancellation_reason' => 'refunded: '.$reason, 'updated_at' => now()]);
                DB::table('qr_tickets')->whereIn('ticket_id', $ids)->where('status', 'active')
                    ->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'refunded']);
            }
        });

        $orgId = DB::table('registrations as r')->join('events as e', 'e.id', '=', 'r.event_id')->where('r.id', $payment->registration_id)->value('e.organization_id');
        $this->alerts->refunded($payment, $amountCents / 100, $cancelTickets);
        $this->adminAlerts->refundRecorded((string) $orgId, $amountCents / 100, $reason);
        $this->audit->record('payment.refunded', $payment, new: ['amount' => $amountCents / 100, 'category' => $category->value, 'fee_returned' => $feeCents / 100, 'reference' => $reference, 'cancel_tickets' => $cancelTickets],
            organizationId: $orgId, reason: $reason);
    }

    /**
     * Payments made through the demo checkout (seeded demo data, or before
     * PayMongo keys were added) are never known to PayMongo.
     */
    private function gatewayFor(Payment $payment): PaymentGateway
    {
        $intent = (string) $payment->paymongo_payment_intent_id;

        return str_starts_with($intent, 'fake_pi_') || str_starts_with($intent, 'demo_') ? new FakeGateway : $this->gateway;
    }

    /**
     * Webhook entry point: log once (provider event id is unique), then act.
     *
     * @return string processed | duplicate | ignored | invalid
     */
    public function handleWebhook(Request $request): string
    {
        $event = $this->gateway->parseWebhook($request);

        if ($event === null || $event['id'] === '') {
            return 'invalid';
        }

        $paymentId = $event['intent_id'] ? $this->db->system('webhook.lookup', fn () => DB::table('payments')->where('paymongo_payment_intent_id', $event['intent_id'])->value('id')) : null;

        if ($paymentId === null) {
            Log::info('paymongo.webhook_unmatched', ['event' => $event['id'], 'type' => $event['type']]);

            return 'ignored';
        }

        $logged = $this->db->system('webhook.log', fn () => DB::table('payment_webhook_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'payment_id' => $paymentId, 'provider_event_id' => $event['id'],
            'event_type' => $event['type'], 'payload' => json_encode($event['payload']), 'processing_status' => 'received', 'created_at' => now(),
        ]));

        if ($logged === 0) {
            return 'duplicate'; // delivered before: already handled
        }

        $status = match ($event['type']) {
            'checkout_session.payment.paid', 'payment.paid' => $this->settle($event['intent_id'], 'webhook') ? 'processed' : 'ignored',
            'payment.failed' => (function () use ($event) {
                $this->fail($event['intent_id'], 'declined by the payment provider');

                return 'processed';
            })(),
            default => 'ignored',
        };

        $this->db->system('webhook.mark', fn () => DB::table('payment_webhook_events')->where('provider_event_id', $event['id'])
            ->update(['processing_status' => $status, 'processed_at' => now()]));

        return $status;
    }

    /**
     * Platform fee handed back with this refund, in centavos. Valid reasons
     * return the fee in proportion to the amount refunded; the refund that
     * empties the payment takes whatever fee is left, so rounding never
     * leaves stray centavos. Negligence and buyer requests return nothing.
     */
    private function feeToReturn(Payment $payment, RefundReason $category, int $amountCents, int $remainingCents): int
    {
        if (! $category->returnsFee()) {
            return 0;
        }

        return $this->db->system('payments.fee_lookup', function () use ($payment, $amountCents, $remainingCents) {
            $fee = (int) round((float) DB::table('platform_fee_snapshots')->where('payment_id', $payment->id)->value('computed_amount') * 100);
            $returned = (int) round((float) DB::table('payment_refunds')->where('payment_id', $payment->id)->sum('fee_portion_refunded') * 100);
            $left = max(0, $fee - $returned);

            return $amountCents === $remainingCents ? $left : min($left, intdiv($fee * $amountCents, (int) round((float) $payment->amount * 100)));
        });
    }

    /**
     * The 0014 refund-total check is a DEFERRED constraint trigger: left
     * deferred it fires at COMMIT, after system() has switched back to the
     * app role, which may not lock payments (42501). Fire it inside the
     * privileged block instead. Tests never commit, so only a real request
     * shows this.
     */
    private function checkRefundTotalNow(): void
    {
        DB::statement('SET CONSTRAINTS trg_check_refund_total IMMEDIATE');
    }

    /** Hold still valid, or (hold expired) the places are free again. */
    private function placesStillAvailable(Registration $registration): bool
    {
        $activeHold = DB::table('ticket_holds')->where('registration_id', $registration->id)->where('status', HoldStatus::Active->value)
            ->where('expires_at', '>', now())->exists();

        if ($activeHold) {
            return true;
        }

        $byTier = DB::table('tickets')->where('registration_id', $registration->id)->where('status', TicketStatus::Pending->value)
            ->selectRaw('ticket_type_id, count(*) as n')->groupBy('ticket_type_id')->pluck('n', 'ticket_type_id');
        $event = Event::query()->find($registration->event_id);

        try {
            foreach ($byTier as $tierId => $n) {
                $this->capacity->reserve($event, TicketType::query()->findOrFail($tierId), (int) $n);
            }
        } catch (ApiException) {
            return false;
        }

        return ! $event->isTerminal();
    }

    private function autoRefund(Payment $payment, Registration $registration, string $problem, ?string $orgId): void
    {
        try {
            $reference = $this->gateway->refund($payment->paymongo_payment_intent_id, (int) round((float) $payment->amount * 100), 'Automatic refund: '.$problem);
            $this->db->system('payments.auto_refund', function () use ($payment, $registration, $problem, $reference) {
                $this->checkRefundTotalNow();
                DB::table('payment_refunds')->insert([
                    'id' => (string) Str::uuid7(), 'payment_id' => $payment->id, 'amount' => $payment->amount, 'fee_portion_refunded' => '0.00', // no fee is taken before tickets are issued
                    'reason' => 'Automatic refund: '.$problem, 'recorded_by' => $registration->purchaser_user_id,
                    'external_reference' => $reference, 'refunded_at' => now(), 'created_at' => now(),
                ]);
            });
            $this->audit->record('payment.auto_refunded', $payment, new: ['reference' => $reference], organizationId: $orgId, reason: $problem);
            $this->alerts->refunded($payment, (float) $payment->amount, true);
        } catch (Throwable $e) {
            // Flag for manual follow-up; the payment row shows it as paid with cancelled tickets.
            Log::error('payments.auto_refund_failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
            $this->audit->record('payment.refund_needed', $payment, organizationId: $orgId, reason: $problem);
        }
    }
}
