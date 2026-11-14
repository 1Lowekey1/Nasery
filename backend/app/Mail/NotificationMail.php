<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The e-mail copy of an in-app notification (B13). Sent by DeliverNotificationEmail. */
class NotificationMail extends Mailable
{
    public function __construct(
        public readonly string $heading,
        public readonly ?string $text,
        public readonly ?string $path,
        public readonly ?string $firstName,
        /** Sent to a buyer without an account: no settings link to offer. */
        public readonly bool $guest = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading.' | NaSeRy');
    }

    public function content(): Content
    {
        $frontend = (string) config('nasery.auth.frontend_url');

        return new Content(
            view: 'mail.notification',
            text: 'mail.notification-text',
            with: [
                'heading' => $this->heading,
                'text' => $this->text,
                'firstName' => $this->firstName,
                'link' => $this->path !== null ? $frontend.$this->path : null,
                'settings' => $this->guest ? null : $frontend.'/notifications?tab=settings',
            ],
        );
    }
}
