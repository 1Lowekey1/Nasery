<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `inquiry_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum InquiryStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ReceivingBids = 'receiving_bids';
    case Accepted = 'accepted';
    case ConvertedToEvent = 'converted_to_event';
    case Cancelled = 'cancelled';
}
