<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.event_types (0006). organization_id NULL = platform default (seeded,
 * admin-managed); otherwise an organizer's own custom type. Events may only
 * use their own organization's types or platform defaults (0009 trigger).
 */
#[Fillable(['organization_id', 'name', 'description'])]
class EventType extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'description' => null,
    ];

    protected function casts(): array
    {
        return ['is_system_default' => 'boolean'];
    }

    public function isPlatformDefault(): bool
    {
        return $this->organization_id === null;
    }
}
