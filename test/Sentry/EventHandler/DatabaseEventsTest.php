<?php

namespace Sentry\Laravel\Tests\EventHandler;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
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

    public function testSqlBindingsAreRecordedWhenEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

        $this->dispatchLaravelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            $bindings = ['1'],
            10,
            $this->getMockedConnection()
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertSame($query, $lastBreadcrumb->getMessage());
        $this->assertSame($bindings, $lastBreadcrumb->getMetadata()['bindings']);
    }

    public function testSqlBindingsAreInlined(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => 'embed',
        ]);

        $this->assertSame('embed', $this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

        $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;';
        $bindings = ['1'];
        $rawSQL = 'SELECT * FROM breadcrumbs WHERE bindings = 1;';

        $grammar = Mockery::mock(Grammar::class);
        $grammar
            ->shouldReceive('substituteBindingsIntoRawSql')
            ->with($query, $bindings)
            ->andReturn($rawSQL);

        $builder = Mockery::mock(Builder::class);
        $builder
            ->shouldReceive('getGrammar')
            ->andReturn($grammar);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getName')
            ->andReturn('test');
        $connection->shouldReceive('query')
            ->andReturn($builder);
        $connection->shouldReceive('prepareBindings')
            ->with($bindings)
            ->andReturn($bindings);

        $this->dispatchLaravelEvent(new QueryExecuted(
            $query,
            $bindings,
            10,
            $connection
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertSame($rawSQL, $lastBreadcrumb->getMessage());
        $this->assertArrayNotHasKey('bindings', $lastBreadcrumb->getMetadata());
    }

    public function testSqlQueriesAreNotRecordedWhenDisabled(): void
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

    public function testSqlBindingsAreNotRecordedWhenDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

        $this->dispatchLaravelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings <> ?;',
            ['1'],
            10,
            $this->getMockedConnection()
        ));

        $lastBreadcrumb = $this->getLastSentryBreadcrumb();

        $this->assertSame($query, $lastBreadcrumb->getMessage());
        $this->assertArrayNotHasKey('bindings', $lastBreadcrumb->getMetadata());
    }

    private function getMockedConnection()
    {
        return Mockery::mock(Connection::class)
            ->shouldReceive('getName')
            ->andReturn('test');
    }
}
