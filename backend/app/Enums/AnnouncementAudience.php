<?php

namespace App\Enums;

/**
 * Mirrors PostgreSQL enum type `announcement_audience`. Generated from the live schema; keep in
 * sync with database/supabase/ migrations.
 */
enum AnnouncementAudience: string
{
    case All = 'all';
    case Organizers = 'organizers';
    case Clients = 'clients';
}
