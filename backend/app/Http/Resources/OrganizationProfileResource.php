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
        // 0046 columns (read only when loaded, so older rows/queries keep working).
        $extra = fn (string $key, mixed $default = null) => array_key_exists($key, $this->resource->getAttributes()) ? ($this->{$key} ?? $default) : $default;

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
            'years_experience' => $extra('years_experience'),
            'service_radius_km' => $extra('service_radius_km'),
            'event_tags' => $extra('event_tags', []),
            'specialties' => $extra('specialties', []),
            'website' => $extra('website'),
            'facebook' => $extra('facebook'),
            'instagram' => $extra('instagram'),
            'banner_color' => $extra('banner_color'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
