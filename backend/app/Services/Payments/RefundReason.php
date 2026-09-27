<?php

namespace App\Services\Payments;

/**
 * Why a refund is made; decides who carries NaSeRy's platform fee (user
 * policy 2026-09-25): the fee is returned only for a valid reason outside
 * anyone's control, never when the refund comes from the organizer's own
 * negligence (or a buyer's change of plans, which the organizer grants as
 * goodwill). Stored as a prefix on payment_refunds.reason and in the audit
 * entry; there is no category column (0014).
 */
enum RefundReason: string
{
    case ForceMajeure = 'force_majeure';
    case PaymentError = 'payment_error';
    case OrganizerFault = 'organizer_fault';
    case BuyerRequest = 'buyer_request';

    public function returnsFee(): bool
    {
        return in_array($this, [self::ForceMajeure, self::PaymentError], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ForceMajeure => 'Force majeure',
            self::PaymentError => 'Payment error',
            self::OrganizerFault => 'Organizer fault',
            self::BuyerRequest => 'Buyer request',
        };
    }
}
