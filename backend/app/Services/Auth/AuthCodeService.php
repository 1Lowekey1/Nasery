<?php

namespace App\Services\Auth;

use App\Enums\AuthCodePurpose;
use App\Exceptions\ApiException;
use App\Mail\AuthCodeMail;
use App\Models\Auth\AuthCode;
use App\Models\User;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Issues and checks 6-digit one-time codes (email verification, password
 * reset). Codes are stored as HMAC-SHA256 (keyed with APP_KEY), expire,
 * allow a limited number of attempts, and have a resend cooldown.
 */
class AuthCodeService
{
    /**
     * Create (or replace) the user's code for $purpose and email it.
     * Throws RESEND_COOLDOWN when asked again too soon.
     */
    public function issue(User $user, AuthCodePurpose $purpose, ?string $invitedBy = null): void
    {
        $settings = config('nasery.auth.codes');

        $existing = AuthCode::query()->where('user_id', $user->id)->where('purpose', $purpose)->first();

        if ($existing !== null && $existing->consumed_at === null
            && $existing->last_sent_at->gt(now()->subSeconds($settings['resend_cooldown_seconds']))) {
            $wait = (int) ceil(now()->diffInSeconds($existing->last_sent_at->copy()->addSeconds($settings['resend_cooldown_seconds'])));

            throw new ApiException(ErrorCode::ResendCooldown, 'Please wait before requesting another code.', 429, [
                'retry_after_seconds' => max(1, $wait),
            ]);
        }

        $code = str_pad((string) random_int(0, 10 ** $settings['length'] - 1), $settings['length'], '0', STR_PAD_LEFT);

        AuthCode::query()->updateOrCreate(
            ['user_id' => $user->id, 'purpose' => $purpose],
            [
                'code_hash' => $this->hash($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($settings['ttl_minutes']),
                'last_sent_at' => now(),
                'consumed_at' => null,
            ],
        );

        // Sent as soon as the request commits (never for a rolled-back sign-up), directly
        // rather than through the queue: codes expire in minutes. A mail failure never
        // undoes the request; the user can press "Resend code".
        $mail = new AuthCodeMail($code, $purpose, $user->first_name, $invitedBy);
        DB::afterCommit(function () use ($user, $mail, $purpose) {
            try {
                Mail::to($user->email)->send($mail);
            } catch (Throwable $e) {
                Log::warning('auth_code.mail_failed', ['user_id' => $user->id, 'purpose' => $purpose->value, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Validate and consume a code. Wrong codes count towards the attempt
     * limit even though the business transaction is rolled back, because the
     * code row lives on the separate `system` connection.
     */
    public function consume(User $user, AuthCodePurpose $purpose, string $code): void
    {
        $settings = config('nasery.auth.codes');

        // The transaction only records the outcome; the error is thrown after
        // it commits, otherwise the failed-attempt increment would be rolled
        // back with it and brute force would be unlimited.
        $failure = DB::connection('system')->transaction(function () use ($user, $purpose, $code, $settings): ?ApiException {
            $record = AuthCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if ($record === null || $record->consumed_at !== null) {
                return new ApiException(ErrorCode::InvalidCode, 'This code is invalid. Request a new one.', 422);
            }

            if ($record->attempts >= $settings['max_attempts']) {
                return new ApiException(ErrorCode::TooManyAttempts, 'Too many incorrect attempts. Request a new code.', 429);
            }

            if ($record->expires_at->isPast()) {
                return new ApiException(ErrorCode::CodeExpired, 'This code has expired. Request a new one.', 422);
            }

            if (! hash_equals($record->code_hash, $this->hash($code))) {
                $record->increment('attempts');

                return new ApiException(ErrorCode::InvalidCode, 'The code is incorrect.', 422, [
                    'attempts_remaining' => max(0, $settings['max_attempts'] - $record->attempts),
                ]);
            }

            $record->update(['consumed_at' => now()]);

            return null;
        });

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
