<?php

namespace App\Models;

use App\Enums\HoldStatus;

/**
 * public.ticket_holds (0011): seats reserved while a paid checkout is in
 * progress (15 minutes). Active, unexpired holds count against capacity;
 * expire_stale_holds() releases the rest.
 */
class TicketHold extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => HoldStatus::class, 'expires_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
