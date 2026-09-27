<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * public.event_progress (0010): the ONLY preparation surface clients can
 * read: readiness percentage and current stage. Maintained by
 * ProgressTracker; never exposes task or staff detail.
 */
#[Table('event_progress')]
class EventProgress extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'readiness_percent' => 'decimal:2',
            'is_manual_override' => 'boolean',
            'overridden_at' => 'datetime',
        ];
    }
}
