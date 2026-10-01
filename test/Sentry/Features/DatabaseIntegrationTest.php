<?php

namespace Sentry\Laravel\Tests\Features;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Sentry\Laravel\Tests\TestCase;
use Sentry\Tracing\Span;

class DatabaseIntegrationTest extends TestCase
{
    protected function usesMySQL($app): void
    {
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => 'host-mysql',
            'port' => 3306,
            'username' => 'user-mysql',
            'password' => 'password',
            'database' => 'db-mysql',
        ]);
    }

    protected function usesMySQLFromUrl($app): void
    {
        $app['config']->set('database.default', 'mysqlurl');
        $app['config']->set('database.connections.mysqlurl', [
            'driver' => 'mysql',
            'url' => 'mysql://user-mysqlurl:password@host-mysqlurl:3307/db-mysqlurl',
        ]);
    }

    protected function usesInMemorySqlite($app): void
    {
        $app['config']->set('database.default', 'inmemory');
        $app['config']->set('database.connections.inmemory', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    protected function usesPostgreSQL($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => 'host-pgsql',
            'port' => 5432,
            'username' => 'user-pgsql',
            'password' => 'password',
            'database' => 'db-pgsql',
        ]);
    }

    protected function usesSqlServer($app): void
    {
        $app['config']->set('database.default', 'sqlsrv');
        $app['config']->set('database.connections.sqlsrv', [
            'driver' => 'sqlsrv',
            'host' => 'host-sqlsrv',
            'port' => 1433,
            'username' => 'user-sqlsrv',
            'password' => 'password',
            'database' => 'db-sqlsrv',
        ]);
    }

    /**
     * @define-env usesMySQL
     */
    #[DefineEnvironment('usesMySQL')]
    public function testSpanIsCreatedForMySQLConnectionQuery(): void
    {
        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT "mysql"'
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals('db.sql.query', $span->getOp());
        $this->assertEquals('host-mysql', $span->getData()['server.address']);
        $this->assertEquals(3306, $span->getData()['server.port']);
        $this->assertEquals('mysql', $span->getData()['db.system']);
    }

    /**
     * @define-env usesMySQLFromUrl
     */
    #[DefineEnvironment('usesMySQLFromUrl')]
    public function testSpanIsCreatedForMySQLUrlConnectionQuery(): void
    {
        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT "mysqlurl"'
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals('db.sql.query', $span->getOp());
        $this->assertEquals('host-mysqlurl', $span->getData()['server.address']);
        $this->assertEquals(3307, $span->getData()['server.port']);
    }

    /**
     * @define-env usesInMemorySqlite
     */
    #[DefineEnvironment('usesInMemorySqlite')]
    public function testSpanIsCreatedForSqliteConnectionQuery(): void
    {
        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT "inmemory"'
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals('db.sql.query', $span->getOp());
        $this->assertNull($span->getData()['server.address']);
        $this->assertNull($span->getData()['server.port']);
        $this->assertEquals('sqlite', $span->getData()['db.system']);
    }

    /**
     * @define-env usesPostgreSQL
     */
    #[DefineEnvironment('usesPostgreSQL')]
    public function testSpanDbSystemIsMappedToOpenTelemetryValueForPostgres(): void
    {
        $span = $this->executeQueryAndRetrieveSpan('SELECT "pgsql"');

        $this->assertEquals('postgresql', $span->getData()['db.system']);
    }

    /**
     * @define-env usesSqlServer
     */
    #[DefineEnvironment('usesSqlServer')]
    public function testSpanDbSystemIsMappedToOpenTelemetryValueForSqlServer(): void
    {
        $span = $this->executeQueryAndRetrieveSpan('SELECT "sqlsrv"');

        $this->assertEquals('microsoft.sql_server', $span->getData()['db.system']);
    }

    public function testSqlBindingsAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.sql_bindings' => true,
        ]);

        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT %',
            $bindings = ['1']
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertEquals($bindings, $span->getData()['db.sql.bindings']);
    }

    public function testSqlBindingsAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.sql_bindings' => false,
        ]);

        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT %',
            ['1']
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertFalse(isset($span->getData()['db.sql.bindings']));
    }

    public function testSqlBindingsAreNotRecordedWhenConfigKeyIsMissing(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing' => [
                'sql_queries' => true,
            ],
        ]);

        $span = $this->executeQueryAndRetrieveSpan(
            $query = 'SELECT %',
            ['1']
        );

        $this->assertEquals($query, $span->getDescription());
        $this->assertFalse(isset($span->getData()['db.sql.bindings']));
    }

    public function testSqlOriginIsResolvedWhenEnabledAndOverTreshold(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.sql_origin' => true,
            'sentry.tracing.sql_origin_threshold_ms' => 10,
        ]);

        $span = $this->executeQueryAndRetrieveSpan('SELECT 1', [], 20);

        $this->assertArrayHasKey('code.filepath', $span->getData());
    }

    public function testSqlOriginIsNotResolvedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.sql_origin' => false,
        ]);

        $span = $this->executeQueryAndRetrieveSpan('SELECT 1');

        $this->assertArrayNotHasKey('code.filepath', $span->getData());
    }

    public function testSqlOriginIsNotResolvedWhenUnderThreshold(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.tracing.sql_origin' => true,
            'sentry.tracing.sql_origin_threshold_ms' => 10,
        ]);

        $span = $this->executeQueryAndRetrieveSpan('SELECT 1', [], 5);

        $this->assertArrayNotHasKey('code.filepath', $span->getData());
    }

    private function executeQueryAndRetrieveSpan(string $query, array $bindings = [], int $time = 123): Span
    {
        $transaction = $this->startTransaction();

        $this->dispatchLaravelEvent(new QueryExecuted($query, $bindings, $time, DB::connection()));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);

        return $spans[1];
    }
}
