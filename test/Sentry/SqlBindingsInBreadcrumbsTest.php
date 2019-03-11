<?php

namespace Sentry\Laravel\Tests;

use Sentry\State\Hub;
use Illuminate\Support\Arr;

class SqlBindingsInBreadcrumbsTest extends SentryLaravelTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.dsn', 'http://publickey:secretkey@sentry.dev/123');

        parent::getEnvironmentSetUp($app);
    }

    public function testIsBound()
    {
        $this->assertTrue(app()->bound('sentry'));
        $this->assertInstanceOf(Hub::class, app('sentry'));
    }

    /**
     * @depends testIsBound
     */
    public function testSqlBindingsAreRecordedWhenEnabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => true,
        ]);

        $this->assertTrue($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

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

    /**
     * @depends testIsBound
     */
    public function testSqlBindingsAreRecordedWhenDisabled()
    {
        $this->resetApplicationWithConfig([
            'sentry.breadcrumbs.sql_bindings' => false,
        ]);

        $this->assertFalse($this->app['config']->get('sentry.breadcrumbs.sql_bindings'));

        $this->app['events']->dispatch('illuminate.query', [
            $query = 'SELECT * FROM breadcrumbs WHERE bindings <> ?;',
            $bindings = ['1'],
            10,
            'test',
        ]);

        /** @var \Sentry\Breadcrumb $lastBreadcrumb */
        $lastBreadcrumb = Arr::last($this->getScope(Hub::getCurrent())->getBreadcrumbs());

        $this->assertEquals($query, $lastBreadcrumb->getMessage());
        $this->assertFalse(isset($lastBreadcrumb->getMetadata()['bindings']));
    }
}
