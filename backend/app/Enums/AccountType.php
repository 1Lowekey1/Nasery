<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `account_type`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum AccountType: string
{
    case Client = 'client';
    case Business = 'business';
    case Admin = 'admin';
}
