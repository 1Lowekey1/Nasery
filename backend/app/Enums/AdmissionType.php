<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `admission_type`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum AdmissionType: string
{
    case Paid = 'paid';
    case Free = 'free';
    case Invited = 'invited';
    case Complimentary = 'complimentary';
}
