<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin access to an organization's workspace needs the organization's
 * consent (team decision 2026-09-25, replacing 0018's self-service grant):
 *
 *  - an admin requests access with a reason; an owner approves or refuses
 *  - or an owner invites NaSeRy support (debugging, help); an admin accepts
 *
 * Only then is an admin_access_grants row written (granted_by = the owner
 * who agreed), which is what RLS and GrantedView honour. Owners can end a
 * grant anytime; admins can end their own early. Grants are read-only in
 * the app: no admin write path checks them. Rows are written in the system
 * context after these checks (0040 gives the app role SELECT only).
 */
class AccessRequests
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AdminAlerts $alerts,
        private readonly OrganizationAccess $access,
        private readonly PlatformSettings $settings,
    ) {}

    // ---- Admin side ---------------------------------------------------------

    /** @return array<string, mixed> */
    public function forAdmin(User $admin): array
    {
        $this->expireStale();

        return $this->db->system('access.admin_list', fn () => [
            'invites' => $this->query()->where('r.initiated_by', 'organizer')->where('r.status', 'pending')->orderBy('r.created_at')->get()->map(fn ($r) => $this->present($r)),
            'requests' => $this->query()->where('r.initiated_by', 'admin')->where('r.admin_user_id', $admin->id)->orderByDesc('r.created_at')->limit(50)->get()->map(fn ($r) => $this->present($r)),
            'grants' => $this->grants()->where('g.admin_user_id', $admin->id)->get()->map(fn ($g) => $this->presentGrant($g)),
            'default_hours' => $this->defaultHours(),
        ]);
    }

    public function request(User $admin, Organization $org, string $reason, ?int $hours): array
    {
        $this->expireStale();
        if ($this->activeGrant($admin, $org->id)) {
            throw new ApiException(ErrorCode::Conflict, 'You already have access to this workspace.', 409);
        }
        $hours ??= $this->defaultHours();

        $id = $this->db->system('access.request', function () use ($admin, $org, $reason, $hours) {
            if (DB::table('admin_access_requests')->where('organization_id', $org->id)->where('admin_user_id', $admin->id)
                ->where('initiated_by', 'admin')->where('status', 'pending')->exists()) {
                throw new ApiException(ErrorCode::Conflict, 'You already asked this organizer; wait for their answer.', 409);
            }
            $id = (string) Str::uuid7();
            DB::table('admin_access_requests')->insert(['id' => $id, 'organization_id' => $org->id, 'initiated_by' => 'admin',
                'admin_user_id' => $admin->id, 'requested_by' => $admin->id, 'reason' => $reason, 'duration_hours' => $hours,
                'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        $this->audit->record('admin_access.requested', ['admin_access_requests', $id], new: ['hours' => $hours], organizationId: $org->id, reason: $reason);
        $this->alerts->accessRequested($org->id, $reason, $hours);

        return $this->find($id);
    }

    public function cancel(User $admin, string $requestId): void
    {
        $r = $this->pending($requestId);
        if ($r->initiated_by !== 'admin' || $r->admin_user_id !== $admin->id) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        $this->close($r, 'cancelled', $admin, null);
        $this->audit->record('admin_access.cancelled', ['admin_access_requests', $r->id], organizationId: $r->organization_id);
    }

    /** An admin takes an organizer's support invite: consent was given when they sent it. */
    public function acceptInvite(User $admin, string $requestId): array
    {
        $r = $this->pending($requestId);
        if ($r->initiated_by !== 'organizer') {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        if ($this->activeGrant($admin, $r->organization_id)) {
            throw new ApiException(ErrorCode::Conflict, 'You already have access to this workspace.', 409);
        }

        $grant = $this->approve($r, $admin->id, grantedBy: $r->requested_by, decidedBy: $admin);
        $this->audit->record('admin_access.invite_accepted', ['admin_access_grants', $grant->id], new: ['expires_at' => $grant->expires_at], organizationId: $r->organization_id, reason: $r->reason);
        $this->alerts->accessStarted($r->organization_id, $this->name($admin->id), $this->when($grant->expires_at));

        return $this->find($r->id);
    }

    // ---- Organization side --------------------------------------------------

    /** @return array<string, mixed> */
    public function forOrganization(Organization $org): array
    {
        $this->expireStale();

        return $this->db->system('access.org_list', fn () => [
            'pending' => $this->query()->where('r.organization_id', $org->id)->where('r.status', 'pending')->orderBy('r.created_at')->get()->map(fn ($r) => $this->present($r)),
            'grants' => $this->grants()->where('g.organization_id', $org->id)->get()->map(fn ($g) => $this->presentGrant($g)),
            'history' => $this->query()->where('r.organization_id', $org->id)->where('r.status', '<>', 'pending')->orderByDesc('r.updated_at')->limit(20)->get()->map(fn ($r) => $this->present($r)),
            'default_hours' => $this->defaultHours(),
        ]);
    }

    public function decide(User $owner, Organization $org, string $requestId, bool $approve, ?string $note): array
    {
        $this->assertOwner($owner, $org);
        $r = $this->pending($requestId);
        if ($r->organization_id !== $org->id || $r->initiated_by !== 'admin') {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        if ($approve) {
            $grant = $this->approve($r, $r->admin_user_id, grantedBy: $owner->id, decidedBy: $owner, note: $note);
            $this->audit->record('admin_access.approved', ['admin_access_grants', $grant->id], new: ['admin_user_id' => $r->admin_user_id, 'expires_at' => $grant->expires_at], organizationId: $org->id, reason: $note);
            $this->alerts->accessAnswered($r->admin_user_id, $org->display_name, true, $note, $this->when($grant->expires_at));
        } else {
            $this->close($r, 'declined', $owner, $note);
            $this->audit->record('admin_access.declined', ['admin_access_requests', $r->id], organizationId: $org->id, reason: $note);
            $this->alerts->accessAnswered($r->admin_user_id, $org->display_name, false, $note, null);
        }

        return $this->find($r->id);
    }

    public function invite(User $owner, Organization $org, string $reason, ?int $hours): array
    {
        $this->assertOwner($owner, $org);
        $this->expireStale();
        $hours ??= $this->defaultHours();

        $id = $this->db->system('access.invite', function () use ($owner, $org, $reason, $hours) {
            if (DB::table('admin_access_requests')->where('organization_id', $org->id)->where('initiated_by', 'organizer')->where('status', 'pending')->exists()) {
                throw new ApiException(ErrorCode::Conflict, 'You already invited NaSeRy support; an administrator will pick it up.', 409);
            }
            $id = (string) Str::uuid7();
            DB::table('admin_access_requests')->insert(['id' => $id, 'organization_id' => $org->id, 'initiated_by' => 'organizer',
                'requested_by' => $owner->id, 'reason' => $reason, 'duration_hours' => $hours, 'status' => 'pending',
                'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        });

        $this->audit->record('admin_access.invited', ['admin_access_requests', $id], new: ['hours' => $hours], organizationId: $org->id, reason: $reason);
        $this->alerts->supportInvited($org->id, $org->display_name, $reason);

        return $this->find($id);
    }

    public function cancelInvite(User $owner, Organization $org, string $requestId): void
    {
        $this->assertOwner($owner, $org);
        $r = $this->pending($requestId);
        if ($r->organization_id !== $org->id || $r->initiated_by !== 'organizer') {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        $this->close($r, 'cancelled', $owner, null);
        $this->audit->record('admin_access.invite_cancelled', ['admin_access_requests', $r->id], organizationId: $org->id);
    }

    /** End a live grant: an owner of the organization, or the admin holding it. */
    public function revoke(User $by, string $grantId, ?Organization $asOwnerOf = null): void
    {
        $grant = $this->db->system('access.revoke_find', fn () => DB::table('admin_access_grants')->where('id', $grantId)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->first());

        $allowed = $grant !== null && ($asOwnerOf
            ? $grant->organization_id === $asOwnerOf->id && $this->access->isOwner($by, $asOwnerOf)
            : $grant->admin_user_id === $by->id);
        if (! $allowed) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        $this->db->system('access.revoke', fn () => DB::table('admin_access_grants')->where('id', $grantId)->update(['revoked_at' => now()]));
        $this->audit->record('admin_access.revoked', ['admin_access_grants', $grantId], organizationId: $grant->organization_id,
            reason: $asOwnerOf ? 'ended by the organization' : 'ended by the admin');
        $this->alerts->accessEnded($grant->organization_id, $asOwnerOf ? $grant->admin_user_id : null,
            $asOwnerOf ? 'the organization' : $this->name($by->id));
    }

    // ---- Shared -------------------------------------------------------------

    /** The admin's live grant on this organization, if any. */
    public function activeGrant(User $admin, string $organizationId): ?object
    {
        return $this->db->system('access.active', fn () => $this->grants()->where('g.admin_user_id', $admin->id)->where('g.organization_id', $organizationId)->first());
    }

    public function defaultHours(): int
    {
        return max(1, min(72, (int) $this->settings->get('admin_grant_default_expiry_hours', 24)));
    }

    /** Requests nobody answered within 7 days lapse. */
    private function expireStale(): void
    {
        $this->db->system('access.expire', fn () => DB::table('admin_access_requests')->where('status', 'pending')
            ->where('expires_at', '<=', now())->update(['status' => 'expired', 'updated_at' => now()]));
    }

    private function approve(object $r, string $adminUserId, string $grantedBy, User $decidedBy, ?string $note = null): object
    {
        return $this->db->system('access.approve', function () use ($r, $adminUserId, $grantedBy, $decidedBy, $note) {
            $grantId = (string) Str::uuid7();
            $expires = now()->addHours($r->duration_hours);
            DB::table('admin_access_grants')->insert(['id' => $grantId, 'admin_user_id' => $adminUserId, 'organization_id' => $r->organization_id,
                'reason' => $r->reason, 'granted_by' => $grantedBy, 'starts_at' => now(), 'expires_at' => $expires, 'created_at' => now()]);
            DB::table('admin_access_requests')->where('id', $r->id)->update(['status' => 'approved', 'admin_user_id' => $adminUserId,
                'decided_by' => $decidedBy->id, 'decided_at' => now(), 'decision_note' => $note, 'grant_id' => $grantId, 'updated_at' => now()]);

            return (object) ['id' => $grantId, 'expires_at' => $expires->toIso8601String()];
        });
    }

    private function close(object $r, string $status, User $by, ?string $note): void
    {
        $this->db->system('access.close', fn () => DB::table('admin_access_requests')->where('id', $r->id)
            ->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]));
    }

    private function pending(string $id): object
    {
        $this->expireStale();
        $r = $this->db->system('access.find', fn () => DB::table('admin_access_requests')->where('id', $id)->lockForUpdate()->first());
        if ($r === null) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        if ($r->status !== 'pending') {
            throw new ApiException(ErrorCode::InvalidStateTransition, "This request is already {$r->status}.", 409);
        }

        return $r;
    }

    private function assertOwner(User $user, Organization $org): void
    {
        if (! $this->access->isOwner($user, $org)) {
            throw new ApiException(ErrorCode::Forbidden, 'Only an owner of the organization can manage NaSeRy access.', 403);
        }
    }

    private function query()
    {
        return DB::table('admin_access_requests as r')
            ->join('organizations as o', 'o.id', '=', 'r.organization_id')
            ->join('users as rq', 'rq.id', '=', 'r.requested_by')
            ->leftJoin('users as a', 'a.id', '=', 'r.admin_user_id')
            ->leftJoin('users as d', 'd.id', '=', 'r.decided_by')
            ->leftJoin('admin_access_grants as g', 'g.id', '=', 'r.grant_id')
            ->select(['r.*', 'o.display_name as organization', 'rq.first_name as rq_first', 'rq.last_name as rq_last',
                'a.first_name as a_first', 'a.last_name as a_last', 'd.first_name as d_first', 'd.last_name as d_last',
                'g.expires_at as grant_expires_at', 'g.revoked_at as grant_revoked_at']);
    }

    private function grants()
    {
        return DB::table('admin_access_grants as g')
            ->join('organizations as o', 'o.id', '=', 'g.organization_id')
            ->join('users as a', 'a.id', '=', 'g.admin_user_id')
            ->leftJoin('users as gb', 'gb.id', '=', 'g.granted_by')
            ->whereNull('g.revoked_at')->where('g.expires_at', '>', now())->where('g.starts_at', '<=', now())
            ->orderBy('g.expires_at')
            ->select(['g.*', 'o.display_name as organization', 'a.first_name as a_first', 'a.last_name as a_last', 'gb.first_name as gb_first', 'gb.last_name as gb_last']);
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return $this->db->system('access.find_one', fn () => $this->present($this->query()->where('r.id', $id)->first()));
    }

    /** @return array<string, mixed> */
    private function present(object $r): array
    {
        $name = fn (?string $f, ?string $l) => $f ? trim("{$f} {$l}") : null;

        return [
            'id' => $r->id,
            'organization' => ['id' => $r->organization_id, 'name' => $r->organization],
            'initiated_by' => $r->initiated_by,
            'status' => $r->status,
            'reason' => $r->reason,
            'duration_hours' => (int) $r->duration_hours,
            'requested_by' => $name($r->rq_first, $r->rq_last),
            'admin' => $name($r->a_first, $r->a_last),
            'decided_by' => $name($r->d_first, $r->d_last),
            'decided_at' => $r->decided_at ? CarbonImmutable::parse($r->decided_at)->toIso8601String() : null,
            'decision_note' => $r->decision_note,
            'expires_at' => CarbonImmutable::parse($r->expires_at)->toIso8601String(),
            'grant' => $r->grant_id ? [
                'id' => $r->grant_id,
                'expires_at' => CarbonImmutable::parse($r->grant_expires_at)->toIso8601String(),
                'active' => $r->grant_revoked_at === null && CarbonImmutable::parse($r->grant_expires_at)->isFuture(),
            ] : null,
            'created_at' => CarbonImmutable::parse($r->created_at)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentGrant(object $g): array
    {
        return [
            'id' => $g->id,
            'organization' => ['id' => $g->organization_id, 'name' => $g->organization],
            'admin' => trim("{$g->a_first} {$g->a_last}"),
            'granted_by' => $g->gb_first ? trim("{$g->gb_first} {$g->gb_last}") : null,
            'reason' => $g->reason,
            'starts_at' => CarbonImmutable::parse($g->starts_at)->toIso8601String(),
            'expires_at' => CarbonImmutable::parse($g->expires_at)->toIso8601String(),
        ];
    }

    private function name(string $userId): string
    {
        $u = $this->db->system('access.name', fn () => DB::table('users')->where('id', $userId)->first(['first_name', 'last_name']));

        return $u ? trim("{$u->first_name} {$u->last_name}") : 'An administrator';
    }

    private function when(string $iso): string
    {
        return CarbonImmutable::parse($iso)->setTimezone('Asia/Manila')->format('M j, g:i A');
    }
}
