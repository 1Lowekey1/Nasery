<?php

namespace Tests\Concerns;

use App\Enums\AuthCodePurpose;
use App\Mail\AuthCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

trait InteractsWithAuth
{
    /** First-party SPA request headers (Sanctum stateful). */
    protected const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    protected function spaJson(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json($method, $uri, $data, self::SPA + $headers);
    }

    protected function userWithPassword(string $password = 'Secret123', array $attributes = []): User
    {
        return User::factory()->create(['password_hash' => $password] + $attributes);
    }

    /** The code from the most recent queued auth email for $purpose. */
    protected function lastCode(AuthCodePurpose $purpose): string
    {
        $mail = Mail::sent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->purpose === $purpose)->last();
        $this->assertNotNull($mail, "No {$purpose->value} code was emailed.");

        return $mail->code;
    }

    protected function wrongCode(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Pretend $user is signed in on another device (a stored session row). */
    protected function fakeOtherSession(User $user, string $id): void
    {
        DB::connection('system')->table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '198.51.100.7',
            'user_agent' => 'Other device', 'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->timestamp,
        ]);
    }

    protected function sessionExists(string $id): bool
    {
        return DB::connection('system')->table('sessions')->where('id', $id)->exists();
    }
}
