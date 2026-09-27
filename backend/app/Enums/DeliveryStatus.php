<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `delivery_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Retrying = 'retrying';
}
