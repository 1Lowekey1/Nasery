<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.organization_price_options (0033): an organizer's own priced option
 * for the public quotation calculator, optionally grouped under one of the
 * organization's services (clients pick one option per service). Priced
 * flat, per guest, per hour, per day or per unit (0048: with an optional unit
 * label and min/max quantity a client may pick); estimates are not binding.
 * 0052: event_tags = the event types it's for (empty = every event type).
 */
#[Fillable(['organization_service_id', 'name', 'description', 'pricing_unit', 'price', 'min_guests', 'is_active', 'sort_order', 'unit_label', 'min_quantity', 'max_quantity', 'event_tags'])]
class OrganizationPriceOption extends Model
{
    use SoftDeletes;

    public const UNITS = ['flat', 'per_guest', 'per_hour', 'per_day', 'per_unit'];

    /** Units where the client chooses how many (hours, days, items). */
    public const QUANTITY_UNITS = ['per_hour', 'per_day', 'per_unit'];

    protected $attributes = [
        'organization_service_id' => null,
        'description' => null,
        'pricing_unit' => 'flat',
        'min_guests' => null,
        'unit_label' => null,
        'min_quantity' => null,
        'max_quantity' => null,
        'event_tags' => '[]',
        'is_active' => true,
        'sort_order' => 0,
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'min_guests' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'event_tags' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'organization_service_id' => $this->organization_service_id,
            'name' => $this->name,
            'description' => $this->description,
            'pricing_unit' => $this->pricing_unit,
            'price' => $this->price,
            'min_guests' => $this->min_guests,
            'unit_label' => $this->unit_label,
            'min_quantity' => $this->min_quantity,
            'max_quantity' => $this->max_quantity,
            'event_tags' => $this->event_tags ?? [],
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
