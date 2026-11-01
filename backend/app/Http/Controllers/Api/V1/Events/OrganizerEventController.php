<?php

namespace App\Http\Controllers\Api\V1\Events;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventRequest;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Quotation;
use App\Services\Events\EventManager;
use App\Services\Events\EventPresenter;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * An organization's events. Any active member may view; changes need the
 * event.* permission for the action (owners have all) and an operational
 * organization. {event} binds under RLS and must belong to {organization}.
 */
class OrganizerEventController extends Controller
{
    private const TRANSITION_PERMISSIONS = [
        'publish' => 'event.publish', 'unpublish' => 'event.publish', 'start' => 'event.update',
        'stop' => 'event.update', 'complete' => 'event.complete', 'cancel' => 'event.cancel',
    ];

    public function __construct(
        private readonly EventManager $events,
        private readonly EventPresenter $presenter,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->member($request, $organization);

        $data = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(['draft', 'published', 'ongoing', 'completed', 'cancelled'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $member = $this->access->membership($request->user(), $organization);
        $page = Event::query()->where('organization_id', $organization->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->whereIn('status', $s))
            // B8: team members without organization-wide event rights see their assigned events only.
            ->when(! $this->access->seesAllEvents($request->user(), $organization), fn ($q) => $q->whereIn('id', DB::table('event_members')
                ->where('organization_member_id', $member->id)->where('status', 'active')->whereNull('deleted_at')->select('event_id')))
            ->orderByRaw("case status when 'ongoing' then 0 when 'published' then 1 when 'draft' then 2 else 3 end")
            ->orderByDesc('created_at')
            ->paginate(perPage: 15, page: (int) ($data['page'] ?? 1));

        $page->setCollection($this->presenter->forOrganizer($page->getCollection()));

        return ApiResponse::success($page);
    }

    public function store(EventRequest $request, Organization $organization): JsonResponse
    {
        $member = $this->member($request, $organization, 'event.create');

        return ApiResponse::created($this->show($this->events->create($organization, $member, $request->validated())), 'Event created.');
    }

    public function convert(EventRequest $request, Organization $organization, Quotation $quotation): JsonResponse
    {
        $member = $this->member($request, $organization, 'event.create');

        return ApiResponse::created($this->show($this->events->convert($organization, $member, $quotation, $request->validated())), 'Event created from the accepted quotation.');
    }

    public function view(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->member($request, $organization, event: $event);

        return ApiResponse::success($this->show($event));
    }

    public function update(EventRequest $request, Organization $organization, Event $event): JsonResponse
    {
        $this->member($request, $organization, 'event.update', $event);

        return ApiResponse::success($this->show($this->events->update($event, $request->validated())), 'Event saved.');
    }

    public function transition(Request $request, Organization $organization, Event $event, string $action): JsonResponse
    {
        $this->member($request, $organization, self::TRANSITION_PERMISSIONS[$action], $event);
        // Cancelling, and taking a started event back to "not started", always say why (audit log).
        $reason = in_array($action, ['cancel', 'stop'], true)
            ? $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason']
            : null;

        return ApiResponse::success($this->show($this->events->transition($event, $action, $reason)));
    }

    /** 0055: the final agreed price (organizer-only; null = the accepted quotation's total). */
    public function agreedAmount(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->member($request, $organization, 'quotation.manage', $event);
        $data = $request->validate([
            'agreed_amount' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'agreed_amount_note' => ['nullable', 'string', 'max:500'],
        ]);
        $amount = $data['agreed_amount'] === null ? null : (float) $data['agreed_amount'];

        return ApiResponse::success($this->show($this->events->setAgreedAmount($event, $amount, $data['agreed_amount_note'] ?? null)),
            $amount === null ? 'Back to the accepted offer’s total.' : 'Final agreed price saved.');
    }

    public function destroy(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->member($request, $organization, 'event.update', $event);
        $this->events->deleteDraft($event);

        return ApiResponse::success(null, 'Draft event deleted.');
    }

    public function storeSession(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->member($request, $organization, 'event.update', $event);
        $this->events->saveSession($event, null, $this->sessionData($request));

        return ApiResponse::created($this->show($event->refresh()), 'Session added.');
    }

    public function updateSession(Request $request, Organization $organization, Event $event, EventSession $session): JsonResponse
    {
        $this->member($request, $organization, 'event.update', $event);
        $this->events->saveSession($event, $session, $this->sessionData($request));

        return ApiResponse::success($this->show($event->refresh()), 'Session saved.');
    }

    public function destroySession(Request $request, Organization $organization, Event $event, EventSession $session): JsonResponse
    {
        $this->member($request, $organization, 'event.update', $event);
        $this->events->deleteSession($event, $session);

        return ApiResponse::success($this->show($event->refresh()), 'Session removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionData(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function show(Event $event): array
    {
        $user = request()->user();
        $can = fn (string $permission) => $this->access->canOnEvent($user, $event, $permission);

        // 0055: the price, for members who handle quotations or see finances (never the client API).
        $seesPrice = $can('quotation.manage') || $this->access->can($user, $event->organization_id, 'finance.view');
        $offer = $seesPrice && $event->accepted_quotation_id
            ? DB::table('quotations')->where('id', $event->accepted_quotation_id)->value('total_amount') : null;

        // What the signed-in member may do here (B8: organization-wide or on this event), for the UI.
        return [...$this->presenter->forOrganizer(collect([$event]))->first(),
            ...($seesPrice ? ['price' => [
                'offer_amount' => $offer,
                'agreed_amount' => $event->agreed_amount,
                'agreed_amount_note' => $event->agreed_amount_note,
            ]] : []),
            'can' => [
                'update' => $can('event.update'),
                'publish' => $can('event.publish'),
                'complete' => $can('event.complete'),
                'cancel' => $can('event.cancel'),
                'manage_team' => $this->access->can($user, $event->organization_id, 'staff.manage'),
                'set_price' => $can('quotation.manage'),
            ]];
    }

    private function member(Request $request, Organization $organization, ?string $permission = null, ?Event $event = null): OrganizationMember
    {
        $member = $this->access->membership($request->user(), $organization);

        if ($event !== null && ($event->organization_id !== $organization->id || ($member !== null && ! $this->access->canSeeEvent($request->user(), $event)))) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        // Organization-wide permission, or (B8) the same permission granted on this event.
        $allowed = $permission === null || ($event !== null
            ? $this->access->canOnEvent($request->user(), $event, $permission)
            : $this->access->can($request->user(), $organization, $permission));

        if ($member === null || ! $allowed) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($permission !== null && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to manage events.', 403);
        }

        return $member;
    }
}
