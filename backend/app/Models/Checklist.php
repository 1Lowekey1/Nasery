<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.checklists (0010): a group of preparation tasks for one event
 * (e.g. "Venue & suppliers"). Internal to the organizer; clients only ever
 * see the computed readiness in event_progress.
 */
#[Fillable(['name', 'description'])]
class Checklist extends Model
{
    use SoftDeletes;

    protected $attributes = ['description' => null, 'deleted_at' => null];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('created_at')->orderBy('id');
    }
}
