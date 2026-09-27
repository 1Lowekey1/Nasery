<?php

namespace App\Models;

use App\Enums\AdmissionType;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.tickets (0011): one admission. valid/used tickets consume capacity;
 * a pending ticket's capacity is represented by its hold (paid, B10).
 */
class Ticket extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'admission_type' => AdmissionType::class,
            'purchased_price' => 'decimal:2',
            'platform_fee_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function qr(): HasOne
    {
        return $this->hasOne(QrTicket::class);
    }
}
