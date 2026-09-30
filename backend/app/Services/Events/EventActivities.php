<?php

namespace App\Services\Events;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Services\Preparation\ActivityTimeline;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Activity Timeline (0045): the organizer's written event program
 * ("activities"), shown together with NaSeRy's automatic event history
 * (source "nasery", read-only).
 */
class EventActivities
{
    public const HISTORY_PER_EVENT = 30;

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly OrganizationAccess $access,
        private readonly ActivityTimeline $history,
    ) {}

    /**
     * Every event the user can see: written activities and the automatic
     * history, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrganization(User $user, Organization $organization, ?string $eventId = null): array
    {
        $events = $this->db->system('activities.events', fn () => Event::query()->where('organization_id', $organization->id)
            ->when($eventId, fn ($q) => $q->whereKey($eventId))->get())
            ->filter(fn (Event $e) => $this->access->canSeeEvent($user, $e))->keyBy('id');

        if ($events->isEmpty()) {
            return [];
        }

        $written = $this->db->system('activities.list', fn () => DB::table('event_activities')->whereIn('event_id', $events->keys())
            ->whereNull('deleted_at')->orderByDesc('activity_at')->orderByDesc('created_at')->limit(500)->get())
            ->map(fn ($a) => $this->present($a, $events[$a->event_id]));

        $automatic = $events->flatMap(fn (Event $e) => $this->history->forOrganizer($e)->take(self::HISTORY_PER_EVENT)->map(fn ($h) => [
            'id' => 'nasery-'.$h['id'],
            'event_id' => $e->id,
            'title' => $h['message'],
            'description' => $h['reason'] ?? null,
            'category' => 'Recorded by NaSeRy',
            'tag' => null,
            'person' => $h['actor'] ?? null,
            'status' => 'completed',
            'activity_at' => $h['at'] instanceof \DateTimeInterface ? CarbonImmutable::instance($h['at'])->toIso8601String() : CarbonImmutable::parse($h['at'])->toIso8601String(),
            'source' => 'nasery',
            'editable' => false,
            'event' => ['id' => $e->id, 'name' => $e->name, 'event_type' => null],
        ]));

        return $written->concat($automatic)
            ->sortByDesc(fn ($a) => $a['activity_at'] ?? '')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $user, Event $event, array $data): array
    {
        $this->assertCanWrite($user, $event);
        $id = (string) Str::uuid7();

        $row = $this->db->system('activities.create', function () use ($id, $user, $event, $data) {
            DB::table('event_activities')->insert([
                'id' => $id, 'event_id' => $event->id, 'organization_id' => $event->organization_id,
                ...$this->fields($data, $event),
                'status' => $data['status'] ?? 'pending',
                'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('event_activities')->where('id', $id)->first();
        });
        $this->audit->record('event_activity.created', ['event_activities', $id], new: ['title' => $row->title, 'event_id' => $event->id], organizationId: $event->organization_id);

        return $this->present($row, $event);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $user, Event $event, string $activityId, array $data): array
    {
        $this->assertCanWrite($user, $event);
        $row = $this->db->system('activities.update', function () use ($event, $activityId, $data) {
            $changes = [...$this->fields($data, $event, partial: true), ...(isset($data['status']) ? ['status' => $data['status']] : [])];
            $updated = DB::table('event_activities')->where('id', $activityId)->where('event_id', $event->id)->whereNull('deleted_at')
                ->update([...$changes, 'updated_at' => now()]);
            if ($updated === 0) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }

            return DB::table('event_activities')->where('id', $activityId)->first();
        });
        $this->audit->record('event_activity.updated', ['event_activities', $activityId], new: ['status' => $row->status], organizationId: $event->organization_id);

        return $this->present($row, $event);
    }

    public function delete(User $user, Event $event, string $activityId): void
    {
        $this->assertCanWrite($user, $event);
        $this->db->system('activities.delete', function () use ($event, $activityId) {
            if (DB::table('event_activities')->where('id', $activityId)->where('event_id', $event->id)->whereNull('deleted_at')->update(['deleted_at' => now()]) === 0) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }
        });
        $this->audit->record('event_activity.removed', ['event_activities', $activityId], organizationId: $event->organization_id);
    }

    /** Planning the program is event planning: event.update or task.manage on this event. */
    private function assertCanWrite(User $user, Event $event): void
    {
        if (! $this->access->canOnEvent($user, $event, 'event.update') && ! $this->access->canOnEvent($user, $event, 'task.manage')) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to change this event\'s activities.', 403);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data, Event $event, bool $partial = false): array
    {
        $out = [];
        foreach (['title', 'description', 'category', 'tag', 'person'] as $key) {
            if (! $partial || array_key_exists($key, $data)) {
                $value = $data[$key] ?? null;
                $out[$key] = is_string($value) && trim($value) !== '' ? trim($value) : ($key === 'title' ? '' : null);
            }
        }
        if (! $partial || array_key_exists('activity_at', $data)) {
            $out['activity_at'] = filled($data['activity_at'] ?? null) ? CarbonImmutable::parse($data['activity_at'], $event->timezone)->utc() : null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function present(object $a, Event $event): array
    {
        return [
            'id' => $a->id,
            'event_id' => $a->event_id,
            'title' => $a->title,
            'description' => $a->description,
            'category' => $a->category,
            'tag' => $a->tag,
            'person' => $a->person,
            'status' => $a->status,
            'activity_at' => $a->activity_at ? CarbonImmutable::parse($a->activity_at)->toIso8601String() : CarbonImmutable::parse($a->created_at)->toIso8601String(),
            'source' => 'organizer',
            'editable' => true,
            'event' => ['id' => $event->id, 'name' => $event->name, 'event_type' => null],
        ];
    }

    /** @return Collection<int, mixed> */
    public static function statuses(): Collection
    {
        return collect(['pending', 'in_progress', 'completed']);
    }
}
