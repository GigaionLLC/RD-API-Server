<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

/**
 * Short-lived proof that an administrator completed the configured console sign-in flow.
 *
 * The encrypted marker is deliberately bound to the account, credential version, current
 * password hash, and regenerated browser-session ID. It therefore cannot cross accounts or
 * sessions or outlive a credential replacement even when database sessions are not encrypted
 * as a whole.
 */
final class RecentAdminAuthentication
{
    public const SESSION_KEY = 'auth.recent_admin_authentication';

    private const PENDING_FACTOR_KEY = 'auth.pending_factor_verification';

    private const MARKER_VERSION = 2;

    private const DEFAULT_TIMEOUT_SECONDS = 300;

    private const MINIMUM_TIMEOUT_SECONDS = 60;

    private const MAXIMUM_TIMEOUT_SECONDS = 900;

    public function mark(Session $session, User $user): void
    {
        $issuedAt = now()->getTimestamp();
        $pendingFactor = $session->pull(self::PENDING_FACTOR_KEY);
        $currentFactor = $this->factorFingerprint($user);
        $verifiedFactor = is_string($pendingFactor)
            && is_string($currentFactor)
            && hash_equals($currentFactor, $pendingFactor)
                ? $currentFactor
                : null;
        $payload = json_encode([
            'version' => self::MARKER_VERSION,
            'user_id' => (string) $user->getAuthIdentifier(),
            'credential_version' => $this->credentialVersion($user),
            'credential_fingerprint' => $this->credentialFingerprint($user),
            'session_fingerprint' => $this->sessionFingerprint($session),
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + $this->timeoutSeconds(),
            'nonce' => Str::random(40),
            'factor_fingerprint' => $verifiedFactor,
        ], JSON_THROW_ON_ERROR);

        $session->put(self::SESSION_KEY, Crypt::encryptString($payload));
    }

    public function isValid(Session $session, User $user): bool
    {
        return $this->payload($session, $user) !== null;
    }

    public function nonce(Session $session, User $user): ?string
    {
        return $this->payload($session, $user)['nonce'] ?? null;
    }

    public function factorWasVerified(Session $session, User $user): bool
    {
        $verifiedFactor = $this->payload($session, $user)['factor_fingerprint'] ?? null;
        $currentFactor = $this->factorFingerprint($user);

        return is_string($verifiedFactor)
            && is_string($currentFactor)
            && hash_equals($currentFactor, $verifiedFactor);
    }

    public function noteFactorVerification(Session $session, User $user): void
    {
        $factorFingerprint = $this->factorFingerprint($user);
        if ($factorFingerprint === null) {
            $session->forget(self::PENDING_FACTOR_KEY);

            return;
        }

        $session->put(self::PENDING_FACTOR_KEY, $factorFingerprint);
    }

    public function clear(Session $session): void
    {
        $session->forget([self::SESSION_KEY, self::PENDING_FACTOR_KEY]);
    }

    public function timeoutSeconds(): int
    {
        $configured = (int) config(
            'auth.two_factor_management_timeout',
            self::DEFAULT_TIMEOUT_SECONDS,
        );

        return min(
            self::MAXIMUM_TIMEOUT_SECONDS,
            max(self::MINIMUM_TIMEOUT_SECONDS, $configured),
        );
    }

    /**
     * @return array{version:int,user_id:string,credential_version:int,credential_fingerprint:string,session_fingerprint:string,issued_at:int,expires_at:int,nonce:string,factor_fingerprint:string|null}|null
     */
    private function payload(Session $session, User $user): ?array
    {
        $stored = $session->get(self::SESSION_KEY);
        if (! is_string($stored)) {
            $this->clear($session);

            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->clear($session);

            return null;
        }

        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== self::MARKER_VERSION
            || ! is_string($decoded['user_id'] ?? null)
            || ! is_int($decoded['credential_version'] ?? null)
            || ! is_string($decoded['credential_fingerprint'] ?? null)
            || ! is_string($decoded['session_fingerprint'] ?? null)
            || ! is_int($decoded['issued_at'] ?? null)
            || ! is_int($decoded['expires_at'] ?? null)
            || ! is_string($decoded['nonce'] ?? null)
            || (! is_string($decoded['factor_fingerprint'] ?? null)
                && ($decoded['factor_fingerprint'] ?? null) !== null)) {
            $this->clear($session);

            return null;
        }

        $now = now()->getTimestamp();
        $valid = hash_equals((string) $user->getAuthIdentifier(), $decoded['user_id'])
            && $decoded['credential_version'] === $this->credentialVersion($user)
            && hash_equals($this->credentialFingerprint($user), $decoded['credential_fingerprint'])
            && hash_equals($this->sessionFingerprint($session), $decoded['session_fingerprint'])
            && $decoded['issued_at'] <= $now
            && $decoded['expires_at'] === $decoded['issued_at'] + $this->timeoutSeconds()
            && $decoded['expires_at'] >= $now
            && preg_match('/\A[A-Za-z0-9]{40}\z/', $decoded['nonce']) === 1;

        if (! $valid) {
            $this->clear($session);

            return null;
        }

        /** @var array{version:int,user_id:string,credential_version:int,credential_fingerprint:string,session_fingerprint:string,issued_at:int,expires_at:int,nonce:string,factor_fingerprint:string|null} $decoded */
        return $decoded;
    }

    private function credentialVersion(User $user): int
    {
        return max(1, (int) $user->credential_version);
    }

    private function credentialFingerprint(User $user): string
    {
        return hash_hmac(
            'sha256',
            $user->getAuthIdentifier().'|'.$this->credentialVersion($user).'|'.$user->getAuthPassword(),
            (string) config('app.key'),
        );
    }

    private function sessionFingerprint(Session $session): string
    {
        return hash_hmac('sha256', $session->getId(), (string) config('app.key'));
    }

    /**
     * Bind carried factor assurance to the exact authenticator configuration that was proved.
     * Recovery-code consumption deliberately does not change this fingerprint: every recovery
     * code belongs to the same TOTP enrollment, while replacing that enrollment changes its
     * encrypted secret and confirmation timestamp.
     */
    private function factorFingerprint(User $user): ?string
    {
        $rawSecret = $user->getRawOriginal('two_factor_secret');
        if (! $user->two_factor_enabled
            || $user->login_verify !== User::LOGIN_VERIFY_TOTP
            || ! is_string($rawSecret)
            || $rawSecret === '') {
            return null;
        }

        $confirmedAt = $user->getRawOriginal('two_factor_confirmed_at');

        return hash_hmac(
            'sha256',
            $user->getAuthIdentifier().'|'.$rawSecret.'|'.(is_scalar($confirmedAt) ? (string) $confirmedAt : ''),
            (string) config('app.key'),
        );
    }
}
