<?php

namespace App\Http\Resources;

use App\Models\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationService
 */
class OfferedServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'name' => $this->custom_name ?? $this->catalog_name,
            'custom_name' => $this->custom_name,
            'description' => $this->description,
            'base_price' => $this->base_price,
            'is_active' => $this->is_active,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
