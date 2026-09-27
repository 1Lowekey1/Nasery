<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `billing_cycle`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}
