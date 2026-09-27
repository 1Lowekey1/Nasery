<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `inquiry_org_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum InquiryOrgStatus: string
{
    case Pending = 'pending';
    case Viewed = 'viewed';
    case Declined = 'declined';
    case Quoted = 'quoted';
    case Withdrawn = 'withdrawn';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
