<?php

namespace App\Models\Auth;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * laravel.social_accounts: a provider identity linked to public.users.
 */
#[Connection('system')]
#[Fillable(['user_id', 'provider', 'provider_user_id', 'provider_email', 'last_used_at'])]
class SocialAccount extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'last_used_at' => 'datetime',
        ];
    }
}
