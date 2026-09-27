<?php

namespace App\Services\Admin;

use App\Enums\AccountType;
use App\Enums\AuthCodePurpose;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\AuthCodeService;
use App\Services\Auth\SessionService;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * NaSeRy's admin team (2026-09-26): admins add admins from the app instead
 * of SQL. An invited admin gets an account WITHOUT a password and an e-mail
 * with a password-reset code; they choose their own password on the normal
 * "Forgot password" screen (which also verifies the e-mail). Nobody ever
 * sets or sees another person's password.
 *
 * Admins can disable and re-enable other admins (signed out everywhere),
 * never themselves (so at least one admin always stays active). `nasery:admin:create
 * --invite` does the same from the server for the very first admin.
 *
 * users has no app-role INSERT grant and column-limited UPDATE (0028), so
 * writes run in the system context after the account:admin route check.
 */
class AdminAccounts
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AuthCodeService $codes,
        private readonly SessionService $sessions,
        private readonly AdminAlerts $alerts,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return $this->db->system('admins.list', fn () => DB::table('users')->where('account_type', AccountType::Admin->value)->whereNull('deleted_at')
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email', 'status', 'password_hash', 'created_at'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => trim("{$u->first_name} {$u->last_name}"),
                'email' => $u->email,
                'status' => $u->status,
                // No password yet = still has to accept the invitation.
                'invited' => $u->password_hash === null,
                'joined' => CarbonImmutable::parse($u->created_at)->toIso8601String(),
            ])->values()->all());
    }

    /** Create an admin account and e-mail them a code to set their own password. */
    public function invite(?User $by, string $firstName, string $lastName, string $email): array
    {
        $email = Str::lower(trim($email));
        $taken = $this->db->system('admins.email_check', fn () => DB::table('users')->whereRaw('lower(email) = ?', [$email])->whereNull('deleted_at')->exists());
        if ($taken) {
            throw ValidationException::withMessages(['email' => 'An account already uses this e-mail. Admins need an e-mail that has no NaSeRy account yet.']);
        }

        $user = $this->db->system('admins.create', function () use ($firstName, $lastName, $email) {
            $user = new User;
            $user->forceFill([
                'email' => $email, 'first_name' => trim($firstName), 'last_name' => trim($lastName),
                'account_type' => AccountType::Admin, 'password_hash' => null,
            ])->save();

            return $user->refresh();
        });

        $inviter = $by ? trim("{$by->first_name} {$by->last_name}") : 'The NaSeRy team';
        $this->codes->issue($user, AuthCodePurpose::PasswordReset, $inviter);
        $this->audit->record('admin.invited', $user, new: ['email' => $email, 'name' => trim("{$firstName} {$lastName}")], actorUserId: $by?->id);

        return collect($this->list())->firstWhere('id', $user->id);
    }

    /** Send a fresh invitation code to an admin who hasn't set a password yet. */
    public function resend(User $by, string $userId): void
    {
        $user = $this->admin($userId);
        if ($user->password_hash !== null) {
            throw new ApiException(ErrorCode::Conflict, 'This admin already set a password. They can use "Forgot password" if needed.', 409);
        }
        $this->codes->issue($user, AuthCodePurpose::PasswordReset, trim("{$by->first_name} {$by->last_name}"));
        $this->audit->record('admin.invite_resent', $user);
    }

    public function setActive(User $by, string $userId, bool $active, string $reason): array
    {
        $user = $this->admin($userId);
        if ($user->id === $by->id) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, "You can't disable your own admin account.", 409);
        }
        $from = $user->status->value;
        $to = $active ? 'active' : 'suspended';
        if ($from === $to || $from === 'deactivated') {
            throw new ApiException(ErrorCode::InvalidStateTransition, "This admin account is {$from}.", 409);
        }
        $this->db->system('admins.status', fn () => DB::table('users')->where('id', $user->id)->update(['status' => $to, 'updated_at' => now()]));
        if (! $active) {
            $this->sessions->revokeAll($user);
        }
        $this->audit->record($active ? 'admin.enabled' : 'admin.disabled', $user, old: ['status' => $from], new: ['status' => $to], reason: $reason);
        $this->alerts->accountStatus($user->id, ! $active, $reason);

        return collect($this->list())->firstWhere('id', $user->id);
    }

    private function admin(string $userId): User
    {
        $user = $this->db->system('admins.find', fn () => User::query()->whereKey($userId)->where('account_type', AccountType::Admin->value)->first());

        return $user ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
    }
}
