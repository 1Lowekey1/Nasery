<?php

namespace App\Services\Ticketing;

use App\Enums\AdmissionType;
use Carbon\CarbonImmutable;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\OrganizationMember;
use App\Models\QrTicket;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\Notifications\Alerts;

/**
 * The organizer's attendee list (paper: "import a guest list from a
 * spreadsheet and the system generates individual QR-coded tickets per
 * guest"). Guests are added one by one or imported from CSV; each gets a
 * confirmed organizer registration and a valid ticket with a QR code.
 * Issuance runs in the system context (attendees have no RLS write path)
 * after the controller's attendee.manage check.
 */
class GuestList
{
    public const IMPORT_LIMIT = 1000;

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly CapacityGuard $capacity,
        private readonly TicketIssuer $issuer,
        private readonly QrTokens $qr,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @param  array{full_name: string, email?: ?string, contact_number?: ?string}  $guest
     */
    public function add(Event $event, TicketType $tier, array $guest, AdmissionType $admission, OrganizationMember $by, bool $sendEmail = true): Ticket
    {
        return $this->addMany($event, [[...$guest, 'tier' => $tier]], $admission, $by, $sendEmail)->first();
    }

    /**
     * CSV columns (header row, case-insensitive): name, email, contact, tier.
     * All-or-nothing: any invalid row or insufficient capacity rejects the
     * whole file, with every problem listed.
     *
     * @return array{imported: int, tickets: Collection<int, Ticket>}
     */
    public function import(Event $event, string $csv, TicketType $defaultTier, AdmissionType $admission, OrganizationMember $by, bool $sendEmail = true): array
    {
        $rows = $this->parseCsv($csv);
        $tiers = TicketType::query()->where('event_id', $event->id)->get();
        $errors = [];
        $guests = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // header is line 1
            $v = Validator::make($row, [
                'name' => ['required', 'string', 'min:2', 'max:120'],
                'email' => ['nullable', 'email', 'max:254'],
                'contact' => ['nullable', 'string', 'max:40'],
            ]);

            if ($v->fails()) {
                $errors[] = "Line {$line}: ".implode(' ', $v->errors()->all());

                continue;
            }

            $tier = $defaultTier;
            if (filled($row['tier'] ?? null)) {
                $tier = $tiers->first(fn (TicketType $t) => Str::lower($t->name) === Str::lower(trim($row['tier'])));

                if ($tier === null) {
                    $errors[] = "Line {$line}: no tier called “{$row['tier']}”.";

                    continue;
                }
            }

            $guests[] = ['full_name' => $row['name'], 'email' => $row['email'] ?? null, 'contact_number' => $row['contact'] ?? null, 'tier' => $tier];
        }

        if ($rows !== [] && ! array_key_exists('name', $rows[0])) {
            $errors = ['The first row needs a "name" column (or "first name" and "last name"). Download the template to see the format.'];
        }

        if ($guests === [] && $errors === []) {
            $errors[] = 'The file has no guests. The first row must be the column names (name, email, contact, tier), then one guest per row.';
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::ValidationError, 'The guest list has problems; nothing was imported.', 422, ['fields' => ['file' => array_slice($errors, 0, 50)]]);
        }

        $tickets = $this->addMany($event, $guests, $admission, $by, $sendEmail);

        return ['imported' => $tickets->count(), 'tickets' => $tickets];
    }

    public function cancel(Event $event, Ticket $ticket, string $reason): void
    {
        $this->db->system('tickets.cancel', function () use ($ticket, $reason) {
            $locked = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();

            if ($locked->status !== TicketStatus::Valid) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a valid (unused) ticket can be cancelled.', 409);
            }

            $locked->forceFill(['status' => TicketStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason])->save();
            DB::table('qr_tickets')->where('ticket_id', $ticket->id)
                ->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'ticket cancelled']);
            $this->issuer->refreshSoldCount($locked->ticket_type_id);
        });

        $this->audit->record('ticket.cancelled', $ticket, new: ['event_id' => $event->id], organizationId: $event->organization_id, reason: $reason);
        $this->alerts->ticketsCancelled([$ticket->id], $reason);
    }

    /** Replace a (possibly leaked) QR code; the old one stops working. */
    public function reissueQr(Event $event, Ticket $ticket): string
    {
        if (! in_array($ticket->status, [TicketStatus::Valid, TicketStatus::Used], true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a valid ticket can get a new QR code.', 409);
        }

        $qr = $this->db->system('tickets.reissue_qr', fn () => $this->issuer->issueQr($ticket));
        $this->audit->record('ticket.qr_reissued', $ticket, new: ['event_id' => $event->id], organizationId: $event->organization_id);

        return $this->qr->token($qr);
    }

    public function qrToken(Ticket $ticket): ?string
    {
        $qr = $this->db->system('tickets.qr', fn () => QrTicket::query()->where('ticket_id', $ticket->id)->where('status', 'active')->first());

        return $qr ? $this->qr->token($qr) : null;
    }

    /**
     * @param  array{search?: ?string, status?: ?string, ticket_type_id?: ?string}  $filters
     */
    public function list(Event $event, array $filters, int $page, int $perPage = 25): LengthAwarePaginator
    {
        $page = $this->query($event, $filters)->paginate(perPage: $perPage, page: $page);
        $times = $this->checkInTimes($page->getCollection()->pluck('id')->all());

        return $page->through(fn ($r) => $this->present($r, $times[$r->id] ?? null));
    }

    /**
     * Latest check-in per ticket (the attendance report's "Check-in time"). Attendance
     * rows are readable only with checkin.perform; the caller already holds attendee
     * access to this event's list, so read just these tickets' times in system context.
     *
     * @param  list<string>  $ticketIds
     * @return array<string, string>
     */
    private function checkInTimes(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        return $this->db->system('tickets.checkin_times', fn () => DB::table('attendance_records')->whereIn('ticket_id', $ticketIds)
            ->groupBy('ticket_id')->selectRaw('ticket_id, max(checked_in_at) as at')->pluck('at', 'ticket_id')
            ->map(fn ($at) => CarbonImmutable::parse($at)->toIso8601String())->all());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function export(Event $event): Collection
    {
        $rows = $this->query($event, [])->limit(20000)->get();
        $times = $this->checkInTimes($rows->pluck('id')->all());

        return $rows->map(fn ($r) => $this->present($r, $times[$r->id] ?? null));
    }

    /**
     * @return array{total: int, valid: int, used: int, cancelled: int}
     */
    public function summary(Event $event): array
    {
        $c = DB::table('tickets')->where('event_id', $event->id)->whereNull('deleted_at')
            ->selectRaw("count(*) filter (where status in ('valid','used')) as total,
                count(*) filter (where status = 'valid') as valid,
                count(*) filter (where status = 'used') as used,
                count(*) filter (where status = 'cancelled') as cancelled")->first();

        return ['total' => (int) $c->total, 'valid' => (int) $c->valid, 'used' => (int) $c->used, 'cancelled' => (int) $c->cancelled];
    }

    /**
     * @param  list<array<string, mixed>>  $guests  each with a 'tier' TicketType
     * @return Collection<int, Ticket>
     */
    private function addMany(Event $event, array $guests, AdmissionType $admission, OrganizationMember $by, bool $sendEmail = true): Collection
    {
        if ($event->isTerminal()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This event has ended; guests can no longer be added.', 409);
        }

        if (count($guests) > self::IMPORT_LIMIT) {
            throw new ApiException(ErrorCode::ValidationError, 'The given data was invalid.', 422, ['fields' => ['file' => ['Import at most '.self::IMPORT_LIMIT.' guests at a time.']]]);
        }

        $tickets = $this->db->system('tickets.guest_list', function () use ($event, $guests, $admission, $by) {
            // Lock tiers in a stable order, then check each tier's total.
            $byTier = collect($guests)->groupBy(fn ($g) => $g['tier']->id)->sortKeys();
            foreach ($byTier as $group) {
                $this->capacity->reserve($event, $group->first()['tier'], $group->count());
            }

            return collect($guests)->map(function (array $guest) use ($event, $admission, $by) {
                $registration = Registration::query()->forceCreate([
                    'id' => (string) Str::uuid7(),
                    'event_id' => $event->id,
                    'purchaser_name' => trim($guest['full_name']),
                    'purchaser_email' => $guest['email'] ?? null,
                    'purchaser_contact' => $guest['contact_number'] ?? null,
                    'registration_source' => RegistrationSource::Organizer,
                    'status' => RegistrationStatus::Confirmed,
                    'created_by_member_id' => $by->id,
                ]);

                return $this->issuer->issue($registration, $guest['tier'], [$guest], $admission)->first();
            });
        });

        $this->audit->record('ticket.guests_added', ['events', $event->id],
            new: ['event_id' => $event->id, 'count' => $tickets->count(), 'admission' => $admission->value], organizationId: $event->organization_id);

        // B13: e-mail each guest their QR ticket (guests without an address are logged as skipped).
        if ($sendEmail) {
            $this->alerts->ticketsIssued($tickets->pluck('id')->all(), null, $event->name);
        }
        $this->alerts->capacity($event);

        return $tickets;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(Event $event, array $filters)
    {
        return DB::table('tickets as t')
            ->join('attendees as a', 'a.id', '=', 't.attendee_id')
            ->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
            ->join('registrations as r', 'r.id', '=', 't.registration_id')
            ->where('t.event_id', $event->id)->whereNull('t.deleted_at')
            ->where('t.status', '!=', TicketStatus::Pending->value)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('t.status', $s))
            ->when($filters['ticket_type_id'] ?? null, fn ($q, $id) => $q->where('t.ticket_type_id', $id))
            ->when(filled($filters['search'] ?? null), function ($q) use ($filters) {
                $term = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
                $q->where(fn ($w) => $w->where('a.full_name', 'ilike', $term)->orWhere('a.email', 'ilike', $term));
            })
            ->orderBy('a.full_name')->orderBy('t.id')
            ->select(['t.id', 't.status', 't.admission_type', 't.issued_at', 't.cancellation_reason', 'a.full_name', 'a.email', 'a.contact_number',
                'tt.name as tier', 'tt.zone_name', 'r.registration_source']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $r, ?string $checkedInAt = null): array
    {
        return [
            'id' => $r->id,
            'name' => $r->full_name,
            'email' => $r->email,
            'contact_number' => $r->contact_number,
            'tier' => $r->tier,
            'zone' => $r->zone_name,
            'status' => $r->status,
            'admission_type' => $r->admission_type,
            'source' => $r->registration_source,
            'issued_at' => $r->issued_at,
            'checked_in_at' => $checkedInAt,
            'cancellation_reason' => $r->cancellation_reason,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv); // Excel BOM
        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $csv), fn ($l) => trim($l) !== ''));

        if ($lines === []) {
            return [];
        }

        // Excel saves "CSV" with ; (or tabs) in some regional settings: use whichever splits the header best.
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($lines[0], $d))->first();

        $aliases = ['full name' => 'name', 'full_name' => 'name', 'guest' => 'name', 'guest name' => 'name', 'attendee' => 'name',
            'e-mail' => 'email', 'email address' => 'email', 'phone' => 'contact', 'contact number' => 'contact', 'mobile' => 'contact',
            'mobile number' => 'contact', 'phone number' => 'contact', 'ticket' => 'tier', 'ticket type' => 'tier', 'ticket tier' => 'tier', 'zone' => 'tier',
            'first name' => 'first_name', 'firstname' => 'first_name', 'given name' => 'first_name',
            'last name' => 'last_name', 'lastname' => 'last_name', 'surname' => 'last_name', 'family name' => 'last_name'];
        $header = array_map(fn ($h) => $aliases[Str::lower(trim((string) $h))] ?? Str::lower(trim((string) $h)), str_getcsv(array_shift($lines), $delimiter));

        return array_map(function (string $line) use ($header, $delimiter) {
            $cells = array_pad(array_slice(str_getcsv($line, $delimiter), 0, count($header)), count($header), '');
            $row = array_combine($header, array_map(fn ($v) => trim((string) $v), $cells));
            // "First name" + "Last name" columns instead of one "name" column.
            if (blank($row['name'] ?? null) && (array_key_exists('first_name', $row) || array_key_exists('last_name', $row))) {
                $row['name'] = trim(($row['first_name'] ?? '').' '.($row['last_name'] ?? ''));
            }

            return $row;
        }, $lines);
    }
}
