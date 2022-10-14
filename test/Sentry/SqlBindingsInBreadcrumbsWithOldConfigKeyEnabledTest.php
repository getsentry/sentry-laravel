<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Mockery;

class SqlBindingsInBreadcrumbsWithOldConfigKeyEnabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $config = $app['config']->all();

        $config['sentry']['breadcrumbs.sql_bindings'] = true;

        $app['config'] = new Repository($config);
    }

    public function testSqlBindingsAreRecordedWhenEnabledByOldConfigKey(): void
    {
        $this->assertTrue($this->app['config']->get('sentry')['breadcrumbs.sql_bindings']);

        $connection = Mockery::mock(Connection::class)
            ->shouldReceive('getName')->andReturn('test');



        $this->dispatchLaravelEvent(new QueryExecuted(
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            $bindings = ['1'],
            10,
            $connection
        ));

        $lastBreadcrumb = $this->getLastBreadcrumb();

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
        $this->assertEquals($bindings, $lastBreadcrumb->getMetadata()['bindings']);
    }
}
