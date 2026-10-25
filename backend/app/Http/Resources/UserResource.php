<?php

namespace App\Http\Resources;

use App\Services\Media\ImageStore;
use App\Models\User;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own account. Never exposes password_hash.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]))),
            'phone' => $this->phone,
            'avatar_url' => ImageStore::url($this->avatar_path ?? null),
            'account_type' => $this->account_type->value,
            'status' => $this->status->value,
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'has_password' => $this->password_hash !== null,
            'social_providers' => app(SocialAuthService::class)->linkedProviders($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
