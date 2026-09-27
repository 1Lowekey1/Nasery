<?php

namespace App\Jobs;

use App\Mail\TicketMail;
use App\Services\Tickets\TicketCards;
use App\Services\Tickets\TicketPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one logged ticket e-mail (laravel.mail_deliveries) with inline QR
 * codes and the PDF, then records sent / failed. Tickets cancelled since
 * the e-mail was queued are left out; if none remain, nothing is sent.
 */
class SendTicketEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(TicketCards $cards, TicketPdf $pdf): void
    {
        $row = DB::connection('system')->table('mail_deliveries')->where('id', $this->deliveryId)->first();

        if ($row === null || in_array($row->status, ['sent', 'suppressed'], true)) {
            return;
        }

        $tickets = $cards->load(json_decode($row->ticket_ids, true))->filter(fn ($c) => $c['token'] !== null)->values();

        if ($tickets->isEmpty()) {
            $this->record('failed', 'The tickets are no longer valid.');

            return;
        }

        Mail::to($row->recipient)->send(new TicketMail($tickets, $pdf->render($tickets), $row->kind, (string) $tickets->first()['recipient_name']));
        $this->record('sent');
    }

    public function failed(?Throwable $e): void
    {
        $this->record('failed', $e?->getMessage());
    }

    private function record(string $status, ?string $error = null): void
    {
        DB::connection('system')->table('mail_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status,
            'attempts' => DB::raw('attempts + 1'),
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'sent_at' => $status === 'sent' ? now() : null,
            'updated_at' => now(),
        ]);
    }
}
