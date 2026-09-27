<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.organization_price_options (0033): an organizer's own priced option
 * for the public quotation calculator, optionally grouped under one of the
 * organization's services (clients pick one option per service). Priced
 * flat or per guest; estimates are not binding.
 */
#[Fillable(['organization_service_id', 'name', 'description', 'pricing_unit', 'price', 'min_guests', 'is_active', 'sort_order'])]
class OrganizationPriceOption extends Model
{
    use SoftDeletes;

    public const UNITS = ['flat', 'per_guest'];

    protected $attributes = [
        'organization_service_id' => null,
        'description' => null,
        'pricing_unit' => 'flat',
        'min_guests' => null,
        'is_active' => true,
        'sort_order' => 0,
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'min_guests' => 'integer',
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
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
