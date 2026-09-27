<?php

namespace App\Services\Notifications;

/**
 * Who may receive real e-mail (B13, real sending is on). Reserved test
 * domains never do (seed/E2E data), and MAIL_ONLY_TO narrows everything to
 * an allowlist for rehearsals. Skipped mail is logged as "suppressed".
 */
class MailGuard
{
    public function allows(?string $email): bool
    {
        $email = strtolower(trim((string) $email));

        // Switched off for a whole run (e.g. while the demo dataset is being built).
        if (config('nasery.mail.enabled', true) === false) {
            return false;
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        foreach ((array) config('nasery.mail.never_to', []) as $blocked) {
            if (str_ends_with($email, strtolower($blocked))) {
                return false;
            }
        }

        $only = array_map('strtolower', (array) config('nasery.mail.only_to', []));

        if ($only === []) {
            return true;
        }

        foreach ($only as $entry) {
            if ($entry === $email || (str_starts_with($entry, '@') && str_ends_with($email, $entry))) {
                return true;
            }
        }

        return false;
    }

    /** Why a recipient was skipped, for the delivery log. */
    public function reason(?string $email): string
    {
        if (config('nasery.mail.enabled', true) === false) {
            return 'Not sent: e-mail switched off for this run.';
        }
        if (trim((string) $email) === '') {
            return 'No e-mail address.';
        }

        return filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL)
            ? 'Not sent: test address or outside MAIL_ONLY_TO.'
            : 'Invalid e-mail address.';
    }
}
