<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.services (0006): the platform service catalog. RLS has no policy
 * for it, so it is read in the system context; curated by admins (B17).
 */
class Service extends Model
{
    use SoftDeletes;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['is_system_default' => 'boolean'];
    }
}
