<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Admin\AccessRequests;
use App\Services\Admin\AccountRemoval;
use App\Services\Admin\AdminAccounts;
use App\Services\Admin\FeeClaims;
use App\Services\Admin\GrantedView;
use App\Services\Admin\PlatformAnalytics;
use App\Services\Admin\PlatformDirectory;
use App\Services\Admin\PlatformSettings;
use App\Services\Admin\ReviewModeration;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * B17 platform administration (route group: account:admin).
 *
 *  - overview, platform analytics, events (totals for all; rows only for
 *    public events and organizers who granted access)
 *  - workspace access: request, cancel, accept support invites, end, and
 *    the read-only granted view
 *  - moderation: reported reviews and refund fee claims
 *  - platform: settings and fee, users, service catalog, announcements,
 *    activity log
 */
class AdminController extends Controller
{
    private const REASON = ['required', 'string', 'min:5', 'max:1000'];

    public function __construct(
        private readonly PlatformAnalytics $analytics,
        private readonly AccessRequests $access,
        private readonly GrantedView $granted,
        private readonly ReviewModeration $moderation,
        private readonly FeeClaims $claims,
        private readonly PlatformSettings $settings,
        private readonly PlatformDirectory $directory,
        private readonly DatabaseContext $db,
        private readonly AdminAccounts $admins,
    ) {}

    // ---- Dashboard --------------------------------------------------------

    public function overview(): JsonResponse
    {
        return ApiResponse::success($this->analytics->overview());
    }

    /** What NaSeRy earns: plan run rate, paying organizers, last 12 months of plans and ticket fees. */
    public function earnings(): JsonResponse
    {
        return ApiResponse::success($this->analytics->earnings());
    }

    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate(['months' => ['nullable', 'integer', Rule::in([3, 6, 12, 24])]]);

        return ApiResponse::success([
            'totals' => $this->db->system('admin.totals', fn () => $this->analytics->totals()),
            'trends' => $this->analytics->trends((int) ($data['months'] ?? 12)),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'published', 'ongoing', 'completed', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $granted = $this->db->system('admin.granted_orgs', fn () => DB::table('admin_access_grants')->where('admin_user_id', $request->user()->id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->pluck('organization_id')->all());

        return ApiResponse::success($this->analytics->events($granted, $data['status'] ?? null, $data['search'] ?? null));
    }

    // ---- Workspace access ---------------------------------------------------

    public function access(Request $request): JsonResponse
    {
        return ApiResponse::success($this->access->forAdmin($request->user()));
    }

    public function requestAccess(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['reason' => self::REASON, 'hours' => ['nullable', 'integer', 'min:1', 'max:72']]);

        return ApiResponse::created($this->access->request($request->user(), $organization, trim($data['reason']), $data['hours'] ?? null),
            'Request sent. The organizer decides whether to allow it.');
    }

    public function cancelRequest(Request $request, string $accessRequest): JsonResponse
    {
        $this->access->cancel($request->user(), $accessRequest);

        return ApiResponse::success(null, 'Request withdrawn.');
    }

    public function acceptInvite(Request $request, string $accessRequest): JsonResponse
    {
        return ApiResponse::success($this->access->acceptInvite($request->user(), $accessRequest), 'Invite accepted. You have read-only access.');
    }

    public function endGrant(Request $request, string $grant): JsonResponse
    {
        $this->access->revoke($request->user(), $grant);

        return ApiResponse::success(null, 'Access ended.');
    }

    public function workspace(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success($this->granted->show($request->user(), $organization));
    }

    // ---- Moderation ---------------------------------------------------------

    public function reportedReviews(Request $request): JsonResponse
    {
        $data = $request->validate(['queue' => ['nullable', Rule::in(ReviewModeration::QUEUES)]]);

        return ApiResponse::success($this->moderation->queue($data['queue'] ?? 'open'));
    }

    public function startReview(Request $request, string $review): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->moderation->start($request->user(), $review, $data['note'] ?? null);

        return ApiResponse::success($this->moderation->counts(), 'The review is hidden while you look into it.');
    }

    public function decideReview(Request $request, string $review): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['keep', 'hide'])], 'reason' => self::REASON]);
        $this->moderation->decide($request->user(), $review, $data['decision'], trim($data['reason']));

        return ApiResponse::success($this->moderation->counts(), $data['decision'] === 'hide' ? 'Review hidden.' : 'Review kept.');
    }

    public function restoreReview(Request $request, string $review): JsonResponse
    {
        $data = $request->validate(['reason' => self::REASON]);
        $this->moderation->restore($request->user(), $review, trim($data['reason']));

        return ApiResponse::success($this->moderation->counts(), 'Review restored.');
    }

    public function feeClaims(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'accepted', 'flagged', 'all'])]]);

        return ApiResponse::success($this->claims->list($data['status'] ?? 'pending'));
    }

    public function reviewFeeClaim(Request $request, string $refund): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['accepted', 'flagged'])],
            'note' => [Rule::requiredIf($request->input('outcome') === 'flagged'), 'nullable', 'string', 'min:5', 'max:1000'],
        ]);

        return ApiResponse::success($this->claims->review($request->user(), $refund, $data['outcome'] === 'accepted', $data['note'] ?? null),
            $data['outcome'] === 'accepted' ? 'Claim accepted.' : 'Claim flagged. The organizer was told.');
    }

    // ---- Platform -----------------------------------------------------------

    public function settings(Request $request): JsonResponse
    {
        // Whether this admin may delete accounts permanently (NASERY_HARD_DELETE_ADMINS).
        return ApiResponse::success([...$this->settings->overview(), 'can_delete_accounts' => AccountRemoval::allowed($request->user())]);
    }

    /** Delete an account: what it takes with it (nothing is changed). */
    public function accountRemovalPreview(Request $request, AccountRemoval $removal): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);

        return ApiResponse::success($removal->preview($request->user(), $data['email']));
    }

    /** Delete an account permanently (after a backup). */
    public function removeAccount(Request $request, AccountRemoval $removal): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'confirm_email' => ['required', 'string', 'max:254'], 'reason' => self::REASON]);
        $counts = $removal->remove($request->user(), $data['email'], $data['confirm_email'], trim($data['reason']));

        return ApiResponse::success(['removed' => $counts], 'The account and everything linked to it were deleted.');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $rules = collect(PlatformSettings::EDITABLE)->map(fn ($m) => ['sometimes', 'integer', "min:{$m['min']}", "max:{$m['max']}"])->all();

        return ApiResponse::success($this->settings->update($request->validate($rules), $request->user()), 'Settings saved.');
    }

    public function changeFee(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'model' => ['required_if:enabled,true', Rule::in(['percentage', 'fixed', 'hybrid'])],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'fixed' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'reason' => self::REASON,
        ]);
        $data['model'] ??= 'percentage';

        return ApiResponse::success($this->settings->changeFee($data, $request->user()), 'New fee saved. It applies to payments from now on.');
    }

    public function users(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['client', 'business', 'admin'])],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deactivated'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success($this->directory->users($data));
    }

    public function userStatus(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => self::REASON]);
        $suspend = $data['status'] === 'suspended';

        return ApiResponse::success($this->directory->setUserStatus($request->user(), $user, $suspend, trim($data['reason'])),
            $suspend ? 'Account suspended and signed out everywhere.' : 'Account reactivated.');
    }

    public function services(): JsonResponse
    {
        return ApiResponse::success($this->directory->services());
    }

    public function saveService(Request $request, ?string $service = null): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'category' => ['nullable', 'string', 'max:60']]);
        $saved = $this->directory->saveService($service, $data['name'], $data['category'] ?? null);

        return $service ? ApiResponse::success($saved, 'Service updated.') : ApiResponse::created($saved, 'Service added to the catalog.');
    }

    public function archiveService(Request $request, string $service): JsonResponse
    {
        $archive = $request->isMethod('delete');
        $this->directory->archiveService($service, $archive);

        return ApiResponse::success(null, $archive ? 'Service archived.' : 'Service restored.');
    }

    public function user(string $user): JsonResponse
    {
        return ApiResponse::success($this->directory->user($user));
    }

    public function eventTypes(): JsonResponse
    {
        return ApiResponse::success($this->directory->eventTypes());
    }

    public function saveEventType(Request $request, ?string $eventType = null): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80'], 'description' => ['nullable', 'string', 'max:500']]);
        $saved = $this->directory->saveEventType($eventType, $data['name'], $data['description'] ?? null);

        return $eventType ? ApiResponse::success($saved, 'Event type updated.') : ApiResponse::created($saved, 'Event type added.');
    }

    public function removeEventType(string $eventType): JsonResponse
    {
        $this->directory->removeEventType($eventType);

        return ApiResponse::success(null, 'Event type removed.');
    }

    public function announcements(): JsonResponse
    {
        return ApiResponse::success($this->directory->announcements());
    }

    public function saveAnnouncement(Request $request, ?string $announcement = null): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'body' => ['required', 'string', 'min:3', 'max:1000'],
            'audience' => ['required', Rule::in(['all', 'organizers', 'clients'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);
        $saved = $this->directory->saveAnnouncement($announcement, $data, $request->user());

        return $announcement ? ApiResponse::success($saved, 'Announcement updated.') : ApiResponse::created($saved, 'Announcement posted.');
    }

    public function deleteAnnouncement(string $announcement): JsonResponse
    {
        $this->directory->deleteAnnouncement($announcement);

        return ApiResponse::success(null, 'Announcement removed.');
    }

    // ---- Admin team -------------------------------------------------------

    public function admins(): JsonResponse
    {
        return ApiResponse::success($this->admins->list());
    }

    public function inviteAdmin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:254'],
        ]);

        return ApiResponse::created($this->admins->invite($request->user(), $data['first_name'], $data['last_name'], $data['email']),
            'Admin added. They got an e-mail with a code to set their password.');
    }

    public function resendAdminInvite(Request $request, string $user): JsonResponse
    {
        $this->admins->resend($request->user(), $user);

        return ApiResponse::success(null, 'A new code is on its way.');
    }

    public function adminStatus(Request $request, string $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'reason' => self::REASON]);
        $active = $data['status'] === 'active';

        return ApiResponse::success($this->admins->setActive($request->user(), $user, $active, trim($data['reason'])),
            $active ? 'Admin enabled.' : 'Admin disabled and signed out everywhere.');
    }

    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'actor' => ['nullable', Rule::in(['admin', 'business', 'client', 'system'])],
            'organization_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success($this->directory->activity($request->user(), $data));
    }
}
