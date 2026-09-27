<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `webhook_processing_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum WebhookProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
