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
            $bindings = ['1'],
            10,
            'test',
        ]);

        $breadcrumbs = $this->getCurrentBreadcrumbs();

        /** @var \Sentry\Breadcrumb $lastBreadcrumb */
        $lastBreadcrumb = end($breadcrumbs);

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
    }

    public function testSqlQueriesAreRecordedWhenDisabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_queries' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_queries'));

        $this->dispatchLaravelEvent('illuminate.query', [
            $query = 'SELECT * FROM breadcrumbs WHERE bindings <> ?;',
            $bindings = ['1'],
            10,
            'test',
        ]);

        $breadcrumbs = $this->getCurrentBreadcrumbs();
        $this->assertEmpty($breadcrumbs);
    }
}
