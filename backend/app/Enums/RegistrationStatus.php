<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `registration_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum RegistrationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
