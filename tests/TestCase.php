<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const TEST_CONNECTION = 'mariadb';

    private const TEST_DATABASE = 'rustdesk_api_testing';

    private const TEST_HOSTS = [
        '127.0.0.1',
        '::1',
        'localhost',
        'test-db',
    ];

    /**
     * Validate the live database target before RefreshDatabase can run migrate:fresh.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->assertSafeTestDatabase($app);

        return $app;
    }

    private function assertSafeTestDatabase(Application $app): void
    {
        if (! $app->environment('testing')) {
            throw new RuntimeException('The test suite must run with APP_ENV=testing.');
        }

        $connectionName = $app['config']->get('database.default');
        if ($connectionName !== self::TEST_CONNECTION) {
            throw new RuntimeException(sprintf(
                'Unsafe test database connection [%s]; expected [%s].',
                is_scalar($connectionName) ? (string) $connectionName : get_debug_type($connectionName),
                self::TEST_CONNECTION,
            ));
        }

        $connectionConfig = $app['config']->get('database.connections.'.self::TEST_CONNECTION);
        if (! is_array($connectionConfig)) {
            throw new RuntimeException('The MariaDB test connection is not configured.');
        }

        $driver = (string) ($connectionConfig['driver'] ?? '');
        $database = (string) ($connectionConfig['database'] ?? '');
        $host = strtolower(rtrim(trim((string) ($connectionConfig['host'] ?? '')), '.'));
        $port = (string) ($connectionConfig['port'] ?? '');
        $socket = trim((string) ($connectionConfig['unix_socket'] ?? ''));

        if ($driver !== self::TEST_CONNECTION || $database !== self::TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'Unsafe test database target [%s/%s]; expected [%s/%s].',
                $driver,
                $database,
                self::TEST_CONNECTION,
                self::TEST_DATABASE,
            ));
        }

        if (! in_array($host, self::TEST_HOSTS, true) || $port !== '3306' || $socket !== '') {
            throw new RuntimeException(sprintf(
                'Unsafe MariaDB test endpoint [%s:%s]; use an approved local test service without a Unix socket.',
                $host,
                $port,
            ));
        }

        $connection = $app['db']->connection(self::TEST_CONNECTION);
        $server = $connection->selectOne(
            'SELECT DATABASE() AS database_name, VERSION() AS server_version, '
            .'@@version_comment AS version_comment, '
            .'@@default_storage_engine AS default_storage_engine'
        );
        $actualDatabase = is_object($server) ? (string) ($server->database_name ?? '') : '';
        $serverIdentity = is_object($server)
            ? (string) ($server->server_version ?? '').' '.(string) ($server->version_comment ?? '')
            : '';
        $defaultStorageEngine = is_object($server)
            ? strtolower((string) ($server->default_storage_engine ?? ''))
            : '';

        if ($actualDatabase !== self::TEST_DATABASE
            || ! str_contains(strtolower($serverIdentity), 'mariadb')
            || $defaultStorageEngine !== 'innodb') {
            throw new RuntimeException(sprintf(
                'Unsafe live test database [%s] on server [%s] with engine [%s]; '
                .'expected [%s] on MariaDB with InnoDB.',
                $actualDatabase,
                trim($serverIdentity),
                $defaultStorageEngine,
                self::TEST_DATABASE,
            ));
        }
    }
}
