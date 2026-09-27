<?php

namespace App\Enums;

enum AuthCodePurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';

    public function label(): string
    {
        return match ($this) {
            self::EmailVerification => 'verify your email address',
            self::PasswordReset => 'reset your password',
        };
    }
}
