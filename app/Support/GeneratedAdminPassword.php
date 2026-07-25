<?php

namespace App\Support;

use RuntimeException;

/**
 * Generates the initial administrator password when the operator supplies none.
 *
 * This value is read off a terminal and typed by hand, so the alphabet omits every character pair
 * that is ambiguous in common fonts, and the result is grouped with hyphens. Length carries the
 * entropy instead of character-class rules: `AccountPasswordPolicy` enforces length only, so
 * complexity requirements would add friction without adding strength.
 */
final class GeneratedAdminPassword
{
    /** No 0/O, no 1/I/l. 31 symbols, ~4.95 bits each. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** 20 symbols is about 99 bits, comfortably inside the 12-255 character policy. */
    private const LENGTH = 20;

    private const GROUP_SIZE = 5;

    private const MAX_ATTEMPTS = 5;

    /**
     * A password that is guaranteed to satisfy the bootstrap credential rules.
     *
     * Generation is cheap and validation is the authority, so a rejected candidate is discarded
     * rather than patched. Exhausting the attempts means the rules and the generator disagree,
     * which is a bug worth failing loudly on rather than looping.
     */
    public static function create(string $username = ''): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::candidate();

            if (self::isAcceptable($candidate, $username)) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Could not generate an administrator password that satisfies the bootstrap policy.'
        );
    }

    private static function candidate(): string
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;
        $symbols = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            // random_int() is the CSPRNG; the modulo-free index keeps the distribution uniform.
            $symbols .= $alphabet[random_int(0, $last)];
        }

        return implode('-', str_split($symbols, self::GROUP_SIZE));
    }

    private static function isAcceptable(string $candidate, string $username): bool
    {
        if (! AccountPasswordPolicy::hasValidLength($candidate)) {
            return false;
        }

        // Route the candidate through the same gate an operator-supplied ADMIN_PASS faces, so a
        // generated value can never be weaker than one this application would have refused.
        try {
            BootstrapAdminCredentials::resolvePassword($candidate, $username, true);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }
}
