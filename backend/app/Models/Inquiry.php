<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.inquiries (0007 + 0030): a client's event request.
 *
 * Lifecycle: draft → submitted (invitations sent) → receiving_bids (first
 * quotation, B5) → accepted (accept_quotation(), B5) → converted_to_event
 * (B6); open states can become cancelled (client, or auto-close). While open,
 * the client may change it (0051: revised_at, revision_count); organizers who
 * already quoted are told and can revise their quotation.
 * Direct inquiries invite chosen organizers; open postings (match_radius_km
 * set) invite every listed organizer that matches.
 */
#[Fillable(['event_type_id', 'requested_date', 'guest_count', 'budget_min', 'budget_max', 'location_text', 'latitude', 'longitude', 'match_radius_km', 'additional_requirements'])]
class Inquiry extends Model
{
    use SoftDeletes;

    public const OPEN_STATUSES = [InquiryStatus::Submitted, InquiryStatus::ReceivingBids];

    /** 0051: how many times a client may change a request after sending it. */
    public const MAX_REVISIONS = 5;

    protected $attributes = [
        'event_type_id' => null,
        'requested_date' => null,
        'guest_count' => null,
        'budget_min' => null,
        'budget_max' => null,
        'location_text' => null,
        'latitude' => null,
        'longitude' => null,
        'match_radius_km' => null,
        'additional_requirements' => null,
        'status' => 'draft',
        'revised_at' => null,
        'revision_count' => 0,
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => InquiryStatus::class,
            'requested_date' => 'date',
            'guest_count' => 'integer',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'match_radius_km' => 'decimal:1',
            'revised_at' => 'datetime',
            'revision_count' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(InquiryInvitation::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(InquiryService::class)->orderBy('created_at');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isOpenPosting(): bool
    {
        return $this->match_radius_km !== null;
    }
}
