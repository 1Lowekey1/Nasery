<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * public.qr_tickets (0021): the scannable credential for a ticket. Only a
 * hash is stored; the raw token is re-derived when an authorized viewer
 * needs the QR code (see QrTokens).
 */
#[Table('qr_tickets')]
class QrTicket extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'invalidated_at' => 'datetime'];
    }
}
