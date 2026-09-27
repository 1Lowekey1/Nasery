<?php

namespace App\Models;

use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.registrations (0011): one booking: a self-registration by a
 * signed-in user (online) or a guest-list entry by the organizer. Holds
 * the tickets it produced.
 */
class Registration extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RegistrationStatus::class,
            'registration_source' => RegistrationSource::class,
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
