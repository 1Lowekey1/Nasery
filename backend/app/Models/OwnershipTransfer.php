<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * public.ownership_transfers (0004): append-only ownership trail. The first
 * row of an organization (from_member_id NULL) records its initial owner.
 */
#[Fillable(['organization_id', 'from_member_id', 'to_member_id', 'initiated_by', 'reason', 'transferred_at'])]
class OwnershipTransfer extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }
}
