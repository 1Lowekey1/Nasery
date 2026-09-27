<?php

namespace App\Enums;

enum SocialProvider: string
{
    case Google = 'google';
    case Facebook = 'facebook';
    case Apple = 'apple';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isConfigured(): bool
    {
        $config = (array) config("services.{$this->value}", []);

        return filled($config['client_id'] ?? null)
            && filled($config['redirect'] ?? null)
            && ($this === self::Apple
                ? filled($config['client_secret'] ?? null) || filled($config['private_key'] ?? null)
                : filled($config['client_secret'] ?? null));
    }
}
