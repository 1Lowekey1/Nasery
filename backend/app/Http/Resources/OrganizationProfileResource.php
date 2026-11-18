<?php

namespace App\Http\Resources;

use App\Services\Admin\PlatformSettings;
use App\Services\Media\ImageStore;
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
            // Uploaded pictures; the cover falls back to the older pasted link.
            'logo_url' => ImageStore::url($this->logo_path ?? null),
            'cover_url' => ImageStore::url($this->cover_path ?? null) ?? $this->cover_image_url,
            'cover_uploaded' => ($this->cover_path ?? null) !== null,
            'is_published' => $this->is_published,
            'years_experience' => $extra('years_experience'),
            'service_radius_km' => $extra('service_radius_km'),
            'event_tags' => $extra('event_tags', []),
            'specialties' => $extra('specialties', []),
            'website' => $extra('website'),
            'facebook' => $extra('facebook'),
            'instagram' => $extra('instagram'),
            'banner_color' => $extra('banner_color'),
            'estimate_service_charge_percent' => ($v = $extra('estimate_service_charge_percent')) !== null ? (float) $v : null,
            'estimate_tax_percent' => app(PlatformSettings::class)->vatFor($extra('estimate_tax_percent')),
            // NaSeRy's VAT rate, for the "We charge VAT" switch.
            'vat_rate' => (float) app(PlatformSettings::class)->get('vat_percent'),
            // 0062: event types with no VAT (ids).
            'vat_exempt_event_types' => $extra('vat_exempt_event_types', []),
            'estimate_note' => $extra('estimate_note'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
