<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `transfer_qr_action`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum TransferQrAction: string
{
    case Retained = 'retained';
    case Reissued = 'reissued';
}
