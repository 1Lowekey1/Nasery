<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Reserved test domain so factory rows are recognisable on shared DBs.
            'email' => 'test+'.Str::lower(Str::random(16)).'@nasery.test',
            'password_hash' => static::$password ??= Hash::make('password'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'account_type' => AccountType::Client,
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function business(): static
    {
        return $this->state(fn () => ['account_type' => AccountType::Business]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['account_type' => AccountType::Admin]);
    }
}
