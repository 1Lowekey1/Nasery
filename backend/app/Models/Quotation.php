<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * public.quotations (0008 + 0032): an organizer's bid on an inquiry it was
 * invited to (composite FK to inquiry_organizations).
 *
 * Lifecycle: draft → submitted → accepted | rejected (client) | withdrawn
 * (organizer, or replaced by a revision) | cancelled (inquiry closed).
 * One active (draft/submitted) quotation per organizer per inquiry. Status
 * and decision columns are not writable by the app role (0032): they change
 * in the logged system context or through accept_quotation().
 */
#[Fillable(['total_amount', 'terms', 'package_name', 'proposal'])]
class Quotation extends Model
{
    public const ACTIVE_STATUSES = [QuotationStatus::Draft, QuotationStatus::Submitted];

    /** Only columns the app role may INSERT (0032); status defaults to 'draft' in the DB. */
    protected $attributes = [
        'currency' => 'PHP',
        'terms' => null,
        'package_name' => null,
        'proposal' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'total_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('created_at')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === QuotationStatus::Draft;
    }

    public function isSubmitted(): bool
    {
        return $this->status === QuotationStatus::Submitted;
    }
}
