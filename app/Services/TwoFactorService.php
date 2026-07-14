<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerifyCode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Self-contained two-factor authentication helpers for the client login flow
 * (docs/modernization/02-client-api-contract.md §4).
 *
 *  - TOTP: RFC 6238, HMAC-SHA1, 6 digits, 30s step. No external composer dependency.
 *  - Email: short numeric codes persisted in the VerifyCode model and mailed to the user.
 */
class TwoFactorService
{
    /** TOTP code length. */
    private const DIGITS = 6;

    /** TOTP time step in seconds. */
    private const PERIOD = 30;

    /** How many steps before/after "now" are still accepted (clock skew tolerance). */
    private const WINDOW = 1;

    /** Email verification code lifetime in minutes. */
    private const EMAIL_TTL_MINUTES = 5;

    /** Failed guesses permitted for one issued email challenge. */
    private const EMAIL_MAX_ATTEMPTS = 5;

    /** TOTP challenge lifetime in minutes. */
    private const TOTP_CHALLENGE_TTL_MINUTES = 5;

    /** Failed guesses permitted for one issued TOTP challenge. */
    private const TOTP_MAX_ATTEMPTS = 5;

    /** Length of an opaque login-challenge secret returned to the RustDesk client. */
    private const CHALLENGE_LENGTH = 64;

    /**
     * Verify a 6-digit TOTP code against the user's stored Base32 secret.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        return $this->verifyCode((string) $user->two_factor_secret, $code);
    }

    /**
     * Verify a 6-digit TOTP code against an arbitrary Base32 secret. Used both by the client
     * login flow (via the user's stored secret) and the admin console enrollment, where the
     * candidate secret lives in the session until a valid code confirms it.
     */
    public function verifyCode(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if ($secret === '' || strlen($code) !== self::DIGITS) {
            return false;
        }

        $key = $this->base32Decode($secret);
        if ($key === '') {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals($this->hotp($key, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The TOTP code valid for the current time step against a Base32 secret. The inverse of
     * verifyCode(); returns '' for an invalid secret.
     */
    public function currentCode(string $secret): string
    {
        $key = $this->base32Decode($secret);

        return $key === '' ? '' : $this->hotp($key, intdiv(time(), self::PERIOD));
    }

    /**
     * Generate a fresh random Base32 TOTP secret (160 bits → 32 Base32 chars).
     */
    public function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        return $secret;
    }

    /**
     * Build the otpauth:// provisioning URI an authenticator app imports (also the value a
     * QR code would encode). Account + issuer are percent-encoded.
     */
    public function provisioningUri(string $secret, string $account, string $issuer = 'rustdesk-api'): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
    }

    /**
     * Generate one-time recovery codes (shown once at enrollment) for when the device is lost.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(3)).'-'.bin2hex(random_bytes(3)));
        }

        return $codes;
    }

    /**
     * Verify and consume a recovery code for the user. Returns true and removes the code on a
     * match; false otherwise. Persists the trimmed list on success.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }

        /** @var list<string> $codes */
        $codes = $user->two_factor_recovery_codes ?? [];
        $remaining = array_values(array_filter(
            $codes,
            static fn (string $c): bool => ! hash_equals(strtoupper($c), $code),
        ));

        if (count($remaining) === count($codes)) {
            return false; // no match
        }

        $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();

        return true;
    }

    /**
     * Compute the HOTP value (the TOTP building block) for a binary key + counter.
     */
    private function hotp(string $key, int $counter): string
    {
        // 8-byte big-endian counter.
        $binCounter = pack('N*', 0, $counter);

        $hash = hash_hmac('sha1', $binCounter, $key, true);

        // Dynamic truncation (RFC 4226 §5.3).
        $bytes = unpack('C*', $hash);
        $offset = $bytes[20] & 0x0F; // last nibble; unpack is 1-indexed.

        $binary = (($bytes[$offset + 1] & 0x7F) << 24)
            | (($bytes[$offset + 2] & 0xFF) << 16)
            | (($bytes[$offset + 3] & 0xFF) << 8)
            | ($bytes[$offset + 4] & 0xFF);

        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Decode an RFC 4648 Base32 string into raw bytes. Returns '' on invalid input.
     */
    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(rtrim($secret, '='));
        $secret = preg_replace('/\s+/', '', $secret) ?? '';

        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos($alphabet, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }

    /**
     * Issue an opaque, device-bound challenge after the caller has verified the first factor.
     * The TOTP itself is never persisted; the row proves that this login attempt already passed
     * its password or LDAP check before the stock client submits a code without a password.
     */
    public function issueTotpChallenge(User $user, string $uuid, string $rustdeskId): string
    {
        if ($uuid === '' || $rustdeskId === ''
            || mb_strlen($uuid) > 255 || mb_strlen($rustdeskId) > 255) {
            throw new InvalidArgumentException('A bounded RustDesk id and UUID are required.');
        }

        $secret = Str::random(self::CHALLENGE_LENGTH);

        DB::transaction(function () use ($user, $uuid, $rustdeskId, $secret): void {
            VerifyCode::where('user_id', $user->id)
                ->where('type', VerifyCode::TYPE_TOTP)
                ->where('uuid', $uuid)
                ->where('rustdesk_id', $rustdeskId)
                ->where('status', VerifyCode::STATUS_ACTIVE)
                ->update(['status' => VerifyCode::STATUS_INACTIVE]);

            VerifyCode::create([
                'user_id' => $user->id,
                'credential_version' => max(1, (int) $user->credential_version),
                'type' => VerifyCode::TYPE_TOTP,
                'uuid' => $uuid,
                'challenge_hash' => hash('sha256', $secret),
                'code' => null,
                'failed_attempts' => 0,
                'max_attempts' => self::TOTP_MAX_ATTEMPTS,
                'rustdesk_id' => $rustdeskId,
                'status' => VerifyCode::STATUS_ACTIVE,
                'expires_at' => now()->addMinutes(self::TOTP_CHALLENGE_TTL_MINUTES),
            ]);
        });

        return $secret;
    }

    /**
     * Verify and consume a bound TOTP challenge under a row lock.
     */
    public function verifyTotpChallenge(
        User $user,
        string $rustdeskId,
        string $uuid,
        string $secret,
        string $code,
    ): bool {
        if ($rustdeskId === '' || $uuid === ''
            || mb_strlen($rustdeskId) > 255 || mb_strlen($uuid) > 255
            || preg_match('/\A[A-Za-z0-9]{'.self::CHALLENGE_LENGTH.'}\z/', $secret) !== 1) {
            return false;
        }

        $code = trim($code);
        $challengeHash = hash('sha256', $secret);

        return DB::transaction(function () use ($user, $rustdeskId, $uuid, $challengeHash, $code): bool {
            $record = VerifyCode::query()
                ->where('user_id', $user->id)
                ->where('type', VerifyCode::TYPE_TOTP)
                ->where('uuid', $uuid)
                ->where('rustdesk_id', $rustdeskId)
                ->where('challenge_hash', $challengeHash)
                ->where('status', VerifyCode::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return false;
            }

            if (! $this->credentialVersionMatches($record, $user)) {
                $record->forceFill(['status' => VerifyCode::STATUS_INACTIVE])->save();

                return false;
            }

            $expiresAt = $record->getAttribute('expires_at');
            if (! $expiresAt instanceof CarbonInterface || $expiresAt->isPast()) {
                $record->forceFill(['status' => VerifyCode::STATUS_INACTIVE])->save();

                return false;
            }

            $maxAttempts = max(1, (int) $record->max_attempts);
            if ((int) $record->failed_attempts >= $maxAttempts) {
                $record->forceFill(['status' => VerifyCode::STATUS_INACTIVE])->save();

                return false;
            }

            $valid = preg_match('/\A\d{6}\z/', $code) === 1
                && $this->verifyTotp($user, $code);

            if (! $valid) {
                $failedAttempts = (int) $record->failed_attempts + 1;
                $record->forceFill([
                    'failed_attempts' => $failedAttempts,
                    'status' => $failedAttempts >= $maxAttempts
                        ? VerifyCode::STATUS_INACTIVE
                        : VerifyCode::STATUS_ACTIVE,
                ])->save();

                return false;
            }

            $record->forceFill([
                'status' => VerifyCode::STATUS_INACTIVE,
                'consumed_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * Generate an email code plus an opaque per-login challenge for the stock client to echo.
     * Only hashes are persisted; the caller receives the plaintext values long enough to return
     * the challenge and mail the code.
     *
     * @return array{code: string, secret: string}
     */
    public function issueEmailCode(User $user, string $uuid, string $rustdeskId): array
    {
        if ($uuid === '' || $rustdeskId === ''
            || mb_strlen($uuid) > 255 || mb_strlen($rustdeskId) > 255) {
            throw new InvalidArgumentException('A bounded RustDesk id and UUID are required.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $secret = Str::random(self::CHALLENGE_LENGTH);
        $codeHash = Hash::make($code);

        DB::transaction(function () use ($user, $uuid, $rustdeskId, $codeHash, $secret): void {
            // Invalidate any previous outstanding email codes for this user + device.
            VerifyCode::where('user_id', $user->id)
                ->where('type', VerifyCode::TYPE_EMAIL)
                ->where('uuid', $uuid)
                ->where('rustdesk_id', $rustdeskId)
                ->where('status', VerifyCode::STATUS_ACTIVE)
                ->update(['status' => VerifyCode::STATUS_INACTIVE]);

            VerifyCode::create([
                'user_id' => $user->id,
                'credential_version' => max(1, (int) $user->credential_version),
                'type' => VerifyCode::TYPE_EMAIL,
                'uuid' => $uuid,
                'challenge_hash' => hash('sha256', $secret),
                'code' => $codeHash,
                'failed_attempts' => 0,
                'max_attempts' => self::EMAIL_MAX_ATTEMPTS,
                'rustdesk_id' => $rustdeskId,
                'status' => VerifyCode::STATUS_ACTIVE,
                'expires_at' => now()->addMinutes(self::EMAIL_TTL_MINUTES),
            ]);
        });

        return ['code' => $code, 'secret' => $secret];
    }

    /**
     * Verify a bound email challenge under a row lock. Failed guesses are counted on the
     * challenge itself, independent of request IP/account throttles; success consumes it.
     */
    public function verifyEmailCode(
        User $user,
        string $rustdeskId,
        string $uuid,
        string $secret,
        string $code,
    ): bool {
        if ($rustdeskId === '' || $uuid === ''
            || mb_strlen($rustdeskId) > 255 || mb_strlen($uuid) > 255
            || preg_match('/\A[A-Za-z0-9]{'.self::CHALLENGE_LENGTH.'}\z/', $secret) !== 1) {
            return false;
        }

        $code = trim($code);
        $challengeHash = hash('sha256', $secret);

        return DB::transaction(function () use ($user, $rustdeskId, $uuid, $challengeHash, $code): bool {
            $record = VerifyCode::query()
                ->where('user_id', $user->id)
                ->where('type', VerifyCode::TYPE_EMAIL)
                ->where('uuid', $uuid)
                ->where('rustdesk_id', $rustdeskId)
                ->where('challenge_hash', $challengeHash)
                ->where('status', VerifyCode::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return false;
            }

            if (! $this->credentialVersionMatches($record, $user)) {
                $record->forceFill(['status' => VerifyCode::STATUS_INACTIVE])->save();

                return false;
            }

            if ($record->expires_at === null || $record->expires_at->isPast()) {
                $record->forceFill(['status' => VerifyCode::STATUS_INACTIVE])->save();

                return false;
            }

            $maxAttempts = max(1, (int) $record->max_attempts);
            if ((int) $record->failed_attempts >= $maxAttempts) {
                $record->forceFill(['status' => VerifyCode::STATUS_INACTIVE])->save();

                return false;
            }

            $valid = preg_match('/\A\d{6}\z/', $code) === 1
                && $record->code !== null
                && Hash::check($code, (string) $record->code);

            if (! $valid) {
                $failedAttempts = (int) $record->failed_attempts + 1;
                $record->forceFill([
                    'failed_attempts' => $failedAttempts,
                    'status' => $failedAttempts >= $maxAttempts
                        ? VerifyCode::STATUS_INACTIVE
                        : VerifyCode::STATUS_ACTIVE,
                ])->save();

                return false;
            }

            $record->forceFill([
                'status' => VerifyCode::STATUS_INACTIVE,
                'consumed_at' => now(),
            ])->save();

            return true;
        });
    }

    private function credentialVersionMatches(VerifyCode $record, User $user): bool
    {
        $current = User::query()->whereKey($user->id)->value('credential_version');

        return $current !== null
            && (int) $record->credential_version === max(1, (int) $current);
    }
}
