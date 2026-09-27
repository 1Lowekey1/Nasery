<?php

namespace App\Http\Resources;

use App\Models\OrganizationProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationProfile
 */
class OrganizationProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'business_name' => $this->business_name,
            'description' => $this->description,
            'location_text' => $this->location_text,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'cover_image_url' => $this->cover_image_url,
            'is_published' => $this->is_published,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
