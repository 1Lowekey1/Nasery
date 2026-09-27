<?php

namespace App\Http\Requests\Concerns;

/**
 * Accepts common Philippine mobile formats (0917..., 917..., 63917...,
 * +63 917 ...) and normalizes them to E.164 (+639XXXXXXXXX). Anything else
 * is passed through unchanged so validation can reject it.
 */
trait NormalizesPhone
{
    protected function normalizePhone(mixed $phone): mixed
    {
        if (! is_string($phone)) {
            return $phone;
        }

        $digits = preg_replace('/[\s\-().]+/', '', trim($phone));

        return match (true) {
            $digits === '' => null,
            (bool) preg_match('/^\+639\d{9}$/', $digits) => $digits,
            (bool) preg_match('/^639\d{9}$/', $digits) => '+'.$digits,
            (bool) preg_match('/^09\d{9}$/', $digits) => '+63'.substr($digits, 1),
            (bool) preg_match('/^9\d{9}$/', $digits) => '+63'.$digits,
            default => $phone,
        };
    }
}
