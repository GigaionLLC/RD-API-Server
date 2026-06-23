<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerifyCode;

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
     * Generate, persist (VerifyCode), and return a numeric email verification code.
     * The caller is responsible for mailing it; we only store the record.
     */
    public function issueEmailCode(User $user, string $uuid, ?string $rustdeskId = null): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Invalidate any previous outstanding email codes for this user + device.
        VerifyCode::where('user_id', $user->id)
            ->where('type', VerifyCode::TYPE_EMAIL)
            ->where('uuid', $uuid)
            ->update(['status' => 0]);

        VerifyCode::create([
            'user_id' => $user->id,
            'type' => VerifyCode::TYPE_EMAIL,
            'uuid' => $uuid !== '' ? $uuid : (string) $user->id,
            'code' => $code,
            'rustdesk_id' => $rustdeskId,
            'status' => 1,
            'expires_at' => now()->addMinutes(self::EMAIL_TTL_MINUTES),
        ]);

        return $code;
    }

    /**
     * Verify a previously issued email code, consuming it on success.
     */
    public function verifyEmailCode(User $user, string $uuid, string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        $record = VerifyCode::where('user_id', $user->id)
            ->where('type', VerifyCode::TYPE_EMAIL)
            ->where('uuid', $uuid !== '' ? $uuid : (string) $user->id)
            ->where('status', 1)
            ->orderByDesc('id')
            ->first();

        if (! $record) {
            return false;
        }

        if ($record->expires_at !== null && $record->expires_at->isPast()) {
            $record->forceFill(['status' => 0])->save();

            return false;
        }

        if (! hash_equals((string) $record->code, $code)) {
            return false;
        }

        // Single-use: consume the code.
        $record->forceFill(['status' => 0])->save();

        return true;
    }
}
