<?php

namespace App\Services\Notifications;

use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail for buyers without an account (guest checkout): they have no bell
 * or My Tickets, so refunds and cancellations reach them only by e-mail, at
 * the address they bought with. MailGuard rules apply (test addresses never
 * get mail). A failure to send never undoes the refund or cancellation.
 */
class GuestMail
{
    public function __construct(private readonly MailGuard $guard) {}

    public function send(?string $email, ?string $name, string $heading, string $text): bool
    {
        $email = trim((string) $email);
        if (! $this->guard->allows($email)) {
            return false;
        }

        $first = trim(explode(' ', trim((string) $name))[0] ?? '') ?: null;
        try {
            Mail::to($email)->queue(new NotificationMail($heading, $text, null, $first, guest: true));
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
