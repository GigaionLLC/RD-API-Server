<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Shared brute-force bookkeeping for the client API login and the admin console login.
 *
 * Two independent ceilings apply:
 *
 * - Per source: callers are bucketed by IPv4 address, or by IPv6 /64 network, because a single
 *   IPv6 host commonly controls a whole /64 and could otherwise rotate addresses for free.
 * - Per account: failed first-factor attempts are counted per submitted account name across all
 *   sources, so a botnet cannot make unlimited guesses against one account. The ceiling is
 *   checked before any LDAP bind is attempted, so this server can never drive more directory
 *   binds for one account than the ceiling allows (and never more than before this existed).
 *   A successful sign-in clears the counter; the default (20 failures per 15 minutes) leaves a
 *   legitimate user ample room for ordinary typing mistakes.
 */
final class LoginThrottle
{
    /**
     * The limiter bucket for a client address: the address itself for IPv4 (including IPv4-mapped
     * IPv6), and the /64 network prefix for other IPv6 addresses.
     */
    public static function ipBucket(?string $ip): string
    {
        $ip = trim((string) $ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    public static function accountLocked(string $username): bool
    {
        $max = self::maxAccountFailures();

        return $max > 0 && RateLimiter::tooManyAttempts(self::accountKey($username), $max);
    }

    public static function accountAvailableIn(string $username): int
    {
        return max(1, RateLimiter::availableIn(self::accountKey($username)));
    }

    public static function recordAccountFailure(string $username): void
    {
        if (self::maxAccountFailures() > 0) {
            RateLimiter::hit(self::accountKey($username), self::decaySeconds());
        }
    }

    public static function clearAccount(string $username): void
    {
        RateLimiter::clear(self::accountKey($username));
    }

    private static function accountKey(string $username): string
    {
        return 'rd-login-account:'.hash('sha256', mb_strtolower(trim($username)));
    }

    private static function maxAccountFailures(): int
    {
        return max(0, (int) config('rustdesk.login.account_max_failures', 20));
    }

    private static function decaySeconds(): int
    {
        return max(1, (int) config('rustdesk.login.account_decay_minutes', 15)) * 60;
    }
}
