<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.event_sessions (0009): a dated block of an event (one-day events
 * have one). Times are stored in UTC with the event's timezone alongside.
 */
#[Fillable(['name', 'starts_at', 'ends_at', 'timezone'])]
class EventSession extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'name' => null,
        'status' => 'upcoming',
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
