<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Task;
use App\Services\Staff\EventTeam;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Event team (who works on an event), task assignment and a member's own
 * task list ("task link" destination). Team changes need staff.manage;
 * assigning tasks needs task.manage (organization-wide or on this event).
 */
class EventTeamController extends Controller
{
    public function __construct(
        private readonly EventTeam $team,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event);

        return ApiResponse::success($this->team->list($event));
    }

    public function store(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event, 'staff.manage');
        $data = $request->validate([
            'organization_member_id' => ['required', 'uuid'],
            'label' => ['nullable', 'string', 'max:60'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(EventTeam::EVENT_PERMISSIONS)],
        ]);

        $member = OrganizationMember::query()->where('organization_id', $organization->id)->find($data['organization_member_id'])
            ?? throw new ApiException(ErrorCode::ValidationError, 'The given data was invalid.', 422, ['fields' => ['organization_member_id' => ['Choose a member of your team.']]]);
        $this->team->assign($event, $member, $data['label'] ?? null, $data['permissions'], $request->user()->id);

        return ApiResponse::created($this->team->list($event), 'Added to the event team.');
    }

    public function destroy(Request $request, Organization $organization, Event $event, string $eventMember): JsonResponse
    {
        $this->check($request, $organization, $event, 'staff.manage');
        $row = EventMember::query()->where('event_id', $event->id)->find($eventMember) ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        $this->team->unassign($event, $row);

        return ApiResponse::success($this->team->list($event), 'Removed from the event team.');
    }

    public function assignTask(Request $request, Organization $organization, Event $event, Task $task): JsonResponse
    {
        $this->check($request, $organization, $event);

        if (! $this->access->canOnEvent($request->user(), $event, 'task.manage')) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        $data = $request->validate(['event_member_ids' => ['present', 'array', 'max:20'], 'event_member_ids.*' => ['uuid', 'distinct']]);
        $this->team->assignTask($event, $task, $data['event_member_ids']);

        return ApiResponse::success(['task_id' => $task->id, 'event_member_ids' => $data['event_member_ids']], 'Task assigned.');
    }

    /**
     * Tasks assigned to the signed-in member across the organization's open
     * events (the paper's "task link" lands here).
     */
    public function myTasks(Request $request, Organization $organization): JsonResponse
    {
        $member = $this->access->membership($request->user(), $organization)
            ?? throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);

        $tasks = DB::table('task_assignees as ta')
            ->join('event_members as em', 'em.id', '=', 'ta.event_member_id')
            ->join('tasks as t', 't.id', '=', 'ta.task_id')
            ->join('checklists as c', 'c.id', '=', 't.checklist_id')
            ->join('events as e', 'e.id', '=', 'c.event_id')
            ->where('em.organization_member_id', $member->id)->where('em.status', MembershipStatus::Active->value)->whereNull('em.deleted_at')
            ->whereNull('t.deleted_at')->whereNull('c.deleted_at')->whereNull('e.deleted_at')
            ->whereIn('e.status', ['draft', 'published', 'ongoing'])
            ->orderByRaw("case t.status when 'done' then 1 when 'cancelled' then 2 else 0 end")
            ->orderByRaw('t.due_at asc nulls last')
            ->limit(200)
            ->get(['t.id', 't.name', 't.description', 't.status', 't.priority', 't.due_at', 'c.name as checklist', 'e.id as event_id', 'e.name as event', 'e.timezone']);

        return ApiResponse::success($tasks->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'description' => $t->description, 'status' => $t->status, 'priority' => $t->priority,
            'due_at' => $t->due_at ? CarbonImmutable::parse($t->due_at)->setTimezone($t->timezone)->toIso8601String() : null,
            'checklist' => $t->checklist, 'event_id' => $t->event_id, 'event' => $t->event,
        ])->values());
    }

    private function check(Request $request, Organization $organization, Event $event, ?string $permission = null): void
    {
        if ($event->organization_id !== $organization->id || ! $this->access->canSeeEvent($request->user(), $event)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        if ($permission !== null && ! $this->access->can($request->user(), $organization, $permission)) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($permission !== null && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to change events.', 403);
        }
    }
}
