<?php

namespace App\Enums;

enum AuthCodePurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
    // Signing up again with the e-mail of an account no longer in use (e.g. a removed team member).
    case AccountConversion = 'account_conversion';

    public function label(): string
    {
        return match ($this) {
            self::EmailVerification => 'verify your email address',
            self::PasswordReset => 'reset your password',
            self::AccountConversion => 'switch your NaSeRy account to the new account type',
        };
    }
}
