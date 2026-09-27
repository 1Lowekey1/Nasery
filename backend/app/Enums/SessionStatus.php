<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `session_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum SessionStatus: string
{
    case Upcoming = 'upcoming';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
