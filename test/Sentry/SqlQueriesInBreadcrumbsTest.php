<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Mockery;

class SqlQueriesInBreadcrumbsTest extends SentryLaravelTestCase
{
    public function testSqlQueriesAreRecordedWhenEnabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

        $connection = Mockery::mock(Connection::class)
            ->shouldReceive('getName')->andReturn('test');

        $this->dispatchLaravelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            $connection
        ));

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
    }

    public function testSqlQueriesAreRecordedWhenDisabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

        $this->dispatchLaravelEvent('illuminate.query', [
            'SELECT * FROM breadcrumbs WHERE bindings <> ?;',
            ['1'],
            10,
            'test',
        ]);

        $this->assertEmpty($this->getCurrentBreadcrumbs());
    }
}
