<?php

namespace Sentry\Laravel\Tests;

class SqlBindingsInBreadcrumbsTest extends SentryLaravelTestCase
{
    public function testSqlBindingsAreRecordedWhenEnabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

        $this->dispatchLaravelEvent('illuminate.query', [
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            $bindings = ['1'],
            10,
            'test',
        ]);

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
        $this->assertEquals($bindings, $lastBreadcrumb->getMetadata()['bindings']);
    }

    public function testSqlBindingsAreRecordedWhenDisabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

        $this->dispatchLaravelEvent('illuminate.query', [
            $query = 'SELECT * FROM breadcrumbs WHERE bindings <> ?;',
            ['1'],
            10,
            'test',
        ]);

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
        $this->assertFalse(isset($lastBreadcrumb->getMetadata()['bindings']));
    }
}
