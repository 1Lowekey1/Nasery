<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * public.users (0003). One identity table for every actor; account_type is a
 * platform-level category only; organization roles/permissions decide what
 * a business user may do inside a tenant.
 */
#[Fillable(['email', 'password_hash', 'first_name', 'middle_name', 'last_name', 'phone', 'account_type'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * Mirrors the column defaults (0003_users) so a freshly created instance is
     * complete without a re-read; strict mode rejects missing attributes.
     */
    protected $attributes = [
        'status' => 'active',
        'middle_name' => null,
        'phone' => null,
        'password_hash' => null,
        'email_verified_at' => null,
        'deleted_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * The schema has no remember_token column; "remember me" is not supported.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active && $this->deleted_at === null;
    }
}
