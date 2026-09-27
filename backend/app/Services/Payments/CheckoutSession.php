<?php

namespace App\Services\Payments;

/** The provider's answer to "start a checkout". */
final readonly class CheckoutSession
{
    public function __construct(
        public string $intentId,
        public string $checkoutUrl,
    ) {}
}
