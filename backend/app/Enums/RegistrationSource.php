<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `registration_source`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum RegistrationSource: string
{
    case Online = 'online';
    case Organizer = 'organizer';
    case Staff = 'staff';
    case WalkIn = 'walk_in';
}
