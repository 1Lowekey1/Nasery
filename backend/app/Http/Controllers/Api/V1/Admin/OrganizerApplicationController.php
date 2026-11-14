<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\ApprovalDecisionRequest;
use App\Http\Resources\ApprovalHistoryResource;
use App\Http\Resources\OfferedServiceResource;
use App\Http\Resources\OrganizationProfileResource;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Services\Admin\AccessRequests;
use App\Services\Admin\PlatformAnalytics;
use App\Services\Organizations\ApprovalWorkflow;
use App\Services\Organizations\OfferedServices;
use App\Services\Organizations\OrganizationDocuments;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin review of organizer applications (route group: account:admin).
 *
 * Admins may read organizations under RLS, but profile/services/owner rows
 * are tenant data that RLS only opens through an admin_access_grant.
 * Reviewing what an organizer SUBMITTED for approval is the reason those
 * rows exist, so the review screens read them in the (logged) system
 * context after the admin check. Anything beyond the application (events,
 * finances, attendees) still requires a grant (B17).
 */
class OrganizerApplicationController extends Controller
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly ApprovalWorkflow $workflow,
        private readonly OfferedServices $services,
        private readonly PlatformAnalytics $analytics,
        private readonly AccessRequests $access,
        private readonly OrganizationDocuments $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_column(ApprovalStatus::cases(), 'value'))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $this->db->system('admin.applications.list', fn () => Organization::query()
            ->with('profile')
            ->when($request->filled('status'), fn ($q) => $q->where('approval_status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $request->string('search')).'%';
                $q->where(fn ($w) => $w->where('display_name', 'ilike', $term)->orWhere('legal_name', 'ilike', $term));
            })
            ->orderByRaw("case approval_status when 'pending' then 0 when 'under_review' then 1 else 2 end")
            ->orderBy('created_at')
            ->paginate(20));

        $page->getCollection()->transform(fn (Organization $o) => $this->summary($o));

        // B17: totals and owner e-mail per row (no workspace grant needed for totals).
        $ids = $page->getCollection()->pluck('id')->all();
        [$totals, $emails] = $this->db->system('admin.applications.totals', fn () => [
            $this->analytics->organizerTotals($ids)->keyBy('id'),
            OrganizationMember::query()->join('users', 'users.id', '=', 'organization_members.user_id')
                ->whereIn('organization_id', $ids)->where('is_primary_owner', true)->pluck('users.email', 'organization_id'),
        ]);
        $page->getCollection()->transform(fn (array $row) => $row + ['owner_email' => $emails[$row['id']] ?? null, 'totals' => $totals[$row['id']] ?? null]);

        $counts = $this->db->system('admin.applications.counts', fn () => Organization::query()
            ->selectRaw('approval_status, count(*) as total')
            ->groupBy('approval_status')
            ->pluck('total', 'approval_status'));

        return ApiResponse::success($page, meta: [
            'counts' => collect(ApprovalStatus::cases())->mapWithKeys(fn (ApprovalStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
        ]);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success($this->application($organization) + $this->oversight($request, $organization));
    }

    /**
     * B17: totals for every organizer; this admin's access state (the
     * events/clients/team details need the organizer's consent).
     *
     * @return array<string, mixed>
     */
    private function oversight(Request $request, Organization $organization): array
    {
        $grant = $this->access->activeGrant($request->user(), $organization->id);
        $pending = $this->db->system('admin.applications.access', fn () => DB::table('admin_access_requests')
            ->where('organization_id', $organization->id)->where('admin_user_id', $request->user()->id)->where('initiated_by', 'admin')
            ->where('status', 'pending')->where('expires_at', '>', now())->value('id'));

        return [
            'totals' => $this->db->system('admin.applications.org_totals', fn () => $this->analytics->organizerTotals([$organization->id])->first()),
            'access' => [
                'grant' => $grant ? ['id' => $grant->id, 'expires_at' => CarbonImmutable::parse($grant->expires_at)->toIso8601String()] : null,
                'pending_request_id' => $pending,
                'default_hours' => $this->access->defaultHours(),
            ],
        ];
    }

    public function note(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->workflow->note($organization, $request->user(), $data['note']);

        return ApiResponse::success($this->application($organization->refresh()), 'Note sent to the organizer.');
    }

    public function decide(ApprovalDecisionRequest $request, Organization $organization): JsonResponse
    {
        $organization = $this->workflow->decide(
            $organization,
            ApprovalStatus::from($request->string('status')),
            $request->input('reason'),
        );

        return ApiResponse::success($this->application($organization), 'Decision recorded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'display_name' => $organization->display_name,
            'legal_name' => $organization->legal_name,
            'slug' => $organization->slug,
            'approval_status' => $organization->approval_status->value,
            'is_suspended' => $organization->is_suspended,
            'business_name' => $organization->profile?->business_name,
            'location_text' => $organization->profile?->location_text,
            'submitted_at' => $organization->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function application(Organization $organization): array
    {
        return $this->db->system('admin.applications.review', function () use ($organization) {
            $owner = OrganizationMember::query()
                ->where('organization_id', $organization->id)
                ->where('is_primary_owner', true)
                ->with('user')
                ->first()?->user;

            return [
                ...$this->summary($organization),
                'suspended_reason' => $organization->suspended_reason,
                'allowed_decisions' => array_map(fn (ApprovalStatus $s) => $s->value, $this->workflow->allowedDecisions($organization)),
                // Proof of business (0057); the reviewer can still reach the owner by e-mail.
                'documents' => $this->documents->list($organization)->values(),
                'documents_required' => OrganizationDocuments::required(),
                'owner' => $owner ? [
                    'id' => $owner->id,
                    'full_name' => trim("{$owner->first_name} {$owner->last_name}"),
                    'email' => $owner->email,
                    'email_verified' => $owner->email_verified_at !== null,
                    'phone' => $owner->phone,
                    'account_status' => $owner->status->value,
                ] : null,
                'profile' => $organization->profile()->first() ? new OrganizationProfileResource($organization->profile()->first()) : null,
                'services' => OfferedServiceResource::collection($this->services->list($organization)),
                'history' => ApprovalHistoryResource::collection($organization->approvalRequests()->get()),
            ];
        });
    }
}
