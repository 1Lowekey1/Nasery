<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `moderation_report_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum ModerationReportStatus: string
{
    case Open = 'open';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
