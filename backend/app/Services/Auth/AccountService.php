<?php

namespace App\Services\Auth;

use App\Enums\AccountType;
use App\Enums\AuthCodePurpose;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Registration, login/logout, profile, email verification and passwords.
 *
 * RLS note: a guest cannot see any users row, so lookups by email (login,
 * registration uniqueness, forgot/reset password) run in the system context.
 * Anything an authenticated user does to their own row (profile, password
 * change, verifying their email) goes through RLS as usual.
 */
class AccountService
{
    /** Pre-computed so unknown-email logins cost the same as real ones. */
    private const DUMMY_HASH = '$2y$12$m./8tHD2a1kUgLAInjD.tO/CUHPNVJEXfFCf4/h.dq.Pu4ECu3zni';

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AuthCodeService $codes,
        private readonly SessionService $sessions,
    ) {}

    /**
     * @param  array{first_name: string, middle_name?: ?string, last_name: string, email: string, phone?: ?string, password: string, account_type: string}  $data
     */
    public function register(array $data, Request $request): User
    {
        $email = self::normalizeEmail($data['email']);

        $user = $this->db->system('auth.register', function () use ($data, $email) {
            if ($this->findByEmail($email) !== null) {
                throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
            }

            return User::query()->create([
                'email' => $email,
                'password_hash' => $data['password'],
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'account_type' => AccountType::from($data['account_type']),
            ]);
        });

        $this->audit->record('user.registered', $user, new: [
            'account_type' => $user->account_type->value,
        ], actorUserId: $user->id);

        $this->codes->issue($user, AuthCodePurpose::EmailVerification);
        $this->startSession($user, $request);

        // Re-read DB defaults (status, timestamps). This request's RLS
        // identity is still the guest it started as, so read via system.
        return $this->db->system('auth.register', fn () => $user->refresh());
    }

    public function login(string $email, string $password, Request $request): User
    {
        $user = $this->db->system('auth.login', fn () => $this->findByEmail(self::normalizeEmail($email)));

        $valid = Hash::check($password, $user?->password_hash ?? self::DUMMY_HASH) && $user?->password_hash !== null;

        if (! $valid || $user === null) {
            Log::channel('security')->notice('auth.login_failed', [
                'email_sha256' => hash('sha256', self::normalizeEmail($email)),
                'ip' => $request->ip(),
            ]);

            throw new ApiException(ErrorCode::InvalidCredentials, 'The email or password is incorrect.', 401);
        }

        $this->assertActive($user);

        if (Hash::needsRehash($user->password_hash)) {
            $this->db->system('auth.rehash', fn () => $user->forceFill(['password_hash' => $password])->save());
        }

        $this->startSession($user, $request);
        Log::channel('security')->info('auth.login', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return $user;
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * @param  array{first_name?: string, middle_name?: ?string, last_name?: string, phone?: ?string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $old = $user->only(array_keys($data));
        $user->fill($data)->save(); // own row: allowed by RLS users_self_update

        $this->audit->record('user.profile_updated', $user, old: $old, new: $user->only(array_keys($data)));

        return $user;
    }

    public function sendVerificationCode(User $user): void
    {
        if ($user->email_verified_at !== null) {
            throw new ApiException(ErrorCode::EmailAlreadyVerified, 'Your email address is already verified.', 409);
        }

        $this->codes->issue($user, AuthCodePurpose::EmailVerification);
    }

    public function verifyEmail(User $user, string $code): User
    {
        if ($user->email_verified_at !== null) {
            throw new ApiException(ErrorCode::EmailAlreadyVerified, 'Your email address is already verified.', 409);
        }

        $this->codes->consume($user, AuthCodePurpose::EmailVerification, $code);

        // email_verified_at is not user-writable at the DB level (0028): the
        // code check above is what authorizes this privileged write.
        $this->db->system('auth.verify_email', fn () => $user->forceFill(['email_verified_at' => now()])->save());
        $this->audit->record('user.email_verified', $user);

        return $user;
    }

    /**
     * Always succeeds from the caller's point of view (no account enumeration).
     */
    public function requestPasswordReset(string $email): void
    {
        $user = $this->db->system('auth.password_reset_request', fn () => $this->findByEmail(self::normalizeEmail($email)));

        if ($user === null || $user->status !== UserStatus::Active) {
            return;
        }

        try {
            $this->codes->issue($user, AuthCodePurpose::PasswordReset);
        } catch (ApiException $e) {
            // Cooldown hit: stay silent, the earlier code is still valid.
            if ($e->errorCode !== ErrorCode::ResendCooldown) {
                throw $e;
            }
        }
    }

    public function resetPassword(string $email, string $code, string $password): void
    {
        $user = $this->db->system('auth.password_reset', fn () => $this->findByEmail(self::normalizeEmail($email)));

        if ($user === null || $user->status !== UserStatus::Active) {
            // Same answer as a wrong code: don't reveal whether the account exists.
            throw new ApiException(ErrorCode::InvalidCode, 'This code is invalid. Request a new one.', 422);
        }

        $this->codes->consume($user, AuthCodePurpose::PasswordReset, $code);

        $this->db->system('auth.password_reset', fn () => $user->forceFill([
            'password_hash' => $password,
            // Receiving the code proves the user controls the mailbox.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save());

        $this->sessions->revokeAll($user);
        $this->audit->record('user.password_reset', $user, actorUserId: $user->id);
        Log::channel('security')->info('auth.password_reset', ['user_id' => $user->id]);
    }

    public function changePassword(User $user, string $current, string $new, Request $request): void
    {
        if ($user->password_hash !== null && ! Hash::check($current, $user->password_hash)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $user->forceFill(['password_hash' => $new])->save(); // own row via RLS

        // Keep this device signed in, sign every other one out.
        $request->session()->regenerate();
        $this->sessions->revokeOthers($user, $request->session()->getId());
        Auth::guard('web')->setUser($user);

        $this->audit->record('user.password_changed', $user);
        Log::channel('security')->info('auth.password_changed', ['user_id' => $user->id]);
    }

    public function assertActive(User $user): void
    {
        if ($user->status === UserStatus::Suspended) {
            throw new ApiException(ErrorCode::AccountSuspended, 'This account is suspended. Contact NaSeRy support.', 403);
        }

        if ($user->status === UserStatus::Deactivated || $user->deleted_at !== null) {
            throw new ApiException(ErrorCode::AccountDeactivated, 'This account has been deactivated.', 403);
        }
    }

    /**
     * Log the user into the cookie session (fixation-safe).
     */
    public function startSession(User $user, Request $request): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
    }

    /**
     * Must be called inside DatabaseContext::system() for guests.
     */
    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
