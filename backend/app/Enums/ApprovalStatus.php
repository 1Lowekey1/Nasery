<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `approval_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
}
