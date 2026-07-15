<?php

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use PDO;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Enforces the MariaDB-only boundary even when Laravel loaded a stale configuration cache.
 */
final class MariaDbConnectionBoundary
{
    /** @var WeakMap<Connection, bool>|null */
    private static ?WeakMap $liveConnectionStates = null;

    /**
     * Commands that must remain available so an operator can remove stale configuration.
     * Package discovery and framework asset publishing are database-independent Composer hooks.
     */
    private const SAFE_RECOVERY_COMMANDS = [
        'config:clear',
        'package:discover',
        'vendor:publish',
    ];

    /**
     * Database-backed framework services that may carry a connection name in stale config.
     */
    private const DATABASE_CONNECTION_CONSUMERS = [
        'cache.stores.database.connection',
        'cache.stores.database.lock_connection',
        'queue.connections.database.connection',
        'session.connection',
    ];

    /**
     * @param  array<int, string>|null  $arguments
     */
    public static function enforce(Application $app, ?array $arguments = null): void
    {
        $arguments ??= is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
        $command = (string) ($arguments[1] ?? '');

        if ($app->runningInConsole() && in_array($command, self::SAFE_RECOVERY_COMMANDS, true)) {
            self::prepareRecoveryConfiguration($app);

            return;
        }

        $connectionName = $app['config']->get('database.default');
        $connections = $app['config']->get('database.connections');
        $connection = $app['config']->get('database.connections.mariadb');
        $cachedUrl = is_array($connection) ? trim((string) ($connection['url'] ?? '')) : '';
        $environmentUrl = trim((string) getenv('DB_URL'));
        $hasUnsupportedConnection = ! is_array($connections);

        if (is_array($connections)) {
            foreach ($connections as $name => $definition) {
                if ($name !== 'mariadb' && $definition !== null) {
                    $hasUnsupportedConnection = true;
                    break;
                }
            }
        }

        $hasUnsupportedConsumer = false;
        foreach (self::DATABASE_CONNECTION_CONSUMERS as $path) {
            $consumer = $app['config']->get($path);
            if ($consumer !== null && $consumer !== '' && $consumer !== 'mariadb') {
                $hasUnsupportedConsumer = true;
                break;
            }
        }

        if ($connectionName !== 'mariadb'
            || $hasUnsupportedConnection
            || $hasUnsupportedConsumer
            || ! is_array($connection)
            || ($connection['driver'] ?? null) !== 'mariadb'
            || ($connection['read'] ?? null) !== null
            || ($connection['write'] ?? null) !== null
            || $cachedUrl !== ''
            || $environmentUrl !== '') {
            throw new RuntimeException(
                'Unsupported database configuration is loaded. MariaDB is required; unset '
                .'DB_URL, set DB_CONNECTION=mariadb, and run php artisan config:clear.',
            );
        }
    }

    /**
     * Verify every database connection when Laravel first opens it or reconnects it.
     */
    public static function registerLiveGuard(Application $app): void
    {
        $app['events']->listen(
            ConnectionEstablished::class,
            static fn (ConnectionEstablished $event) => self::attachLiveGuard($event->connection),
        );
    }

    /**
     * Reject Oracle MySQL, the wrong schema, a non-InnoDB default, or legacy table engines.
     */
    public static function enforceLiveConnection(Connection $connection): void
    {
        $database = (string) $connection->getDatabaseName();

        if ($connection->getName() !== 'mariadb'
            || $connection->getConfig('driver') !== 'mariadb'
            || $database === '') {
            self::rejectLiveConnection($connection);
        }

        try {
            $statement = $connection->getPdo()->query(
                'SELECT DATABASE() AS database_name, VERSION() AS server_version, '
                .'@@default_storage_engine AS default_storage_engine, '
                .'(SELECT COUNT(*) FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = \'BASE TABLE\' '
                .'AND COALESCE(UPPER(ENGINE), \'\') <> \'INNODB\') AS unsupported_table_count'
            );
            $server = $statement === false ? false : $statement->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            self::rejectLiveConnection($connection);
        }

        $server = is_array($server ?? null) ? $server : [];
        $hasCompleteResult = array_key_exists('database_name', $server)
            && array_key_exists('server_version', $server)
            && array_key_exists('default_storage_engine', $server)
            && array_key_exists('unsupported_table_count', $server);

        if (! $hasCompleteResult
            || (string) $server['database_name'] !== $database
            || ! str_contains(strtolower((string) $server['server_version']), 'mariadb')
            || strtolower((string) $server['default_storage_engine']) !== 'innodb'
            || (int) $server['unsupported_table_count'] !== 0) {
            self::rejectLiveConnection($connection);
        }
    }

    /**
     * Keep database-independent recovery commands bootable when an old cache names a removed
     * connection. No query is issued; the command can then delete the stale cache safely.
     */
    private static function prepareRecoveryConfiguration(Application $app): void
    {
        $connection = $app['config']->get('database.connections.mariadb');
        if (! is_array($connection)) {
            $connection = [
                'driver' => 'mariadb',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'rustdesk_api',
                'username' => 'root',
                'password' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ];
        }

        $connection['driver'] = 'mariadb';
        unset($connection['url'], $connection['read'], $connection['write']);

        $app['config']->set('database.connections', ['mariadb' => $connection]);
        $app['config']->set('database.default', 'mariadb');

        foreach (self::DATABASE_CONNECTION_CONSUMERS as $path) {
            $app['config']->set($path, null);
        }
    }

    /**
     * Validate lazily before the first query, then eagerly whenever Laravel reconnects the same
     * connection so its automatic retry cannot reach a newly selected, unverified server.
     */
    private static function attachLiveGuard(Connection $connection): void
    {
        $states = self::$liveConnectionStates ??= new WeakMap;
        $wasAttached = isset($states[$connection]);
        $wasVerified = $states[$connection] ?? false;
        $states[$connection] = false;

        if (! $wasAttached) {
            $connection->beforeExecuting(static function (mixed $_query, mixed $_bindings, Connection $liveConnection): void {
                $states = self::$liveConnectionStates ??= new WeakMap;
                if (($states[$liveConnection] ?? false) === true) {
                    return;
                }

                self::enforceLiveConnection($liveConnection);
                $states[$liveConnection] = true;
            });
        }

        if ($wasVerified) {
            self::enforceLiveConnection($connection);
            $states[$connection] = true;
        }
    }

    /**
     * Disconnect before throwing so a caught failure cannot reuse an unverified PDO handle.
     */
    private static function rejectLiveConnection(Connection $connection): never
    {
        $connection->disconnect();

        throw new RuntimeException(
            'The live database boundary could not be verified. The selected schema must be '
            .'served by MariaDB, default to InnoDB, and contain only InnoDB base tables.',
        );
    }
}
