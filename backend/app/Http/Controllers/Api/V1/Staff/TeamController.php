<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationRole;
use App\Services\Staff\StaffManager;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The organization's team and roles (B8). Any active member may view the
 * team; inviting and changing members or roles needs staff.manage (owners
 * have it) and an operational organization.
 */
class TeamController extends Controller
{
    public function __construct(
        private readonly StaffManager $staff,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeTeam($request, $organization);

        return ApiResponse::success([
            'members' => $this->staff->members($organization),
            'roles' => $this->staff->roles($organization),
            'presets' => collect(StaffManager::PRESETS)->map(fn ($p, $key) => ['key' => $key, ...$p])->values(),
            'permissions' => DB::table('permissions')->orderBy('category')->orderBy('key')
                ->get(['key', 'category', 'description']),
            'can_manage' => $this->access->can($request->user(), $organization, 'staff.manage'),
        ]);
    }

    public function invite(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            // Needed only when no NaSeRy account uses the e-mail (the invitation creates it).
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'role_id' => ['required_without:preset', 'nullable', 'uuid'],
            'preset' => ['required_without:role_id', 'nullable', Rule::in(array_keys(StaffManager::PRESETS))],
        ]);

        $role = $this->role($organization, $request, $data);
        $this->staff->invite($organization, $request->user(), $data['email'], $role, $data['first_name'] ?? null, $data['last_name'] ?? null);

        return ApiResponse::created($this->staff->members($organization), 'Invitation sent. They join with the link in their e-mail.');
    }

    public function update(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $data = $request->validate([
            'role_id' => ['required_without:preset', 'nullable', 'uuid'],
            'preset' => ['required_without:role_id', 'nullable', Rule::in(array_keys(StaffManager::PRESETS))],
        ]);

        $this->staff->changeRole($organization, $request->user(), $member, $this->role($organization, $request, $data));

        return ApiResponse::success($this->staff->members($organization), 'Role changed.');
    }

    public function destroy(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $data = $request->validate(['reassign_to' => ['nullable', 'uuid']]);
        $to = filled($data['reassign_to'] ?? null)
            ? OrganizationMember::query()->where('organization_id', $organization->id)->find($data['reassign_to'])
                ?? throw ValidationException::withMessages(['reassign_to' => 'Choose an active member of your team.'])
            : null;
        $result = $this->staff->remove($organization, $request->user(), $member, $to);

        $message = match (true) {
            $result['reassigned'] > 0 => "Team member removed. {$result['reassigned']} open ".($result['reassigned'] === 1 ? 'task was' : 'tasks were').' handed over.',
            $result['unassigned'] > 0 => "Team member removed. {$result['unassigned']} open ".($result['unassigned'] === 1 ? 'task needs' : 'tasks need').' a new assignee.',
            default => 'Team member removed.',
        };

        return ApiResponse::success($this->staff->members($organization), $message);
    }

    /** Their open tasks, shown before removing someone. */
    public function openTasks(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);

        return ApiResponse::success($this->staff->openTasks($organization, $member));
    }

    public function resend(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $this->staff->resendInvite($organization, $request->user(), $member);

        return ApiResponse::success($this->staff->members($organization), 'Invitation sent again.');
    }

    public function reactivate(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $this->staff->reactivate($organization, $request->user(), $member);

        return ApiResponse::success($this->staff->members($organization), 'Team member reactivated.');
    }

    public function storeRole(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $data = $this->roleData($request, $organization, creating: true);
        $this->staff->createRole($organization, $request->user(), $data['name'], $data['description'] ?? null, $data['permissions']);

        return ApiResponse::created($this->staff->roles($organization), 'Role created.');
    }

    public function updateRole(Request $request, Organization $organization, OrganizationRole $role): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $this->ownRole($organization, $role);
        $this->staff->updateRole($organization, $request->user(), $role, $this->roleData($request, $organization, creating: false, ignore: $role->id));

        return ApiResponse::success($this->staff->roles($organization), 'Role saved.');
    }

    public function destroyRole(Request $request, Organization $organization, OrganizationRole $role): JsonResponse
    {
        $this->authorizeTeam($request, $organization, manage: true);
        $this->ownRole($organization, $role);
        $this->staff->deleteRole($organization, $role);

        return ApiResponse::success($this->staff->roles($organization), 'Role removed.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function role(Organization $organization, Request $request, array $data): OrganizationRole
    {
        if (! empty($data['preset'])) {
            return $this->staff->presetRole($organization, $request->user(), $data['preset']);
        }

        $role = OrganizationRole::query()->where('organization_id', $organization->id)->find($data['role_id']);

        return $role ?? throw new ApiException(ErrorCode::ValidationError, 'The given data was invalid.', 422, ['fields' => ['role_id' => ['Choose one of your roles.']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function roleData(Request $request, Organization $organization, bool $creating, ?string $ignore = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:60',
                Rule::unique('organization_roles', 'name')->where('organization_id', $organization->id)->whereNull('deleted_at')->ignore($ignore)],
            'description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'permissions' => [$required, 'array', 'max:30'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'key')],
        ]);
    }

    private function ownRole(Organization $organization, OrganizationRole $role): void
    {
        if ($role->organization_id !== $organization->id) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
    }

    private function authorizeTeam(Request $request, Organization $organization, bool $manage = false): void
    {
        if (! $this->access->isMember($request->user(), $organization)
            || ($manage && ! $this->access->can($request->user(), $organization, 'staff.manage'))) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($manage && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to manage its team.', 403);
        }
    }
}
