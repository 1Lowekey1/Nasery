<?php

namespace App\Enums;

/**
 * Mirrors the CHECK constraint on users.status (text column, not a PG enum).
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
}
