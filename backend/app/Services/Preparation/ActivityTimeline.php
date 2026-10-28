<?php

namespace App\Services\Preparation;

use App\Models\Event;
use App\Support\Database\DatabaseContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Event Activity Timeline (paper: detailed view for the organizer and team,
 * simplified milestone view for the client), built from audit_logs; no
 * separate table. Inquiry-phase rows have no organization_id and clients
 * can't read audit_logs, so the read runs in the system context with the
 * filters below (the caller has already authorized access to $event).
 *
 * Organizer: every activity on the event, its checklists/tasks, the inquiry
 * it came from and THIS organization's quotations (never competitors').
 * Client: only whitelisted milestones; no task names, staff or amounts.
 */
class ActivityTimeline
{
    /** Client-visible actions. */
    private const MILESTONES = ['inquiry.submitted', 'quotation_accept', 'event.converted_from_inquiry', 'event.stage_reached', 'event.cancelled', 'report.shared'];

    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forOrganizer(Event $event): Collection
    {
        return $this->rows($event, organizer: true)->map(fn ($r) => [
            'id' => $r->id,
            'at' => $r->created_at,
            'action' => $r->action,
            'message' => $this->organizerMessage($r),
            'actor' => $r->actor_name,
            // Reasons people typed (cancel, withdraw, decline); not internal notes such as accept_quotation()'s.
            'reason' => $r->action === 'quotation_accept' ? null : $r->reason,
            'milestone' => in_array($r->action, self::MILESTONES, true),
        ])->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forClient(Event $event, string $organizerName): Collection
    {
        // A request sent straight to this organizer (no open posting) names them (#11).
        $direct = $event->inquiry_id !== null && $this->db->system('event.inquiry_direct', fn () => DB::table('inquiries')
            ->where('id', $event->inquiry_id)->whereNull('match_radius_km')->exists());

        return $this->rows($event, organizer: false)
            ->filter(fn ($r) => in_array($r->action, self::MILESTONES, true))
            ->map(fn ($r) => [
                'id' => $r->id,
                'at' => $r->created_at,
                'action' => $r->action,
                'message' => match ($r->action) {
                    'inquiry.submitted' => $direct ? "You sent your request to {$organizerName}" : 'You sent your inquiry to organizers',
                    'quotation_accept' => 'You accepted '.$organizerName.(str_ends_with(strtolower($organizerName), 's') ? "'" : "'s").' quotation',
                    'event.converted_from_inquiry' => "{$organizerName} set up your event",
                    'event.stage_reached' => 'Stage reached: '.($this->values($r)['stage'] ?? ''),
                    'event.cancelled' => 'The event was cancelled',
                    'report.shared' => "{$organizerName} shared the post-event report",
                },
                'reason' => $r->action === 'event.cancelled' ? $r->reason : null,
            ])->values();
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(Event $event, bool $organizer): Collection
    {
        return $this->db->system('event.timeline', function () use ($event, $organizer) {
            $quotations = $event->inquiry_id === null ? collect() : DB::table('quotations')
                ->where('inquiry_id', $event->inquiry_id)
                ->when($organizer, fn ($q) => $q->where('organization_id', $event->organization_id))
                ->when(! $organizer, fn ($q) => $q->where('id', $event->accepted_quotation_id))
                ->pluck('id');

            return DB::table('audit_logs as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')
                ->where(function ($q) use ($event, $quotations) {
                    $q->where(fn ($q) => $q->where('a.entity_type', 'events')->where('a.entity_id', $event->id))
                        ->orWhereRaw("a.new_values->>'event_id' = ?", [$event->id]);

                    if ($event->inquiry_id !== null) {
                        $q->orWhere(fn ($q) => $q->where('a.entity_type', 'inquiries')->where('a.entity_id', $event->inquiry_id)
                            ->whereIn('a.action', ['inquiry.created', 'inquiry.submitted']));
                    }

                    if ($quotations->isNotEmpty()) {
                        $q->orWhere(fn ($q) => $q->where('a.entity_type', 'quotations')->whereIn('a.entity_id', $quotations));
                    }
                })
                ->orderBy('a.created_at')->orderBy('a.id')
                ->limit(500)
                ->get(['a.id', 'a.action', 'a.new_values', 'a.old_values', 'a.reason', 'a.created_at',
                    DB::raw("nullif(trim(coalesce(u.first_name, '') || ' ' || coalesce(u.last_name, '')), '') as actor_name")]);
        });
    }

    private function organizerMessage(object $r): string
    {
        $v = $this->values($r);
        $name = fn () => '“'.($v['name'] ?? 'item').'”';

        return match ($r->action) {
            'inquiry.created' => 'Client drafted the inquiry',
            'inquiry.submitted' => 'Client sent the inquiry',
            'quotation.created' => 'Quotation drafted',
            'quotation.submitted' => 'Quotation sent to the client',
            'quotation.revised' => 'Revised quotation sent',
            'quotation.withdrawn' => 'Quotation withdrawn',
            'quotation_accept' => 'Client accepted the quotation',
            'event.created' => 'Event created',
            'event.converted_from_inquiry' => 'Event created from the accepted quotation',
            'event.updated' => 'Event details updated',
            'event.published' => 'Event published',
            'event.unpublished' => 'Event moved back to draft',
            'event.started' => 'Event started',
            'event.stopped' => 'Event stopped and set back to not started'.($r->reason ? ': '.$r->reason : ''),
            'event.completed' => 'Event completed',
            'event.cancelled' => 'Event cancelled',
            'event.session_added' => 'Session added to the schedule',
            'event.session_updated' => 'Schedule updated',
            'event.session_removed' => 'Session removed from the schedule',
            'event.stage_reached' => 'Reached stage: '.($v['stage'] ?? ''),
            'event.readiness_overridden' => ($v['readiness_percent'] ?? null) === null
                ? 'Readiness set back to automatic'
                : 'Readiness set manually to '.rtrim(rtrim(number_format((float) $v['readiness_percent'], 2), '0'), '.').'%',
            'checklist.created' => 'Checklist '.$name().' added',
            'checklist.updated' => 'Checklist '.$name().' updated',
            'checklist.removed' => 'Checklist '.$name().' removed',
            'task.created' => 'Task '.$name().' added'.(isset($v['checklist']) ? ' to “'.$v['checklist'].'”' : ''),
            'task.updated' => 'Task '.$name().' updated',
            'task.status_changed' => $name().' marked '.str_replace('_', ' ', $v['status'] ?? ''),
            'task.removed' => 'Task '.$name().' removed',
            'checkin.undone' => 'A check-in was undone',
            'walkin.undone' => 'A walk-in entry was removed',
            'report.shared' => 'Post-event report shared with the client (version '.($v['version'] ?? '?').')',
            'walkin.capacity_override' => 'Admitted past capacity (over by '.($v['over_by'] ?? '?').')',
            default => ucfirst(str_replace(['.', '_'], ' ', $r->action)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function values(object $r): array
    {
        return is_string($r->new_values) ? (json_decode($r->new_values, true) ?? []) : [];
    }
}
