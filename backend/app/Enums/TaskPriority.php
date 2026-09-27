<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `task_priority`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';
}
