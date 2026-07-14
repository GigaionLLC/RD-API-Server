<?php

namespace App\Support;

use RuntimeException;

/**
 * Resolves the one-time administrator password used by the database seeders.
 *
 * Local and test environments retain a predictable credential for development fixtures.
 * Production must always supply an explicit, non-placeholder password before an administrator
 * can be created.
 */
final class BootstrapAdminCredentials
{
    public const DEVELOPMENT_PASSWORD = 'admin123456';

    public const MINIMUM_PASSWORD_LENGTH = 12;

    /**
     * @throws RuntimeException when a production bootstrap password is unsafe
     */
    public static function resolvePassword(?string $configuredPassword, string $username, bool $production): string
    {
        if (! $production) {
            return self::isMissing($configuredPassword)
                ? self::DEVELOPMENT_PASSWORD
                : (string) $configuredPassword;
        }

        if (self::isMissing($configuredPassword)) {
            throw new RuntimeException(
                'Production bootstrap refused: ADMIN_PASS is required before the initial administrator can be created.'
            );
        }

        $password = (string) $configuredPassword;

        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
            throw new RuntimeException(sprintf(
                'Production bootstrap refused: ADMIN_PASS must contain at least %d characters.',
                self::MINIMUM_PASSWORD_LENGTH
            ));
        }

        if (self::isKnownOrPlaceholder($password) || self::isDerivedFromUsername($password, $username)) {
            throw new RuntimeException(
                'Production bootstrap refused: ADMIN_PASS must not be a default, placeholder, repeated, or username-derived value.'
            );
        }

        return $password;
    }

    private static function isMissing(?string $password): bool
    {
        return $password === null || trim($password) === '';
    }

    private static function isKnownOrPlaceholder(string $password): bool
    {
        $trimmed = trim($password);
        $normalized = self::normalize($trimmed);

        if (str_starts_with($trimmed, '<') && str_ends_with($trimmed, '>')) {
            return true;
        }

        $knownPasswords = [
            'admin',
            'administrator',
            'admin123',
            'admin123456',
            'adminpassword',
            'letmein',
            'password',
            'password1',
            'password123',
            'qwerty',
            'qwerty123',
            'rustdesk',
            'rustdesk123',
            'secret',
            'secret123',
            'welcome',
            '123456',
            '12345678',
            '123456789',
            '1234567890',
        ];

        if (in_array($normalized, $knownPasswords, true)) {
            return true;
        }

        foreach ([
            'changeme',
            'chooseastrongpassword',
            'chooseauniquepassword',
            'generateastrongpassword',
            'generaterandompassword',
            'passwordhere',
            'placeholder',
            'replaceme',
            'replacewith',
            'setme',
            'youradminpassword',
            'yourpassword',
            'yourstrongpassword',
        ] as $placeholderFragment) {
            if (str_contains($normalized, $placeholderFragment)) {
                return true;
            }
        }

        if (preg_match('/^(.{1,8})\1+$/u', $trimmed) === 1 || preg_match('/^\d+$/', $trimmed) === 1) {
            return true;
        }

        if (preg_match(
            '/^(?:(?:admin|administrator|letmein|password|passw0rd|qwerty|rustdesk|secret|welcome)[0-9!@#$%^&*._-]*)+$/i',
            $trimmed
        ) === 1) {
            return true;
        }

        return preg_match(
            '/(?:change|choose|generate|replace|set)[-_ ]*me|placeholder|example|your[-_ ]*(?:admin[-_ ]*)?password/i',
            $trimmed
        ) === 1;
    }

    private static function isDerivedFromUsername(string $password, string $username): bool
    {
        $normalizedPassword = self::normalize($password);
        $normalizedUsername = self::normalize($username);

        if ($normalizedUsername === '') {
            return false;
        }

        if ($normalizedPassword === $normalizedUsername) {
            return true;
        }

        if (str_starts_with($normalizedPassword, $normalizedUsername)) {
            $suffix = substr($normalizedPassword, strlen($normalizedUsername));
            if ($suffix !== '' && (ctype_digit($suffix) || in_array($suffix, ['pass', 'password'], true))) {
                return true;
            }
        }

        return preg_match(
            '/^(?:'.preg_quote($normalizedUsername, '/').'){2,}$/',
            $normalizedPassword
        ) === 1;
    }

    private static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?? '';
    }
}
