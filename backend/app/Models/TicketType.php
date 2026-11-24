<?php

namespace App\Models;

use App\Enums\TicketTypeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.ticket_types (0011 + 0036): a ticket or invitation tier: price,
 * capacity, access zone, benefits and sales window. Capacity is enforced
 * from tickets + active holds under a row lock (CapacityGuard), never from
 * cached_sold_count.
 */
#[Fillable(['name', 'description', 'zone_name', 'benefits', 'price', 'capacity', 'sales_start_at', 'sales_end_at', 'status', 'sort_order', 'seating_mode', 'table_label', 'table_count', 'table_size'])]
class TicketType extends Model
{
    use SoftDeletes;

    /** A private event's invitation list (free, closed: never on sale). The event editor manages it. */
    public const GUEST_LIST = 'Guest list';

    protected $attributes = [
        'description' => null,
        'zone_name' => null,
        'benefits' => '[]',
        'currency' => 'PHP',
        'sales_start_at' => null,
        'sales_end_at' => null,
        'status' => 'draft',
        'sort_order' => 0,
        'cached_sold_count' => 0,
        'deleted_at' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketTypeStatus::class,
            'benefits' => 'array',
            'price' => 'decimal:2',
            'capacity' => 'integer',
            'sales_start_at' => 'datetime',
            'sales_end_at' => 'datetime',
            'sort_order' => 'integer',
            'table_count' => 'integer',
            'table_size' => 'integer',
        ];
    }

    public function isFree(): bool
    {
        return (float) $this->price === 0.0;
    }
}
