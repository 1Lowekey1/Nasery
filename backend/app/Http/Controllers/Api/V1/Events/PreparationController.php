<?php

namespace App\Http\Controllers\Api\V1\Events;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Checklist;
use App\Models\Event;
use App\Models\EventProgress;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Preparation\ActivityTimeline;
use App\Services\Preparation\PreparationManager;
use App\Services\Preparation\ProgressTracker;
use App\Services\Staff\EventTeam;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Preparation tracking + activity timeline for one event (B7).
 * View: any active member. Checklists: checklist.manage. Tasks: task.manage.
 * Readiness override: event.update. Writes need an operational organization.
 * Route bindings are scoped: {checklist} and {task} must belong to {event}.
 */
class PreparationController extends Controller
{
    public function __construct(
        private readonly PreparationManager $preparation,
        private readonly ProgressTracker $progress,
        private readonly ActivityTimeline $timeline,
        private readonly OrganizationAccess $access,
        private readonly EventTeam $team,
    ) {}

    public function show(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event);

        return ApiResponse::success($this->payload($event));
    }

    public function storeChecklist(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event, 'checklist.manage');
        $data = $request->validate([
            'name' => ['required_unless:starter,true', 'nullable', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'starter' => ['sometimes', 'boolean'],
        ]);
        $this->preparation->createChecklist($event, $data, $request->user()->id);

        return ApiResponse::created($this->payload($event), 'Checklist added.');
    }

    public function updateChecklist(Request $request, Organization $organization, Event $event, Checklist $checklist): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event, 'checklist.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $this->preparation->updateChecklist($event, $checklist, $data);

        return ApiResponse::success($this->payload($event), 'Checklist saved.');
    }

    public function destroyChecklist(Request $request, Organization $organization, Event $event, Checklist $checklist): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event, 'checklist.manage');
        $this->preparation->deleteChecklist($event, $checklist);

        return ApiResponse::success($this->payload($event), 'Checklist removed.');
    }

    public function storeTask(Request $request, Organization $organization, Event $event, Checklist $checklist): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event, 'task.manage');
        $this->preparation->createTask($event, $checklist, $this->taskData($request, creating: true), $request->user()->id);

        return ApiResponse::created($this->payload($event), 'Task added.');
    }

    public function updateTask(Request $request, Organization $organization, Event $event, Task $task): JsonResponse
    {
        $data = $this->taskData($request, creating: false);

        // B8: an assignee without task.manage may move their own task's status (paper: team members update task progress).
        $statusOnly = array_keys($data) === ['status'];
        $this->authorizeFor($request, $organization, $event, 'task.manage',
            orIf: fn () => $statusOnly && $this->team->isAssignee($event, $task, $request->user()));
        $this->preparation->updateTask($event, $task, $data);

        return ApiResponse::success($this->payload($event), 'Task saved.');
    }

    public function destroyTask(Request $request, Organization $organization, Event $event, Task $task): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event, 'task.manage');
        $this->preparation->deleteTask($event, $task);

        return ApiResponse::success($this->payload($event), 'Task removed.');
    }

    public function overrideReadiness(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event, 'event.update');
        $data = $request->validate(['readiness_percent' => ['present', 'nullable', 'numeric', 'min:0', 'max:100']]);

        if ($event->isTerminal()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This event has ended; its preparation can no longer change.', 409);
        }

        $percent = $data['readiness_percent'];
        $this->progress->override($event, $percent === null ? null : (float) $percent, $request->user()->id);

        return ApiResponse::success($this->payload($event), $percent === null ? 'Readiness is automatic again.' : 'Readiness set.');
    }

    public function timeline(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->authorizeFor($request, $organization, $event);

        return ApiResponse::success($this->timeline->forOrganizer($event));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
        ]);
    }

    /**
     * Readiness, stage track and checklists with their tasks.
     *
     * @return array<string, mixed>
     */
    private function payload(Event $event): array
    {
        $user = request()->user();
        $myEventMember = $this->access->eventMemberId($user, $event);
        $progress = EventProgress::query()->where('event_id', $event->id)->first() ?? $this->progress->refresh($event);
        $stages = $this->progress->stages();
        $current = $stages->search($progress->current_stage_id);
        $checklists = $event->checklists()->with('tasks')->get();
        $assignees = DB::table('task_assignees')->whereIn('task_id', $checklists->flatMap->tasks->pluck('id'))
            ->get(['task_id', 'event_member_id'])->groupBy('task_id');

        return [
            'readiness_percent' => $progress->readiness_percent !== null ? (float) $progress->readiness_percent : null,
            'computed_readiness_percent' => $this->progress->computedReadiness($event),
            'readiness_manual' => $progress->is_manual_override,
            'stage' => $current === false ? null : $current,
            'stages' => $stages->keys()->values(),
            'event_status' => $event->status->value,
            // What the signed-in member may do (B8); assignees can always move their own tasks.
            'can' => [
                'manage_checklists' => $this->access->canOnEvent($user, $event, 'checklist.manage'),
                'manage_tasks' => $this->access->canOnEvent($user, $event, 'task.manage'),
                'override_readiness' => $this->access->canOnEvent($user, $event, 'event.update'),
            ],
            'team' => $this->team->list($event)->where('status', 'active')->values(),
            'checklists' => $checklists->map(fn (Checklist $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'done' => $c->tasks->where('status', TaskStatus::Done)->count(),
                'total' => $c->tasks->where('status', '!=', TaskStatus::Cancelled)->count(),
                'tasks' => $c->tasks->map(fn (Task $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'status' => $t->status->value,
                    'priority' => $t->priority->value,
                    'due_at' => $t->due_at?->setTimezone($event->timezone)->toIso8601String(),
                    'due_local' => $t->due_at?->setTimezone($event->timezone)->format('Y-m-d\TH:i'),
                    'assignee_ids' => collect($assignees[$t->id] ?? [])->pluck('event_member_id')->values(),
                    'mine' => $myEventMember !== null && collect($assignees[$t->id] ?? [])->contains('event_member_id', $myEventMember),
                ])->values(),
            ])->values(),
        ];
    }

    private function authorizeFor(Request $request, Organization $organization, Event $event, ?string $permission = null, ?Closure $orIf = null): void
    {
        $member = $this->access->membership($request->user(), $organization);

        if ($member === null) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($event->organization_id !== $organization->id || ! $this->access->canSeeEvent($request->user(), $event)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        // Organization-wide or event-level permission (B8), or the caller's own exception.
        if ($permission !== null && ! $this->access->canOnEvent($request->user(), $event, $permission) && ! ($orIf && $orIf())) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($permission !== null && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to change events.', 403);
        }
    }
}
