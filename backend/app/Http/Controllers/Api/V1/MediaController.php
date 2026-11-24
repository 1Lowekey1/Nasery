<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\Media\ImageStore;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Uploaded pictures: profile photo (any signed-in user), organizer logo and
 * cover (whoever may manage the organization), event cover (event.update on
 * that event). Every upload goes through ImageStore (type by content, size and
 * dimension limits, re-encoded WebP); the previous file is deleted on replace.
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly ImageStore $images,
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly OrganizationAccess $access,
    ) {}

    /** GET /media/{folder}/{file}: a stored picture, never anything else. */
    public function show(string $folder, string $file): BinaryFileResponse
    {
        $relative = "{$folder}/{$file}";
        if (! ImageStore::valid($relative) || ! is_file($path = $this->images->absolute($relative))) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        return response()->file($path, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            // Each upload gets a new random name, so a file never changes.
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    // ---- Profile photo (the signed-in user) ---------------------------------------

    public function storeAvatar(Request $request): JsonResponse
    {
        $this->validateUpload($request);
        $user = $request->user();
        $path = $this->images->store($request->file('image'), 'avatar');
        $old = $this->setUserAvatar($user, $path);
        $this->images->delete($old);
        $this->audit->record('user.avatar_updated', $user, actorUserId: $user->id);

        return ApiResponse::success(new UserResource($user->refresh()), 'Profile photo updated.');
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->images->delete($this->setUserAvatar($user, null));
        $this->audit->record('user.avatar_removed', $user, actorUserId: $user->id);

        return ApiResponse::success(new UserResource($user->refresh()), 'Profile photo removed.');
    }

    // ---- Organizer logo and cover ------------------------------------------------------

    public function storeOrganizationImage(Request $request, Organization $organization, string $slot): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $this->validateUpload($request);
        $column = $slot === 'logo' ? 'logo_path' : 'cover_path';
        $path = $this->images->store($request->file('image'), $slot === 'logo' ? 'logo' : 'cover');
        $old = $this->setProfileImage($organization, $column, $path);
        $this->images->delete($old);
        $this->audit->record("organization.{$slot}_updated", $organization, organizationId: $organization->id);

        return ApiResponse::success(['url' => ImageStore::url($path)], $slot === 'logo' ? 'Logo updated.' : 'Cover photo updated.');
    }

    public function destroyOrganizationImage(Request $request, Organization $organization, string $slot): JsonResponse
    {
        Gate::authorize('manage', $organization);
        $column = $slot === 'logo' ? 'logo_path' : 'cover_path';
        $this->images->delete($this->setProfileImage($organization, $column, null));
        $this->audit->record("organization.{$slot}_removed", $organization, organizationId: $organization->id);

        return ApiResponse::success(['url' => null], $slot === 'logo' ? 'Logo removed.' : 'Cover photo removed.');
    }

    // ---- Event cover ----------------------------------------------------------------------

    public function storeEventCover(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeEvent($request, $organization, $event);
        $this->validateUpload($request);
        $path = $this->images->store($request->file('image'), 'event-cover');
        $old = $this->setEventCover($event, $path);
        $this->images->delete($old);
        $this->audit->record('event.cover_updated', $event, organizationId: $organization->id);

        return ApiResponse::success(['url' => ImageStore::url($path)], 'Event cover updated.');
    }

    public function destroyEventCover(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeEvent($request, $organization, $event);
        $this->images->delete($this->setEventCover($event, null));
        $this->audit->record('event.cover_removed', $event, organizationId: $organization->id);

        return ApiResponse::success(['url' => null], 'Event cover removed.');
    }

    // ---- Event floor plan (0066) ----------------------------------------------------------

    public function storeFloorPlan(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeEvent($request, $organization, $event);
        $this->validateUpload($request);
        $path = $this->images->store($request->file('image'), 'floor-plan');
        $this->images->delete($this->setFloorPlan($event, $path));
        $this->audit->record('event.floor_plan_updated', $event, organizationId: $organization->id);

        return ApiResponse::success(['url' => ImageStore::url($path)], 'Floor plan updated.');
    }

    public function destroyFloorPlan(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeEvent($request, $organization, $event);
        $this->images->delete($this->setFloorPlan($event, null));
        $this->audit->record('event.floor_plan_removed', $event, organizationId: $organization->id);

        return ApiResponse::success(['url' => null], 'Floor plan removed.');
    }

    // ---- Helpers ----------------------------------------------------------------------------

    private function validateUpload(Request $request): void
    {
        // Size/type are checked again (by content) in ImageStore; this rejects the obvious early.
        $request->validate(['image' => ['required', 'file', 'max:'.(int) ceil(config('nasery.media.max_bytes') / 1024)]],
            ['image.required' => 'Choose an image to upload.', 'image.max' => 'Choose an image under 5 MB.']);
    }

    /** @return string|null the previous path */
    private function setUserAvatar(User $user, ?string $path): ?string
    {
        return $this->db->system('media.avatar', function () use ($user, $path) {
            $old = DB::table('users')->where('id', $user->id)->lockForUpdate()->value('avatar_path');
            DB::table('users')->where('id', $user->id)->update(['avatar_path' => $path, 'updated_at' => now()]);

            return $old;
        });
    }

    private function setProfileImage(Organization $organization, string $column, ?string $path): ?string
    {
        return $this->db->system('media.organization', function () use ($organization, $column, $path) {
            $old = DB::table('organization_profiles')->where('organization_id', $organization->id)->lockForUpdate()->value($column);
            DB::table('organization_profiles')->where('organization_id', $organization->id)->update([$column => $path, 'updated_at' => now()]);

            return $old;
        });
    }

    private function setEventCover(Event $event, ?string $path): ?string
    {
        return $this->db->system('media.event', function () use ($event, $path) {
            $old = DB::table('events')->where('id', $event->id)->lockForUpdate()->value('cover_path');
            DB::table('events')->where('id', $event->id)->update(['cover_path' => $path, 'updated_at' => now()]);

            return $old;
        });
    }

    /** @return string|null the previous path */
    private function setFloorPlan(Event $event, ?string $path): ?string
    {
        return $this->db->system('media.floor_plan', function () use ($event, $path) {
            $old = DB::table('events')->where('id', $event->id)->lockForUpdate()->value('floor_plan_path');
            DB::table('events')->where('id', $event->id)->update(['floor_plan_path' => $path, 'updated_at' => now()]);

            return $old;
        });
    }

    private function authorizeEvent(Request $request, Organization $organization, Event $event): void
    {
        if ($event->organization_id !== $organization->id || $this->access->membership($request->user(), $organization) === null) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        if (! $this->access->canOnEvent($request->user(), $event, 'event.update')) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }
    }
}
