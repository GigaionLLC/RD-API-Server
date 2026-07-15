<?php

namespace Tests\Feature;

use App\Support\MariaDbConnectionBoundary;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Mockery;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MariaDbConnectionBoundaryTest extends TestCase
{
    private mixed $originalDefault;

    /** @var array<string, mixed> */
    private array $originalConnections;

    /** @var array<string, mixed> */
    private array $originalConsumers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $connections = config('database.connections');
        $this->originalConnections = is_array($connections) ? $connections : [];
        $this->originalConsumers = [
            'cache.stores.database.connection' => config('cache.stores.database.connection'),
            'cache.stores.database.lock_connection' => config('cache.stores.database.lock_connection'),
            'queue.connections.database.connection' => config('queue.connections.database.connection'),
            'session.connection' => config('session.connection'),
        ];
    }

    protected function tearDown(): void
    {
        config()->set('database.default', $this->originalDefault);
        config()->set('database.connections', $this->originalConnections);
        foreach ($this->originalConsumers as $path => $value) {
            config()->set($path, $value);
        }

        parent::tearDown();
    }

    public function test_loaded_unsupported_connection_is_rejected(): void
    {
        config()->set('database.default', 'sqlite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MariaDB is required');

        MariaDbConnectionBoundary::enforce($this->app, ['artisan', 'migrate']);
    }

    public function test_cached_database_url_is_rejected_without_disclosing_it(): void
    {
        config()->set('database.connections.mariadb.url', 'mariadb://secret@example.test/database');

        try {
            MariaDbConnectionBoundary::enforce($this->app, ['artisan', 'migrate']);
            $this->fail('Expected the cached database URL to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('DB_URL', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function test_cached_unsupported_connection_definition_is_rejected(): void
    {
        config()->set('database.connections.mysql', ['driver' => 'mysql']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MariaDB is required');

        MariaDbConnectionBoundary::enforce($this->app, ['artisan', 'migrate']);
    }

    #[DataProvider('databaseConsumerProvider')]
    public function test_cached_database_consumer_cannot_select_an_unsupported_connection(string $path): void
    {
        config()->set($path, 'mysql');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MariaDB is required');

        MariaDbConnectionBoundary::enforce($this->app, ['artisan', 'migrate']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function databaseConsumerProvider(): array
    {
        return [
            'cache connection' => ['cache.stores.database.connection'],
            'cache lock connection' => ['cache.stores.database.lock_connection'],
            'queue connection' => ['queue.connections.database.connection'],
            'session connection' => ['session.connection'],
        ];
    }

    #[DataProvider('recoveryCommandProvider')]
    public function test_database_independent_commands_remain_available_for_stale_configuration_recovery(
        string $command,
    ): void {
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', ['driver' => 'mysql']);
        config()->set('cache.stores.database.connection', 'mysql');

        MariaDbConnectionBoundary::enforce($this->app, ['artisan', $command]);

        $this->assertSame('mariadb', config('database.default'));
        $this->assertSame('mariadb', config('database.connections.mariadb.driver'));
        $this->assertNull(config('database.connections.mysql'));
        $this->assertNull(config('cache.stores.database.connection'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function recoveryCommandProvider(): array
    {
        return [
            'config clear' => ['config:clear'],
            'package discovery' => ['package:discover'],
            'framework asset publishing' => ['vendor:publish'],
        ];
    }

    public function test_live_mariadb_innodb_connection_is_accepted(): void
    {
        $connection = $this->mockLiveConnection((object) [
            'database_name' => 'rustdesk_api',
            'server_version' => '11.8.8-MariaDB',
            'default_storage_engine' => 'InnoDB',
            'unsupported_table_count' => 0,
        ]);

        MariaDbConnectionBoundary::enforceLiveConnection($connection);

        $this->addToAssertionCount(1);
    }

    public function test_live_guard_attaches_once_and_revalidates_on_reconnect(): void
    {
        $server = [
            'database_name' => 'rustdesk_api',
            'server_version' => '11.8.8-MariaDB',
            'default_storage_engine' => 'InnoDB',
            'unsupported_table_count' => 0,
        ];
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('fetch')->twice()->with(PDO::FETCH_ASSOC)->andReturn($server);

        $queryCount = 0;
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('query')->twice()->andReturnUsing(
            static function () use (&$queryCount, $statement): PDOStatement {
                $queryCount++;

                return $statement;
            },
        );

        $callbacks = [];
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')->times(4)->andReturn('mariadb');
        $connection->shouldReceive('getDatabaseName')->twice()->andReturn('rustdesk_api');
        $connection->shouldReceive('getConfig')->twice()->with('driver')->andReturn('mariadb');
        $connection->shouldReceive('getPdo')->twice()->andReturn($pdo);
        $connection->shouldReceive('beforeExecuting')->once()->andReturnUsing(
            static function (callable $callback) use (&$callbacks): void {
                $callbacks[] = $callback;
            },
        );

        $this->app['events']->dispatch(new ConnectionEstablished($connection));

        $this->assertCount(1, $callbacks);
        $this->assertSame(0, $queryCount);

        $callbacks[0]('SELECT 1', [], $connection);
        $this->assertSame(1, $queryCount);

        $this->app['events']->dispatch(new ConnectionEstablished($connection));

        $this->assertCount(1, $callbacks);
        $this->assertSame(2, $queryCount);
    }

    #[DataProvider('invalidLiveDatabaseProvider')]
    public function test_live_database_must_be_mariadb_with_only_innodb_tables(object $server): void
    {
        $connection = $this->mockLiveConnection($server, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('live database boundary');

        MariaDbConnectionBoundary::enforceLiveConnection($connection);
    }

    /**
     * @return array<string, array{object}>
     */
    public static function invalidLiveDatabaseProvider(): array
    {
        return [
            'Oracle MySQL server' => [(object) [
                'database_name' => 'rustdesk_api',
                'server_version' => '8.4.0',
                'default_storage_engine' => 'InnoDB',
                'unsupported_table_count' => 0,
            ]],
            'wrong schema' => [(object) [
                'database_name' => 'another_database',
                'server_version' => '11.8.8-MariaDB',
                'default_storage_engine' => 'InnoDB',
                'unsupported_table_count' => 0,
            ]],
            'unsupported default engine' => [(object) [
                'database_name' => 'rustdesk_api',
                'server_version' => '11.8.8-MariaDB',
                'default_storage_engine' => 'MyISAM',
                'unsupported_table_count' => 0,
            ]],
            'legacy table engine' => [(object) [
                'database_name' => 'rustdesk_api',
                'server_version' => '11.8.8-MariaDB',
                'default_storage_engine' => 'InnoDB',
                'unsupported_table_count' => 1,
            ]],
        ];
    }

    private function mockLiveConnection(object $server, bool $expectsDisconnect = false): Connection
    {
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('fetch')->once()->with(PDO::FETCH_ASSOC)->andReturn((array) $server);

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('query')->once()->andReturn($statement);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('rustdesk_api');
        $connection->shouldReceive('getName')->once()->andReturn('mariadb');
        $connection->shouldReceive('getConfig')->once()->with('driver')->andReturn('mariadb');
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);

        if ($expectsDisconnect) {
            $connection->shouldReceive('disconnect')->once();
        }

        return $connection;
    }
}
