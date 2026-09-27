<?php

namespace App\Services\Reports;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\User;
use App\Services\Notifications\Alerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * B14: the organizer reviews the live report, then shares a frozen copy
 * with the client (paper: "review and approve the reports before sending
 * them to clients"). Each share is a new immutable version (0037).
 *
 * The client's copy never carries revenue unless the organizer ticks it
 * (user). Guest names are included only for private events (the client's
 * own guests); public events share numbers only, since ticket buyers are
 * strangers to the client.
 */
class ReportSharing
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AttendanceReport $reports,
        private readonly AuditLogger $audit,
        private readonly Alerts $alerts,
    ) {}

    public function share(Event $event, User $by, ?string $note, bool $includeRevenue): array
    {
        if ($event->status !== EventStatus::Completed) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Share the report once the event is completed.', 409);
        }

        if ($event->inquiry_id === null) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'This event has no client to share the report with (it was not created from an inquiry).', 409);
        }

        $snapshot = $this->reports->build($event, $includeRevenue, withAttendees: $event->visibility === 'private');
        $row = $this->db->system('report.share', function () use ($event, $by, $note, $includeRevenue, $snapshot) {
            DB::table('events')->where('id', $event->id)->lockForUpdate()->value('id');
            $version = (int) DB::table('event_reports')->where('event_id', $event->id)->max('version') + 1;
            $id = (string) Str::uuid7();
            DB::table('event_reports')->insert([
                'id' => $id, 'event_id' => $event->id, 'organization_id' => $event->organization_id, 'version' => $version,
                'snapshot' => json_encode($snapshot), 'note' => $note, 'includes_revenue' => $includeRevenue,
                'shared_by' => $by->id, 'shared_at' => now(), 'created_at' => now(),
            ]);

            return ['id' => $id, 'version' => $version];
        });

        $this->audit->record('report.shared', ['event_reports', $row['id']], new: ['event_id' => $event->id, 'version' => $row['version'], 'includes_revenue' => $includeRevenue],
            organizationId: $event->organization_id);
        $this->alerts->reportShared($event, $row['version']);

        return $this->versions($event)->first();
    }

    /**
     * Shared versions, newest first (without the snapshot).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function versions(Event $event): Collection
    {
        return $this->db->system('report.versions', fn () => DB::table('event_reports as r')->join('users as u', 'u.id', '=', 'r.shared_by')
            ->where('r.event_id', $event->id)->orderByDesc('r.version')
            ->get(['r.version', 'r.note', 'r.includes_revenue', 'r.shared_at', DB::raw("trim(concat(u.first_name, ' ', u.last_name)) as shared_by")])
            ->map(fn ($r) => [
                'version' => (int) $r->version, 'note' => $r->note, 'includes_revenue' => (bool) $r->includes_revenue,
                'shared_by' => $r->shared_by, 'shared_at' => CarbonImmutable::parse($r->shared_at)->toIso8601String(),
            ]));
    }

    /**
     * A shared version as the client sees it (null version = latest). Read
     * under the caller's RLS, so only the event's client (or its team) gets it.
     *
     * @return array{version: int, note: ?string, shared_at: string, report: array<string, mixed>}|null
     */
    public function shared(Event $event, ?int $version = null): ?array
    {
        $row = DB::table('event_reports')->where('event_id', $event->id)
            ->when($version !== null, fn ($q) => $q->where('version', $version), fn ($q) => $q->orderByDesc('version'))
            ->first(['version', 'note', 'shared_at', 'snapshot']);

        return $row === null ? null : [
            'version' => (int) $row->version,
            'note' => $row->note,
            'shared_at' => CarbonImmutable::parse($row->shared_at)->toIso8601String(),
            'report' => json_decode($row->snapshot, true),
        ];
    }
}
