<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `permission_effect`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum PermissionEffect: string
{
    case Grant = 'grant';
    case Revoke = 'revoke';
}
