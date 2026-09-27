<?php

namespace App\Models;

/**
 * public.attendees (0011): the person a ticket admits. Not unique by email
 * (people attend many events). Written only by the ticket issuance path in
 * the system context (0025 removed generic write policies).
 */
class Attendee extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['extra_fields' => 'array', 'age' => 'integer'];
    }
}
