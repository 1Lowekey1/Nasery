<?php

namespace App\Http\Controllers\Api\V1\CheckIn;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Services\CheckIn\CheckInDesk;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B11 door check-in for one event. Scanning, manual check-in and guest
 * search need checkin.perform; undo needs attendee.manage (both
 * organization-wide or on the event, B8). Anyone who may do either can
 * read the live numbers.
 */
class CheckInController extends Controller
{
    public function __construct(
        private readonly CheckInDesk $desk,
        private readonly OrganizationAccess $access,
    ) {}

    public function summary(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event, ['checkin.perform', 'attendee.manage']);

        return ApiResponse::success([
            ...$this->desk->summary($event),
            'can' => [
                'scan' => $this->access->canOnEvent($request->user(), $event, 'checkin.perform'),
                'undo' => $this->access->canOnEvent($request->user(), $event, 'attendee.manage'),
            ],
        ]);
    }

    public function scan(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $member = $this->check($request, $organization, $event, 'checkin.perform', write: true);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        return ApiResponse::success($this->desk->scan($event, $member, $data['token'], $data['session_id'] ?? null));
    }

    public function manual(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $member = $this->check($request, $organization, $event, 'checkin.perform', write: true);
        $data = $request->validate([
            'ticket_id' => ['required', 'uuid'],
            'session_id' => ['nullable', 'uuid'],
            // Proof: the last 4 of the ticket ID, or why the guest doesn't have it.
            'ticket_code' => ['required_without:no_ticket_reason', 'nullable', 'string', 'size:4'],
            'no_ticket_reason' => ['required_without:ticket_code', 'nullable', 'string', 'min:3', 'max:300'],
        ]);

        return ApiResponse::success($this->desk->manual($event, $member, $data['ticket_id'], $data['session_id'] ?? null, $data['ticket_code'] ?? null, $data['no_ticket_reason'] ?? null));
    }

    public function search(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event, 'checkin.perform');
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return ApiResponse::success($this->desk->search($event, $data['q']));
    }

    public function undo(Request $request, Organization $organization, Event $event, string $attendance): JsonResponse
    {
        $this->check($request, $organization, $event, 'attendee.manage', write: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->desk->undo($event, $attendance, $data['reason']);

        return ApiResponse::success(null, 'Check-in undone.');
    }

    /**
     * @param  string|list<string>  $permission  any of these
     */
    private function check(Request $request, Organization $organization, Event $event, string|array $permission, bool $write = false): OrganizationMember
    {
        $member = $this->access->membership($request->user(), $organization);

        if ($member === null || $event->organization_id !== $organization->id || ! $this->access->canSeeEvent($request->user(), $event)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        if (! collect((array) $permission)->contains(fn ($p) => $this->access->canOnEvent($request->user(), $event, $p))) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($write && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to check guests in.', 403);
        }

        return $member;
    }
}
