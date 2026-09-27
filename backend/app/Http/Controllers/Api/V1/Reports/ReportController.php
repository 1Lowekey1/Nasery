<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Billing\Entitlements;
use App\Services\Reports\AttendanceReport;
use App\Services\Reports\ReportPdf;
use App\Services\Reports\ReportSharing;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * B14 organizer report for one event: the live report (JSON, PDF, CSV)
 * and sharing a frozen copy with the client. Viewing needs event.update or
 * attendee.manage; the revenue section needs finance.view; sharing needs
 * event.update (and finance.view to include revenue).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly AttendanceReport $reports,
        private readonly ReportSharing $sharing,
        private readonly ReportPdf $pdf,
        private readonly OrganizationAccess $access,
        private readonly Entitlements $entitlements,
    ) {}

    public function show(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event);

        return ApiResponse::success([
            ...$this->reports->build($event, $this->canSeeRevenue($request, $event)),
            'shares' => $this->sharing->versions($event),
            'can' => [
                'revenue' => $this->canSeeRevenue($request, $event),
                'share' => $this->access->canOnEvent($request->user(), $event, 'event.update') && $event->inquiry_id !== null,
                'has_client' => $event->inquiry_id !== null,
            ],
        ]);
    }

    public function pdf(Request $request, Organization $organization, Event $event): Response
    {
        $this->check($request, $organization, $event);
        $report = $this->reports->build($event, $this->canSeeRevenue($request, $event));

        return response($this->pdf->render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.ReportPdf::fileName($report).'.pdf"',
        ]);
    }

    public function csv(Request $request, Organization $organization, Event $event): StreamedResponse
    {
        $this->check($request, $organization, $event);
        $this->entitlements->assertFeature($organization, 'advanced_reports'); // B18: PDF stays free
        $report = $this->reports->build($event, false);
        $labels = ['attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];
        $sources = ['online' => 'Online', 'organizer' => 'Guest list', 'staff' => 'Staff', 'walk_in' => 'Door sale'];

        return response()->streamDownload(function () use ($report, $labels, $sources) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
            // Neutralize spreadsheet formulas in user-provided cells.
            $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
            fputcsv($out, ['Name', 'Email', 'Tier', 'Zone', 'Source', 'Status', 'First check-in', 'Sessions attended', 'Method']);
            foreach ($report['attendees'] as $a) {
                fputcsv($out, array_map($safe, [$a['name'], $a['email'], $a['tier'], $a['zone'], $sources[$a['source']] ?? $a['source'],
                    $labels[$a['status']], $a['first_check_in'], $a['sessions_attended'], $a['method']]));
            }
            foreach ($report['walk_in_names'] as $w) {
                fputcsv($out, array_map($safe, [$w['name'], null, null, null, 'Walk-in (no ticket)', 'Attended', $w['at'], 1, 'walk-in']));
            }
            $headcount = $report['totals']['walk_ins'] - count($report['walk_in_names']);
            if ($headcount > 0) {
                fputcsv($out, ["(headcount: {$headcount} people)", null, null, null, 'Walk-in (no ticket)', 'Attended', null, null, 'headcount']);
            }
            fclose($out);
        }, ReportPdf::fileName($report, 'attendance').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function share(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event, 'event.update');
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'include_revenue' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('include_revenue') && ! $this->canSeeRevenue($request, $event)) {
            throw new ApiException(ErrorCode::Forbidden, 'Only someone with the Finance permission can share revenue.', 403);
        }

        return ApiResponse::created($this->sharing->share($event, $request->user(), filled($data['note'] ?? null) ? trim($data['note']) : null,
            $request->boolean('include_revenue')), 'Report shared with the client.');
    }

    private function canSeeRevenue(Request $request, Event $event): bool
    {
        return $this->access->canOnEvent($request->user(), $event, 'finance.view');
    }

    private function check(Request $request, Organization $organization, Event $event, ?string $permission = null): void
    {
        if ($event->organization_id !== $organization->id || ! $this->access->canSeeEvent($request->user(), $event)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        $allowed = $permission !== null
            ? $this->access->canOnEvent($request->user(), $event, $permission)
            : $this->access->canOnEvent($request->user(), $event, 'event.update') || $this->access->canOnEvent($request->user(), $event, 'attendee.manage');

        if (! $allowed) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }
    }
}
