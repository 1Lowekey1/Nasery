<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.organizations (0004): the tenant root / organizer workspace.
 *
 * approval_status is the single authoritative current state; it changes only
 * through set_organizer_approval_status() (admins) or the owner's
 * resubmission action (system context). Not app-writable (0028).
 */
#[Fillable(['legal_name', 'display_name', 'slug'])]
class Organization extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'approval_status' => 'pending',
        'is_suspended' => false,
        'suspended_reason' => null,
        'suspended_at' => null,
        'suspended_by' => null,
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => ApprovalStatus::class,
            'is_suspended' => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(OrganizationProfile::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(OrganizationService::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** Price list for the public quotation calculator (0033). */
    public function priceOptions(): HasMany
    {
        return $this->hasMany(OrganizationPriceOption::class);
    }

    /** The organization's own custom event types (not platform defaults). */
    public function eventTypes(): HasMany
    {
        return $this->hasMany(EventType::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(OrganizerApprovalRequest::class)->orderByDesc('created_at');
    }

    /** May operate on the marketplace (bid, publish events, sell tickets). */
    public function isOperational(): bool
    {
        return $this->approval_status === ApprovalStatus::Approved && ! $this->is_suspended && $this->deleted_at === null;
    }
}
