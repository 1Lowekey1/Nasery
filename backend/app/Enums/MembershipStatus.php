<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `membership_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum MembershipStatus: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Deactivated = 'deactivated';
}
