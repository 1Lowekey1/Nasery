<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `hold_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum HoldStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
