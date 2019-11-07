<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Config\Repository;

class SqlBindingsInBreadcrumbsWithOldConfigKeyEnabledTest extends SentryLaravelTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $config = $app['config']->all();

        $config['sentry']['breadcrumbs.sql_bindings'] = true;

        $app['config'] = new Repository($config);
    }

    public function testSqlBindingsAreRecordedWhenEnabledByOldConfigKey()
    {
        $this->assertTrue($this->app['config']->get('sentry')['breadcrumbs.sql_bindings']);

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
}
