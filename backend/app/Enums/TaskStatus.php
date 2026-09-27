<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `task_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';
}
