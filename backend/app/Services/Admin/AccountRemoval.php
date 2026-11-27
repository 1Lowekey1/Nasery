<?php

namespace App\Services\Admin;

use App\Enums\AccountType;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Settings → Delete an account (permanent). Only the admin e-mails in
 * NASERY_HARD_DELETE_ADMINS may use it. Removes the account, the organizations it
 * is the only owner of, and everything that hangs off them (AccountPurge, as in
 * demo resets), after a database backup. Never your own account; an admin must
 * be disabled first. One audit row records who removed which account and why.
 */
class AccountRemoval
{
    public function __construct(
        private readonly AccountPurge $purge,
        private readonly AuditLogger $audit,
        private readonly DatabaseContext $db,
    ) {}

    public static function allowed(User $actor): bool
    {
        return in_array(strtolower((string) $actor->email), (array) config('nasery.hard_delete_admins'), true);
    }

    /**
     * What removing this account takes with it.
     *
     * @return array<string, mixed>
     */
    public function preview(User $actor, string $email): array
    {
        $this->assertAllowed($actor);
        $user = $this->target($email);
        [$users, $orgs] = $this->scope($user);

        return $this->db->system('admin.account_removal.preview', function () use ($actor, $user, $users, $orgs) {
            $inquiries = DB::table('inquiries')->whereIn('client_user_id', $users)->pluck('id');
            $events = DB::table('events')->whereIn('organization_id', $orgs)->orWhereIn('inquiry_id', $inquiries)->whereNull('deleted_at')->pluck('id');
            $bookedFromRequests = DB::table('events')->whereIn('inquiry_id', $inquiries)->whereNotIn('organization_id', $orgs)->whereNull('deleted_at')->count();

            return [
                'account' => [
                    'name' => trim($user->first_name.' '.$user->last_name),
                    'email' => $user->email,
                    'type' => $user->account_type->value,
                    'status' => $user->status->value,
                ],
                'blocked' => $this->blockedReason($actor, $user),
                'removes' => [
                    'organizations' => DB::table('organizations')->whereIn('id', $orgs)->orderBy('display_name')->pluck('display_name')->all(),
                    'team_memberships' => DB::table('organization_members')->where('user_id', $user->id)->whereNotIn('organization_id', $orgs)->whereNull('deleted_at')->count(),
                    'events' => $events->count(),
                    'events_booked_from_their_requests' => $bookedFromRequests,
                    'tickets_on_those_events' => DB::table('tickets')->whereIn('event_id', $events)->count(),
                    'tickets_they_bought' => DB::table('tickets as t')->join('registrations as r', 'r.id', '=', 't.registration_id')->whereIn('r.purchaser_user_id', $users)->count(),
                    'requests' => $inquiries->count(),
                    'offers' => DB::table('quotations')->whereIn('inquiry_id', $inquiries)->orWhereIn('organization_id', $orgs)->count(),
                    'reviews' => DB::table('reviews')->whereIn('client_user_id', $users)->orWhereIn('event_id', $events)->count(),
                ],
            ];
        });
    }

    /**
     * @return array<string, int> rows removed per table
     */
    public function remove(User $actor, string $email, string $confirmation, string $reason): array
    {
        $this->assertAllowed($actor);
        $user = $this->target($email);

        if ($blocked = $this->blockedReason($actor, $user)) {
            throw new ApiException(ErrorCode::Conflict, $blocked, 409);
        }
        if (strtolower(trim($confirmation)) !== strtolower($user->email)) {
            throw ValidationException::withMessages(['confirm_email' => 'Type the account\'s e-mail exactly to confirm.']);
        }

        [$users, $orgs] = $this->scope($user);

        if (! $this->backup()) {
            throw new ApiException(ErrorCode::ServerError, 'The backup failed, so nothing was deleted. Try again later.', 500);
        }

        $counts = $this->purge->purge($users, $orgs);

        // After the purge (it removes the account's own audit rows): who removed whom, and why.
        $this->audit->record('account.deleted', ['users', $user->id],
            old: ['email' => $user->email, 'name' => trim($user->first_name.' '.$user->last_name), 'account_type' => $user->account_type->value],
            reason: $reason, actorUserId: $actor->id);

        try {
            Artisan::call('nasery:media:prune');
        } catch (\Throwable) {
            // Pictures left behind are removed by the daily prune.
        }

        return $counts;
    }

    /** A database backup first (nasery:db:backup); false when it failed. */
    protected function backup(): bool
    {
        $ok = Artisan::call('nasery:db:backup', ['--reason' => 'pre-account-delete']) === 0;
        if (! $ok) {
            \Illuminate\Support\Facades\Log::warning('account_removal.backup_failed', ['output' => mb_substr(Artisan::output(), 0, 2000)]);
        }

        return $ok;
    }

    private function assertAllowed(User $actor): void
    {
        if (! self::allowed($actor)) {
            throw new ApiException(ErrorCode::Forbidden, 'Only the NaSeRy owner account can delete accounts permanently.', 403);
        }
    }

    private function target(string $email): User
    {
        return $this->db->system('admin.account_removal.find', fn () => User::query()
            ->whereRaw('lower(email) = ?', [strtolower(trim($email))])->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'There is no account with that e-mail.', 404);
    }

    private function blockedReason(User $actor, User $user): ?string
    {
        return match (true) {
            $user->id === $actor->id => 'You can\'t delete your own account.',
            $user->account_type === AccountType::Admin && $user->status === UserStatus::Active => 'This is an active admin. Disable them on Admin Team first.',
            default => null,
        };
    }

    /**
     * The account, and the organizations it is the only owner of (others keep theirs).
     *
     * @return array{0: Collection<int, string>, 1: Collection<int, string>}
     */
    private function scope(User $user): array
    {
        $orgs = $this->db->system('admin.account_removal.scope', fn () => DB::table('organization_members as m')
            ->where('m.user_id', $user->id)->where('m.is_owner', true)->whereNull('m.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('organization_members as o')->whereColumn('o.organization_id', 'm.organization_id')
                ->where('o.user_id', '!=', $user->id)->where('o.is_owner', true)->whereNull('o.deleted_at'))
            ->pluck('m.organization_id')->unique()->values());

        return [collect([$user->id]), $orgs];
    }
}
