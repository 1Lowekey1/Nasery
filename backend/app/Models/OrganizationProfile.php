<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * public.organization_profiles (0006): the organizer's business profile.
 * Shown publicly (public_organization_profiles view) only when published
 * AND the organization is approved and not suspended.
 */
#[Fillable(['organization_id', 'business_name', 'description', 'location_text', 'latitude', 'longitude', 'contact_email', 'contact_phone', 'cover_image_url', 'is_published',
    // 0046: details from the business profile panel.
    'years_experience', 'service_radius_km', 'event_tags', 'specialties', 'website', 'facebook', 'instagram', 'banner_color',
    // 0048: estimate settings shown with every client estimate.
    'estimate_service_charge_percent', 'estimate_tax_percent', 'estimate_note'])]
class OrganizationProfile extends Model
{
    protected $attributes = [
        'description' => null,
        'location_text' => null,
        'latitude' => null,
        'longitude' => null,
        'contact_email' => null,
        'contact_phone' => null,
        'cover_image_url' => null,
        'is_published' => false,
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'is_published' => 'boolean',
            'service_radius_km' => 'integer',
            'event_tags' => 'array',
            'estimate_service_charge_percent' => 'decimal:2',
            'estimate_tax_percent' => 'decimal:2',
            'specialties' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
