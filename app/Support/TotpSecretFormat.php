<?php

namespace App\Support;

final class TotpSecretFormat
{
    private const MIN_LENGTH = 16;

    private const MAX_LENGTH = 255;

    /**
     * Whether a value can be a legacy RFC 4648 Base32 TOTP secret.
     */
    public static function isValid(string $secret): bool
    {
        if (strlen($secret) > self::MAX_LENGTH) {
            return false;
        }

        $normalized = preg_replace('/\s+/', '', $secret);

        return is_string($normalized)
            && strlen($normalized) >= self::MIN_LENGTH
            && strlen($normalized) <= self::MAX_LENGTH
            && preg_match('/\A[A-Z2-7]+={0,6}\z/i', $normalized) === 1;
    }
}
