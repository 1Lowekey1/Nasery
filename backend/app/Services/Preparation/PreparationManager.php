<?php

namespace App\Services\Preparation;

use App\Enums\TaskStatus;
use App\Exceptions\ApiException;
use App\Models\Checklist;
use App\Models\Event;
use App\Models\Task;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;

/**
 * Checklists and tasks (paper: "dynamic preparation checklist … live event
 * readiness percentage"). Written under RLS as the member (checklists_org,
 * tasks_org). Every change refreshes event_progress. Audit rows carry
 * event_id so the activity timeline can gather them per event.
 */
class PreparationManager
{
    /** "Use a starter checklist": the preparation areas the paper lists. */
    public const STARTER = [
        'name' => 'Event preparation',
        'tasks' => [
            ['name' => 'Confirm the venue booking', 'priority' => 'high'],
            ['name' => 'Book suppliers (sound, lights, styling)', 'priority' => 'high'],
            ['name' => 'Finalize catering menu and headcount', 'priority' => 'high'],
            ['name' => 'Plan logistics and transport', 'priority' => 'medium'],
            ['name' => 'Prepare guest list and invitations', 'priority' => 'medium'],
            ['name' => 'Final walkthrough with the client', 'priority' => 'medium'],
        ],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProgressTracker $progress,
    ) {}

    /**
     * @param  array{name?: string, description?: ?string, starter?: bool}  $data
     */
    public function createChecklist(Event $event, array $data, string $userId): Checklist
    {
        $this->assertOpen($event);
        $starter = (bool) ($data['starter'] ?? false);

        $checklist = new Checklist([
            'name' => trim($data['name'] ?? '') ?: self::STARTER['name'],
            'description' => $data['description'] ?? null,
        ]);
        $checklist->forceFill(['event_id' => $event->id])->save();

        if ($starter) {
            foreach (self::STARTER['tasks'] as $task) {
                $this->insertTask($checklist, $task, $userId, $event);
            }
        }

        $this->audit->record('checklist.created', $checklist, new: ['name' => $checklist->name, 'event_id' => $event->id, 'starter' => $starter],
            organizationId: $event->organization_id);
        $this->progress->refresh($event);

        return $checklist;
    }

    /**
     * @param  array{name?: string, description?: ?string}  $data
     */
    public function updateChecklist(Event $event, Checklist $checklist, array $data): Checklist
    {
        $this->assertOpen($event);
        $checklist->fill($data)->save();
        $this->audit->record('checklist.updated', $checklist, new: [...$data, 'event_id' => $event->id], organizationId: $event->organization_id);

        return $checklist;
    }

    public function deleteChecklist(Event $event, Checklist $checklist): void
    {
        $this->assertOpen($event);
        $checklist->tasks()->update(['deleted_at' => now()]);
        $checklist->delete();
        $this->audit->record('checklist.removed', $checklist, new: ['name' => $checklist->name, 'event_id' => $event->id], organizationId: $event->organization_id);
        $this->progress->refresh($event);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTask(Event $event, Checklist $checklist, array $data, string $userId): Task
    {
        $this->assertOpen($event);
        $task = $this->insertTask($checklist, $data, $userId, $event);
        $this->audit->record('task.created', $task, new: ['name' => $task->name, 'checklist' => $checklist->name, 'event_id' => $event->id],
            organizationId: $event->organization_id);
        $this->progress->refresh($event);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTask(Event $event, Task $task, array $data): Task
    {
        $this->assertOpen($event);
        $oldStatus = $task->status;

        if (array_key_exists('due_at', $data)) {
            $data['due_at'] = $this->due($data['due_at'], $event);
        }

        $task->fill($data)->save();

        $statusChanged = $task->status !== $oldStatus;
        $this->audit->record($statusChanged ? 'task.status_changed' : 'task.updated', $task,
            old: $statusChanged ? ['status' => $oldStatus->value] : null,
            new: ['name' => $task->name, 'status' => $task->status->value, 'event_id' => $event->id],
            organizationId: $event->organization_id);

        if ($statusChanged) {
            $this->progress->refresh($event);
        }

        return $task;
    }

    public function deleteTask(Event $event, Task $task): void
    {
        $this->assertOpen($event);
        $task->delete();
        $this->audit->record('task.removed', $task, new: ['name' => $task->name, 'event_id' => $event->id], organizationId: $event->organization_id);
        $this->progress->refresh($event);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertTask(Checklist $checklist, array $data, string $userId, Event $event): Task
    {
        $task = new Task([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'status' => $data['status'] ?? TaskStatus::Pending->value,
            'due_at' => $this->due($data['due_at'] ?? null, $event),
        ]);
        $task->forceFill(['checklist_id' => $checklist->id, 'created_by' => $userId])->save();

        return $task;
    }

    /** Due times are entered in the event's local time. */
    private function due(?string $value, Event $event): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse($value, $event->timezone)->utc();
    }

    /** Preparation stays editable until the event is completed or cancelled. */
    private function assertOpen(Event $event): void
    {
        if ($event->isTerminal()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This event has ended; its preparation can no longer change.', 409);
        }
    }
}
