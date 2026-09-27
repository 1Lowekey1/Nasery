<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\SessionService;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Platform records admins curate (B17): user accounts (suspend or
 * reactivate), the service catalog organizers pick from, announcements,
 * and the platform-wide activity log.
 *
 * users has column-level UPDATE grants limited to the user's own names and
 * password (0028), and services has no RLS policy at all (0006), so these
 * writes run in the system context after the account:admin route check.
 */
class PlatformDirectory
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AdminAlerts $alerts,
        private readonly SessionService $sessions,
    ) {}

    // ---- Users --------------------------------------------------------------

    /** @param  array{search?: ?string, type?: ?string, status?: ?string, page?: int}  $f */
    public function users(array $f): LengthAwarePaginator
    {
        return $this->db->system('admin.users', function () use ($f) {
            $orgs = DB::table('organization_members as m')->join('organizations as o', 'o.id', '=', 'm.organization_id')
                ->where('m.status', 'active')->whereNull('m.deleted_at')->groupBy('m.user_id')
                ->selectRaw("m.user_id, string_agg(o.display_name, ', ' order by o.display_name) as orgs");

            $page = DB::table('users as u')->leftJoinSub($orgs, 'g', 'g.user_id', '=', 'u.id')->whereNull('u.deleted_at')
                ->when($f['search'] ?? null, function ($q, $s) {
                    $t = '%'.addcslashes(trim($s), '%_\\').'%';
                    $q->where(fn ($w) => $w->where('u.email', 'ilike', $t)->orWhereRaw("(u.first_name || ' ' || u.last_name) ilike ?", [$t]));
                })
                ->when($f['type'] ?? null, fn ($q, $t) => $q->where('u.account_type', $t))
                ->when($f['status'] ?? null, fn ($q, $s) => $q->where('u.status', $s))
                ->orderByDesc('u.created_at')
                ->paginate(25, ['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'u.account_type', 'u.status', 'u.email_verified_at', 'u.created_at', 'g.orgs'], 'page', $f['page'] ?? 1);

            $page->getCollection()->transform(fn ($u) => [
                'id' => $u->id,
                'name' => trim("{$u->first_name} {$u->last_name}"),
                'email' => $u->email,
                'phone' => $u->phone,
                'type' => $u->account_type,
                'status' => $u->status,
                'verified' => $u->email_verified_at !== null,
                'organizations' => $u->orgs,
                'joined' => CarbonImmutable::parse($u->created_at)->toIso8601String(),
            ]);

            return $page;
        });
    }

    public function setUserStatus(User $admin, string $userId, bool $suspend, string $reason): array
    {
        $user = $this->db->system('admin.user_find', fn () => User::query()->whereKey($userId)->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

        if ($user->id === $admin->id || $user->account_type->value === 'admin') {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'Admin accounts cannot be suspended here.', 409);
        }
        $from = $user->status->value;
        $to = $suspend ? 'suspended' : 'active';
        if ($from === $to || $from === 'deactivated') {
            throw new ApiException(ErrorCode::InvalidStateTransition, "This account is {$from}.", 409);
        }

        $this->db->system('admin.user_status', fn () => DB::table('users')->where('id', $user->id)->update(['status' => $to, 'updated_at' => now()]));
        if ($suspend) {
            $this->sessions->revokeAll($user);
        }
        $this->audit->record($suspend ? 'user.suspended' : 'user.reactivated', $user, old: ['status' => $from], new: ['status' => $to], reason: $reason);
        $this->alerts->accountStatus($user->id, $suspend, $reason);

        return ['id' => $user->id, 'status' => $to];
    }

    // ---- Service catalog ----------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function services(): array
    {
        return $this->db->system('admin.services', fn () => DB::table('services as s')
            ->leftJoinSub(DB::table('organization_services')->whereNull('deleted_at')->whereNotNull('service_id')->groupBy('service_id')->selectRaw('service_id, count(*) as uses'), 'x', 'x.service_id', '=', 's.id')
            ->orderByRaw('s.deleted_at is not null')->orderBy('s.category')->orderBy('s.name')
            ->get(['s.id', 's.name', 's.category', 's.deleted_at', 'x.uses'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'category' => $s->category, 'archived' => $s->deleted_at !== null, 'uses' => (int) $s->uses])
            ->all());
    }

    public function saveService(?string $id, string $name, ?string $category): array
    {
        $name = trim($name);
        $category = $category !== null && trim($category) !== '' ? trim($category) : null;

        $id = $this->db->system('admin.service_save', function () use ($id, $name, $category) {
            $clash = DB::table('services')->whereNull('deleted_at')->whereRaw('lower(name) = lower(?)', [$name])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['name' => 'The catalog already has a service with this name.']);
            }
            if ($id === null) {
                $id = (string) Str::uuid7();
                DB::table('services')->insert(['id' => $id, 'name' => $name, 'category' => $category, 'is_system_default' => true, 'created_at' => now()]);
            } elseif (DB::table('services')->where('id', $id)->update(['name' => $name, 'category' => $category]) === 0) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }

            return $id;
        });
        $this->audit->record('service_catalog.saved', ['services', $id], new: ['name' => $name, 'category' => $category]);

        return collect($this->services())->firstWhere('id', $id);
    }

    /** Archive keeps organizers' existing picks; the service just can't be chosen anymore. */
    public function archiveService(string $id, bool $archive): void
    {
        $this->db->system('admin.service_archive', function () use ($id, $archive) {
            $row = DB::table('services')->where('id', $id)->first() ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            if (! $archive && DB::table('services')->whereNull('deleted_at')->whereRaw('lower(name) = lower(?)', [$row->name])->exists()) {
                throw new ApiException(ErrorCode::Conflict, 'An active service already has this name.', 409);
            }
            DB::table('services')->where('id', $id)->update(['deleted_at' => $archive ? now() : null]);
        });
        $this->audit->record($archive ? 'service_catalog.archived' : 'service_catalog.restored', ['services', $id]);
    }

    // ---- Announcements ------------------------------------------------------

    /** @return list<array<string, mixed>> all, for admins */
    public function announcements(): array
    {
        return $this->db->system('admin.announcements', fn () => DB::table('platform_announcements')->whereNull('deleted_at')
            ->orderByDesc('starts_at')->limit(100)->get()->map(fn ($a) => $this->presentAnnouncement($a))->all());
    }

    /**
     * What a visitor sees now. Guests and admins: "all" only / everything.
     *
     * @return list<array<string, mixed>>
     */
    public function currentAnnouncements(?User $user): array
    {
        $audiences = match ($user?->account_type->value) {
            'client' => ['all', 'clients'],
            'business' => ['all', 'organizers'],
            'admin' => ['all', 'clients', 'organizers'],
            default => ['all'],
        };

        return $this->db->system('announcements.current', fn () => DB::table('platform_announcements')->whereNull('deleted_at')
            ->whereIn('audience', $audiences)->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByDesc('starts_at')->limit(3)->get()->map(fn ($a) => $this->presentAnnouncement($a))->all());
    }

    /** @param  array{title: string, body: string, audience: string, starts_at?: ?string, ends_at?: ?string}  $data */
    public function saveAnnouncement(?string $id, array $data, User $by): array
    {
        $row = [
            'title' => trim($data['title']),
            'body' => trim($data['body']),
            'audience' => $data['audience'],
            'starts_at' => isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at']) : now(),
            'ends_at' => isset($data['ends_at']) ? CarbonImmutable::parse($data['ends_at']) : null,
        ];
        if ($row['ends_at'] && $row['ends_at'] <= $row['starts_at']) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }

        $id = $this->db->system('admin.announcement_save', function () use ($id, $row, $by) {
            if ($id === null) {
                $id = (string) Str::uuid7();
                DB::table('platform_announcements')->insert($row + ['id' => $id, 'created_by' => $by->id, 'created_at' => now()]);
            } elseif (DB::table('platform_announcements')->where('id', $id)->whereNull('deleted_at')->update($row) === 0) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }

            return $id;
        });
        $this->audit->record('announcement.saved', ['platform_announcements', $id], new: ['title' => $row['title'], 'audience' => $row['audience']]);

        return $this->presentAnnouncement($this->db->system('admin.announcement_find', fn () => DB::table('platform_announcements')->where('id', $id)->first()));
    }

    public function deleteAnnouncement(string $id): void
    {
        $n = $this->db->system('admin.announcement_delete', fn () => DB::table('platform_announcements')->where('id', $id)->whereNull('deleted_at')->update(['deleted_at' => now()]));
        if ($n === 0) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        $this->audit->record('announcement.deleted', ['platform_announcements', $id]);
    }

    /** @return array<string, mixed> */
    private function presentAnnouncement(object $a): array
    {
        $starts = CarbonImmutable::parse($a->starts_at);
        $ends = $a->ends_at ? CarbonImmutable::parse($a->ends_at) : null;

        return [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'audience' => $a->audience,
            'starts_at' => $starts->toIso8601String(),
            'ends_at' => $ends?->toIso8601String(),
            'state' => $starts->isFuture() ? 'scheduled' : ($ends && $ends->isPast() ? 'ended' : 'live'),
        ];
    }

    // ---- Activity log -------------------------------------------------------

    /**
     * Platform-wide audit trail. Everyone's actions are listed; the changed
     * values are shown for platform-level rows, admin actions, and
     * organizations that granted this admin access (team rule: details need
     * consent).
     *
     * @param  array{actor?: ?string, organization_id?: ?string, search?: ?string, from?: ?string, to?: ?string, page?: int}  $f
     */
    public function activity(User $admin, array $f): LengthAwarePaginator
    {
        return $this->db->system('admin.activity', function () use ($admin, $f) {
            $granted = DB::table('admin_access_grants')->where('admin_user_id', $admin->id)->whereNull('revoked_at')
                ->where('expires_at', '>', now())->pluck('organization_id')->all();

            $page = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')->leftJoin('organizations as o', 'o.id', '=', 'a.organization_id')
                ->when($f['actor'] ?? null, fn ($q, $t) => $t === 'system' ? $q->whereNull('a.actor_user_id') : $q->where('u.account_type', $t))
                ->when($f['organization_id'] ?? null, fn ($q, $id) => $q->where('a.organization_id', $id))
                ->when($f['search'] ?? null, fn ($q, $s) => $q->where('a.action', 'ilike', '%'.addcslashes(trim($s), '%_\\').'%'))
                ->when($f['from'] ?? null, fn ($q, $d) => $q->where('a.created_at', '>=', CarbonImmutable::parse($d, 'Asia/Manila')->startOfDay()->utc()))
                ->when($f['to'] ?? null, fn ($q, $d) => $q->where('a.created_at', '<', CarbonImmutable::parse($d, 'Asia/Manila')->addDay()->startOfDay()->utc()))
                ->orderByDesc('a.created_at')
                ->paginate(30, ['a.*', 'u.first_name', 'u.last_name', 'u.account_type', 'o.display_name as organization'], 'page', $f['page'] ?? 1);

            $page->getCollection()->transform(function ($a) use ($granted) {
                $open = $a->organization_id === null || $a->account_type === 'admin' || in_array($a->organization_id, $granted, true);

                return [
                    'id' => $a->id,
                    'action' => $a->action,
                    'entity' => $a->entity_type,
                    'actor' => $a->first_name ? trim("{$a->first_name} {$a->last_name}") : 'System',
                    'actor_type' => $a->account_type ?? 'system',
                    'organization' => $a->organization_id ? ['id' => $a->organization_id, 'name' => $a->organization] : null,
                    'reason' => $open ? $a->reason : null,
                    'changes' => $open ? ['old' => json_decode((string) $a->old_values, true), 'new' => json_decode((string) $a->new_values, true)] : null,
                    'details_hidden' => ! $open,
                    'at' => CarbonImmutable::parse($a->created_at)->toIso8601String(),
                ];
            });

            return $page;
        });
    }
}
