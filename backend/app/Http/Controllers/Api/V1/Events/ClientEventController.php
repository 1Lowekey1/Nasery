<?php

namespace App\Http\Controllers\Api\V1\Events;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use App\Support\Http\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Services\CheckIn\CheckInDesk;
use App\Services\Events\EventActivities;
use App\Services\Events\EventPresenter;
use App\Services\Preparation\ActivityTimeline;
use App\Services\Preparation\ProgressTracker;
use App\Services\Ticketing\GuestList;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Reports\ReportSharing;
use App\Services\Reports\ReportPdf;

/**
 * Events created from the client's accepted inquiries (read-only).
 * RLS (0034 events_client_read) limits rows to the client's own events.
 */
class ClientEventController extends Controller
{
    public function __construct(
        private readonly EventPresenter $presenter,
        private readonly ActivityTimeline $timeline,
        private readonly EventActivities $activities,
        private readonly ProgressTracker $progress,
        private readonly CheckInDesk $checkIn,
        private readonly ReportSharing $sharing,
        private readonly ReportPdf $reportPdf,
        private readonly GuestList $guests,
    ) {}

    public function index(): JsonResponse
    {
        $events = Event::query()->whereNotNull('inquiry_id')->orderByDesc('created_at')->limit(100)->get();

        return ApiResponse::success($this->presenter->forClient($events));
    }

    public function show(Event $event): JsonResponse
    {
        return ApiResponse::success($this->presenter->forClient(collect([$event]))->first());
    }

    /**
     * Client milestone view (paper: simplified timeline): readiness, the
     * stage track and whitelisted milestones. No task names or staff.
     */
    public function progress(Event $event): JsonResponse
    {
        $presented = $this->presenter->forClient(collect([$event]))->first();

        return ApiResponse::success([
            'readiness_percent' => $presented['readiness_percent'],
            'stage' => $presented['stage'],
            'stages' => $this->progress->stages()->keys()->values(),
            'event_status' => $presented['status'],
            'milestones' => $this->timeline->forClient($event, $presented['organizer']['display_name'] ?? 'Your organizer'),
            // 0049: program items the organizer marked "visible to client".
            'program' => $this->activities->forClient($event),
        ]);
    }

    /**
     * Live check-in progress for the client's own event (paper: client-facing
     * live attendance view). Numbers only; no guest names.
     */
    /** A private event's guest list for its client (read-only, no contact details). */
    public function guests(Event $event): JsonResponse
    {
        return ApiResponse::success($this->guests->forClient($event));
    }

    public function attendance(Event $event): JsonResponse
    {
        return ApiResponse::success($this->checkIn->forClient($event));
    }

    /**
     * B14: the post-event report(s) the organizer shared (0037 RLS: this
     * client only). `version` defaults to the latest.
     */
    public function report(Request $request, Event $event): JsonResponse
    {
        $version = $request->integer('version') ?: null;

        return ApiResponse::success([
            'shared' => $this->sharing->shared($event, $version),
            'versions' => $this->sharing->versions($event)->map(fn ($v) => collect($v)->except('shared_by'))->values(),
        ]);
    }

    public function reportPdf(Request $request, Event $event): Response
    {
        $shared = $this->sharing->shared($event, $request->integer('version') ?: null)
            ?? throw new ApiException(ErrorCode::NotFound, 'Your organizer has not shared a report yet.', 404);

        return response($this->reportPdf->render($shared['report'], $shared), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.ReportPdf::fileName($shared['report']).'-v'.$shared['version'].'.pdf"',
        ]);
    }
}
