<?php

namespace App\Mail;

use App\Enums\AuthCodePurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sign-up / sign-in / password codes. Sent right away, not queued: the code
 * expires in minutes and must not wait for a queue worker.
 */
class AuthCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly AuthCodePurpose $purpose,
        public readonly string $firstName,
        // Set when an admin added this person as a NaSeRy admin: the reset code sets their first password.
        public readonly ?string $invitedBy = null,
    ) {}

    public function envelope(): Envelope
    {
        if ($this->invitedBy !== null) {
            return new Envelope(subject: "{$this->invitedBy} added you as a NaSeRy administrator");
        }

        return new Envelope(subject: match ($this->purpose) {
            AuthCodePurpose::EmailVerification => 'Your NaSeRy verification code',
            AuthCodePurpose::PasswordReset => 'Your NaSeRy password reset code',
        });
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.auth-code',
            text: 'mail.auth-code-text',
            with: [
                'code' => $this->code,
                'action' => $this->purpose->label(),
                'firstName' => $this->firstName,
                'minutes' => config('nasery.auth.codes.ttl_minutes'),
                'isReset' => $this->purpose === AuthCodePurpose::PasswordReset,
                'invitedBy' => $this->invitedBy,
                'resetUrl' => rtrim((string) config('nasery.auth.frontend_url'), '/').'/forgot-password',
            ],
        );
    }
}
