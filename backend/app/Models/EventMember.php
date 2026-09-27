<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.event_members (0009): a team member assigned to one event, with
 * optional event-only permissions (event_member_permissions). The label is
 * display-only; authorization never reads it. Written in the system context
 * after Laravel's staff.manage check (the RLS insert policy requires an
 * event permission the organization owner doesn't hold as a non-member).
 */
class EventMember extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => MembershipStatus::class];
    }
}
