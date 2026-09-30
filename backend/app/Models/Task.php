<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.tasks (0010): one preparation task. Readiness counts done tasks
 * over all tasks that aren't cancelled.
 */
#[Fillable(['name', 'description', 'due_at', 'priority', 'status', 'completion_note'])]
class Task extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'description' => null,
        'due_at' => null,
        'priority' => 'medium',
        'status' => 'pending',
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }
}
