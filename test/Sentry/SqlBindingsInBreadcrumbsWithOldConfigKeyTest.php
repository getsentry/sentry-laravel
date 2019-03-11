<?php

namespace Sentry\Laravel\Tests;

use Sentry\State\Hub;
use Illuminate\Support\Arr;
use Illuminate\Config\Repository;

class SqlBindingsInBreadcrumbsWithOldConfigKeyTest extends SentryLaravelTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('sentry.dsn', 'http://publickey:secretkey@sentry.dev/123');

        $config = $app['config']->all();

        $config['sentry']['breadcrumbs.sql_bindings'] = true;

        unset($config['sentry']['breadcrumbs']);

        $app['config'] = new Repository($config);
    }

    public function testIsBound()
    {
        $this->assertTrue(app()->bound('sentry'));
        $this->assertInstanceOf(Hub::class, app('sentry'));
    }

    /**
     * @depends testIsBound
     */
    public function testSqlBindingsAreRecordedWhenEnabledByOldConfigKey()
    {
        $this->assertTrue($this->app['config']->get('sentry')['breadcrumbs.sql_bindings']);

        $this->app['events']->dispatch('illuminate.query', [
            $query = 'SELECT * FROM breadcrumbs WHERE bindings = ?;',
            $bindings = ['1'],
            10,
            'test',
        ]);

        /** @var \Sentry\Breadcrumb $lastBreadcrumb */
        $lastBreadcrumb = Arr::last($this->getScope(Hub::getCurrent())->getBreadcrumbs());

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
        $this->assertEquals($bindings, $lastBreadcrumb->getMetadata()['bindings']);
    }
}
