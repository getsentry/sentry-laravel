<?php

namespace Sentry\Laravel\Tests\EventHandler;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Mockery;
use Sentry\Laravel\Tests\TestCase;

class DatabaseEventsTest extends TestCase
{
    public function testSqlQueriesAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

        $this->dispatchLaravelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
    }

    public function testSqlQueriesAreRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

        $this->dispatchLaravelEvent(new QueryExecuted(
            'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $this->assertEmpty($this->getCurrentSentryBreadcrumbs());
    }

    public function testSqlQueryDataIsRecorded(): void
    {
        $this->dispatchLaravelEvent(new QueryExecuted(
            'SELECT * FROM users WHERE email = :email AND password = :password;',
            ['email' => 'foo@example.com', 'password' => 'secret'],
            10,
            $this->getMockedConnection()
        ));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertSame('foo@example.com', $metadata['db.query.parameter.email']);
        $this->assertSame('[Filtered]', $metadata['db.query.parameter.password']);
    }

    public function testSqlQueryDataIsNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.data_collection' => [
                'database_query_data' => false,
            ],
        ]);

        $this->dispatchLaravelEvent(new QueryExecuted(
            'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $metadata = $this->getLastSentryBreadcrumb()->getMetadata();

        $this->assertArrayNotHasKey('db.query.parameter.0', $metadata);
    }

    private function getMockedConnection()
    {
        return Mockery::mock(Connection::class)
            ->shouldReceive('getName')->andReturn('test');
    }
}
