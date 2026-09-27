<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `event_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
