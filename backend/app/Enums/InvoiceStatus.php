<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `invoice_status`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';
}
