<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.events (0009 + 0034).
 *
 * Lifecycle: draft → published → ongoing → completed; draft/published/
 * ongoing → cancelled. completed and cancelled are terminal (DB trigger;
 * only admin_override_event_status() may leave them). An event made from an
 * accepted quotation carries inquiry_id / accepted_quotation_id, which the
 * app role cannot write (0034): EventManager::convert() links them in the
 * system context after checking the quotation was won by this organization.
 */
#[Fillable(['event_type_id', 'name', 'description', 'timezone', 'overall_capacity', 'venue_name', 'venue_address',
    'latitude', 'longitude', 'visibility', 'contact_name', 'contact_email', 'contact_phone'])]
class Event extends Model
{
    use SoftDeletes;

    public const VISIBILITIES = ['private', 'public'];

    /** Only columns the app role may INSERT (0034); status defaults to 'draft' in the DB. */
    protected $attributes = [
        'event_type_id' => null,
        'description' => null,
        'timezone' => 'Asia/Manila',
        'overall_capacity' => null,
        'venue_name' => null,
        'venue_address' => null,
        'latitude' => null,
        'longitude' => null,
        'visibility' => 'private',
        'contact_name' => null,
        'contact_email' => null,
        'contact_phone' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'overall_capacity' => 'integer',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'cancelled_at' => 'datetime',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(EventSession::class)->orderBy('starts_at');
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class)->orderBy('created_at');
    }

    public function tasks(): HasManyThrough
    {
        return $this->hasManyThrough(Task::class, Checklist::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [EventStatus::Completed, EventStatus::Cancelled], true);
    }
}
