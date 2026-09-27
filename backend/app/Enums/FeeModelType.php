<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `fee_model_type`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum FeeModelType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case Hybrid = 'hybrid';
}
