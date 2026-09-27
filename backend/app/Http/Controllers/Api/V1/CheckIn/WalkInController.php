<?php

namespace App\Http\Controllers\Api\V1\CheckIn;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\TicketType;
use App\Services\CheckIn\WalkInDesk;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B12 walk-in counter for one event. Recording needs checkin.perform (door
 * staff); admitting past the venue's capacity needs event.update plus a
 * reason; undo needs attendee.manage. All organization-wide or on the event.
 */
class WalkInController extends Controller
{
    public function __construct(
        private readonly WalkInDesk $desk,
        private readonly OrganizationAccess $access,
    ) {}

    public function summary(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event, ['checkin.perform', 'attendee.manage']);

        return ApiResponse::success([
            ...$this->desk->summary($event),
            'can' => [
                'record' => $this->access->canOnEvent($request->user(), $event, 'checkin.perform'),
                'override' => $this->access->canOnEvent($request->user(), $event, 'event.update'),
                'undo' => $this->access->canOnEvent($request->user(), $event, 'attendee.manage'),
            ],
        ]);
    }

    public function sale(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $member = $this->check($request, $organization, $event, 'checkin.perform', write: true);
        $data = $request->validate([
            'ticket_type_id' => ['required', 'uuid'],
            ...$this->personRules(),
            'cash_received' => ['sometimes', 'boolean'],
            ...$this->commonRules(),
        ]);

        $tier = TicketType::query()->whereKey($data['ticket_type_id'])->where('event_id', $event->id)->whereNull('deleted_at')->first()
            ?? throw new ApiException(ErrorCode::ValidationError, 'The given data was invalid.', 422, ['fields' => ['ticket_type_id' => ['Choose a ticket tier of this event.']]]);

        if ((float) $tier->price > 0 && ! $request->boolean('cash_received')) {
            throw new ApiException(ErrorCode::ValidationError, 'The given data was invalid.', 422,
                ['fields' => ['cash_received' => ['Confirm the ₱'.number_format((float) $tier->price, 2).' was collected.']]]);
        }

        return ApiResponse::created($this->desk->sell($event, $member, $tier, $this->person($data), $data['session_id'] ?? null, $this->override($request, $event, $data)));
    }

    public function name(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $member = $this->check($request, $organization, $event, 'checkin.perform', write: true);
        $data = $request->validate([...$this->personRules(), ...$this->commonRules()]);

        return ApiResponse::created($this->desk->logName($event, $member, $this->person($data), $data['session_id'] ?? null, $this->override($request, $event, $data)));
    }

    public function headcount(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $member = $this->check($request, $organization, $event, 'checkin.perform', write: true);
        $data = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:50'], ...$this->commonRules()]);

        return ApiResponse::created($this->desk->headcount($event, $member, (int) $data['count'], $data['session_id'] ?? null, $this->override($request, $event, $data)));
    }

    public function undo(Request $request, Organization $organization, Event $event, string $walkIn): JsonResponse
    {
        $this->check($request, $organization, $event, 'attendee.manage', write: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->desk->undo($event, $walkIn, $data['reason']);

        return ApiResponse::success(null, 'Walk-in removed.');
    }

    /** @return array<string, list<string>> */
    private function personRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'contact_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<string, list<string>> */
    private function commonRules(): array
    {
        return [
            'session_id' => ['nullable', 'uuid'],
            'override_reason' => ['nullable', 'string', 'min:3', 'max:300'],
        ];
    }

    /** @return array{full_name: string, email: ?string, contact_number: ?string} */
    private function person(array $data): array
    {
        return ['full_name' => trim($data['full_name']), 'email' => $data['email'] ?? null, 'contact_number' => $data['contact_number'] ?? null];
    }

    /** A capacity override reason, only from someone who may edit the event. */
    private function override(Request $request, Event $event, array $data): ?string
    {
        $reason = isset($data['override_reason']) ? trim($data['override_reason']) : null;

        if ($reason !== null && ! $this->access->canOnEvent($request->user(), $event, 'event.update')) {
            throw new ApiException(ErrorCode::Forbidden, 'Only someone who can edit the event may admit guests past its capacity.', 403);
        }

        return $reason;
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
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to record walk-ins.', 403);
        }

        return $member;
    }
}
