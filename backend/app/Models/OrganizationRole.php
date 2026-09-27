<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.organization_roles (0005): a named permission set for team members.
 * Organizations create their own (optionally from a StaffManager preset);
 * permissions live in role_permissions. Owners don't need a role.
 */
#[Fillable(['name', 'description'])]
class OrganizationRole extends Model
{
    use SoftDeletes;

    protected $attributes = ['description' => null, 'is_system_default' => false, 'deleted_at' => null];
}
