<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\Admin\AdminAccounts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Platform admins are never self-registered. Create one from the server:
 *   php artisan nasery:admin:create admin@example.com --first=Nathan --last=Sevilla --invite
 * --invite (recommended): no password is set here; the admin gets an e-mail
 * with a code and chooses their own password on "Forgot password". Without
 * it, the password is prompted (hidden), or generated and shown once with
 * --generate. After the first admin, add admins in the app (Admin team).
 */
#[Signature('nasery:admin:create {email} {--first=Platform} {--last=Admin} {--generate : Generate a strong password and print it once} {--invite : E-mail a code so the admin sets their own password}')]
#[Description('Create a platform admin account')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (User::query()->where('email', $email)->exists()) {
            $this->error('An account with that email already exists.');

            return self::FAILURE;
        }

        if ($this->option('invite')) {
            $validator = Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:254']]);
            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }
            $admin = app(AdminAccounts::class)->invite(null, (string) $this->option('first'), (string) $this->option('last'), $email);
            $this->info("Admin invited: {$admin['email']}. They'll get an e-mail with a code to set their password (valid 10 minutes; they can request a new one on \"Forgot password\").");

            return self::SUCCESS;
        }

        $password = $this->option('generate') ? Str::password(20) : (string) $this->secret('Password (min 12 chars, letters + numbers)');

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', Password::min(12)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->create([
            'email' => $email,
            'password_hash' => $password,
            'first_name' => (string) $this->option('first'),
            'last_name' => (string) $this->option('last'),
            'account_type' => AccountType::Admin,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->info("Admin created: {$user->email} ({$user->id})");

        if ($this->option('generate')) {
            $this->warn("Password (shown once, store it safely): {$password}");
        }

        return self::SUCCESS;
    }
}
