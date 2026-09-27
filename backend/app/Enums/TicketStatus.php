<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `ticket_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum TicketStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Used = 'used';
    case Cancelled = 'cancelled';
    case Invalidated = 'invalidated';
}
