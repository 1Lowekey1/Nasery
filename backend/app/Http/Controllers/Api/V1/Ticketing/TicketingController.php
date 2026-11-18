<?php

namespace App\Http\Controllers\Api\V1\Ticketing;

use App\Enums\AdmissionType;
use App\Enums\TicketStatus;
use App\Enums\TicketTypeStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Ticketing\GuestList;
use App\Services\Ticketing\TierManager;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\Tickets\TicketMailer;
use App\Services\Tickets\TicketCards;
use App\Services\Tickets\TicketPdf;

/**
 * Organizer ticketing for one event (B9). Tiers need ticket.manage; the
 * guest list needs attendee.manage (both organization-wide or on the
 * event, B8). Door staff with checkin.perform may read the list.
 * {ticketType} and {ticket} are scoped to {event}.
 */
class TicketingController extends Controller
{
    public function __construct(
        private readonly TierManager $tiers,
        private readonly GuestList $guests,
        private readonly OrganizationAccess $access,
        private readonly TicketMailer $mailer,
        private readonly TicketCards $cards,
        private readonly TicketPdf $pdf,
    ) {}

    public function tiers(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event);

        return ApiResponse::success([
            'tiers' => $this->tiers->list($event),
            'summary' => $this->guests->summary($event),
            'overall_capacity' => $event->overall_capacity,
            'can_manage' => $this->access->canOnEvent($request->user(), $event, 'ticket.manage'),
            'can_manage_guests' => $this->access->canOnEvent($request->user(), $event, 'attendee.manage'),
        ]);
    }

    public function storeTier(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event, 'ticket.manage', write: true);
        $this->tiers->create($event, $this->tierData($request, creating: true));

        return ApiResponse::created($this->tiers->list($event), 'Ticket tier added.');
    }

    public function updateTier(Request $request, Organization $organization, Event $event, TicketType $ticketType): JsonResponse
    {
        $this->check($request, $organization, $event, 'ticket.manage', write: true);
        $this->tiers->update($event, $ticketType, $this->tierData($request, creating: false));

        return ApiResponse::success($this->tiers->list($event), 'Ticket tier saved.');
    }

    public function destroyTier(Request $request, Organization $organization, Event $event, TicketType $ticketType): JsonResponse
    {
        $this->check($request, $organization, $event, 'ticket.manage', write: true);
        $this->tiers->delete($event, $ticketType);

        return ApiResponse::success($this->tiers->list($event), 'Ticket tier removed.');
    }

    public function guests(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event, ['attendee.manage', 'checkin.perform']);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['valid', 'used', 'cancelled', 'invalidated'])],
            'ticket_type_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->guests->list($event, $data, (int) ($data['page'] ?? 1));
        // B13: last ticket e-mail per guest (sent / failed / skipped and why).
        $emails = $this->mailer->latestFor($page->getCollection()->pluck('id')->all());
        $page->setCollection($page->getCollection()->map(fn ($g) => [...$g, 'email_delivery' => $emails[$g['id']] ?? null]));

        return ApiResponse::success($page, meta: ['summary' => $this->guests->summary($event)]);
    }

    public function addGuest(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $member = $this->check($request, $organization, $event, 'attendee.manage', write: true);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:254'],
            'contact_number' => ['nullable', 'string', 'max:40'],
            'ticket_type_id' => ['required', 'uuid'],
            'admission_type' => ['required', Rule::in(['invited', 'complimentary'])],
            'send_email' => ['sometimes', 'boolean'],
        ]);

        $tier = $this->tier($event, $data['ticket_type_id']);
        $ticket = $this->guests->add($event, $tier, $data, AdmissionType::from($data['admission_type']), $member, $request->boolean('send_email', true));

        return ApiResponse::created(['ticket_id' => $ticket->id, 'qr_token' => $this->guests->qrToken($ticket)], 'Guest added with a QR ticket.');
    }

    public function import(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $member = $this->check($request, $organization, $event, 'attendee.manage', write: true);
        $data = $request->validate([
            // Mock defense: Excel's own .xlsx too (first sheet), not only .csv.
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:1024'],
            'ticket_type_id' => ['required', 'uuid'],
            'admission_type' => ['required', Rule::in(['invited', 'complimentary'])],
            'send_email' => ['sometimes', 'boolean'],
        ]);

        $file = $data['file'];
        $csv = strtolower((string) $file->getClientOriginalExtension()) === 'xlsx'
            ? GuestList::xlsxToCsv($file->getRealPath())
            : (string) file_get_contents($file->getRealPath());
        $result = $this->guests->import($event, $csv,
            $this->tier($event, $data['ticket_type_id']), AdmissionType::from($data['admission_type']), $member, $request->boolean('send_email', true));

        return ApiResponse::created(['imported' => $result['imported']], "{$result['imported']} guests imported with QR tickets.");
    }

    public function export(Request $request, Organization $organization, Event $event): StreamedResponse
    {
        $this->check($request, $organization, $event, ['attendee.manage', 'checkin.perform']);
        $rows = $this->guests->export($event);
        $name = str($event->name)->slug()->limit(60, '')->append('-guests.csv');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
            fputcsv($out, ['Name', 'Email', 'Contact', 'Tier', 'Zone', 'Seat', 'Status', 'Admission', 'Source']);
            foreach ($rows as $r) {
                // Neutralize spreadsheet formulas in user-provided cells.
                $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
                $row = array_map($safe, [$r['name'], $r['email'], $r['contact_number'], $r['tier'], $r['zone'], $r['seat'], $r['status'], $r['admission_type'], $r['source']]);
                // 0917-123-4567: stays text in Excel through every save, and Bulk Import reads it back.
                $row[2] = GuestList::spreadsheetPhone($r['contact_number']);
                fputcsv($out, $row);
            }
            fclose($out);
        }, (string) $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function qr(Request $request, Organization $organization, Event $event, Ticket $ticket): JsonResponse
    {
        $this->check($request, $organization, $event, 'attendee.manage');

        return ApiResponse::success(['ticket_id' => $ticket->id, 'status' => $ticket->status->value, 'qr_token' => $this->guests->qrToken($ticket)]);
    }

    /** B13: printable ticket (QR + details) for a guest who needs a paper copy. */
    public function ticketPdf(Request $request, Organization $organization, Event $event, Ticket $ticket): Response
    {
        $this->check($request, $organization, $event, ['attendee.manage', 'checkin.perform']);

        if ($request->query('format') === 'png') {
            return $this->ticketPng($ticket->id);
        }

        return response($this->pdf->render($this->cards->load([$ticket->id])), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="ticket-'.strtolower(substr(str_replace('-', '', $ticket->id), -8)).'.pdf"',
        ]);
    }

    /** "Save image": one ticket as a PNG (QR with the event, type, name and seat on it). */
    private function ticketPng(string $ticketId): Response
    {
        $card = $this->cards->load([$ticketId])->first()
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

        return response(app(\App\Support\Qr\TicketImage::class)->png($card), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="ticket-'.strtolower($card['code']).'.png"',
        ]);
    }

    /** B13: (re)send a guest's ticket e-mail. */
    public function emailTicket(Request $request, Organization $organization, Event $event, Ticket $ticket): JsonResponse
    {
        $this->check($request, $organization, $event, 'attendee.manage', write: true);

        if (! in_array($ticket->status, [TicketStatus::Valid, TicketStatus::Used], true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a valid ticket can be e-mailed.', 409);
        }

        $result = $this->mailer->send([$ticket->id]);
        $delivery = $this->mailer->latestFor([$ticket->id])[$ticket->id] ?? null;

        return ApiResponse::success(['email_delivery' => $delivery],
            $result['queued'] > 0 ? 'Ticket e-mail on its way.' : 'Not sent: '.($delivery['error'] ?? 'no e-mail address.'));
    }

    public function reissueQr(Request $request, Organization $organization, Event $event, Ticket $ticket): JsonResponse
    {
        $this->check($request, $organization, $event, 'attendee.manage', write: true);

        return ApiResponse::success(['ticket_id' => $ticket->id, 'qr_token' => $this->guests->reissueQr($event, $ticket)], 'New QR code issued; the old one no longer works.');
    }

    /** 0058: an optional seat or table label. Empty clears it. */
    public function seat(Request $request, Organization $organization, Event $event, Ticket $ticket): JsonResponse
    {
        $this->check($request, $organization, $event, 'attendee.manage', write: true);
        $data = $request->validate(['seat' => ['nullable', 'string', 'max:30']]);
        $this->guests->setSeat($event, $ticket, $data['seat'] ?? null);

        return ApiResponse::success(['ticket_id' => $ticket->id], 'Seat saved.');
    }

    public function cancelTicket(Request $request, Organization $organization, Event $event, Ticket $ticket): JsonResponse
    {
        $this->check($request, $organization, $event, 'attendee.manage', write: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->guests->cancel($event, $ticket, $data['reason']);

        return ApiResponse::success(['ticket_id' => $ticket->id, 'status' => TicketStatus::Cancelled->value], 'Ticket cancelled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function tierData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'zone_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'benefits' => ['sometimes', 'array', 'max:12'],
            'benefits.*' => ['nullable', 'string', 'max:120'], // blanks are dropped
            'price' => [$required, 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'capacity' => [$required, 'integer', 'min:1', 'max:1000000'],
            'sales_start_at' => ['sometimes', 'nullable', 'date'],
            'sales_end_at' => ['sometimes', 'nullable', 'date', 'after:sales_start_at'],
            'status' => ['sometimes', Rule::enum(TicketTypeStatus::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
    }

    private function tier(Event $event, string $id): TicketType
    {
        return TicketType::query()->where('event_id', $event->id)->find($id)
            ?? throw new ApiException(ErrorCode::ValidationError, 'The given data was invalid.', 422, ['fields' => ['ticket_type_id' => ['Choose one of this event\'s tiers.']]]);
    }

    /**
     * @param  string|list<string>|null  $permission  any of these
     */
    private function check(Request $request, Organization $organization, Event $event, string|array|null $permission = null, bool $write = false): OrganizationMember
    {
        $member = $this->access->membership($request->user(), $organization);

        if ($member === null || $event->organization_id !== $organization->id || ! $this->access->canSeeEvent($request->user(), $event)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        if ($permission !== null && ! collect((array) $permission)->contains(fn ($p) => $this->access->canOnEvent($request->user(), $event, $p))) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($write && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to manage tickets.', 403);
        }

        return $member;
    }
}
