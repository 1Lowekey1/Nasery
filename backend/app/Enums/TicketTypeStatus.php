<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `ticket_type_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum TicketTypeStatus: string
{
    case Draft = 'draft';
    case OnSale = 'on_sale';
    case Paused = 'paused';
    case Closed = 'closed';
}
