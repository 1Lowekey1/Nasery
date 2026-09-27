<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `subscription_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case PaymentFailed = 'payment_failed';
    case GracePeriod = 'grace_period';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    // 0041: checkout started, not paid yet (not "live").
    case Incomplete = 'incomplete';
}
