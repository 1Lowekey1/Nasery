<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `walk_in_category`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum WalkInCategory: string
{
    case TicketLinked = 'ticket_linked';
    case PaidWalkIn = 'paid_walk_in';
    case RegisteredAttendee = 'registered_attendee';
    case HeadcountOnly = 'headcount_only';
}
