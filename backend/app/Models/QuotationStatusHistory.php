<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * public.quotation_status_history (0008): append-only status trail. No
 * INSERT grant for the app role; written in the system context or by
 * accept_quotation().
 */
#[Table('quotation_status_history')]
class QuotationStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'from_status' => QuotationStatus::class,
            'to_status' => QuotationStatus::class,
        ];
    }
}
