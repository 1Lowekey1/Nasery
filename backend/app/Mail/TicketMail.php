<?php

namespace App\Mail;

use App\Support\Qr\QrImage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/**
 * Ticket e-mail (B13; paper: QR tickets delivered by e-mail). Each ticket's
 * QR is an inline PNG (CID, which Gmail shows; SVG isn't shown by mail
 * clients) and all tickets are attached as one PDF. Sent by SendTicketEmail.
 */
class TicketMail extends Mailable
{
    /**
     * @param  Collection<int, array<string, mixed>>  $cards  from TicketCards::load()
     */
    public function __construct(
        public readonly Collection $cards,
        public readonly string $pdf,
        public readonly string $kind,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function subjectLine(): string
    {
        $event = $this->cards->first()['event'] ?? 'your event';

        return $this->kind === 'ticket_reminder'
            ? "Tomorrow: {$event} — your ticket inside"
            : ($this->cards->count() > 1 ? "Your {$this->cards->count()} tickets for {$event}" : "Your ticket for {$event}");
    }

    public function content(): Content
    {
        $qr = app(QrImage::class);

        return new Content(
            view: 'mail.tickets',
            text: 'mail.tickets-text',
            with: [
                'cards' => $this->cards,
                'pngs' => $this->cards->mapWithKeys(fn ($c) => [$c['id'] => $c['token'] ? $qr->png($c['token'], 6) : null])->all(),
                'reminder' => $this->kind === 'ticket_reminder',
                'name' => $this->recipientName,
                'myTickets' => config('nasery.auth.frontend_url').'/my-tickets',
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($this->cards->first()['event'] ?? 'event'))), '-') ?: 'event';

        return [Attachment::fromData(fn () => $this->pdf, "nasery-tickets-{$slug}.pdf")->withMime('application/pdf')];
    }
}
