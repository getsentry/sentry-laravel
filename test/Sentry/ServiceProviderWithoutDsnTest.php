<?php

namespace Sentry\Laravel\Tests;

use Sentry\Laravel\ServiceProvider;
use Illuminate\Routing\Events\RouteMatched;

class ServiceProviderWithoutDsnTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sentry.dsn', null);
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    public function testIsBound()
    {
        $this->assertTrue(app()->bound('sentry'));
    }

    /**
     * @depends testIsBound
     */
    public function testDsnIsNotSet()
    {
        $this->assertNull(app('sentry')->getClient()->getOptions()->getDsn());
    }

    /**
     * @depends testIsBound
     */
    public function testDidNotRegisterEvents()
    {
        $this->assertEquals(false, app('events')->hasListeners('router.matched') && app('events')->hasListeners(RouteMatched::class));
    }
}
