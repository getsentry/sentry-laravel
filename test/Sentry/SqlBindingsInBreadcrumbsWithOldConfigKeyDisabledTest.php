<?php

namespace Sentry\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Mockery;

class SqlBindingsInBreadcrumbsWithOldConfigKeyDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $config = $app['config']->all();

        $config['sentry']['breadcrumbs.sql_bindings'] = false;

        $app['config'] = new Repository($config);
    }

    public function testSqlBindingsAreRecordedWhenDisabledByOldConfigKey(): void
    {
        $this->assertFalse($this->app['config']->get('sentry')['breadcrumbs.sql_bindings']);

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
        $this->assertFalse(isset($lastBreadcrumb->getMetadata()['bindings']));
    }
}
