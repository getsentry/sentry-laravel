<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Config\Repository;

class SqlBindingsInBreadcrumbsWithOldConfigKeyDisabledTest extends SentryLaravelTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $config = $app['config']->all();

        $config['sentry']['breadcrumbs.sql_bindings'] = false;

        $app['config'] = new Repository($config);
    }

    public function testSqlBindingsAreRecordedWhenDisabledByOldConfigKey()
    {
        $this->assertFalse($this->app['config']->get('sentry')['breadcrumbs.sql_bindings']);

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
        $this->assertFalse(isset($lastBreadcrumb->getMetadata()['bindings']));
    }
}
