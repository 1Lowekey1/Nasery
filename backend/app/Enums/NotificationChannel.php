<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `notification_channel`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Sms = 'sms';
}
