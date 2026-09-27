<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * public.quotation_items (0008): one line of a quotation's cost breakdown.
 * subtotal = quantity × unit_price, computed by the server.
 */
#[Fillable(['organization_service_id', 'description', 'quantity', 'unit_price', 'subtotal'])]
class QuotationItem extends Model
{
    public const UPDATED_AT = null;

    protected $attributes = ['organization_service_id' => null];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }
}
