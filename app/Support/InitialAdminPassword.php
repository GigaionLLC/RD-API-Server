<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Surfaces a generated bootstrap password exactly once, then removes it.
 *
 * A password printed only to the container log is lost the moment `docker compose up` scrolls past
 * it, and the only recovery would be wiping the database volume. A password left on disk forever is
 * a plaintext credential waiting to be found. This does both and then stops: the value is written
 * where an operator can retrieve it, announced loudly, and deleted the first time the account it
 * belongs to actually signs in.
 *
 * The file is deliberately not under `storage/app/public`, never in `storage/logs` (which log
 * collectors ship off-host), and never in the database.
 */
final class InitialAdminPassword
{
    private const FILENAME = '.initial-admin-password';

    public static function path(): string
    {
        return storage_path('app/'.self::FILENAME);
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /**
     * Write the credential and announce it on stdout.
     *
     * Permissions are tightened before any content is written, so the value is never briefly
     * readable by the web process. The entrypoint re-asserts ownership afterwards because it
     * chowns the storage tree during startup.
     */
    public static function publish(string $username, string $password): void
    {
        $path = self::path();

        try {
            // The previous file is mode 0400, so it cannot be truncated in place. Remove it first
            // rather than failing to publish a credential the operator is about to need.
            self::forget();

            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('unable to open '.$path);
            }

            @chmod($path, 0o400);
            fwrite($handle, $password.PHP_EOL);
            fclose($handle);
        } catch (\Throwable $e) {
            // The banner below is still printed, so a failure here costs the backstop, not the
            // password itself. Losing it silently would be the real problem.
            Log::warning('Could not persist the initial administrator password', [
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);
        }

        self::announce($username, $password);
    }

    /**
     * Remove the credential once it has served its purpose.
     */
    public static function forget(): void
    {
        $path = self::path();

        if (is_file($path)) {
            @chmod($path, 0o600);
            @unlink($path);
        }
    }

    private static function announce(string $username, string $password): void
    {
        $lines = [
            '',
            str_repeat('=', 78),
            '  INITIAL ADMINISTRATOR CREATED',
            '',
            '    username: '.$username,
            '    password: '.$password,
            '',
            '  This password was generated because ADMIN_PASS was not set, and it is shown',
            '  here once. A copy is at storage/app/'.self::FILENAME.' inside the container',
            '  until this account first signs in, after which it is deleted automatically.',
            '',
            '  Sign in and change it. To set a new one from a shell:',
            '    php artisan rustdesk:admin:reset '.$username,
            str_repeat('=', 78),
            '',
        ];

        // Written to stderr so it survives stdout redirection into a log processor and stands out
        // from the ordinary boot chatter.
        file_put_contents('php://stderr', implode(PHP_EOL, $lines).PHP_EOL);
    }
}
