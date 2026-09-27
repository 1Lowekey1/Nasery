<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `quotation_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Cancelled = 'cancelled';
}
