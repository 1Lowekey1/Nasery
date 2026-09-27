<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.organization_services (0006): a service the organizer offers,
 * either from the platform catalog (service_id) or custom (custom_name).
 */
#[Fillable(['organization_id', 'service_id', 'custom_name', 'description', 'base_price', 'is_active'])]
class OrganizationService extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'service_id' => null,
        'custom_name' => null,
        'description' => null,
        'base_price' => null,
        'is_active' => true,
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function catalogService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
