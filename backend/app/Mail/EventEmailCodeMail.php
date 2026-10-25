<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Guest ticket checkout: confirms an attendee's e-mail address before their
 * ticket is sent there. Sent right away, not queued (the code expires).
 */
class EventEmailCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly string $eventName,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your code for {$this->eventName}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.event-code',
            text: 'mail.event-code-text',
            with: ['code' => $this->code, 'eventName' => $this->eventName, 'minutes' => $this->minutes],
        );
    }
}
