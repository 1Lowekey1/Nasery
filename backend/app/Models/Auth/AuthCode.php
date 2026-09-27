<?php

namespace App\Models\Auth;

use App\Enums\AuthCodePurpose;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * laravel.auth_codes: one-time codes, stored only as HMAC hashes.
 */
#[Connection('system')]
#[Fillable(['user_id', 'purpose', 'code_hash', 'attempts', 'expires_at', 'last_sent_at', 'consumed_at'])]
class AuthCode extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'purpose' => AuthCodePurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
