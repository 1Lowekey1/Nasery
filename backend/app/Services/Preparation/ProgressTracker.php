<?php

namespace App\Services\Preparation;

use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\EventProgress;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps event_progress (the client-facing summary) in step with the event:
 *
 *   readiness  = done tasks ÷ tasks that aren't cancelled (null when there
 *                are none), unless the organizer set a manual override
 *   stage      = derived from event status and readiness (0035 stages):
 *                draft → Planning; published → Confirmed, or Ready at 100%;
 *                ongoing → Event day; completed → Completed. A cancelled
 *                event keeps the stage it had reached.
 *
 * Called after every task/checklist change and every event transition.
 * Reaching a new stage writes an `event.stage_reached` audit row, which the
 * client timeline shows as a milestone.
 */
class ProgressTracker
{
    public const STAGES = ['Planning', 'Confirmed', 'Ready', 'Event day', 'Completed'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function refresh(Event $event): EventProgress
    {
        $progress = EventProgress::query()->firstOrNew(['event_id' => $event->id]);
        $readiness = $progress->exists && $progress->is_manual_override
            ? $progress->readiness_percent
            : $this->computedReadiness($event);

        $stageName = $this->stageFor($event, $readiness !== null ? (float) $readiness : null);
        $stages = $this->stages();
        $stageId = $stageName !== null ? ($stages[$stageName] ?? null) : $progress->current_stage_id;
        $previousStage = $progress->current_stage_id;

        $progress->forceFill([
            'readiness_percent' => $readiness,
            'current_stage_id' => $stageId,
            'is_manual_override' => $progress->is_manual_override ?? false,
        ])->save();

        if ($stageId !== null && $stageId !== $previousStage) {
            $this->audit->record('event.stage_reached', $event, new: ['stage' => $stageName, 'event_id' => $event->id],
                organizationId: $event->organization_id);
        }

        return $progress;
    }

    /**
     * @param  float|null  $percent  null clears the override (back to computed)
     */
    public function override(Event $event, ?float $percent, string $userId): EventProgress
    {
        $progress = EventProgress::query()->firstOrNew(['event_id' => $event->id]);
        $old = $progress->exists ? ['readiness_percent' => $progress->readiness_percent, 'manual' => $progress->is_manual_override] : null;

        $progress->forceFill($percent === null
            ? ['is_manual_override' => false, 'overridden_by' => null, 'overridden_at' => null]
            : ['is_manual_override' => true, 'readiness_percent' => round($percent, 2), 'overridden_by' => $userId, 'overridden_at' => now()],
        )->save();

        $this->audit->record('event.readiness_overridden', $event, old: $old,
            new: ['readiness_percent' => $percent, 'event_id' => $event->id], organizationId: $event->organization_id);

        return $this->refresh($event);
    }

    public function computedReadiness(Event $event): ?float
    {
        $counts = DB::table('tasks as t')
            ->join('checklists as c', 'c.id', '=', 't.checklist_id')
            ->where('c.event_id', $event->id)
            ->whereNull('c.deleted_at')->whereNull('t.deleted_at')
            ->where('t.status', '!=', TaskStatus::Cancelled->value)
            // 0044: finished work counts once it's verified (or needed no review: older tasks, the reviewer's own).
            ->selectRaw("count(*) as total, count(*) filter (where t.status = 'done' and coalesce(t.review_status, 'verified') = 'verified') as done")
            ->first();

        return (int) $counts->total === 0 ? null : round(100 * (int) $counts->done / (int) $counts->total, 2);
    }

    /**
     * Stage names with ids, in display order.
     *
     * @return Collection<string, string>
     */
    public function stages(): Collection
    {
        static $cache = null;

        return $cache ??= DB::table('event_stages')->whereNull('organization_id')->whereNull('deleted_at')
            ->whereIn('name', self::STAGES)->orderBy('sort_order')->pluck('id', 'name');
    }

    private function stageFor(Event $event, ?float $readiness): ?string
    {
        return match ($event->status) {
            EventStatus::Draft => 'Planning',
            EventStatus::Published => $readiness !== null && $readiness >= 100 ? 'Ready' : 'Confirmed',
            EventStatus::Ongoing => 'Event day',
            EventStatus::Completed => 'Completed',
            EventStatus::Cancelled => null,
        };
    }
}
