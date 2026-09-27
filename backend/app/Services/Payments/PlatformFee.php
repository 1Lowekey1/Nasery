<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The platform fee (0013): DEDUCTED from the ticket subtotal, never added
 * on top; the attendee pays the ticket price only. The active config at
 * settlement time is snapshotted per payment so the arithmetic can be
 * verified later even if the rate changes. Runs in the system context.
 */
class PlatformFee
{
    /**
     * Snapshot the fee for a paid payment and split it across its tickets
     * (proportional to price, leftover centavos to the first ticket).
     *
     * @param  Collection<int, object>  $tickets  id + purchased_price
     * @return array<string, string>  fee per ticket id
     */
    public function apply(Payment $payment, Collection $tickets): array
    {
        $subtotalCents = (int) round((float) $payment->amount * 100);
        $config = DB::table('platform_fee_configs')->where('is_enabled', true)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->orderByDesc('effective_from')->first();

        if ($config === null) {
            return [];
        }

        $feeCents = match ($config->fee_model) {
            'percentage' => (int) round($subtotalCents * (float) $config->percentage_rate),
            'fixed' => (int) round((float) $config->fixed_amount * 100),
            'hybrid' => (int) round($subtotalCents * (float) $config->percentage_rate + (float) $config->fixed_amount * 100),
        };
        $feeCents = min($feeCents, $subtotalCents);

        DB::table('platform_fee_snapshots')->insert([
            'id' => (string) Str::uuid7(),
            'payment_id' => $payment->id,
            'platform_fee_config_id' => $config->id,
            'fee_enabled' => true,
            'fee_type' => $config->fee_model,
            'fee_rate' => $config->percentage_rate,
            'fee_fixed_amount' => $config->fixed_amount,
            'applicable_transaction_amount' => $payment->amount,
            'computed_amount' => number_format($feeCents / 100, 2, '.', ''),
            'created_at' => now(),
        ]);

        if ($tickets->isEmpty() || $subtotalCents === 0) {
            return [];
        }

        $shares = [];
        $allocated = 0;
        foreach ($tickets as $ticket) {
            $share = intdiv($feeCents * (int) round((float) $ticket->purchased_price * 100), $subtotalCents);
            $shares[$ticket->id] = $share;
            $allocated += $share;
        }
        $shares[$tickets->first()->id] += $feeCents - $allocated;

        return array_map(fn (int $c) => number_format($c / 100, 2, '.', ''), $shares);
    }
}
