<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `payment_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
