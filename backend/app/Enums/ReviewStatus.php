<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `review_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum ReviewStatus: string
{
    case Published = 'published';
    case Hidden = 'hidden';
    case Disputed = 'disputed';
}
