<?php

namespace Sentry\Laravel\Tests;

class SqlQueriesInBreadcrumbsTest extends SentryLaravelTestCase
{
    public function testSqlQueriesAreRecordedWhenEnabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

        $this->dispatchLaravelEvent('illuminate.query', [
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            ['1'],
            10,
            'test',
        ]);

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
