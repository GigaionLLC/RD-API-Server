<?php

namespace App\Support;

use RuntimeException;

/**
 * Protect one-time recovery codes with a versioned, application-keyed digest.
 *
 * The codes carry 48 random bits. A keyed digest keeps verification fast enough to avoid
 * turning the login endpoint into a password-hash denial-of-service target while preventing a
 * database-only compromise from recovering or directly using the stored values.
 */
final class RecoveryCodeProtector
{
    private const PREFIX = 'v1:';

    private const CODE_PATTERN = '/\A[A-F0-9]{6}-[A-F0-9]{6}\z/';

    private const DIGEST_PATTERN = '/\Av1:[a-f0-9]{64}\z/';

    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function isValidPlaintext(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $this->normalize($code)) === 1;
    }

    public function isProtected(string $value): bool
    {
        return preg_match(self::DIGEST_PATTERN, $value) === 1;
    }

    public function protect(string $code): string
    {
        $normalized = $this->normalize($code);
        if (! $this->isValidPlaintext($normalized)) {
            throw new RuntimeException('Recovery codes must use the generated recovery-code format.');
        }

        $key = $this->currentKey();

        return self::PREFIX.$this->digest($normalized, $key);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function protectMany(array $codes): array
    {
        return array_map(fn (string $code): string => $this->protect($code), $codes);
    }

    public function protectStored(string $stored): ?string
    {
        if ($this->isProtected($stored)) {
            return $stored;
        }

        return $this->isValidPlaintext($stored)
            ? $this->protect($stored)
            : null;
    }

    public function matches(string $stored, string $code): bool
    {
        $normalized = $this->normalize($code);
        if (! $this->isValidPlaintext($normalized)) {
            return false;
        }

        $keys = $this->verificationKeys();
        if ($keys === []) {
            return false;
        }

        if (! $this->isProtected($stored)) {
            $legacy = $this->normalize($stored);

            return $this->isValidPlaintext($legacy) && hash_equals($legacy, $normalized);
        }

        foreach ($keys as $key) {
            if (hash_equals($stored, self::PREFIX.$this->digest($normalized, $key))) {
                return true;
            }
        }

        return false;
    }

    private function digest(string $normalized, string $key): string
    {
        return hash_hmac('sha256', 'rustdesk-recovery-code-v1|'.$normalized, $key);
    }

    private function currentKey(): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is required to protect recovery codes.');
        }

        return $key;
    }

    /**
     * @return list<string>
     */
    private function verificationKeys(): array
    {
        $configured = [config('app.key'), ...(array) config('app.previous_keys', [])];

        return array_values(array_unique(array_filter(
            $configured,
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        )));
    }
}
