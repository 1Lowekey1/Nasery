<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.payments (0012): one checkout attempt for a registration. amount is
 * the literal charge (= ticket subtotal; the platform fee is deducted from
 * it, never added). At most one paid payment per registration (DB index).
 * Written only in the system context (no app-role write grant).
 */
class Payment extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => PaymentStatus::class, 'amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }
}
