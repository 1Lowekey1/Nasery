<?php

namespace App\Console\Commands;

use App\Services\Billing\Billing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * B18: renewals into grace, unpaid grace into expiry (back to Free),
 * cancellations at period end, abandoned checkouts. Scheduled hourly.
 */
#[Signature('nasery:billing:cycle')]
#[Description('Advance subscription periods: renewals, grace periods, cancellations')]
class RunBillingCycle extends Command
{
    public function handle(Billing $billing): int
    {
        $n = $billing->runCycle();
        $this->info(collect($n)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '));

        return self::SUCCESS;
    }
}
