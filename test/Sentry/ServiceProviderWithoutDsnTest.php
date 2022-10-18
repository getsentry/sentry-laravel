<?php

namespace Sentry\Laravel\Tests;

use Sentry\Laravel\ServiceProvider;
use Illuminate\Routing\Events\RouteMatched;

class ServiceProviderWithoutDsnTest extends \Orchestra\Testbench\TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('sentry.dsn', null);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    public function testIsBound(): void
    {
        $this->assertTrue(app()->bound('sentry'));
    }

    /**
     * @depends testIsBound
     */
    public function testDsnIsNotSet(): void
    {
        $this->assertNull(app('sentry')->getClient()->getOptions()->getDsn());
    }

    /**
     * @depends testIsBound
     */
    public function testDidNotRegisterEvents(): void
    {
        $this->assertEquals(false, app('events')->hasListeners(RouteMatched::class));
    }
}
